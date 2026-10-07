<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePortalDomain
{
    public function handle(Request $request, Closure $next): Response
    {
        if (in_array($request->getHost(), [config('portal.admin_domain'), config('portal.customer_domain')], true)) {
            return $next($request);
        }

        // Preserve old bookmarked panel pages, without forwarding mutations.
        if ($request->getHost() === config('portal.public_domain') && $request->isMethod('GET')) {
            return redirect()->away('https://'.config('portal.admin_domain').$request->getRequestUri());
        }

        abort(404);
    }
}
