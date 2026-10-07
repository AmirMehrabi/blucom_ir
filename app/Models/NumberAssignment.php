<?php

namespace App\Models;

class NumberAssignment extends CommerceRecord
{
    protected array $mutableFields = ['released_at'];

    protected function casts(): array
    {
        return ['assigned_at' => 'immutable_datetime', 'released_at' => 'immutable_datetime'];
    }
}
