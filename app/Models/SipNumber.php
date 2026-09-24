<?php

namespace App\Models;

use Database\Factories\SipNumberFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'tenant_id',
    'requested_by_user_id',
    'number',
    'label',
    'normalized_number',
    'provider_gateway_id',
    'status',
    'enabled',
    'inbound_enabled',
    'outbound_enabled',
])]
class SipNumber extends Model
{
    /** @use HasFactory<SipNumberFactory> */
    use HasFactory;

    public const STATUS_AVAILABLE = 'available';

    public const STATUS_ASSIGNED = 'assigned';

    public const STATUS_PENDING = 'pending';

    public const STATUS_DISABLED = 'disabled';

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'inbound_enabled' => 'boolean',
            'outbound_enabled' => 'boolean',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function providerGateway(): BelongsTo
    {
        return $this->belongsTo(SipGateway::class, 'provider_gateway_id');
    }

    public function inboundRoute(): HasOne
    {
        return $this->hasOne(InboundRoute::class);
    }

    public function outboundRoutes(): HasMany
    {
        return $this->hasMany(OutboundRoute::class);
    }

    public function isRoutable(): bool
    {
        return $this->status === self::STATUS_ASSIGNED
            && $this->tenant_id !== null;
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_AVAILABLE => 'موجود در سبد',
            self::STATUS_ASSIGNED => 'تخصیص‌یافته',
            self::STATUS_PENDING => 'در انتظار تأیید',
            self::STATUS_DISABLED => 'غیرفعال',
            default => $this->status,
        };
    }

    public function statusBadgeClass(): string
    {
        return match ($this->status) {
            self::STATUS_AVAILABLE => 'text-blue-600',
            self::STATUS_ASSIGNED => 'text-emerald-600',
            self::STATUS_PENDING => 'text-amber-600',
            self::STATUS_DISABLED => 'text-red-600',
            default => 'text-slate-600',
        };
    }
}
