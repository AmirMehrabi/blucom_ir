<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OtpChallenge extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';
    protected $fillable = ['id', 'mobile', 'user_id', 'code_hash', 'expires_at'];
    protected function casts(): array { return ['expires_at' => 'datetime', 'verified_at' => 'datetime']; }
}
