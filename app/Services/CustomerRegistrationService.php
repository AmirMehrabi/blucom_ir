<?php

namespace App\Services;

use App\Contracts\OtpProvider;
use App\Models\Customer;
use App\Models\CustomerRegistrationChallenge;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CustomerRegistrationService
{
    public function __construct(private readonly OtpProvider $provider, private readonly CustomerAccountService $accounts) {}

    public function issue(string $name, string $mobile, string $business): CustomerRegistrationChallenge
    {
        if (Customer::query()->where('mobile', $mobile)->exists()) {
            throw $this->duplicateMobile();
        }

        $code = (string) random_int(100000, 999999);
        try {
            $challenge = DB::transaction(function () use ($name, $mobile, $business, $code): CustomerRegistrationChallenge {
                $previous = CustomerRegistrationChallenge::query()->where('mobile', $mobile)->lockForUpdate()->first();
                abort_if($previous !== null && $previous->created_at->diffInSeconds(now()) < config('auth.otp.resend_cooldown_seconds'),
                    429, 'لطفاً کمی بعد دوباره تلاش کنید.');
                $previous?->delete();

                return CustomerRegistrationChallenge::query()->create([
                    'id' => (string) Str::uuid(), 'name' => $name, 'mobile' => $mobile, 'business' => $business,
                    'code_hash' => $this->hash($code), 'expires_at' => now()->addSeconds(config('auth.otp.expires_seconds')),
                ]);
            }, 3);
        } catch (UniqueConstraintViolationException) {
            // Two first-time requests for the same mobile must not issue independent challenges.
            abort(429, 'لطفاً کمی بعد دوباره تلاش کنید.');
        }

        try {
            $this->provider->send($mobile, $code);
        } catch (\Throwable $exception) {
            CustomerRegistrationChallenge::query()->whereKey($challenge->id)->delete();
            throw $exception;
        }

        return $challenge;
    }

    public function register(string $challengeId, string $mobile, string $code): Customer
    {
        try {
            $customer = DB::transaction(function () use ($challengeId, $mobile, $code): ?Customer {
                $challenge = CustomerRegistrationChallenge::query()->whereKey($challengeId)->where('mobile', $mobile)->lockForUpdate()->first();
                if ($challenge === null || $challenge->verified_at !== null || $challenge->expires_at->isPast()
                    || $challenge->attempts >= config('auth.otp.max_attempts')) {
                    return null;
                }

                $challenge->attempts++;
                $valid = hash_equals($challenge->code_hash, $this->hash($code));
                if ($valid || $challenge->attempts >= config('auth.otp.max_attempts')) {
                    $challenge->verified_at = now();
                }
                $challenge->save();
                if (! $valid) {
                    return null;
                }

                $customer = $this->accounts->createOwner($challenge->name, $mobile, $challenge->business);
                $customer->update(['mobile_verified_at' => now()]);

                return $customer;
            }, 3);
        } catch (UniqueConstraintViolationException) {
            // Database uniqueness also protects registration against concurrent admin creation.
            throw $this->duplicateMobile();
        }

        if ($customer === null) {
            throw ValidationException::withMessages(['code' => 'کد واردشده معتبر نیست.']);
        }

        return $customer;
    }

    private function hash(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }

    private function duplicateMobile(): ValidationException
    {
        return ValidationException::withMessages(['mobile' => 'این شماره قبلاً ثبت شده است. از صفحه ورود استفاده کنید.']);
    }
}
