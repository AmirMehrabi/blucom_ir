<?php

namespace App\Models;

use Illuminate\Validation\ValidationException;

class PaymentAttempt extends CommerceRecord
{
    // Infrastructure correlation and transport details never belong in customer responses.
    protected $hidden = ['account_key', 'ref_id', 'sale_reference', 'gateway_amount', 'gateway_unit', 'candidate_sale_reference', 'operation_token', 'payment_gateway_version_id'];

    protected array $mutableFields = ['ref_id', 'sale_reference', 'status', 'verified_at', 'settled_at', 'candidate_sale_reference', 'operation_token', 'operation_expires_at', 'last_code', 'reversal_requested'];

    protected static function booted(): void
    {
        parent::booted();
        static::updating(function (self $attempt): void {
            if ($attempt->getOriginal('reversal_requested') && $attempt->isDirty('reversal_requested')) {
                throw ValidationException::withMessages(['payment' => 'درخواست برگشت پرداخت قابل حذف نیست.']);
            }
            foreach (['ref_id', 'sale_reference', 'verified_at', 'settled_at'] as $field) {
                if ($attempt->getOriginal($field) !== null && $attempt->isDirty($field)) {
                    throw ValidationException::withMessages(['payment' => 'مرجع پرداخت ثبت‌شده قابل تغییر نیست.']);
                }
            }
        });
    }

    public function statusLabel(): string
    {
        if ($this->hasUndeliveredZibalInitiation()) {
            return 'شروع پرداخت ناموفق';
        }

        return self::statusLabels()[$this->status] ?? 'نیازمند بررسی';
    }

    /** A Zibal request only creates a link; no card payment can start before that link is issued. */
    public function hasUndeliveredZibalInitiation(): bool
    {
        return $this->provider === 'zibal' && $this->status === 'unknown'
            && $this->ref_id === null && $this->candidate_sale_reference === null
            && $this->sale_reference === null && $this->verified_at === null && $this->settled_at === null
            && ($this->operation_token === null || $this->operation_expires_at?->isPast());
    }

    public static function statusLabels(): array
    {
        return ['initiating' => 'در حال آغاز', 'redirect_ready' => 'آماده پرداخت', 'verifying' => 'در حال تأیید',
            'settling' => 'در حال تسویه', 'reversing' => 'در حال برگشت', 'unknown' => 'نتیجه نامشخص',
            'pending_settlement' => 'در انتظار تسویه', 'settled' => 'تسویه‌شده', 'duplicate_payment' => 'پرداخت اضافی',
            'reversed' => 'برگشت‌خورده', 'initiation_failed' => 'درخواست ناموفق'];
    }

    protected function casts(): array
    {
        return ['business_amount' => 'integer', 'gateway_amount' => 'integer', 'verified_at' => 'immutable_datetime', 'settled_at' => 'immutable_datetime', 'operation_expires_at' => 'immutable_datetime', 'reversal_requested' => 'boolean'];
    }
}
