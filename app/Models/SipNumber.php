<?php

namespace App\Models;

use Database\Factories\SipNumberFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'tenant_id',
    'number',
    'normalized_number',
    'provider_gateway_id',
    'status',
    'inbound_enabled',
    'outbound_enabled',
])]
class SipNumber extends Model
{
    /** @use HasFactory<SipNumberFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'inbound_enabled' => 'boolean',
            'outbound_enabled' => 'boolean',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function providerGateway(): BelongsTo
    {
        return $this->belongsTo(SipGateway::class, 'provider_gateway_id');
    }

    public function inboundRoute(): HasOne
    {
        return $this->hasOne(InboundRoute::class);
    }

    public function outboundRoute(): HasOne
    {
        return $this->hasOne(OutboundRoute::class);
    }
}
