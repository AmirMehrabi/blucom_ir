<?php

namespace App\Services;

use App\Models\CallQueue;
use App\Models\InboundRoute;
use App\Models\IvrMenu;
use App\Models\OutboundRoute;
use App\Models\SipExtension;
use DOMDocument;
use DOMElement;
use Illuminate\Support\Facades\Storage;

class FreeSwitchDialplanService
{
    public function __construct(
        private readonly NumberNormalizer $numbers,
        private readonly IvrMenuService $menus,
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
            'public' => $this->appendInbound($document, $contextElement),
            'default' => $this->appendDefault($document, $contextElement, $request),
            'blucom_ivr' => $this->appendIvrChoice($document, $contextElement, $request),
            default => null,
        };

        return $document->saveXML() ?: '<?xml version="1.0" encoding="UTF-8"?>'."\n";
    }

    /** @param array<string, mixed> $request */
    private function appendDefault(DOMDocument $document, DOMElement $context, array $request): void
    {
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
                    ->where('status', 'assigned')
                    ->where('enabled', true)
                    ->where('inbound_enabled', true)
                    ->where(fn ($query) => $query
                        ->whereHas('providerGateway', fn ($gateway) => $gateway->whereNull('tenant_id'))
                        ->orWhere(fn ($missing) => $missing
                            ->whereNull('provider_gateway_id')
                            ->whereHas('tenant', fn ($tenant) => $tenant->where('system_key', 'blucom')))))
                ->pluck('destination_id');
            $query->whereIn('id', $legacyDestinations);
        }

        $extensions = $query->orderBy('extension')->get(['id', 'extension']);

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
            $bridge->setAttribute('data', 'user/'.$sipExtension->extension.'@'.config('voip.directory_domain'));
            $condition->appendChild($bridge);

            $extension->appendChild($condition);
            $context->appendChild($extension);
        }
    }

    private function appendInbound(DOMDocument $document, DOMElement $context): void
    {
        $routes = InboundRoute::query()
            ->where('enabled', true)
            ->whereIn('destination_type', [InboundRoute::DESTINATION_EXTENSION, InboundRoute::DESTINATION_QUEUE, InboundRoute::DESTINATION_IVR])
            ->with([
                'sipNumber:id,status,enabled,inbound_enabled,normalized_number,tenant_id,provider_gateway_id',
                'sipNumber.tenant:id,system_key',
                'sipNumber.providerGateway:id,tenant_id,enabled,verification_status',
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
            $destination = $route->destination;

            if ($sipNumber === null || ! $destination instanceof SipExtension && ! $destination instanceof CallQueue
                && ! $destination instanceof IvrMenu || ! $destination->enabled) {
                continue;
            }

            if ($destination instanceof CallQueue && (! config('voip.queues_enabled') || ! $destination->members()->where('enabled', true)->exists())) {
                continue;
            }
            if ($destination instanceof IvrMenu && (! $destination->isPublished()
                || ! Storage::disk('ivr')->exists((string) ($destination->published_config['greeting'] ?? '')))) {
                continue;
            }

            if ($sipNumber->tenant_id !== $destination->tenant_id || $sipNumber->tenant_id !== $route->tenant_id) {
                continue;
            }

            $legacy = $sipNumber->providerGateway?->tenant_id === null
                && ($sipNumber->providerGateway !== null || $sipNumber->tenant?->system_key === 'blucom');
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

            $markers = ['accountcode' => 'btenant_'.$sipNumber->tenant_id, 'blucom_call_direction' => 'inbound'];
            if ($destination instanceof SipExtension) {
                $markers['blucom_extension_id'] = $destination->id;
            } elseif ($destination instanceof CallQueue) {
                $markers['blucom_queue_id'] = $destination->id;
            } else {
                $markers['blucom_ivr_menu_id'] = $destination->id;
                $markers['blucom_ivr_number_id'] = $sipNumber->id;
                $markers['blucom_ivr_version'] = $destination->version;
            }
            foreach ($markers as $key => $value) {
                $set = $document->createElement('action');
                $set->setAttribute('application', 'set');
                $set->setAttribute('data', $key.'='.$value);
                $condition->appendChild($set);
            }

            if ($destination instanceof IvrMenu) {
                $config = $destination->published_config;
                $digits = implode('', array_keys($config['choices']));
                $greeting = Storage::disk('ivr')->path($config['greeting']);
                $this->action($document, $condition, 'answer');
                $this->action($document, $condition, 'set', 'blucom_ivr_digit=');
                $this->action($document, $condition, 'play_and_get_digits',
                    '1 1 2 8000 # '.$greeting.' '.$greeting.' blucom_ivr_digit ^['.$digits.']$ 3000');
                $this->action($document, $condition, 'transfer', 'blucom-menu XML blucom_ivr');
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
                    $fallback->setAttribute('data', 'user/'.$destination->fallbackExtension->extension.'@'.config('voip.directory_domain'));
                    $condition->appendChild($fallback);
                }
            } else {
                $action = $document->createElement('action');
                $action->setAttribute('application', $legacy ? 'transfer' : 'bridge');
                $action->setAttribute('data', $legacy
                    ? $destination->extension.' XML default'
                    : 'user/'.$destination->extension.'@'.config('voip.directory_domain'));
                $condition->appendChild($action);
            }

            $extension->appendChild($condition);
            $context->appendChild($extension);
        }
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
            ->where('destination_type', InboundRoute::DESTINATION_IVR)
            ->where('destination_id', (int) $menuId)
            ->with(['destination', 'sipNumber.tenant', 'sipNumber.providerGateway'])->first();
        $menu = $route?->destination;
        $number = $route?->sipNumber;
        if (! $menu instanceof IvrMenu || ! $menu->isPublished() || $number === null
            || $menu->tenant_id !== $route->tenant_id || $number->tenant_id !== $route->tenant_id
            || ! $number->enabled || ! $number->inbound_enabled || $number->status !== 'assigned'
            || ! $number->tenant?->isActive()) {
            return;
        }
        $legacy = $number->providerGateway?->tenant_id === null
            && ($number->providerGateway !== null || $number->tenant?->system_key === 'blucom');
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
        $destination = $this->menus->destination($menu, (string) ($choice ?? ''))
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
        if ($destination instanceof CallQueue) {
            $this->action($document, $condition, 'set', 'blucom_queue_id='.$destination->id);
            $this->action($document, $condition, 'callcenter', $destination->freeSwitchName());
            if ($destination->fallbackExtension?->enabled
                && $destination->fallbackExtension->tenant_id === $menu->tenant_id) {
                $this->action($document, $condition, 'set', 'blucom_queue_fallback_attempted=true');
                $this->action($document, $condition, 'bridge',
                    'user/'.$destination->fallbackExtension->extension.'@'.config('voip.directory_domain'));
            }
        } else {
            $this->action($document, $condition, 'set', 'blucom_extension_id='.$destination->id);
            $this->action($document, $condition, 'bridge',
                'user/'.$destination->extension.'@'.config('voip.directory_domain'));
        }
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
                'sipNumber:id,status,enabled,outbound_enabled,normalized_number,tenant_id,provider_gateway_id',
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
        $legacy = $route->gateway->tenant_id === null;
        if (! $legacy && ! config('voip.gateway_xml_enabled')) {
            return;
        }
        if ($legacy ? ($route->gateway->tenant_id !== null && $route->gateway->tenant_id !== $extension->tenant_id)
            : ($route->gateway->tenant_id !== $extension->tenant_id || $route->sipNumber->provider_gateway_id !== $route->gateway_id)) {
            return;
        }

        $callerId = ltrim($route->sipNumber->normalized_number, '+');
        $gatewayName = $route->gateway->name;

        $extensionElement = $document->createElement('extension');
        $extensionElement->setAttribute('name', 'outbound_'.$extension->id);

        $condition = $document->createElement('condition');
        $condition->setAttribute('field', 'destination_number');
        $condition->setAttribute('expression', '^(?:00|\+|0)?\d{7,15}$');

        foreach (['accountcode' => 'btenant_'.$extension->tenant_id, 'blucom_call_direction' => 'outbound', 'blucom_extension_id' => $extension->id] as $key => $value) {
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

        $bridgeToGateway = $document->createElement('action');
        $bridgeToGateway->setAttribute('application', 'bridge');
        $bridgeToGateway->setAttribute(
            'data',
            'sofia/gateway/'.$gatewayName.'/'.$this->escapeAttribute('${destination_number}'),
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

            if ($value === '' || ! preg_match('/^\d+$/', $value)) {
                continue;
            }

            $extension = SipExtension::query()
                ->where('extension', $value)
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
