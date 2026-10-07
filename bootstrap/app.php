<?php

use App\Http\Middleware\EnsureActivePortalAccount;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\EnsurePortalDomain;
use App\Http\Middleware\EnsureUserType;
use App\Http\Middleware\SelectPortal;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(SelectPortal::class);
        $middleware->prependToPriorityList(AuthenticatesRequests::class, EnsurePortalDomain::class);
        $middleware->web(append: [EnsureActivePortalAccount::class]);
        $middleware->redirectGuestsTo(fn (Request $request) => $request->attributes->get('customer_portal')
            ? route('customer.login') : route('login'));
        $middleware->alias(['admin' => EnsureUserType::class, 'customer' => EnsureUserType::class, 'permission' => EnsurePermission::class]);
        $middleware->validateCsrfTokens(except: [
            'internal/freeswitch/xml',
            // Only this POST route exists, bound exclusively to the configured customer host.
            'payments/mellat/callback/*',
        ]);
        $middleware->trimStrings(except: ['merchant_password']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['merchant_terminal_id', 'merchant_username', 'merchant_password', 'CardHolderPan', 'CardHolderInfo']);
        $exceptions->render(function (Throwable $exception, Request $request) {
            if (! $request->is('internal/freeswitch/xml')) {
                return null;
            }

            $status = $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 500;

            return response('<?xml version="1.0" encoding="UTF-8"?><document type="freeswitch/xml"/>', $status, [
                'Content-Type' => 'application/xml; charset=UTF-8',
                'Cache-Control' => 'no-store',
            ]);
        });
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
