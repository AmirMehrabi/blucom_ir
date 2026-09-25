<?php

namespace App\Services;

use App\Models\InboundRoute;
use App\Models\OutboundRoute;
use App\Models\SipExtension;
use DOMDocument;
use DOMElement;

class FreeSwitchDialplanService
{
    public function __construct(private readonly NumberNormalizer $numbers) {}

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
            ->where('destination_type', InboundRoute::DESTINATION_EXTENSION)
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

            if ($sipNumber === null || ! $destination instanceof SipExtension || ! $destination->enabled) {
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

            $action = $document->createElement('action');
            $action->setAttribute('application', $legacy ? 'transfer' : 'bridge');
            $action->setAttribute('data', $legacy
                ? $destination->extension.' XML default'
                : 'user/'.$destination->extension.'@'.config('voip.directory_domain'));
            $condition->appendChild($action);

            $extension->appendChild($condition);
            $context->appendChild($extension);
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

        $bridge = $document->createElement('action');
        $bridge->setAttribute('application', 'set');
        $bridge->setAttribute('data', 'effective_caller_id_number='.$callerId);
        $condition->appendChild($bridge);

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
