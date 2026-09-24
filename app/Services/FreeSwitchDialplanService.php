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
        $this->appendLocalExtensions($document, $context);
        $this->appendOutbound($document, $context, $request);
    }

    private function appendLocalExtensions(DOMDocument $document, DOMElement $context): void
    {
        $extensions = SipExtension::query()
            ->where('enabled', true)
            ->whereHas('tenant', fn ($query) => $query->where('status', 'active')->where('system_key', 'blucom'))
            ->orderBy('extension')
            ->get(['id', 'extension']);

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
            ->where('destination_type', 'extension')
            ->with([
                'sipNumber',
                'destination',
                'sipNumber:id,status,enabled,inbound_enabled,normalized_number,tenant_id',
                'destination:id,extension,enabled,tenant_id',
            ])
            ->whereHas('sipNumber', fn ($query) => $query
                ->where('status', 'assigned')
                ->where('enabled', true)
                ->whereNotNull('tenant_id')
                ->where('inbound_enabled', true))
            ->whereHas('sipNumber.tenant', fn ($query) => $query->where('status', 'active')->where('system_key', 'blucom'))
            ->whereHas('destination', fn ($query) => $query->where('enabled', true))
            ->orderBy('id')
            ->get();

        /** @var InboundRoute $route */
        foreach ($routes as $route) {
            $sipNumber = $route->sipNumber;
            $destination = $route->destination;

            if ($sipNumber === null || $destination === null) {
                continue;
            }

            if ($sipNumber->tenant_id !== $destination->tenant_id || $sipNumber->tenant_id !== $route->tenant_id) {
                continue;
            }

            $extension = $document->createElement('extension');
            $extension->setAttribute('name', 'inbound_'.$sipNumber->id);

            $condition = $document->createElement('condition');
            $condition->setAttribute('field', 'destination_number');
            $condition->setAttribute('expression', $this->numbers->destinationExpression($sipNumber->normalized_number));

            $action = $document->createElement('action');
            $action->setAttribute('application', 'transfer');
            $action->setAttribute('data', $destination->extension.' XML default');
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
                'sipNumber:id,status,enabled,outbound_enabled,normalized_number,tenant_id',
            ])
            ->whereHas('gateway', fn ($query) => $query->where('enabled', true)->whereIn('name', config('voip.allowed_outbound_gateways', [])))
            ->whereHas('tenant', fn ($query) => $query->where('status', 'active')->where('system_key', 'blucom'))
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

            if ($extension !== null && $extension->tenant?->isActive() && $extension->tenant->system_key === 'blucom') {
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
