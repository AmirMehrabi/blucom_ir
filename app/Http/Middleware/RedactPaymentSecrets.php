<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RedactPaymentSecrets
{
    public function handle(Request $request, Closure $next): Response
    {
        try {
            return $next($request);
        } finally {
            foreach (['merchant_terminal_id', 'merchant_username', 'merchant_password', 'merchant_id', 'CardHolderPan', 'CardHolderInfo'] as $key) {
                $request->request->remove($key);
                $request->json()->remove($key);
            }
        }
    }
}
