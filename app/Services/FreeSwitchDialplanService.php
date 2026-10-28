<?php

namespace App\Services;

use App\Models\CallQueue;
use App\Models\InboundRoute;
use App\Models\IvrMenu;
use App\Models\OutboundRoute;
use App\Models\SipExtension;
use App\Models\SipNumber;
use App\Services\Commerce\LineEntitlementService;
use DOMDocument;
use DOMElement;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class FreeSwitchDialplanService
{
    public function __construct(
        private readonly NumberNormalizer $numbers,
        private readonly IvrMenuService $menus,
        private readonly InboundScheduleService $schedules,
        private readonly RecordingPolicyService $recordings,
        private readonly RecordingStorageService $recordingStorage,
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
            'blucom_ivr' => $this->appendIvrChoice($document, $contextElement, $request),
            default => null,
        };

        return $document->saveXML() ?: '<?xml version="1.0" encoding="UTF-8"?>'."\n";
    }

    /** @param array<string, mixed> $request */
    private function appendDefault(DOMDocument $document, DOMElement $context, array $request): void
    {
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

        $extensions = $query->orderBy('extension')->get(['id', 'extension', 'sip_username']);

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

            $bridge = $document->createElement('action');
            $bridge->setAttribute('application', 'bridge');
            $bridge->setAttribute('data', 'user/'.$sipExtension->sipIdentity().'@'.config('voip.directory_domain'));
            $condition->appendChild($bridge);

            $extension->appendChild($condition);
            $context->appendChild($extension);
        }
    }

    private function appendInbound(DOMDocument $document, DOMElement $context, array $request): void
    {
        $routes = InboundRoute::query()
            ->where('enabled', true)
            ->whereIn('destination_type', [InboundRoute::DESTINATION_EXTENSION, InboundRoute::DESTINATION_QUEUE, InboundRoute::DESTINATION_IVR])
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
            $terminal = $closed && in_array($destinationType, ['announcement', 'disconnect'], true);

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
                $this->appendRecording($document, $condition, $sipNumber, 'inbound', $request);
            }

            if ($destinationType === 'announcement') {
                $this->action($document, $condition, 'answer');
                $this->action($document, $condition, 'playback', Storage::disk('ivr')->path($route->closed_announcement_path));
                $this->action($document, $condition, 'hangup');
            } elseif ($destinationType === 'disconnect') {
                $this->action($document, $condition, 'hangup');
            } elseif ($destination instanceof IvrMenu) {
                $this->appendMenu($document, $condition, $destination, $sipNumber->id);
            } elseif ($destination instanceof CallQueue) {
                $endAfterBridge = $document->createElement('action');
                $endAfterBridge->setAttribute('application', 'set');
                $endAfterBridge->setAttribute('data', 'hangup_after_bridge=true');
                $condition->appendChild($endAfterBridge);
                $answer = $document->createElement('action');
                $answer->setAttribute('application', 'answer');
                $condition->appendChild($answer);
                $callcenter = $document->createElement('action');
                $callcenter->setAttribute('application', 'callcenter');
                $callcenter->setAttribute('data', $destination->freeSwitchName());
                $condition->appendChild($callcenter);
                if ($destination->fallbackExtension?->enabled && $destination->fallbackExtension->tenant_id === $destination->tenant_id) {
                    $fallbackMarker = $document->createElement('action');
                    $fallbackMarker->setAttribute('application', 'set');
                    $fallbackMarker->setAttribute('data', 'blucom_queue_fallback_attempted=true');
                    $condition->appendChild($fallbackMarker);
                    $fallback = $document->createElement('action');
                    $fallback->setAttribute('application', 'bridge');
                    $fallback->setAttribute('data', 'user/'.$destination->fallbackExtension->sipIdentity().'@'.config('voip.directory_domain'));
                    $condition->appendChild($fallback);
                }
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
                $condition->appendChild($action);
                if ($useUnanswered && ! $legacyTransfer) {
                    $this->appendUnanswered($document, $condition, $route);
                }
            }

            $extension->appendChild($condition);
            $context->appendChild($extension);
        }
    }

    /** One bounded fallback; never follows another line's fallback configuration. */
    private function appendUnanswered(DOMDocument $document, DOMElement $condition, InboundRoute $route): void
    {
        if ($route->unanswered_type === 'forward') {
            try {
                $outbound = app(LineUnansweredService::class)->forwardingRoute($route, (string) $route->unanswered_phone);
                $callerId = ltrim($outbound->sipNumber->normalized_number, '+');
                foreach (['effective_caller_id_number', 'effective_caller_id_name', 'origination_caller_id_number', 'originate_caller_id_number'] as $key) {
                    $this->action($document, $condition, 'set', $key.'='.$callerId);
                }
                $this->action($document, $condition, 'bridge', 'sofia/gateway/'.$outbound->gateway->name.'/'.ltrim($route->unanswered_phone, '+'));
            } catch (ValidationException) {
                // A revoked or malformed route must fail closed.
            }
            $this->action($document, $condition, 'hangup');

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
        if ($target instanceof SipExtension && ! ($route->destination_type === 'extension' && $target->id === $route->destination_id)) {
            $this->action($document, $condition, 'set', 'blucom_extension_id='.$target->id);
            $this->action($document, $condition, 'bridge', 'user/'.$target->sipIdentity().'@'.config('voip.directory_domain'));
        } elseif ($target instanceof CallQueue && $target->members()->where('enabled', true)->where('sip_extensions.tenant_id', $route->tenant_id)->exists()
            && ! $target->members()->where('sip_extensions.tenant_id', '!=', $route->tenant_id)->exists()) {
            $this->action($document, $condition, 'set', 'blucom_queue_id='.$target->id);
            $this->action($document, $condition, 'answer');
            $this->action($document, $condition, 'callcenter', $target->freeSwitchName());
            if ($target->fallbackExtension?->enabled && $target->fallbackExtension->tenant_id === $route->tenant_id) {
                $this->action($document, $condition, 'set', 'blucom_queue_fallback_attempted=true');
                $this->action($document, $condition, 'bridge', 'user/'.$target->fallbackExtension->sipIdentity().'@'.config('voip.directory_domain'));
            }
        } elseif ($target instanceof IvrMenu && Storage::disk('ivr')->exists((string) ($target->published_config['greeting'] ?? ''))) {
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
        $digit = (string) ($request['variable_blucom_ivr_digit'] ?? '');
        $choice = preg_match('/^[0-9]$/D', $digit) ? ($config['choices'][$digit]['destination'] ?? null) : null;
        $directExtension = preg_match('/^[1-9][0-9]{2,8}$/D', $digit)
            ? $this->menus->directDialExtensions($menu, $config)->first(fn (SipExtension $extension) => $extension->extension === $digit)
            : null;
        $destination = $this->menus->destination($menu, (string) ($choice ?? ''))
            ?? $directExtension
            ?? $this->menus->destination($menu, (string) ($config['fallback'] ?? ''));
        if ($destination === null) {
            return;
        }

        $extension = $context->appendChild($document->createElement('extension'));
        $extension->setAttribute('name', 'ivr_'.$menu->id);
        $condition = $extension->appendChild($document->createElement('condition'));
        $condition->setAttribute('field', 'destination_number');
        $condition->setAttribute('expression', '^blucom-menu$');
        $this->action($document, $condition, 'set', 'hangup_after_bridge=true');
        if ($destination instanceof IvrMenu) {
            $this->appendMenu($document, $condition, $destination, $number->id, $depth + 1);
        } elseif ($destination instanceof CallQueue) {
            $this->action($document, $condition, 'set', 'blucom_queue_id='.$destination->id);
            $this->action($document, $condition, 'callcenter', $destination->freeSwitchName());
            if ($destination->fallbackExtension?->enabled
                && $destination->fallbackExtension->tenant_id === $menu->tenant_id) {
                $this->action($document, $condition, 'set', 'blucom_queue_fallback_attempted=true');
                $this->action($document, $condition, 'bridge',
                    'user/'.$destination->fallbackExtension->sipIdentity().'@'.config('voip.directory_domain'));
            }
        } else {
            $this->action($document, $condition, 'set', 'blucom_extension_id='.$destination->id);
            $this->action($document, $condition, 'bridge',
                'user/'.$destination->sipIdentity().'@'.config('voip.directory_domain'));
        }
    }

    private function appendMenu(DOMDocument $document, DOMElement $condition, IvrMenu $menu, int $numberId, int $depth = 0): void
    {
        $config = $menu->published_config;
        if (! is_array($config) || ! Storage::disk('ivr')->exists((string) ($config['greeting'] ?? ''))) {
            return;
        }
        $direct = $this->menus->directDialExtensions($menu, $config);
        $digits = implode('', array_keys($config['choices']));
        $pattern = $direct->isEmpty() ? '^['.$digits.']$' : '^(?:['.$digits.']|'.implode('|', $direct->pluck('extension')->all()).')$';
        foreach (['blucom_ivr_menu_id' => $menu->id, 'blucom_ivr_number_id' => $numberId, 'blucom_ivr_version' => $menu->version, 'blucom_ivr_depth' => $depth] as $key => $value) {
            $this->action($document, $condition, 'set', $key.'='.$value);
        }
        $this->action($document, $condition, 'answer');
        $this->action($document, $condition, 'set', 'blucom_ivr_digit=');
        $this->action($document, $condition, 'play_and_get_digits', '1 '.max([1, ...$direct->map(fn ($e) => strlen($e->extension))->all()]).' 2 8000 # '.Storage::disk('ivr')->path($config['greeting']).' '.Storage::disk('ivr')->path($config['greeting']).' blucom_ivr_digit '.$pattern.' 3000');
        $this->action($document, $condition, 'transfer', 'blucom-menu XML blucom_ivr');
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
            $this->appendRecording($document, $condition, $sipNumber, 'outbound', $request);
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

    /**
     * Resolve the authenticated SIP extension that originates an outbound call
     * from FreeSWITCH dialplan request variables. Never trusts tenant IDs
     * supplied by the endpoint beyond locating the extension row.
     *
     * @param  array<string, mixed>  $request
     */
    private function appendRecording(DOMDocument $document, DOMElement $condition, SipNumber $number, string $direction, array $request): void
    {
        $recording = $this->recordings->reserve($number, $direction, $request);
        if ($recording === null || $recording->status !== 'recording') {
            return;
        }
        if (! empty($recording->policy['announcement_path'])) {
            $this->action($document, $condition, 'answer');
            $this->action($document, $condition, 'playback', Storage::disk('ivr')->path($recording->policy['announcement_path']));
        }
        foreach ([
            'blucom_recording_id' => $recording->id,
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
