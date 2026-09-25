<?php

namespace App\Models;

use Database\Factories\SipGatewayFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'name',
    'tenant_id',
    'display_name',
    'provider_name',
    'connection_method',
    'verification_status',
    'host',
    'port',
    'transport',
    'username',
    'password_encrypted',
    'profile',
    'context',
    'enabled',
    'register',
    'auth_username',
    'realm',
    'approved_for_outbound',
])]
#[Hidden(['password_encrypted'])]
class SipGateway extends Model
{
    /** @use HasFactory<SipGatewayFactory> */
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    protected function casts(): array
    {
        return [
            'password_encrypted' => 'encrypted',
            'enabled' => 'boolean',
            'register' => 'boolean',
            'approved_for_outbound' => 'boolean',
            'port' => 'integer',
        ];
    }

    public function outboundRoutes(): HasMany
    {
        return $this->hasMany(OutboundRoute::class, 'gateway_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function sipNumbers(): HasMany
    {
        return $this->hasMany(SipNumber::class, 'provider_gateway_id');
    }
}
