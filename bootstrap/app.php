<?php

use App\Http\Middleware\EnsureActivePortalAccount;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\EnsurePortalDomain;
use App\Http\Middleware\EnsureUserType;
use App\Http\Middleware\SelectPortal;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
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
            // Payment callbacks are bound exclusively to the configured customer host.
            'payments/mellat/callback/*',
            'payments/zibal/callback/*',
        ]);
        $middleware->trimStrings(except: ['merchant_password']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['merchant_terminal_id', 'merchant_username', 'merchant_password', 'merchant_id', 'CardHolderPan', 'CardHolderInfo']);
        $exceptions->render(function (Throwable $exception, Request $request) {
            if ($exception instanceof AuthenticationException) {
                return null;
            }
            if ($request->getHost() !== config('portal.customer_domain')
                || ! $request->is('numbers', 'numbers/*', 'orders', 'orders/*', 'payments/*', 'invoices/*/payments')) {
                return null;
            }
            $status = $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : ($exception instanceof ValidationException ? 422 : 500);
            $copy = match ($status) {
                403 => ['دسترسی به این بخش برای شما فعال نیست', 'برای خرید یا مشاهدهٔ پرداخت‌ها، از مدیر حساب کسب‌وکار خود درخواست دسترسی کنید.'],
                404 => ['این سفارش یا شماره پیدا نشد', 'ممکن است شماره دیگر موجود نباشد یا این سفارش متعلق به حساب شما نباشد. از فهرست شماره‌ها یا سفارش‌های خود ادامه دهید.'],
                419 => ['زمان ورود شما پایان یافته است', 'دوباره وارد حساب شوید و وضعیت سفارش را بررسی کنید. اگر پرداخت کرده‌اید، پیش از پرداخت مجدد نتیجهٔ سفارش را پیگیری کنید.'],
                429 => ['کمی صبر کنید و دوباره تلاش کنید', 'درخواست‌های شما سریع ارسال شده‌اند. پس از کمی انتظار، وضعیت سفارش را دوباره بررسی کنید.'],
                422 => ['اطلاعات خرید یا پرداخت قابل بررسی نیست', 'به سفارش خود برگردید و وضعیت آن را بررسی کنید. موفقیت پرداخت فقط پس از تأیید بانک در سفارش نمایش داده می‌شود.'],
                409 => ['تکمیل خرید فعلاً امکان‌پذیر نیست', 'چند لحظهٔ دیگر دوباره تلاش کنید یا برای پیگیری با پشتیبانی تماس بگیرید.'],
                default => ['بررسی سفارش فعلاً امکان‌پذیر نیست', 'لطفاً کمی بعد دوباره وضعیت سفارش را بررسی کنید. اگر مبلغی پرداخت کرده‌اید، پرداخت دوباره انجام ندهید و با پشتیبانی تماس بگیرید.'],
            };

            return response()->view('customer.commerce.error', ['title' => $copy[0], 'message' => $copy[1]], $status)
                ->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer');
        });
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
