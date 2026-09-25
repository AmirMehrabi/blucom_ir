<?php

use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\EnsureUserType;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias(['admin' => EnsureUserType::class, 'customer' => EnsureUserType::class, 'permission' => EnsurePermission::class]);
        $middleware->validateCsrfTokens(except: [
            'internal/freeswitch/xml',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
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
