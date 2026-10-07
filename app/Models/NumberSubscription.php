<?php

namespace App\Models;

class NumberSubscription extends CommerceRecord
{
    protected array $mutableFields = ['status', 'activated_at', 'period_starts_at', 'period_ends_at'];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'monthly_amount' => 'integer', 'activated_at' => 'immutable_datetime', 'period_starts_at' => 'immutable_datetime', 'period_ends_at' => 'immutable_datetime'];
    }
}
