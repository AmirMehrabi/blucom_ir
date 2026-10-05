<?php

namespace App\Services;

use App\Contracts\OtpProvider;
use App\Models\Customer;
use App\Models\CustomerOtpChallenge;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CustomerOtpService
{
    public function __construct(private readonly OtpProvider $provider) {}

    public function issue(Customer $customer): CustomerOtpChallenge
    {
        $code = (string) random_int(100000, 999999);
        $challenge = DB::transaction(function () use ($customer, $code): CustomerOtpChallenge {
            $customer = Customer::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();
            app(TenantService::class)->forUser($customer);
            CustomerOtpChallenge::query()->where('customer_id', $customer->id)->whereNull('verified_at')
                ->update(['verified_at' => now()]);

            return CustomerOtpChallenge::query()->create([
                'id' => (string) Str::uuid(), 'customer_id' => $customer->id, 'mobile' => $customer->mobile,
                'code_hash' => $this->hash($code), 'expires_at' => now()->addSeconds(config('auth.otp.expires_seconds')),
            ]);
        });
        try {
            $this->provider->send($customer->mobile, $code);
        } catch (\Throwable $exception) {
            $challenge->delete();
            throw $exception;
        }

        return $challenge;
    }

    public function verify(CustomerOtpChallenge $challenge, string $code): bool
    {
        return DB::transaction(function () use ($challenge, $code): bool {
            $challenge = CustomerOtpChallenge::query()->whereKey($challenge->id)->lockForUpdate()->first();
            if ($challenge === null || $challenge->verified_at !== null || $challenge->expires_at->isPast()
                || $challenge->attempts >= config('auth.otp.max_attempts')) {
                return false;
            }
            $valid = hash_equals($challenge->code_hash, $this->hash($code));
            $challenge->attempts++;
            if ($valid || $challenge->attempts >= config('auth.otp.max_attempts')) {
                $challenge->verified_at = now();
            }
            $challenge->save();

            return $valid;
        });
    }

    private function hash(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }
}
