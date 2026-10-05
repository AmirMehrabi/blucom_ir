<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class SelectPortal
{
    public function handle(Request $request, Closure $next): Response
    {
        $customerPortal = $request->getHost() === config('portal.customer_domain');
        $request->attributes->set('customer_portal', $customerPortal);
        abort_if($customerPortal && $request->is('internal/*'), 404);
        $previous = [
            'session.cookie' => config('session.cookie'),
            'session.domain' => config('session.domain'),
        ];
        $guard = $customerPortal ? 'customer' : 'web';
        config(['session.domain' => null]);
        if ($customerPortal) {
            config(['session.cookie' => config('portal.customer_session_cookie')]);
        }
        // Select the session name before StartSession reads the request cookie.
        app('session')->driver()->setName(config('session.cookie'));
        Auth::shouldUse($guard);

        try {
            return $next($request);
        } finally {
            config($previous);
            Auth::shouldUse('web');
        }
    }
}
