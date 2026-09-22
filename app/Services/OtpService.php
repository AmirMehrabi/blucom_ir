<?php

namespace App\Services;

use App\Contracts\OtpProvider;
use App\Models\OtpChallenge;
use Illuminate\Support\Str;

class OtpService
{
    public function __construct(private readonly OtpProvider $provider) {}

    public function issue(string $mobile, ?int $userId = null): OtpChallenge
    {
        OtpChallenge::query()->where('mobile', $mobile)->whereNull('verified_at')->update(['verified_at' => now()]);
        $code = (string) random_int(100000, 999999);
        $challenge = OtpChallenge::query()->create([
            'id' => (string) Str::uuid(), 'mobile' => $mobile, 'user_id' => $userId,
            'code_hash' => hash('sha256', $code), 'expires_at' => now()->addSeconds(config('auth.otp.expires_seconds')),
        ]);
        try { $this->provider->send($mobile, $code); } catch (\Throwable $exception) { $challenge->delete(); throw $exception; }
        return $challenge;
    }

    public function verify(OtpChallenge $challenge, string $code): bool
    {
        if ($challenge->verified_at || $challenge->expires_at->isPast() || $challenge->attempts >= config('auth.otp.max_attempts')) return false;
        $challenge->increment('attempts');
        if (! hash_equals($challenge->code_hash, hash('sha256', $code))) {
            if ($challenge->fresh()->attempts >= config('auth.otp.max_attempts')) $challenge->update(['verified_at' => now()]);
            return false;
        }
        $challenge->update(['verified_at' => now()]);
        return true;
    }
}
