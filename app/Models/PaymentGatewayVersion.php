<?php

namespace App\Models;

class PaymentGatewayVersion extends CommerceRecord
{
    protected $hidden = ['credentials', 'account_key'];

    public function isTest(): bool
    {
        // Also recognize test credentials saved before the explicit mode selector existed.
        return $this->is_test || ($this->credentials['merchant'] ?? null) === 'zibal';
    }

    public function zibalMerchant(): string
    {
        return $this->isTest() ? 'zibal' : ($this->credentials['merchant'] ?? '');
    }

    protected function casts(): array
    {
        return ['credentials' => 'encrypted:array', 'amount_unit_confirmed' => 'boolean', 'version' => 'integer', 'is_test' => 'boolean'];
    }
}
