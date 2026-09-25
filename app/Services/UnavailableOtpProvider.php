<?php

namespace App\Services;

use App\Contracts\OtpProvider;
use RuntimeException;

class UnavailableOtpProvider implements OtpProvider
{
    public function send(string $mobile, string $code): void
    {
        throw new RuntimeException('OTP provider is not configured.');
    }
}
