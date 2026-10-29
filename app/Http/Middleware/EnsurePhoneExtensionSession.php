<?php

namespace App\Http\Middleware;

use App\Http\Controllers\PhonePortalController;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpFoundation\Response;

class EnsurePhoneExtensionSession
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->session()->has('phone_extension_id')) {
            return redirect()->route('phone.login');
        }

        try {
            app(PhonePortalController::class)->identity($request);
        } catch (HttpExceptionInterface) {
            $request->session()->forget(['phone_extension_id', 'phone_number_id']);
            $request->session()->regenerate();

            return redirect()->route('phone.login');
        }

        return $next($request);
    }
}
