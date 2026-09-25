<?php

namespace App\Console\Commands;

use App\Models\SipGateway;
use DOMDocument;
use DOMXPath;
use Illuminate\Console\Command;

class ImportGatewayXml extends Command
{
    protected $signature = 'voip:import-gateway-xml {path : Existing local gateway XML file}';

    protected $description = 'Import an existing external FreeSWITCH gateway into encrypted database storage';

    public function handle(): int
    {
        $path = (string) $this->argument('path');
        if (! is_file($path) || ! is_readable($path)) {
            $this->error('Gateway XML is not readable.');

            return self::FAILURE;
        }

        $document = new DOMDocument;
        if (! @$document->load($path, LIBXML_NONET)) {
            $this->error('Gateway XML is invalid.');

            return self::FAILURE;
        }

        $nodes = (new DOMXPath($document))->query('//gateway');
        if ($nodes === false || $nodes->length !== 1) {
            $this->error('Expected exactly one gateway in this file.');

            return self::FAILURE;
        }

        $gateway = $nodes->item(0);
        $name = $gateway->attributes?->getNamedItem('name')?->nodeValue;
        $params = [];
        foreach ($gateway->getElementsByTagName('param') as $param) {
            $key = $param->getAttribute('name');
            if ($key !== '') {
                $params[$key] = $param->getAttribute('value');
            }
        }

        $proxy = preg_replace('/^sip:/', '', (string) ($params['proxy'] ?? ''));
        if (! preg_match('/^([A-Za-z0-9.\-]+)(?::([0-9]{1,5}))?$/D', $proxy, $parts)
            || ! preg_match('/^[A-Za-z0-9_-]+$/D', (string) $name)) {
            $this->error('Gateway name or proxy is unsupported.');

            return self::FAILURE;
        }

        $register = ($params['register'] ?? 'true') === 'true';
        if (($params['context'] ?? 'public') !== 'public'
            || ($register && (empty($params['username']) || empty($params['password'])))) {
            $this->error('Gateway context or registration credentials are invalid.');

            return self::FAILURE;
        }

        $transport = $params['register-transport'] ?? 'udp';
        $port = isset($parts[2]) ? (int) $parts[2] : 5060;
        if (! in_array($transport, ['udp', 'tcp', 'tls'], true) || $port < 1 || $port > 65535) {
            $this->error('Gateway transport or port is invalid.');

            return self::FAILURE;
        }

        $record = SipGateway::query()->firstOrNew(['name' => $name]);
        $record->fill([
            'host' => $parts[1],
            'port' => $port,
            'transport' => $transport,
            'username' => $params['username'] ?? null,
            'auth_username' => $params['auth-username'] ?? null,
            'realm' => $params['realm'] ?? null,
            'profile' => 'external',
            'context' => 'public',
            'register' => $register,
            'enabled' => true,
        ]);
        if (isset($params['password']) && $params['password'] !== '') {
            $record->password_encrypted = $params['password'];
        }
        $record->save();

        $this->info('Gateway imported. No credentials were printed or written to source files.');

        return self::SUCCESS;
    }
}
