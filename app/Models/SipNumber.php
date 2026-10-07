<?php

namespace App\Models;

use Database\Factories\SipNumberFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Validation\ValidationException;

#[Fillable([
    'inventory_state', 'inventory_revision', 'destination_prefixes', 'reviewed_by_user_id',
    'reviewed_at', 'readiness_evidence', 'readiness_fingerprint', 'current_offer_id',
    'tenant_id', 'current_reservation_id', 'current_assignment_id',
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
            'inventory_revision' => 'integer', 'destination_prefixes' => 'array', 'reviewed_at' => 'immutable_datetime',
            'enabled' => 'boolean',
            'inbound_enabled' => 'boolean',
            'outbound_enabled' => 'boolean',
        ];
    }

    public function currentOffer(): BelongsTo
    {
        return $this->belongsTo(NumberOffer::class, 'current_offer_id');
    }

    public function currentReservation(): BelongsTo
    {
        return $this->belongsTo(NumberReservation::class, 'current_reservation_id');
    }

    public function currentAssignment(): BelongsTo
    {
        return $this->belongsTo(NumberAssignment::class, 'current_assignment_id');
    }

    public function offers(): HasMany
    {
        return $this->hasMany(NumberOffer::class);
    }

    protected static function booted(): void
    {
        static::updating(function (self $number): void {
            if ($number->getOriginal('current_offer_id') !== null
                && $number->isDirty(['provider_gateway_id', 'enabled', 'inbound_enabled', 'outbound_enabled', 'destination_prefixes'])) {
                throw ValidationException::withMessages(['inventory' => 'ابتدا انتشار پیشنهاد را بردارید.']);
            }
            if ($number->getOriginal('inventory_state') !== null
                && $number->isDirty(['tenant_id', 'requested_by_user_id', 'status', 'number', 'normalized_number'])) {
                throw ValidationException::withMessages(['inventory' => 'موجودی تجاری از مسیر تخصیص قدیمی تغییر نمی‌کند.']);
            }
        });
        static::deleting(function (self $number): void {
            if ($number->inventory_state !== null || $number->offers()->exists()) {
                throw ValidationException::withMessages(['inventory' => 'موجودی تجاری قابل حذف نیست؛ آن را غیرفعال کنید.']);
            }
        });
    }

    public function recordingSetting(): HasOne
    {
        return $this->hasOne(NumberRecordingSetting::class);
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
