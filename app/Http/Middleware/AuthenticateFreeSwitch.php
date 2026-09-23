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
            return response()->json(['message' => 'XML-CURL is not configured.'], 503);
        }

        $provided = (string) ($request->header('X-FS-Token') ?: $request->header('Authorization'));

        if (str_starts_with($provided, 'Bearer ')) {
            $provided = substr($provided, 7);
        }

        if ($provided === '' || ! hash_equals($token, $provided)) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        return $next($request);
    }
}
