<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Validation\ValidationException;

class CommerceInvoice extends CommerceRecord
{
    protected array $mutableFields = ['status', 'current_payment_attempt_id', 'paid_payment_attempt_id', 'paid_at'];

    protected static function booted(): void
    {
        parent::booted();
        static::updating(function (self $invoice): void {
            if ($invoice->getOriginal('paid_payment_attempt_id') !== null && $invoice->isDirty(['paid_payment_attempt_id', 'paid_at'])) {
                throw ValidationException::withMessages(['payment' => 'پرداخت ثبت‌شده صورتحساب قابل جایگزینی نیست.']);
            }
        });
    }

    protected function casts(): array
    {
        return ['buyer_snapshot' => 'array', 'total_amount' => 'integer', 'issued_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime', 'paid_at' => 'immutable_datetime'];
    }

    public function item(): HasOne
    {
        return $this->hasOne(CommerceInvoiceItem::class);
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
