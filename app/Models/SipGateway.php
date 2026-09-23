<?php

namespace App\Models;

use Database\Factories\SipGatewayFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'name',
    'host',
    'port',
    'transport',
    'username',
    'password_encrypted',
    'profile',
    'context',
    'enabled',
])]
#[Hidden(['password_encrypted'])]
class SipGateway extends Model
{
    /** @use HasFactory<SipGatewayFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'password_encrypted' => 'encrypted',
            'enabled' => 'boolean',
            'port' => 'integer',
        ];
    }
}
