<?php

namespace App\Models;

use Database\Factories\OutboundRouteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'tenant_id',
    'sip_number_id',
    'gateway_id',
    'enabled',
])]
class OutboundRoute extends Model
{
    /** @use HasFactory<OutboundRouteFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function sipNumber(): BelongsTo
    {
        return $this->belongsTo(SipNumber::class);
    }

    public function gateway(): BelongsTo
    {
        return $this->belongsTo(SipGateway::class, 'gateway_id');
    }
}
