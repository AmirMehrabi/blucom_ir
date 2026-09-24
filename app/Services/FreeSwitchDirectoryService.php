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
     * A miss yields FreeSWITCH's explicit not-found document.
     */
    public function build(?Tenant $tenant, ?string $user = null, ?string $domain = null): string
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = true;

        $root = $document->appendChild($document->createElement('document'));
        $root->setAttribute('type', 'freeswitch/xml');

        $section = $root->appendChild($document->createElement('section'));
        $section->setAttribute('name', 'directory');

        if ($tenant === null || $tenant->status !== 'active') {
            return $this->notFound();
        }

        $query = $tenant->sipExtensions()->where('enabled', true)->orderBy('extension');

        if ($user !== null && $user !== '') {
            $query->where('extension', $user);
        }

        $extensions = $query->get();

        if ($extensions->isEmpty()) {
            return $this->notFound();
        }

        $domainElement = $section->appendChild($document->createElement('domain'));
        $domainElement->setAttribute('name', $domain ?: $this->domainName());
        $domainParams = $domainElement->appendChild($document->createElement('params'));
        $dialString = $domainParams->appendChild($document->createElement('param'));
        $dialString->setAttribute('name', 'dial-string');
        $dialString->setAttribute('value', (string) config('voip.directory_dial_string'));
        $users = $domainElement->appendChild($document->createElement('users'));

        /** @var SipExtension $extension */
        foreach ($extensions as $extension) {
            $this->appendUser($document, $users, $extension, $tenant);
        }

        return $document->saveXML() ?: '<?xml version="1.0" encoding="UTF-8"?>'."\n";
    }

    private function appendUser(DOMDocument $document, DOMElement $users, SipExtension $extension, Tenant $tenant): void
    {
        $user = $users->appendChild($document->createElement('user'));
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
        return OutboundRoute::query()
            ->where('sip_extension_id', $extension->id)
            ->where('tenant_id', $extension->tenant_id)
            ->with(['sipNumber', 'gateway'])
            ->where('enabled', true)
            ->whereHas('gateway', fn ($query) => $query->where('enabled', true)->whereIn('name', config('voip.allowed_outbound_gateways', [])))
            ->whereHas('sipNumber', fn ($query) => $query
                ->where('tenant_id', $extension->tenant_id)
                ->where('status', 'assigned')
                ->where('enabled', true)
                ->where('outbound_enabled', true))
            ->first();
    }

    private function domainName(): string
    {
        return (string) config('voip.directory_domain', parse_url(config('app.url'), PHP_URL_HOST) ?: 'localhost');
    }

    public function notFound(): string
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $root = $document->appendChild($document->createElement('document'));
        $root->setAttribute('type', 'freeswitch/xml');
        $section = $root->appendChild($document->createElement('section'));
        $section->setAttribute('name', 'result');
        $result = $section->appendChild($document->createElement('result'));
        $result->setAttribute('status', 'not found');

        return $document->saveXML() ?: '<?xml version="1.0" encoding="UTF-8"?><document type="freeswitch/xml"><section name="result"><result status="not found"/></section></document>';
    }
}
