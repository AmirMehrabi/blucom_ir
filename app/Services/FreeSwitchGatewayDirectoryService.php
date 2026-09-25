<?php

namespace App\Services;

use App\Models\SipGateway;
use DOMDocument;
use DOMElement;

class FreeSwitchGatewayDirectoryService
{
    public function build(string $domain): string
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $root = $document->appendChild($document->createElement('document'));
        $root->setAttribute('type', 'freeswitch/xml');
        $section = $root->appendChild($document->createElement('section'));
        $section->setAttribute('name', 'directory');
        $domainElement = $section->appendChild($document->createElement('domain'));
        $domainElement->setAttribute('name', $domain);
        $groups = $domainElement->appendChild($document->createElement('groups'));
        $group = $groups->appendChild($document->createElement('group'));
        $group->setAttribute('name', 'default');
        $users = $group->appendChild($document->createElement('users'));
        $user = $users->appendChild($document->createElement('user'));
        $user->setAttribute('id', 'blucom-gateway-definitions');
        $gateways = $user->appendChild($document->createElement('gateways'));

        $records = SipGateway::query()
            ->where('enabled', true)
            ->where('profile', 'external')
            ->where('context', 'public')
            ->orderBy('name')
            ->get();

        foreach ($records as $record) {
            if (! preg_match('/^[A-Za-z0-9_-]+$/D', $record->name)
                || ! preg_match('/^[A-Za-z0-9.-]+$/D', $record->host)
                || $record->port < 1 || $record->port > 65535
                || ! in_array($record->transport, ['udp', 'tcp', 'tls'], true)
                || ($record->register && (! $record->username || ! $record->password_encrypted))) {
                continue;
            }

            $gateway = $gateways->appendChild($document->createElement('gateway'));
            $gateway->setAttribute('name', $record->name);
            $params = $gateway->appendChild($document->createElement('params'));
            $this->param($document, $params, 'proxy', $record->port === 5060 ? $record->host : $record->host.':'.$record->port);
            $this->param($document, $params, 'register', $record->register ? 'true' : 'false');
            $this->param($document, $params, 'register-transport', $record->transport);
            $this->param($document, $params, 'context', 'public');
            $this->param($document, $params, 'ping', '30');

            if ($record->username !== null && $record->username !== '') {
                $this->param($document, $params, 'username', $record->username);
            }
            if ($record->auth_username !== null && $record->auth_username !== '') {
                $this->param($document, $params, 'auth-username', $record->auth_username);
            }
            if ($record->realm !== null && $record->realm !== '') {
                $this->param($document, $params, 'realm', $record->realm);
            }
            if ($record->register) {
                $this->param($document, $params, 'password', $record->password_encrypted);
            }
        }

        return $document->saveXML() ?: '<?xml version="1.0" encoding="UTF-8"?><document type="freeswitch/xml"/>';
    }

    private function param(DOMDocument $document, DOMElement $params, string $name, string $value): void
    {
        $param = $params->appendChild($document->createElement('param'));
        $param->setAttribute('name', $name);
        $param->setAttribute('value', $value);
    }
}
