<?php

namespace App\Providers;

use App\Contracts\OtpProvider;
use App\Models\CallQueue;
use App\Models\IvrMenu;
use App\Models\SipExtension;
use App\Services\KavenegarOtpProvider;
use App\Services\RateLimitService;
use App\Services\UnavailableOtpProvider;
use App\Support\CustomerMobile;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Cache\RateLimiting\Unlimited;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\ValidationException;

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

            return app(UnavailableOtpProvider::class);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Relation::morphMap(['extension' => SipExtension::class, 'queue' => CallQueue::class, 'ivr' => IvrMenu::class]);

        RateLimiter::for('otp-request', function (Request $request) {
            if (! app(RateLimitService::class)->isEnabled()) {
                return new Unlimited;
            }

            return [
                Limit::perMinute(5)->by('ip:'.$request->ip()),
                Limit::perMinutes(10, 3)->by(($request->attributes->get('customer_portal') ? 'customer:' : 'user:').'mobile:'.sha1((string) $request->input('mobile'))),
            ];
        });

        RateLimiter::for('otp-verify', function (Request $request) {
            if (! app(RateLimitService::class)->isEnabled()) {
                return new Unlimited;
            }

            return Limit::perMinute(10)->by(($request->attributes->get('customer_portal') ? 'customer:' : 'user:').$request->ip().'|'.sha1((string) $request->input('mobile')));
        });

        // Public registration keeps abuse protection even during an operational
        // suspension of the existing internal/customer login rate limits.
        RateLimiter::for('registration-request', function (Request $request) {
            $mobile = $request->input('mobile');
            try {
                $mobile = CustomerMobile::normalize(is_string($mobile) ? $mobile : '');
            } catch (ValidationException) {
                $mobile = '';
            }

            return [
                Limit::perMinute(5)->by('registration:ip:'.$request->ip()),
                Limit::perMinutes(10, 3)->by('registration:mobile:'.sha1($mobile)),
            ];
        });
        RateLimiter::for('registration-verify', fn (Request $request) => Limit::perMinute(10)->by('registration:verify:'.$request->ip()));

        RateLimiter::for('freeswitch-xml', function () {
            if (! app(RateLimitService::class)->isEnabled()) {
                return new Unlimited;
            }

            return Limit::perMinute(60)->by('freeswitch-xml');
        });
    }
}
