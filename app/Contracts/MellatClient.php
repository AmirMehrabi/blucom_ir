<?php

namespace App\Contracts;

use App\Models\PaymentGatewayVersion;

interface MellatClient
{
    public function call(#[\SensitiveParameter] PaymentGatewayVersion $account, string $method, array $fields): string;
}
