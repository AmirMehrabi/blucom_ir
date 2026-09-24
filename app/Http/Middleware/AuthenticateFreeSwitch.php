<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateFreeSwitch
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = (string) config('voip.xml_curl.token');

        if ($token === '') {
            return $this->xmlFailure(503);
        }

        $provided = (string) ($request->header('X-FS-Token') ?: $request->header('Authorization'));

        if (str_starts_with($provided, 'Bearer ')) {
            $provided = substr($provided, 7);
        }

        if ($provided === '' || ! hash_equals($token, $provided)) {
            return $this->xmlFailure(401);
        }

        return $next($request);
    }

    private function xmlFailure(int $status): Response
    {
        return response('<?xml version="1.0" encoding="UTF-8"?><document type="freeswitch/xml"/>', $status, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Cache-Control' => 'no-store',
        ]);
    }
}
