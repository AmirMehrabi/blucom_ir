<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class RateLimitService
{
    public const string CACHE_KEY = 'rate_limiting:temporarily_disabled';

    public function isEnabled(): bool
    {
        if (! config('rate_limiting.enabled', true)) {
            return false;
        }

        return ! $this->isTemporarilyDisabled();
    }

    public function isTemporarilyDisabled(): bool
    {
        return (bool) Cache::get(self::CACHE_KEY);
    }

    public function disable(?int $minutes = null): void
    {
        if ($minutes !== null && $minutes > 0) {
            $expiresAt = now()->addMinutes($minutes);
            Cache::put(self::CACHE_KEY, true, $expiresAt);
            Cache::put(self::CACHE_KEY.':expires_at', $expiresAt->toIso8601String(), $expiresAt);

            return;
        }

        Cache::forever(self::CACHE_KEY, true);
        Cache::forget(self::CACHE_KEY.':expires_at');
    }

    public function enable(): void
    {
        Cache::forget(self::CACHE_KEY);
        Cache::forget(self::CACHE_KEY.':expires_at');
    }

    /**
     * @return array{env: bool, temporary: bool, active: bool, expires_at: string|null}
     */
    public function status(): array
    {
        $expiresAt = Cache::get(self::CACHE_KEY.':expires_at');

        return [
            'env' => (bool) config('rate_limiting.enabled', true),
            'temporary' => $this->isTemporarilyDisabled(),
            'active' => $this->isEnabled(),
            'expires_at' => is_string($expiresAt) ? $expiresAt : null,
        ];
    }
}
