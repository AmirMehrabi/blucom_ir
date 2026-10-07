<?php

namespace App\Models;

class PaymentGatewayVersion extends CommerceRecord
{
    protected $hidden = ['credentials', 'account_key'];

    protected function casts(): array
    {
        return ['credentials' => 'encrypted:array', 'amount_unit_confirmed' => 'boolean', 'version' => 'integer'];
    }
}
