<?php

namespace App\Services\AudioCalls;

use Illuminate\Http\UploadedFile;
use RuntimeException;

class FetchAudio
{
    public function download(string $url, string $path): UploadedFile
    {
        $parts = parse_url($url);
        $host = strtolower($parts['host'] ?? '');
        if (($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass'])
            || ($parts['port'] ?? 443) !== 443 || ! filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)
            || ! str_contains($host, '.') || preg_match('/(^|\.)[0-9]+$/D', $host)) {
            throw new RuntimeException('Invalid audio host.');
        }
        $addresses = gethostbynamel($host) ?: [];
        $ip = $addresses[0] ?? '';
        foreach ($addresses as $address) {
            if (! filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_GLOBAL_RANGE)) {
                throw new RuntimeException('Audio host must resolve to public addresses.');
            }
        }
        if ($ip === '') {
            throw new RuntimeException('Audio host is unavailable.');
        }

        $file = fopen($path, 'wb');
        $size = 0;
        $curl = curl_init($url);
        try {
            curl_setopt_array($curl, [
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROXY => '',
                CURLOPT_RESOLVE => [$host.':443:'.$ip],
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_TIMEOUT => 8,
                CURLOPT_WRITEFUNCTION => function ($curl, string $chunk) use ($file, &$size) {
                    $size += strlen($chunk);

                    return $size <= 8 * 1024 * 1024 ? fwrite($file, $chunk) : 0;
                },
            ]);
            if (! curl_exec($curl) || curl_getinfo($curl, CURLINFO_RESPONSE_CODE) !== 200 || $size === 0) {
                throw new RuntimeException('Audio download failed.');
            }
        } finally {
            curl_close($curl);
            fclose($file);
        }

        return new UploadedFile($path, 'audio', test: true);
    }
}
