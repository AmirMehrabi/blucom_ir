<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

#[Fillable(['sip_number_id', 'plan_version_id', 'monthly_amount', 'currency', 'published_at', 'withdrawn_at'])]
class NumberOffer extends Model
{
    protected function casts(): array
    {
        return ['monthly_amount' => 'integer', 'published_at' => 'immutable_datetime', 'withdrawn_at' => 'immutable_datetime'];
    }

    public function planVersion(): BelongsTo
    {
        return $this->belongsTo(PlanVersion::class);
    }

    public function number(): BelongsTo
    {
        return $this->belongsTo(SipNumber::class, 'sip_number_id');
    }

    protected static function booted(): void
    {
        static::updating(function (self $offer): void {
            if ($offer->isDirty(['sip_number_id', 'plan_version_id', 'monthly_amount', 'currency', 'published_at'])) {
                throw ValidationException::withMessages(['offer' => 'پیشنهاد قیمت تغییر نمی‌کند؛ پیشنهاد جدید منتشر کنید.']);
            }
        });
        static::deleting(fn () => throw ValidationException::withMessages(['offer' => 'تاریخچه قیمت قابل حذف نیست.']));
    }
}
