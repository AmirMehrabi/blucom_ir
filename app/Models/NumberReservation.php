<?php

namespace App\Models;

class NumberReservation extends CommerceRecord
{
    protected array $mutableFields = ['status', 'released_at'];

    protected function casts(): array
    {
        return ['expires_at' => 'immutable_datetime', 'released_at' => 'immutable_datetime'];
    }
}
