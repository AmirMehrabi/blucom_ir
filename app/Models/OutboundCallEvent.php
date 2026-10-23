<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OutboundCallEvent extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['occurred_at' => 'immutable_datetime'];
    }
}
