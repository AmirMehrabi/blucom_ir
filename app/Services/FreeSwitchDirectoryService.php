<?php

namespace App\Services;

use App\Models\OutboundRoute;
use App\Models\SipExtension;
use App\Models\Tenant;
use DOMDocument;
use DOMElement;

class FreeSwitchDirectoryService
{
    /**
     * Build FreeSWITCH directory XML for a tenant extension lookup.
     *
     * When $user is null, all enabled extensions for the tenant are returned.
     * When $user is set, only a matching enabled extension is returned.
     * A miss yields an empty document so FreeSWITCH fails authentication safely.
     */
    public function build(?Tenant $tenant, ?string $user = null, ?string $domain = null): string
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = true;

        $root = $document->appendChild($document->createElement('document'));
        $root->setAttribute('type', 'freeswitch/xml');

        $section = $root->appendChild($document->createElement('section'));
        $section->setAttribute('name', 'directory');

        $domainElement = $section->appendChild($document->createElement('domain'));
        $domainElement->setAttribute('name', $domain ?: $this->domainName());

        if ($tenant === null || $tenant->status !== 'active') {
            return $document->saveXML() ?: '<?xml version="1.0" encoding="UTF-8"?>'."\n";
        }

        $query = $tenant->sipExtensions()->where('enabled', true)->orderBy('extension');

        if ($user !== null && $user !== '') {
            $query->where('extension', $user);
        }

        /** @var SipExtension $extension */
        foreach ($query->get() as $extension) {
            $this->appendUser($document, $domainElement, $extension, $tenant);
        }

        return $document->saveXML() ?: '<?xml version="1.0" encoding="UTF-8"?>'."\n";
    }

    private function appendUser(DOMDocument $document, DOMElement $domain, SipExtension $extension, Tenant $tenant): void
    {
        $user = $domain->appendChild($document->createElement('user'));
        $user->setAttribute('id', $extension->extension);

        $params = $user->appendChild($document->createElement('params'));
        $password = $params->appendChild($document->createElement('param'));
        $password->setAttribute('name', 'password');
        $password->setAttribute('value', (string) $extension->password_encrypted);

        $variables = $user->appendChild($document->createElement('variables'));

        $this->addVariable($document, $variables, 'user_context', 'default');
        $this->addVariable($document, $variables, 'tenant_id', (string) $tenant->id);
        $this->addVariable($document, $variables, 'extension_id', (string) $extension->id);

        $route = $this->approvedOutboundRoute($extension);

        if ($route !== null) {
            $callerId = ltrim((string) $route->sipNumber->normalized_number, '+');
            $this->addVariable($document, $variables, 'effective_caller_id_number', $callerId);
            $this->addVariable($document, $variables, 'effective_caller_id_name', $callerId);
            $this->addVariable($document, $variables, 'outbound_caller_id_number', $callerId);
            $this->addVariable($document, $variables, 'outbound_gateway', $route->gateway->name);
        }
    }

    private function addVariable(DOMDocument $document, DOMElement $variables, string $name, string $value): void
    {
        $variable = $document->createElement('variable');
        $variable->setAttribute('name', $name);
        $variable->setAttribute('value', $value);
        $variables->appendChild($variable);
    }

    private function approvedOutboundRoute(SipExtension $extension): ?OutboundRoute
    {
        return $extension->tenant
            ?->outboundRoutes()
            ->with(['sipNumber', 'gateway'])
            ->where('enabled', true)
            ->whereHas('gateway', fn ($query) => $query->where('enabled', true))
            ->whereHas('sipNumber', fn ($query) => $query
                ->where('status', 'assigned')
                ->where('outbound_enabled', true))
            ->whereBelongsTo($extension->tenant)
            ->orderBy('id')
            ->first();
    }

    private function domainName(): string
    {
        return (string) config('voip.directory_domain', parse_url(config('app.url'), PHP_URL_HOST) ?: 'localhost');
    }
}
