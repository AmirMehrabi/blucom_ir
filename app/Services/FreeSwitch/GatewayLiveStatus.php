<?php

namespace App\Services\FreeSwitch;

use DOMDocument;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class GatewayLiveStatus
{
    /** Retain only operational fields; never cache or return raw Sofia output. */
    public function parse(string $xml): array
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument;
            if (stripos($xml, '<!DOCTYPE') !== false || ! $document->loadXML($xml, LIBXML_NONET)
                || $document->documentElement?->nodeName !== 'gateways') {
                throw new RuntimeException('Invalid gateway snapshot.');
            }
            $result = [];
            foreach ($document->documentElement->childNodes as $gateway) {
                if ($gateway->nodeName !== 'gateway') {
                    continue;
                }
                $fields = [];
                foreach ($gateway->childNodes as $field) {
                    $fields[$field->nodeName] = trim($field->textContent);
                }
                if (empty($fields['name']) || empty($fields['profile'])) {
                    throw new RuntimeException('Invalid gateway identity.');
                }
                $result[$fields['profile']][$fields['name']] = [
                    'state' => in_array($fields['state'] ?? '', ['REGED', 'NOREG', 'TRYING', 'REGISTER', 'UNREGED', 'UNREGISTER', 'FAILED', 'FAIL_WAIT', 'EXPIRED', 'DOWN', 'TIMEOUT'], true) ? $fields['state'] : 'UNKNOWN',
                    'status' => in_array($fields['status'] ?? '', ['UP', 'DOWN'], true) ? $fields['status'] : 'UNKNOWN',
                    'ping_enabled' => (int) ($fields['pingfreq'] ?? 0) > 0,
                ];
            }

            return $result;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    public function snapshot(): array
    {
        $unknown = ['fresh' => false, 'updated_at' => null, 'gateways' => []];
        if (! config('voip.live.enabled')) {
            return $unknown;
        }
        try {
            $snapshot = Cache::store(config('voip.live.cache_store'))->get('voip:live:gateways', []);
            $updated = $snapshot['updated_at'] ?? null;
            if (! ($snapshot['connected'] ?? false) || ! is_int($updated)
                || $updated > time() || time() - $updated > config('voip.live.stale_after', 20)) {
                return array_replace($unknown, ['updated_at' => $updated]);
            }

            return ['fresh' => true, 'updated_at' => $updated, 'gateways' => $snapshot['gateways'] ?? []];
        } catch (\Throwable) {
            return $unknown;
        }
    }
}
