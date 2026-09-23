<?php

namespace App\Services;

use App\Contracts\OtpProvider;
use Illuminate\Support\Facades\Log;

class LogOtpProvider implements OtpProvider
{
    public function send(string $mobile, string $code): void
    {
        Log::warning('OTP code issued (LogOtpProvider — configure Kavenegar for real SMS).', [
            'mobile' => $mobile,
            'code' => $code,
        ]);
    }
}
