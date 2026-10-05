<?php

namespace App\Http\Middleware;

use App\Models\Customer;
use App\Services\TenantService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureActivePortalAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        $account = $request->user();
        if ($account !== null) {
            abort_if($account->isDisabled(), 403);
            if ($account instanceof Customer) {
                app(TenantService::class)->forUser($account);
            }
        }

        return $next($request);
    }
}
