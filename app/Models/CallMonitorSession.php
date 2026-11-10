<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

#[Guarded([])]
#[Hidden(['password_encrypted'])]
class CallMonitorSession extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['password_encrypted' => 'encrypted', 'expires_at' => 'datetime', 'dispatched_at' => 'datetime', 'last_seen_at' => 'datetime', 'connected_at' => 'datetime', 'ended_at' => 'datetime'];
    }
}
