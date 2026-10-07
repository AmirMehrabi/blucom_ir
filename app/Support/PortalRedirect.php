<?php

namespace App\Support;

use Illuminate\Http\Request;

class PortalRedirect
{
    public static function intended(Request $request, string $fallback): string
    {
        $url = $request->session()->pull('url.intended');
        if (! is_string($url) || str_contains($url, '\\') || preg_match('/[\x00-\x20\x7f]/', $url)) {
            return $fallback;
        }

        if (str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
            return $url;
        }

        $parts = parse_url($url);
        if ($parts !== false && ($parts['host'] ?? null) === $request->getHost()
            && in_array($parts['scheme'] ?? null, ['http', 'https'], true)
            && ! isset($parts['user']) && ! isset($parts['pass'])
            && ($parts['port'] ?? $request->getPort()) === $request->getPort()) {
            return $url;
        }

        return $fallback;
    }
}
