<?php

namespace App\Contracts;

interface OtpProvider
{
    public function send(string $mobile, string $code): void;
}
