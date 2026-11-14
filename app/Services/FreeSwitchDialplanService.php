<?php

namespace App\Services;

use App\Models\CallQueue;
use App\Models\InboundRoute;
use App\Models\IvrMenu;
use App\Models\OutboundRoute;
use App\Models\SipExtension;
use App\Models\SipNumber;
use App\Models\Tenant;
use App\Services\Commerce\LineEntitlementService;
use DOMDocument;
use DOMElement;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FreeSwitchDialplanService
{
    public function __construct(
        private readonly NumberNormalizer $numbers,
        private readonly IvrMenuService $menus,
        private readonly InboundScheduleService $schedules,
        private readonly RecordingPolicyService $recordings,
        private readonly RecordingStorageService $recordingStorage,
        private readonly InboundAudioService $audio,
    ) {}

    /**
     * Build dialplan XML for a FreeSWITCH XML-CURL dialplan request.
     *
     * @param  array<string, mixed>  $request
     */
    public function build(string $context, array $request = []): string
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = true;

        $root = $document->appendChild($document->createElement('document'));
        $root->setAttribute('type', 'freeswitch/xml');

        $section = $root->appendChild($document->createElement('section'));
        $section->setAttribute('name', 'dialplan');

        $contextElement = $section->appendChild($document->createElement('context'));
        $contextElement->setAttribute('name', $context);

        match ($context) {
            'public' => $this->appendInbound($document, $contextElement, $request),
            'default' => $this->appendDefault($document, $contextElement, $request),
            'blucom_recording' => $this->appendRecordingLookup($document, $contextElement, $request),
            'blucom_queue_audio' => $this->appendQueueAudio($document, $contextElement, $request),
            'blucom_follow_me' => $this->appendFollowMeLookup($document, $contextElement, $request),
            'blucom_ivr' => $this->appendIvrChoice($document, $contextElement, $request),
            default => null,
        };

        return $document->saveXML() ?: '<?xml version="1.0" encoding="UTF-8"?>'."\n";
    }

    /** @param array<string, mixed> $request */
    private function appendDefault(DOMDocument $document, DOMElement $context, array $request): void
    {
        $code = $request['Hunt-Destination-Number'] ?? $request['Caller-Destination-Number'] ?? $request['destination_number'] ?? '';
        if (is_string($code) && ($code === '*8' || str_starts_with($code, '**'))) {
            $entry = $context->appendChild($document->createElement('extension'));
            $entry->setAttribute('name', 'call_pickup');
            $condition = $entry->appendChild($document->createElement('condition'));
            $condition->setAttribute('field', 'destination_number');
            $condition->setAttribute('expression', '^'.preg_quote($code, '/').'$');
            $caller = $this->resolveCallingExtension($request);
            $targets = $caller ? app(CallPickupService::class)->targets($caller, $code) : [];
            if ($targets !== [] && preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $caller->sipIdentity())) {
                $this->action($document, $condition, 'lua', 'blucom_pickup.lua '.$caller->sipIdentity().' '.implode(',', $targets));
            } else {
                $this->action($document, $condition, 'hangup', 'CALL_REJECTED');
            }

            return;
        }
        if (config('voip.voicemail_enabled')) {
            $caller = $this->resolveCallingExtension($request);
            if ($caller && $caller->enabled && $caller->voicemail_secret_encrypted && app(LineEntitlementService::class)->tenantAllows($caller->tenant, true)) {
                $entry = $context->appendChild($document->createElement('extension'));
                $entry->setAttribute('name', 'own_voicemail');
                $condition = $entry->appendChild($document->createElement('condition'));
                $condition->setAttribute('field', 'destination_number');
                $condition->setAttribute('expression', '^\\*98$');
                $this->action($document, $condition, 'answer');
                $this->action($document, $condition, 'voicemail', 'check auth default '.FreeSwitchDirectoryService::voicemailDomain($caller->tenant_id).' '.$caller->sipIdentity());
                $this->action($document, $condition, 'hangup');
            }
        }
        $this->appendLocalExtensions($document, $context, $request);
        $this->appendOutbound($document, $context, $request);
    }

    /** @param array<string, mixed> $request */
    private function appendLocalExtensions(DOMDocument $document, DOMElement $context, array $request): void
    {
        $caller = $this->resolveCallingExtension($request);
        $query = SipExtension::query()
            ->where('enabled', true)
            ->whereHas('tenant', fn ($query) => $query->where('status', 'active'));

        if ($caller !== null) {
            if (! app(LineEntitlementService::class)->tenantAllows($caller->tenant, true)) {
                return;
            }
            $query->where('tenant_id', $caller->tenant_id);
        } elseif (isset($request['variable_sip_auth_username'])) {
            return;
        } else {
            // Only extensions reached by legacy public routes may be exposed
            // to an unauthenticated public->default transfer.
            $legacyDestinations = InboundRoute::query()
                ->where('enabled', true)
                ->where('destination_type', InboundRoute::DESTINATION_EXTENSION)
                ->whereHas('sipNumber', fn ($number) => $number
                    ->whereNull('inventory_state')
                    ->where('status', 'assigned')
                    ->where('enabled', true)
                    ->where('inbound_enabled', true)
                    ->where(fn ($query) => $query
                        ->whereHas('providerGateway', fn ($gateway) => $gateway->whereNull('tenant_id'))
                        ->orWhere(fn ($missing) => $missing
                            ->whereNull('provider_gateway_id')
                            ->whereHas('tenant', fn ($tenant) => $tenant->where('system_key', 'blucom')))))
                ->pluck('destination_id');
            $query->whereIn('id', $legacyDestinations)->whereNotIn('extension', SipExtension::query()->select('extension')->groupBy('extension')->havingRaw('COUNT(*) > 1'));
        }

        $extensions = $query->orderBy('extension')->get(['id', 'extension', 'sip_username', 'enabled', 'paused_at', 'tenant_id', 'follow_me']);

        foreach ($extensions as $sipExtension) {
            $extension = $document->createElement('extension');
            $extension->setAttribute('name', 'local_'.$sipExtension->id);

            $condition = $document->createElement('condition');
            $condition->setAttribute('field', 'destination_number');
            $condition->setAttribute('expression', '^'.preg_quote($sipExtension->extension, '/').'$');

            if ($caller !== null) {
                foreach (['effective_caller_id_number', 'effective_caller_id_name'] as $variable) {
                    $set = $document->createElement('action');
                    $set->setAttribute('application', 'set');
                    $set->setAttribute('data', $variable.'='.$caller->extension);
                    $condition->appendChild($set);
                }
            }

            if ($sipExtension->follow_me['enabled'] ?? false) {
                $this->appendExtensionFollowMe($document, $condition, $sipExtension, $request);
                $this->action($document, $condition, 'hangup');
            } elseif (! app(ExtensionAvailabilityService::class)->receivesCalls($sipExtension)) {
                $this->action($document, $condition, 'hangup', 'USER_BUSY');
            } else {
                $this->action($document, $condition, 'set', 'blucom_pickup_target='.$sipExtension->id);
                $this->action($document, $condition, 'bridge', 'user/'.$sipExtension->sipIdentity().'@'.config('voip.directory_domain'));
                $this->action($document, $condition, 'unset', 'blucom_pickup_target');
            }

            $extension->appendChild($condition);
            $context->appendChild($extension);
        }
    }

    private function appendInbound(DOMDocument $document, DOMElement $context, array $request): void
    {
        $routes = InboundRoute::query()
            ->where('enabled', true)
            ->whereIn('destination_type', [InboundRoute::DESTINATION_EXTENSION, InboundRoute::DESTINATION_QUEUE, InboundRoute::DESTINATION_IVR, InboundRoute::DESTINATION_AUDIO])
            ->with([
                'sipNumber',
                'sipNumber.tenant',
                'sipNumber.providerGateway',
                'destination',
            ])
            ->whereHas('sipNumber', fn ($query) => $query
                ->where('status', 'assigned')
                ->where('enabled', true)
                ->whereNotNull('tenant_id')
                ->where('inbound_enabled', true))
            ->whereHas('sipNumber.tenant', fn ($query) => $query->where('status', 'active'))
            ->orderBy('id')
            ->get();

        /** @var InboundRoute $route */
        foreach ($routes as $route) {
            $sipNumber = $route->sipNumber;
            if ($sipNumber && ! app(LineEntitlementService::class)->allows($sipNumber)) {
                continue;
            }
            $closed = ! $this->schedules->isOpen($route);
            $destinationType = $closed ? $route->closed_destination_type : $route->destination_type;
            $destination = $closed ? $this->closedDestination($route) : $route->destination;
            $terminal = in_array($destinationType, ['audio', 'announcement', 'disconnect'], true);

            if ($sipNumber === null || (! $terminal && (! $destination instanceof SipExtension && ! $destination instanceof CallQueue
                && ! $destination instanceof IvrMenu || ! $destination->enabled))) {
                continue;
            }

            if ($destination instanceof CallQueue && (! config('voip.queues_enabled')
                || ! $destination->members()->where('enabled', true)->where('sip_extensions.tenant_id', $destination->tenant_id)->exists()
                || $destination->members()->where('sip_extensions.tenant_id', '!=', $destination->tenant_id)->exists())) {
                continue;
            }
            if ($destination instanceof IvrMenu && (! $destination->isPublished()
                || ! Storage::disk('ivr')->exists((string) ($destination->published_config['greeting'] ?? '')))) {
                continue;
            }

            if ((! $terminal && $sipNumber->tenant_id !== $destination->tenant_id)
                || $sipNumber->tenant_id !== $route->tenant_id) {
                continue;
            }
            if ($destinationType === 'announcement' && (! is_string($route->closed_announcement_path)
                || ! preg_match('#^announcements/'.$route->tenant_id.'/'.$sipNumber->id.'/[0-9A-Z]+\.wav$#D', $route->closed_announcement_path)
                || ! Storage::disk('ivr')->exists($route->closed_announcement_path))) {
                continue;
            }

            $legacy = $sipNumber->providerGateway?->tenant_id === null
                && ($sipNumber->providerGateway !== null || $sipNumber->tenant?->system_key === 'blucom');
            if ($sipNumber->tenant?->owner_customer_id !== null && (! $sipNumber->providerGateway?->enabled
                || $sipNumber->providerGateway?->verification_status !== 'approved')) {
                continue;
            }
            if (! $legacy && (! config('voip.gateway_xml_enabled')
                || $sipNumber->providerGateway?->tenant_id !== $sipNumber->tenant_id
                || ! $sipNumber->providerGateway?->enabled
                || $sipNumber->providerGateway?->verification_status !== 'approved')) {
                continue;
            }

            $extension = $document->createElement('extension');
            $extension->setAttribute('name', 'inbound_'.$sipNumber->id);

            $condition = $document->createElement('condition');
            $condition->setAttribute('field', 'destination_number');
            $condition->setAttribute('expression', $this->numbers->destinationExpression($sipNumber->normalized_number));

            $markers = ['accountcode' => 'btenant_'.$sipNumber->tenant_id, 'blucom_call_direction' => 'inbound', 'blucom_sip_number_id' => $sipNumber->id, 'blucom_assignment_id' => $sipNumber->current_assignment_id ?? 0];
            if ($destination instanceof SipExtension) {
                $markers['blucom_extension_id'] = $destination->id;
            } elseif ($destination instanceof CallQueue) {
                if ($closed) {
                    $markers['blucom_extension_id'] = 0;
                }
                $markers['blucom_queue_id'] = $destination->id;
            } elseif ($destination instanceof IvrMenu) {
                if ($closed) {
                    $markers['blucom_extension_id'] = 0;
                }
                $markers['blucom_ivr_menu_id'] = $destination->id;
                $markers['blucom_ivr_number_id'] = $sipNumber->id;
                $markers['blucom_ivr_version'] = $destination->version;
            } elseif ($closed) {
                $markers['blucom_extension_id'] = 0;
            }
            foreach ($markers as $key => $value) {
                $set = $document->createElement('action');
                $set->setAttribute('application', 'set');
                $set->setAttribute('data', $key.'='.$value);
                $condition->appendChild($set);
            }

            $requestedNumber = $request['Hunt-Destination-Number'] ?? $request['Caller-Destination-Number'] ?? $request['destination_number'] ?? null;
            if (! $terminal && is_string($requestedNumber)
                && $this->numbers->normalize($requestedNumber) === $sipNumber->normalized_number
                && preg_match('~'.$this->numbers->destinationExpression($sipNumber->normalized_number).'~D', $requestedNumber)) {
                $this->appendRecording($document, $condition, $sipNumber, 'inbound', $request, $destination?->recording_mode);
            }

            if ($destination instanceof SipExtension && ($destination->follow_me['enabled'] ?? false)) {
                $this->appendExtensionFollowMe($document, $condition, $destination, $request);
                if (! $closed) {
                    $this->appendUnanswered($document, $condition, $route, $request);
                } else {
                    $this->action($document, $condition, 'hangup');
                }
            } elseif ($destination instanceof SipExtension && ! app(ExtensionAvailabilityService::class)->receivesCalls($destination)) {
                $this->action($document, $condition, 'set', 'blucom_extension_id=0');
                $this->action($document, $condition, 'set', 'blucom_extension_paused=true');
                if (! $closed) {
                    $this->appendUnanswered($document, $condition, $route, $request);
                } else {
                    $this->action($document, $condition, 'hangup', 'USER_BUSY');
                }
            } elseif ($destinationType === InboundRoute::DESTINATION_AUDIO) {
                $this->appendAudio($document, $condition, $route->tenant_id, $route->audio_destination);
            } elseif ($destinationType === 'announcement') {
                $this->action($document, $condition, 'answer');
                $this->action($document, $condition, 'playback', Storage::disk('ivr')->path($route->closed_announcement_path));
                $this->action($document, $condition, 'hangup');
            } elseif ($destinationType === 'disconnect') {
                $this->action($document, $condition, 'hangup');
            } elseif ($destination instanceof IvrMenu) {
                $this->appendMenu($document, $condition, $destination, $sipNumber->id);
            } elseif ($destination instanceof CallQueue) {
                $this->appendQueue($document, $condition, $destination, $sipNumber, $request);
            } else {
                $action = $document->createElement('action');
                $useUnanswered = ! $closed && $route->destination_type === 'extension';
                if ($useUnanswered) {
                    $this->action($document, $condition, 'set', 'call_timeout='.max(10, min(120, $route->ring_seconds)));
                    $this->action($document, $condition, 'set', 'hangup_after_bridge=true');
                    $this->action($document, $condition, 'set', 'continue_on_fail=USER_BUSY,NO_ANSWER,NO_USER_RESPONSE,USER_NOT_REGISTERED,UNALLOCATED_NUMBER,NO_ROUTE_DESTINATION,CALL_REJECTED');
                }
                $legacyTransfer = $route->unanswered_type === null && $route->ring_seconds === 30 && $sipNumber->inventory_state === null && $legacy && $route->destination_type === InboundRoute::DESTINATION_EXTENSION
                    && $route->destination_id === $destination->id
                    && $destination->sipIdentity() === $destination->extension
                    && ! SipExtension::query()->where('extension', $destination->extension)->where('id', '!=', $destination->id)->exists();
                $action->setAttribute('application', $legacyTransfer ? 'transfer' : 'bridge');
                $action->setAttribute('data', $legacyTransfer
                    ? $destination->extension.' XML default'
                    : 'user/'.$destination->sipIdentity().'@'.config('voip.directory_domain'));
                if (! $legacyTransfer) {
                    $this->action($document, $condition, 'set', 'blucom_pickup_target='.$destination->id);
                }
                $condition->appendChild($action);
                if (! $legacyTransfer) {
                    $this->action($document, $condition, 'unset', 'blucom_pickup_target');
                }
                if ($useUnanswered && ! $legacyTransfer) {
                    $this->appendUnanswered($document, $condition, $route, $request);
                }
            }

            $extension->appendChild($condition);
            $context->appendChild($extension);
        }
    }

    /** One bounded fallback; never follows another line's fallback configuration. */
    private function appendUnanswered(DOMDocument $document, DOMElement $condition, InboundRoute $route, array $request): void
    {
        if ($route->unanswered_type === 'forward') {
            try {
                $outbound = app(LineUnansweredService::class)->forwardingRoute($route, (string) $route->unanswered_phone);
                $callerId = ltrim($outbound->sipNumber->normalized_number, '+');
                foreach (['effective_caller_id_number', 'effective_caller_id_name', 'origination_caller_id_number', 'originate_caller_id_number'] as $key) {
                    $this->action($document, $condition, 'set', $key.'='.$callerId);
                }
                // Preserve canonical E.164: the provider rejects bare country-code destinations.
                $this->action($document, $condition, 'bridge', 'sofia/gateway/'.$outbound->gateway->name.'/'.$route->unanswered_phone);
            } catch (ValidationException) {
                // A revoked or malformed route must fail closed.
            }
            $this->action($document, $condition, 'hangup');

            return;
        }
        if ($route->unanswered_type === 'audio') {
            $this->appendAudio($document, $condition, $route->tenant_id, $route->unanswered_audio_destination);

            return;
        }
        if ($route->unanswered_type === 'voicemail') {
            $mailbox = app(LineUnansweredService::class)->mailbox($route);
            if ($mailbox) {
                $this->action($document, $condition, 'answer');
                $this->action($document, $condition, 'voicemail', 'default '.FreeSwitchDirectoryService::voicemailDomain($mailbox->tenant_id).' '.$mailbox->sipIdentity());
            }
            $this->action($document, $condition, 'hangup');

            return;
        }
        $target = match ($route->unanswered_type) {
            'extension' => SipExtension::query()->where('tenant_id', $route->tenant_id)->where('enabled', true)->find($route->unanswered_id),
            'queue' => config('voip.queues_enabled') ? CallQueue::query()->where('tenant_id', $route->tenant_id)->where('enabled', true)->find($route->unanswered_id) : null,
            'ivr' => IvrMenu::query()->where('tenant_id', $route->tenant_id)->where('enabled', true)->whereNotNull('published_config')->find($route->unanswered_id),
            default => null,
        };
        if ($target instanceof SipExtension && ($route->destination_type !== 'extension' || $target->id !== $route->destination_id)
            && ($target->follow_me['enabled'] ?? false)) {
            $this->executeRecordingLookup($document, $condition, $route->sipNumber, $request, 'extension', $target->id);
            $this->action($document, $condition, 'set', 'blucom_extension_id='.$target->id);
            $this->appendExtensionFollowMe($document, $condition, $target, $request);
        } elseif ($target instanceof SipExtension && app(ExtensionAvailabilityService::class)->receivesCalls($target) && ! ($route->destination_type === 'extension' && $target->id === $route->destination_id)) {
            $this->executeRecordingLookup($document, $condition, $route->sipNumber, $request, 'extension', $target->id);
            $this->action($document, $condition, 'set', 'blucom_extension_id='.$target->id);
            $this->action($document, $condition, 'set', 'blucom_pickup_target='.$target->id);
            $this->action($document, $condition, 'bridge', 'user/'.$target->sipIdentity().'@'.config('voip.directory_domain'));
            $this->action($document, $condition, 'unset', 'blucom_pickup_target');
        } elseif ($target instanceof CallQueue && $target->members()->where('enabled', true)->where('sip_extensions.tenant_id', $route->tenant_id)->exists()
            && ! $target->members()->where('sip_extensions.tenant_id', '!=', $route->tenant_id)->exists()) {
            $this->executeRecordingLookup($document, $condition, $route->sipNumber, $request, 'queue', $target->id);
            $this->appendQueue($document, $condition, $target, $route->sipNumber, $request);
        } elseif ($target instanceof IvrMenu && Storage::disk('ivr')->exists((string) ($target->published_config['greeting'] ?? ''))) {
            $this->executeRecordingLookup($document, $condition, $route->sipNumber, $request, 'ivr', $target->id);
            $this->appendMenu($document, $condition, $target, $route->sip_number_id);
        }
        $this->action($document, $condition, 'hangup');
    }

    private function closedDestination(InboundRoute $route): SipExtension|CallQueue|IvrMenu|null
    {
        $id = $route->closed_destination_id;
        if ($id === null) {
            return null;
        }

        return match ($route->closed_destination_type) {
            'extension' => SipExtension::query()->where('tenant_id', $route->tenant_id)->find($id),
            'queue' => CallQueue::query()->where('tenant_id', $route->tenant_id)->find($id),
            'ivr' => IvrMenu::query()->where('tenant_id', $route->tenant_id)->find($id),
            default => null,
        };
    }

    /** @param array<string, mixed> $request */
    private function appendIvrChoice(DOMDocument $document, DOMElement $context, array $request): void
    {
        $menuId = $request['variable_blucom_ivr_menu_id'] ?? null;
        $numberId = $request['variable_blucom_ivr_number_id'] ?? null;
        $version = $request['variable_blucom_ivr_version'] ?? null;
        if (! is_scalar($menuId) || ! is_scalar($numberId) || ! is_scalar($version)
            || ! ctype_digit((string) $menuId) || ! ctype_digit((string) $numberId)
            || ! ctype_digit((string) $version)) {
            return;
        }
        $route = InboundRoute::query()->where('enabled', true)
            ->where('sip_number_id', (int) $numberId)
            ->with(['destination', 'sipNumber.tenant', 'sipNumber.providerGateway'])->first();
        $menu = $route === null ? null : ($route->destination_type === InboundRoute::DESTINATION_IVR
            && $route->destination_id === (int) $menuId ? $route->destination
            : ($route->closed_destination_type === InboundRoute::DESTINATION_IVR
                && $route->closed_destination_id === (int) $menuId ? $this->closedDestination($route) : null));
        if ($route?->unanswered_type === 'ivr' && $route->unanswered_id === (int) $menuId) {
            $menu = IvrMenu::query()->where('tenant_id', $route->tenant_id)->find($route->unanswered_id);
        }
        if ($route && ! $menu) {
            $roots = [];
            if ($route->destination_type === 'ivr') {
                $roots[] = $route->destination_id;
            }
            if ($route->closed_destination_type === 'ivr') {
                $roots[] = $route->closed_destination_id;
            }
            if ($route->unanswered_type === 'ivr') {
                $roots[] = $route->unanswered_id;
            }
            foreach (IvrMenu::query()->where('tenant_id', $route->tenant_id)->where('enabled', true)->whereIn('id', $roots)->get() as $rootMenu) {
                if ($this->menus->reachable($rootMenu, (int) $menuId)) {
                    $menu = IvrMenu::query()->where('tenant_id', $route->tenant_id)->where('enabled', true)->find((int) $menuId);
                    break;
                }
            }
        }
        $depth = (int) ($request['variable_blucom_ivr_depth'] ?? 0);
        if ($depth < 0 || $depth >= 5) {
            return;
        }
        $number = $route?->sipNumber;
        if (! $menu instanceof IvrMenu || ! $menu->isPublished() || $number === null
            || $menu->id !== (int) $menuId
            || $menu->tenant_id !== $route->tenant_id || $number->tenant_id !== $route->tenant_id
            || ! $number->enabled || ! $number->inbound_enabled || $number->status !== 'assigned'
            || ! $number->tenant?->isActive()) {
            return;
        }
        if (! app(LineEntitlementService::class)->allows($number)) {
            return;
        }
        if ($number->inventory_state !== null && (string) ($request['variable_blucom_assignment_id'] ?? '') !== (string) $number->current_assignment_id) {
            return;
        }
        $legacy = $number->providerGateway?->tenant_id === null
            && ($number->providerGateway !== null || $number->tenant?->system_key === 'blucom');
        if ($number->tenant?->owner_customer_id !== null && (! $number->providerGateway?->enabled
            || $number->providerGateway?->verification_status !== 'approved')) {
            return;
        }
        if (! $legacy && (! config('voip.gateway_xml_enabled')
            || $number->providerGateway?->tenant_id !== $number->tenant_id
            || ! $number->providerGateway?->enabled
            || $number->providerGateway?->verification_status !== 'approved')) {
            return;
        }

        $config = (int) $version === $menu->version ? $menu->published_config
            : ((int) $version === $menu->version - 1 ? $menu->previous_config : null);
        if (! is_array($config)) {
            return;
        }
        $destination = $this->selectIvrDestination($menu, $config, $request);
        if ($destination === null) {
            $entry = $context->appendChild($document->createElement('extension'));
            $entry->setAttribute('name', 'ivr_unavailable_'.$menu->id);
            $condition = $entry->appendChild($document->createElement('condition'));
            $condition->setAttribute('field', 'destination_number');
            $condition->setAttribute('expression', '^blucom-menu$');
            $this->action($document, $condition, 'hangup', 'USER_BUSY');

            return;
        }

        $extension = $context->appendChild($document->createElement('extension'));
        $extension->setAttribute('name', 'ivr_'.$menu->id);
        $condition = $extension->appendChild($document->createElement('condition'));
        $condition->setAttribute('field', 'destination_number');
        $condition->setAttribute('expression', '^blucom-menu$');
        $this->action($document, $condition, 'set', 'hangup_after_bridge=true');
        if (! is_string($destination) && $destination->recording_mode !== 'inherit') {
            $this->appendRecording($document, $condition, $number, 'inbound', $request, $destination->recording_mode);
        }
        if (is_string($destination)) {
            $this->appendAudio($document, $condition, $menu->tenant_id, $destination);
        } elseif ($destination instanceof IvrMenu) {
            $this->appendMenu($document, $condition, $destination, $number->id, $depth + 1);
        } elseif ($destination instanceof CallQueue) {
            $this->appendQueue($document, $condition, $destination, $number, $request);
        } elseif ($destination->follow_me['enabled'] ?? false) {
            $this->action($document, $condition, 'set', 'blucom_extension_id='.$destination->id);
            $this->appendExtensionFollowMe($document, $condition, $destination, $request);
            $this->action($document, $condition, 'hangup');
        } else {
            $this->action($document, $condition, 'set', 'blucom_extension_id='.$destination->id);
            $this->action($document, $condition, 'bridge',
                'user/'.$destination->sipIdentity().'@'.config('voip.directory_domain'));
        }
    }

    private function selectIvrDestination(IvrMenu $menu, array $config, array $request): SipExtension|CallQueue|IvrMenu|string|null
    {
        $digit = ($request['variable_blucom_ivr_digit'] ?? '');
        $digit = is_scalar($digit) ? (string) $digit : '';
        $choice = preg_match('/^[0-9]$/D', $digit) ? ($config['choices'][$digit]['destination'] ?? null) : null;
        $directExtension = preg_match('/^[1-9][0-9]{2,8}$/D', $digit)
            ? $this->menus->directDialExtensions($menu, $config)->first(fn (SipExtension $extension) => $extension->extension === $digit)
            : null;
        $destination = $this->ivrDestination($menu, $choice) ?? $directExtension;
        if ($destination instanceof SipExtension && ! ($destination->follow_me['enabled'] ?? false)
            && ! app(ExtensionAvailabilityService::class)->receivesCalls($destination)) {
            $destination = null;
        }
        $destination ??= $this->ivrDestination($menu, $config['fallback'] ?? null);
        if ($destination instanceof SipExtension && ! ($destination->follow_me['enabled'] ?? false)
            && ! app(ExtensionAvailabilityService::class)->receivesCalls($destination)) {
            $destination = null;
        }
        return $destination;
    }

    private function ivrDestination(IvrMenu $menu, ?string $choice): SipExtension|CallQueue|IvrMenu|string|null
    {
        if ($choice && InboundAudioService::isAudio($choice)) {
            return $this->audio->announcement($menu->tenant_id, $choice) || $this->audio->mailbox($menu->tenant_id, $choice)
                ? $choice : null;
        }

        return $this->menus->destination($menu, $choice ?? '');
    }

    private function appendAudio(DOMDocument $document, DOMElement $condition, int $tenantId, ?string $choice): void
    {
        $path = $this->audio->announcement($tenantId, $choice ?? '');
        $mailbox = $path ? null : $this->audio->mailbox($tenantId, $choice ?? '');
        if ($path) {
            $this->action($document, $condition, 'answer');
            $this->action($document, $condition, 'set', 'playback_terminators=none');
            $this->action($document, $condition, 'playback', $path);
            $this->action($document, $condition, 'set', 'blucom_audio_outcome=announcement:${current_application_response}');
        } elseif ($mailbox) {
            $this->action($document, $condition, 'answer');
            $this->action($document, $condition, 'unset', 'voicemail_message_len');
            $this->action($document, $condition, 'set', 'blucom_audio_outcome=voicemail_entered');
            $this->action($document, $condition, 'voicemail',
                'default '.FreeSwitchDirectoryService::voicemailDomain($tenantId).' '.$mailbox->sipIdentity());
        }
        $this->action($document, $condition, 'hangup', $path || $mailbox ? 'NORMAL_CLEARING' : 'NORMAL_TEMPORARY_FAILURE');
    }

    private function appendExtensionFollowMe(DOMDocument $document, DOMElement $condition, SipExtension $extension, array $request, int $seconds = FollowMeService::PRIMARY_SECONDS, array $path = []): void
    {
        if (app(ExtensionAvailabilityService::class)->receivesCalls($extension)) {
            $this->appendFollowMeBridge($document, $condition, $extension, $seconds);
        }
        if ($extension->follow_me['enabled'] ?? false) {
            $this->appendFollowMeSteps($document, $condition, $extension, $request, $path);
        }
    }

    private function appendFollowMeBridge(DOMDocument $document, DOMElement $condition, SipExtension $extension, int $seconds): void
    {
        $this->action($document, $condition, 'set', 'hangup_after_bridge=true');
        $this->action($document, $condition, 'set', 'continue_on_fail=USER_BUSY,NO_ANSWER,NO_USER_RESPONSE,USER_NOT_REGISTERED,SUBSCRIBER_ABSENT,UNALLOCATED_NUMBER,NO_ROUTE_DESTINATION,CALL_REJECTED,ALLOTTED_TIMEOUT,PROGRESS_TIMEOUT,NORMAL_TEMPORARY_FAILURE');
        $this->action($document, $condition, 'set', 'blucom_pickup_target='.$extension->id);
        $this->action($document, $condition, 'bridge',
            '{ignore_early_media=true,originate_timeout='.$seconds.'}[leg_timeout='.$seconds.']user/'.$extension->sipIdentity().'@'.config('voip.directory_domain'));
        $this->action($document, $condition, 'unset', 'blucom_pickup_target');
    }

    private function appendFollowMeSteps(DOMDocument $document, DOMElement $condition, SipExtension|CallQueue $source, array $request, array $path = []): void
    {
        $followMe = app(FollowMeService::class);
        $path = [...$path, $followMe->key($source)];
        foreach ($followMe->steps($source) as $step) {
            $key = $followMe->targetKey($step);
            $seconds = $step['seconds'] ?? FollowMeService::PRIMARY_SECONDS;
            if (! is_int($seconds) || $seconds < 5 || $seconds > 30) {
                continue;
            }

            // Keep the original extension-only records readable during rollout.
            $legacy = ! isset($step['destination']);
            $target = $legacy ? $followMe->resolve($source->tenant_id, $key) : null;
            if ($legacy && $target instanceof SipExtension && ! in_array($key, $path, true)
                && ! $target->paused_at && ! $this->followMeCallId($request)) {
                $this->appendFollowMeBridge($document, $condition, $target, $seconds);

                continue;
            }
            $this->executeFollowMe($document, $condition, $request, $source->tenant_id, $key, $path, $seconds);
        }
    }

    private function followMeCallId(array $request): ?string
    {
        $uuid = $request['Unique-ID'] ?? $request['variable_uuid'] ?? null;

        return is_string($uuid) && preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/Di', $uuid) ? strtolower($uuid) : null;
    }

    private function executeFollowMe(DOMDocument $document, DOMElement $condition, array $request, int $tenantId, string $key, array $path, int $seconds = FollowMeService::PRIMARY_SECONDS, string $phase = 'enter', ?int $version = null): void
    {
        $uuid = $this->followMeCallId($request);
        $secret = config('app.key');
        if (! $uuid || ! is_string($secret) || $secret === '' || count($path) > FollowMeService::MAX_DEPTH + 1
            || ($phase === 'enter' && (count($path) > FollowMeService::MAX_DEPTH || in_array($key, $path, true)))) {
            return;
        }
        $started = $request['Caller-Channel-Created-Time'] ?? null;
        $startedAt = is_scalar($started) && ctype_digit((string) $started) && (int) $started > 0
            ? intdiv((int) $started, 1000000) : time();
        $deadline = (int) ($request['_follow_me_deadline'] ?? min(time(), $startedAt) + FollowMeService::MAX_SECONDS);
        $payload = bin2hex(json_encode([$uuid, $tenantId, $key, $path, $seconds, $phase, $version, $deadline], JSON_THROW_ON_ERROR));
        $destination = 'fm-'.$payload.'-'.hash_hmac('sha256', $payload, $secret);
        $this->action($document, $condition, 'execute_extension', $destination.' XML blucom_follow_me');
    }

    private function appendFollowMeLookup(DOMDocument $document, DOMElement $context, array $request): void
    {
        $dialed = $request['Hunt-Destination-Number'] ?? $request['destination_number'] ?? null;
        $secret = config('app.key');
        if (! is_string($secret) || $secret === '' || ! is_string($dialed)
            || ! preg_match('/^fm-([a-f0-9]{2,1200})-([a-f0-9]{64})$/D', $dialed, $matches)
            || strlen($matches[1]) % 2 !== 0 || ! hash_equals(hash_hmac('sha256', $matches[1], $secret), $matches[2])) {
            return;
        }
        [$uuid, $tenantId, $key, $path, $seconds, $phase, $version, $deadline] = json_decode(hex2bin($matches[1]), true, flags: JSON_THROW_ON_ERROR);
        $tenant = Tenant::query()->find($tenantId);
        if ($uuid !== $this->followMeCallId($request) || $deadline <= time() || ! $tenant?->isActive()
            || ! app(LineEntitlementService::class)->tenantAllows($tenant, true)) {
            return;
        }
        $followMe = app(FollowMeService::class);
        $target = $followMe->resolve($tenantId, $key);
        if (! $this->followMeLineAllowed($tenantId, $request)) {
            return;
        }
        if ($phase === 'enter' && (! $target || ! $followMe->claimAttempt($uuid, $key, $deadline))) {
            return;
        }

        $request['_follow_me_deadline'] = $deadline;
        $entry = $context->appendChild($document->createElement('extension'));
        $entry->setAttribute('name', 'follow_me');
        $condition = $entry->appendChild($document->createElement('condition'));
        $condition->setAttribute('field', 'destination_number');
        $condition->setAttribute('expression', '^'.$dialed.'$');

        if ($phase === 'queue' && (($request['variable_cc_cause'] ?? null) === 'answered'
            || ($request['variable_cc_agent_bridged'] ?? null) === 'true')) {
            $this->action($document, $condition, 'hangup');

            return;
        }
        if (! $target) {
            return;
        }
        match ($phase) {
            'queue' => $this->appendFollowMeQueueExit($document, $condition, $target, $request, $path),
            'ivr' => $this->appendFollowMeIvrChoice($document, $condition, $target, $request, $path, $version),
            default => $this->appendFollowMeDestination($document, $condition, $target, $request, $path, min($seconds, $deadline - time())),
        };
    }

    private function followMeLineAllowed(int $tenantId, array $request): bool
    {
        $numberId = $request['variable_blucom_sip_number_id'] ?? null;
        if ($numberId === null || $numberId === '') {
            return true;
        }
        if (! is_scalar($numberId) || ! ctype_digit((string) $numberId)) {
            return false;
        }
        $number = SipNumber::query()->where('tenant_id', $tenantId)->where('status', 'assigned')
            ->where('enabled', true)->where('inbound_enabled', true)->find($numberId);

        return $number && app(LineEntitlementService::class)->allows($number)
            && ($number->inventory_state === null || (string) ($request['variable_blucom_assignment_id'] ?? '') === (string) $number->current_assignment_id);
    }

    private function appendFollowMeDestination(DOMDocument $document, DOMElement $condition, SipExtension|CallQueue|IvrMenu $target, array $request, array $path, int $seconds): void
    {
        $followMe = app(FollowMeService::class);
        $key = $followMe->key($target);
        $number = $this->recordingLine($request, $target->tenant_id);
        $mode = $target->recording_mode;
        if (($request['variable_blucom_recording_queue_paused'] ?? null) === 'true') {
            $this->action($document, $condition, 'unset', 'blucom_recording_queue_paused');
            $mode = $mode === 'inherit' ? ($request['variable_blucom_recording_preference'] ?? null) : $mode;
        }
        if ($number && $mode !== 'inherit') {
            $this->appendRecording($document, $condition, $number, 'inbound', $request, $mode);
        }
        if ($target instanceof SipExtension) {
            $this->appendExtensionFollowMe($document, $condition, $target, $request, $seconds, $path);
        } elseif ($target instanceof CallQueue) {
            if ($target->max_wait_seconds <= 0 || $target->max_wait_seconds + 15 > $request['_follow_me_deadline'] - time()
                || ! $target->members()->where('enabled', true)->where('sip_extensions.tenant_id', $target->tenant_id)->exists()
                || $target->members()->where('sip_extensions.tenant_id', '!=', $target->tenant_id)->exists()) {
                return;
            }
            if (empty($request['variable_blucom_queue_id'])) {
                $this->action($document, $condition, 'set', 'blucom_queue_id='.$target->id);
            }
            $this->action($document, $condition, 'set', 'hangup_after_bridge=true');
            $this->action($document, $condition, 'unset', 'cc_cancel_reason');
            $this->action($document, $condition, 'unset', 'cc_cause');
            $this->action($document, $condition, 'answer');
            $this->appendRecordedCallcenter($document, $condition, $target, $number, $request);
            $this->executeFollowMe($document, $condition, $request, $target->tenant_id, $key, $path, phase: 'queue');
        } elseif ($request['_follow_me_deadline'] - time() >= 30
            && $this->appendMenuPrompt($document, $condition, $target, $target->published_config)) {
            $this->executeFollowMe($document, $condition, $request, $target->tenant_id, $key, $path, phase: 'ivr', version: $target->version);
        }
    }

    private function appendFollowMeQueueExit(DOMDocument $document, DOMElement $condition, SipExtension|CallQueue|IvrMenu $target, array $request, array $path): void
    {
        if (! $target instanceof CallQueue) {
            return;
        }
        if (! in_array($request['variable_cc_cancel_reason'] ?? null, ['TIMEOUT', 'NO_AGENT_TIMEOUT'], true)) {
            return;
        }
        $this->action($document, $condition, 'set', 'blucom_queue_fallback_attempted=true');
        if ($target->follow_me['enabled'] ?? false) {
            $this->appendFollowMeSteps($document, $condition, $target, $request, $path);
        } elseif ($target->fallbackExtension?->tenant_id === $target->tenant_id) {
            $this->executeFollowMe($document, $condition, $request, $target->tenant_id,
                'extension:'.$target->fallback_extension_id, [...$path, 'queue:'.$target->id]);
        }
        if ($target->fallback_audio_destination) {
            $this->appendAudio($document, $condition, $target->tenant_id, $target->fallback_audio_destination);
        }
    }

    private function appendFollowMeIvrChoice(DOMDocument $document, DOMElement $condition, SipExtension|CallQueue|IvrMenu $target, array $request, array $path, ?int $version): void
    {
        if (! $target instanceof IvrMenu) {
            return;
        }
        $config = $version === $target->version ? $target->published_config
            : ($version === $target->version - 1 ? $target->previous_config : null);
        if (! is_array($config)) {
            return;
        }
        $destination = $this->selectIvrDestination($target, $config, $request);
        if (is_string($destination)) {
            $this->appendAudio($document, $condition, $target->tenant_id, $destination);
        } elseif ($destination !== null) {
            $followMe = app(FollowMeService::class);
            $this->executeFollowMe($document, $condition, $request, $target->tenant_id, $followMe->key($destination), [...$path, $followMe->key($target)]);
        }
    }

    private function appendQueue(DOMDocument $document, DOMElement $condition, CallQueue $queue, ?SipNumber $number, array $request): void
    {
        $this->action($document, $condition, 'set', 'blucom_queue_id='.$queue->id);
        $this->action($document, $condition, 'set', 'hangup_after_bridge=true');
        $this->action($document, $condition, 'answer');
        if ($queue->fallback_audio_destination || ($queue->follow_me['enabled'] ?? false)) {
            $this->action($document, $condition, 'unset', 'cc_cancel_reason');
        }
        $this->appendRecordedCallcenter($document, $condition, $queue, $number, $request);
        if ($queue->fallback_audio_destination || ($queue->follow_me['enabled'] ?? false)) {
            $this->action($document, $condition, 'transfer', 'blucom-queue-audio XML blucom_queue_audio');
        } elseif (app(ExtensionAvailabilityService::class)->receivesCalls($queue->fallbackExtension)
            && $queue->fallbackExtension->tenant_id === $queue->tenant_id) {
            $this->action($document, $condition, 'set', 'blucom_queue_fallback_attempted=true');
            $this->executeRecordingLookup($document, $condition, $number, $request, 'queue-fallback', $queue->fallbackExtension->id);
            $this->action($document, $condition, 'bridge',
                'user/'.$queue->fallbackExtension->sipIdentity().'@'.config('voip.directory_domain'));
        }
    }

    private function appendQueueAudio(DOMDocument $document, DOMElement $context, array $request): void
    {
        $numberId = $request['variable_blucom_sip_number_id'] ?? null;
        $queueId = $request['variable_blucom_queue_id'] ?? null;
        if (! config('voip.queues_enabled') || ! is_scalar($numberId) || ! is_scalar($queueId)
            || ! ctype_digit((string) $numberId) || ! ctype_digit((string) $queueId)
            || ! in_array($request['variable_cc_cancel_reason'] ?? null, ['TIMEOUT', 'NO_AGENT_TIMEOUT'], true)) {
            return;
        }

        $number = SipNumber::query()->with('tenant')->where('status', 'assigned')
            ->where('enabled', true)->where('inbound_enabled', true)->find($numberId);
        if (! $number?->tenant?->isActive()
            || ($number->inventory_state !== null && (string) ($request['variable_blucom_assignment_id'] ?? '') !== (string) $number->current_assignment_id)
            || ! app(LineEntitlementService::class)->allows($number)) {
            return;
        }
        $queue = CallQueue::query()->where('tenant_id', $number->tenant_id)->where('enabled', true)->find($queueId);
        if (! $queue || ! InboundRoute::query()->where('sip_number_id', $number->id)
            ->where('tenant_id', $number->tenant_id)->where('enabled', true)->exists()) {
            return;
        }

        $entry = $context->appendChild($document->createElement('extension'));
        $entry->setAttribute('name', 'queue_audio_'.$queue->id);
        $condition = $entry->appendChild($document->createElement('condition'));
        $condition->setAttribute('field', 'destination_number');
        $condition->setAttribute('expression', '^blucom-queue-audio$');
        if ($queue->follow_me['enabled'] ?? false) {
            $this->action($document, $condition, 'set', 'blucom_queue_fallback_attempted=true');
            $this->appendFollowMeSteps($document, $condition, $queue, $request);
        }
        if ($queue->fallback_audio_destination) {
            $this->appendAudio($document, $condition, $queue->tenant_id, $queue->fallback_audio_destination);
        } else {
            $this->action($document, $condition, 'hangup');
        }
    }

    private function appendMenu(DOMDocument $document, DOMElement $condition, IvrMenu $menu, int $numberId, int $depth = 0): void
    {
        $config = $menu->published_config;
        if (! is_array($config) || ! Storage::disk('ivr')->exists((string) ($config['greeting'] ?? ''))) {
            return;
        }
        foreach (['blucom_ivr_menu_id' => $menu->id, 'blucom_ivr_number_id' => $numberId, 'blucom_ivr_version' => $menu->version, 'blucom_ivr_depth' => $depth] as $key => $value) {
            $this->action($document, $condition, 'set', $key.'='.$value);
        }
        if ($this->appendMenuPrompt($document, $condition, $menu, $config)) {
            $this->action($document, $condition, 'transfer', 'blucom-menu XML blucom_ivr');
        }
    }

    private function appendMenuPrompt(DOMDocument $document, DOMElement $condition, IvrMenu $menu, ?array $config): bool
    {
        if (! is_array($config) || ! Storage::disk('ivr')->exists((string) ($config['greeting'] ?? ''))) {
            return false;
        }
        $direct = $this->menus->directDialExtensions($menu, $config);
        $digits = implode('', array_filter(array_keys($config['choices'] ?? []), fn ($digit) => preg_match('/^[0-9]$/D', (string) $digit)));
        if ($digits === '') {
            return false;
        }
        $pattern = $direct->isEmpty() ? '^['.$digits.']$' : '^(?:['.$digits.']|'.implode('|', $direct->pluck('extension')->all()).')$';
        $this->action($document, $condition, 'answer');
        $this->action($document, $condition, 'set', 'blucom_ivr_digit=');
        $this->action($document, $condition, 'play_and_get_digits', '1 '.max([1, ...$direct->map(fn ($e) => strlen($e->extension))->all()]).' 2 8000 # '.Storage::disk('ivr')->path($config['greeting']).' '.Storage::disk('ivr')->path($config['greeting']).' blucom_ivr_digit '.$pattern.' 3000');

        return true;
    }

    private function action(DOMDocument $document, DOMElement $condition, string $application, ?string $data = null): void
    {
        $action = $condition->appendChild($document->createElement('action'));
        $action->setAttribute('application', $application);
        if ($data !== null) {
            $action->setAttribute('data', $data);
        }
    }

    private function appendOutbound(DOMDocument $document, DOMElement $context, array $request): void
    {
        $extension = $this->resolveCallingExtension($request);

        if ($extension === null) {
            return;
        }

        $route = OutboundRoute::query()
            ->where('tenant_id', $extension->tenant_id)
            ->where('sip_extension_id', $extension->id)
            ->where('enabled', true)
            ->with([
                'gateway',
                'sipNumber',
                'sipNumber',
            ])
            ->whereHas('gateway', fn ($query) => $query->where('enabled', true)->where('approved_for_outbound', true)->where('verification_status', 'approved'))
            ->whereHas('tenant', fn ($query) => $query->where('status', 'active'))
            ->whereHas('sipNumber', fn ($query) => $query
                ->where('status', 'assigned')
                ->where('enabled', true)
                ->whereNotNull('tenant_id')
                ->where('outbound_enabled', true))
            ->orderBy('id')
            ->first();

        if ($route === null || $route->gateway === null || $route->sipNumber === null) {
            return;
        }

        if ($route->sipNumber->tenant_id !== $extension->tenant_id) {
            return;
        }
        if ($route->sipNumber->tenant?->owner_customer_id !== null
            && $route->sipNumber->provider_gateway_id !== $route->gateway_id) {
            return;
        }
        $legacy = $route->gateway->tenant_id === null;
        if (! $legacy && ! config('voip.gateway_xml_enabled')) {
            return;
        }
        if ($legacy ? ($route->gateway->tenant_id !== null && $route->gateway->tenant_id !== $extension->tenant_id)
            : ($route->gateway->tenant_id !== $extension->tenant_id || $route->sipNumber->provider_gateway_id !== $route->gateway_id)) {
            return;
        }

        if (! app(LineEntitlementService::class)->allows($route->sipNumber)) {
            return;
        }
        $callerId = ltrim($route->sipNumber->normalized_number, '+');
        $gatewayName = $route->gateway->name;

        if ($route->sipNumber->inventory_state !== null) {
            $raw = $request['destination_number'] ?? $request['Caller-Destination-Number'] ?? null;
            if (! is_string($raw) || ! preg_match('/^(?:\+|00)?[0-9]{7,15}$/D', $raw)) {
                return;
            }
            $normalized = app(NumberNormalizer::class)->normalize($raw);
            $prefixes = $route->sipNumber->destination_prefixes;
            if (! is_string($normalized) || ! is_array($prefixes) || ! collect($prefixes)->contains(
                fn ($prefix) => is_string($prefix) && preg_match('/^\+[1-9][0-9]{0,14}$/D', $prefix) && str_starts_with($normalized, $prefix))) {
                return;
            }
            $this->appendOutboundExtension($document, $context, $extension, $route->sipNumber, $request,
                $gatewayName, $callerId, 'outbound_'.$extension->id, '^'.preg_quote($raw, '/').'$', $normalized);

            return;
        }

        // The provider accepts +98 and national 0-prefixed destinations, but
        // rejects a bare 98-prefixed E.164 number as unallocated. Keep the
        // caller ID policy identical for both dialed forms.
        $this->appendOutboundExtension($document, $context, $extension, $route->sipNumber, $request, $gatewayName, $callerId,
            'outbound_'.$extension->id.'_iran_bare', '^98[1-9]\\d{9}$', '+${destination_number}');
        $this->appendOutboundExtension($document, $context, $extension, $route->sipNumber, $request, $gatewayName, $callerId,
            'outbound_'.$extension->id, '^(?:00|\\+|0)?\\d{7,15}$', '${destination_number}');
    }

    private function appendOutboundExtension(
        DOMDocument $document,
        DOMElement $context,
        SipExtension $extension,
        SipNumber $sipNumber,
        array $request,
        string $gatewayName,
        string $callerId,
        string $name,
        string $expression,
        string $destination,
    ): void {

        $extensionElement = $document->createElement('extension');
        $extensionElement->setAttribute('name', $name);

        $condition = $document->createElement('condition');
        $condition->setAttribute('field', 'destination_number');
        $condition->setAttribute('expression', $expression);

        foreach (['accountcode' => 'btenant_'.$extension->tenant_id, 'blucom_call_direction' => 'outbound', 'blucom_extension_id' => $extension->id, 'blucom_sip_number_id' => $sipNumber->id, 'blucom_assignment_id' => $sipNumber->current_assignment_id ?? 0] as $key => $value) {
            $set = $document->createElement('action');
            $set->setAttribute('application', 'set');
            $set->setAttribute('data', $key.'='.$value);
            $condition->appendChild($set);
        }

        $bridge = $document->createElement('action');
        $bridge->setAttribute('application', 'set');
        $bridge->setAttribute('data', 'effective_caller_id_number='.$callerId);
        $condition->appendChild($bridge);

        $bridgeName = $document->createElement('action');
        $bridgeName->setAttribute('application', 'set');
        $bridgeName->setAttribute('data', 'effective_caller_id_name='.$callerId);
        $condition->appendChild($bridgeName);

        $bridge2 = $document->createElement('action');
        $bridge2->setAttribute('application', 'set');
        $bridge2->setAttribute('data', 'originate_caller_id_number='.$callerId);
        $condition->appendChild($bridge2);

        $dialed = $request['Hunt-Destination-Number'] ?? $request['Caller-Destination-Number'] ?? $request['destination_number'] ?? null;
        // XML contains both local and provider rules, but only the actual
        // matching provider call should reserve recording capacity.
        if (is_string($dialed) && preg_match('~'.$expression.'~D', $dialed)
            && ! SipExtension::query()->where('tenant_id', $extension->tenant_id)->where('enabled', true)->where('extension', $dialed)->exists()) {
            $this->appendRecording($document, $condition, $sipNumber, 'outbound', $request, $extension->recording_mode);
        }

        $bridgeToGateway = $document->createElement('action');
        $bridgeToGateway->setAttribute('application', 'bridge');
        $bridgeToGateway->setAttribute(
            'data',
            'sofia/gateway/'.$gatewayName.'/'.$this->escapeAttribute($destination),
        );
        $condition->appendChild($bridgeToGateway);

        $extensionElement->appendChild($condition);
        $context->appendChild($extensionElement);
    }

    private function appendRecording(DOMDocument $document, DOMElement $condition, SipNumber $number, string $direction, array $request, ?string $mode = null): void
    {
        if (! config('voip.recordings.enabled') || ! $this->recordings->callUuid($request)) {
            return;
        }
        if ($mode === 'never') {
            $this->action($document, $condition, 'set', 'blucom_recording_preference=never');
            $this->stopRecording($document, $condition, $request);

            return;
        }
        if ($mode === 'always') {
            $this->action($document, $condition, 'set', 'blucom_recording_preference=always');
        }
        if (! empty($request['variable_blucom_recording_id'])) {
            return;
        }

        $segment = filter_var($request['variable_blucom_recording_segment'] ?? -1, FILTER_VALIDATE_INT,
            ['options' => ['min_range' => -1, 'max_range' => 1000]]);
        if ($segment === false) {
            return;
        }
        $recording = $this->recordings->reserve($number, $direction, $request, $mode === 'always', $segment + 1);
        if ($recording === null || $recording->status !== 'recording') {
            return;
        }
        if (! empty($recording->policy['announcement_path'])) {
            $this->action($document, $condition, 'answer');
            $this->action($document, $condition, 'playback', Storage::disk('ivr')->path($recording->policy['announcement_path']));
        }
        foreach ([
            'blucom_recording_id' => $recording->id,
            'blucom_recording_segment' => $recording->segment,
            'record_sample_rate' => '8000',
            'RECORD_STEREO' => 'true',
            'RECORD_MIN_SEC' => '0',
            'RECORD_ANSWER_REQ' => 'true',
            'RECORD_BRIDGE_REQ' => $recording->policy['coverage'] === 'conversation' ? 'true' : 'false',
            'RECORD_HANGUP_ON_ERROR' => 'false',
            'record_post_process_exec_api' => 'luarun:blucom_recording_complete.lua '.$this->recordingStorage->spoolPath($recording->id, 'complete'),
        ] as $key => $value) {
            $this->action($document, $condition, 'set', $key.'='.$value);
        }
        $this->action($document, $condition, 'record_session',
            $this->recordingStorage->spoolPath($recording->id).' +'.$recording->policy['max_seconds']);
    }

    private function stopRecording(DOMDocument $document, DOMElement $condition, array $request): void
    {
        $id = $request['variable_blucom_recording_id'] ?? null;
        if (is_string($id) && Str::isUuid($id)) {
            $this->action($document, $condition, 'stop_record_session', $this->recordingStorage->spoolPath(strtolower($id)));
            $this->action($document, $condition, 'unset', 'blucom_recording_id');
        }
    }

    private function appendRecordedCallcenter(DOMDocument $document, DOMElement $condition, CallQueue $queue, ?SipNumber $number, array $request): void
    {
        if ($number && config('voip.recordings.enabled') && $this->recordings->callUuid($request)) {
            $modes = $queue->members()->where('sip_extensions.tenant_id', $queue->tenant_id)
                ->where('sip_extensions.enabled', true)->pluck('sip_extensions.recording_mode');
            // The pre-bridge hook runs after initial media frames. Stop before
            // entering any queue that could connect a Never-record agent.
            if ($modes->contains('never')) {
                $this->action($document, $condition, 'set', 'blucom_recording_queue_paused=true');
                $placeholder = '00000000-0000-4000-8000-000000000000';
                $path = str_replace($placeholder, '${blucom_recording_id}', $this->recordingStorage->spoolPath($placeholder));
                $this->action($document, $condition, 'stop_record_session', $path);
                $this->action($document, $condition, 'unset', 'blucom_recording_id');
            }
            if ($modes->contains('never') || $modes->contains('always')) {
                $destination = $this->recordingLookupDestination($number, $request, 'queue-agent', $queue->id);
                if ($destination !== null) {
                    $this->action($document, $condition, 'set', 'execute_on_pre_bridge=lua blucom_queue_recording.lua '.$destination);
                }
            }
        }

        $this->action($document, $condition, 'callcenter', $queue->freeSwitchName());
        if (isset($destination)) {
            $this->action($document, $condition, 'unset', 'execute_on_pre_bridge');
        }
    }

    private function recordingLine(array $request, int $tenantId): ?SipNumber
    {
        $id = $request['variable_blucom_sip_number_id'] ?? null;
        if (! is_scalar($id) || ! ctype_digit((string) $id)) {
            return null;
        }
        $number = SipNumber::query()->where('tenant_id', $tenantId)->where('enabled', true)
            ->where('inbound_enabled', true)->where('status', 'assigned')->find($id);

        return $number && ($number->inventory_state === null
            || (string) ($request['variable_blucom_assignment_id'] ?? '') === (string) $number->current_assignment_id)
            && app(LineEntitlementService::class)->allows($number) ? $number : null;
    }

    private function executeRecordingLookup(DOMDocument $document, DOMElement $condition, ?SipNumber $number, array $request, string $type, int $id): void
    {
        if ($number && ($destination = $this->recordingLookupDestination($number, $request, $type, $id))) {
            $this->action($document, $condition, 'execute_extension', $destination.' XML blucom_recording');
        }
    }

    private function recordingLookupDestination(SipNumber $number, array $request, string $type, int $id): ?string
    {
        $secret = config('app.key');
        $uuid = $this->recordings->callUuid($request);
        if (! config('voip.recordings.enabled') || ! $uuid || ! is_string($secret) || $secret === '') {
            return null;
        }
        $payload = bin2hex(json_encode([$uuid, $number->tenant_id, $number->id, $type, $id], JSON_THROW_ON_ERROR));

        return 'rec-'.$payload.'-'.hash_hmac('sha256', $payload, $secret);
    }

    private function appendRecordingLookup(DOMDocument $document, DOMElement $context, array $request): void
    {
        $dialed = $request['Hunt-Destination-Number'] ?? $request['destination_number'] ?? null;
        $secret = config('app.key');
        if (! config('voip.recordings.enabled') || ! is_string($dialed) || ! is_string($secret) || $secret === ''
            || ! preg_match('/^rec-((?:[a-f0-9]{2}){1,200})-([a-f0-9]{64})(?:-([1-9][0-9]{0,17}))?$/D', $dialed, $matches)
            || ! hash_equals(hash_hmac('sha256', $matches[1], $secret), $matches[2])) {
            return;
        }
        [$uuid, $tenantId, $numberId, $type, $id] = json_decode(hex2bin($matches[1]), true, flags: JSON_THROW_ON_ERROR);
        if ($uuid !== $this->recordings->callUuid($request)
            || ($number = $this->recordingLine($request, $tenantId)) === null || $number->id !== $numberId) {
            return;
        }

        $entry = $context->appendChild($document->createElement('extension'));
        $entry->setAttribute('name', 'recording_transition');
        $condition = $entry->appendChild($document->createElement('condition'));
        $condition->setAttribute('field', 'destination_number');
        $condition->setAttribute('expression', '^'.$dialed.'$');
        $target = match ($type) {
            'queue' => CallQueue::query()->where('tenant_id', $tenantId)->find($id),
            'ivr' => IvrMenu::query()->where('tenant_id', $tenantId)->find($id),
            'extension', 'queue-fallback' => SipExtension::query()->where('tenant_id', $tenantId)->find($id),
            'queue-agent' => CallQueue::query()->where('tenant_id', $tenantId)->find($id)?->members()
                ->where('sip_extensions.tenant_id', $tenantId)->find($matches[3] ?? 0),
            default => null,
        };
        if ($target === null) {
            return;
        }
        $mode = $target->recording_mode;
        $resume = in_array($type, ['queue-agent', 'queue-fallback'], true)
            || ($request['variable_blucom_recording_queue_paused'] ?? null) === 'true';
        if ($resume && $mode === 'inherit') {
            $mode = $request['variable_blucom_recording_preference'] ?? null;
        }
        if ($resume || $mode !== 'inherit') {
            $this->action($document, $condition, 'unset', 'blucom_recording_queue_paused');
            $this->appendRecording($document, $condition, $number, 'inbound', $request, $mode);
        }
    }

    /**
     * Resolve the authenticated SIP extension that originates an outbound call
     * from FreeSWITCH dialplan request variables. Never trusts tenant IDs
     * supplied by the endpoint beyond locating the extension row.
     *
     * @param  array<string, mixed>  $request
     */
    private function resolveCallingExtension(array $request): ?SipExtension
    {
        $candidates = [];

        foreach (['variable_sip_auth_username'] as $key) {
            if (isset($request[$key]) && is_scalar($request[$key])) {
                $candidates[] = (string) $request[$key];
            }
        }

        foreach ($candidates as $candidate) {
            $value = trim($candidate);

            if (str_contains($value, '@')) {
                $value = explode('@', $value, 2)[0];
            }

            if (str_starts_with($value, 'user/')) {
                $value = substr($value, 5);
            }

            if ($value === '' || ! preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $value)) {
                continue;
            }

            $extension = SipExtension::query()
                ->where(fn ($q) => $q->where('sip_username', $value)->orWhere(fn ($q) => $q->whereNull('sip_username')->where('extension', $value)))
                ->where('enabled', true)
                ->with('tenant')
                ->first();

            if ($extension !== null && $extension->tenant?->isActive()) {
                return $extension;
            }
        }

        return null;
    }

    private function escapeAttribute(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
