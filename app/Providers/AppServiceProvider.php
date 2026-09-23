<?php

namespace App\Providers;

use App\Contracts\OtpProvider;
use App\Services\KavenegarOtpProvider;
use App\Services\LogOtpProvider;
use App\Services\RateLimitService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Cache\RateLimiting\Unlimited;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(OtpProvider::class, function (): OtpProvider {
            if (config('services.kavenegar.api_key')) {
                return app(KavenegarOtpProvider::class);
            }

            return app(LogOtpProvider::class);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('otp-request', function (Request $request) {
            if (! app(RateLimitService::class)->isEnabled()) {
                return new Unlimited;
            }

            return [
                Limit::perMinute(5)->by('ip:'.$request->ip()),
                Limit::perMinutes(10, 3)->by('mobile:'.sha1((string) $request->input('mobile'))),
            ];
        });

        RateLimiter::for('otp-verify', function (Request $request) {
            if (! app(RateLimitService::class)->isEnabled()) {
                return new Unlimited;
            }

            return Limit::perMinute(10)->by($request->ip().'|'.sha1((string) $request->input('mobile')));
        });

        RateLimiter::for('freeswitch-xml', function () {
            if (! app(RateLimitService::class)->isEnabled()) {
                return new Unlimited;
            }

            return Limit::perMinute(60)->by('freeswitch-xml');
        });
    }
}
