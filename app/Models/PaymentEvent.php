<?php

namespace App\Models;

class PaymentEvent extends CommerceRecord
{
    public $timestamps = false;

    protected $hidden = ['evidence'];

    protected function casts(): array
    {
        return ['evidence' => 'array', 'created_at' => 'immutable_datetime'];
    }
}
