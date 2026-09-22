<?php

namespace App\Services;

use App\Contracts\OtpProvider;
use Kavenegar\KavenegarApi;
use RuntimeException;

class KavenegarOtpProvider implements OtpProvider
{
    public function send(string $mobile, string $code): void
    {
        try {
            (new KavenegarApi(config('services.kavenegar.api_key')))
                ->VerifyLookup($mobile, $code, null, null, config('services.kavenegar.template'));
        } catch (\Throwable $exception) {
            report($exception);
            throw new RuntimeException('OTP delivery failed.', previous: $exception);
        }
    }
}
