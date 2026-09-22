<?php

namespace App\Providers;

use App\Contracts\OtpProvider;
use App\Services\KavenegarOtpProvider;
use Illuminate\Cache\RateLimiting\Limit;
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
        $this->app->bind(OtpProvider::class, KavenegarOtpProvider::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('otp-request', fn (Request $request) => [Limit::perMinute(5)->by('ip:'.$request->ip()), Limit::perMinutes(10, 3)->by('mobile:'.sha1((string) $request->input('mobile')))]);
        RateLimiter::for('otp-verify', fn (Request $request) => Limit::perMinute(10)->by($request->ip().'|'.sha1((string) $request->input('mobile'))));
    }
}
