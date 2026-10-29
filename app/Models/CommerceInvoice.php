<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Validation\ValidationException;

class CommerceInvoice extends CommerceRecord
{
    protected array $mutableFields = ['status', 'current_payment_attempt_id', 'paid_payment_attempt_id', 'paid_at', 'applied_at', 'applied_period_starts_at', 'applied_period_ends_at', 'notice_sent_at', 'due_notice_sent_at', 'final_notice_sent_at'];

    protected static function booted(): void
    {
        parent::booted();
        static::updating(function (self $invoice): void {
            if (($invoice->getOriginal('paid_payment_attempt_id') !== null || $invoice->getOriginal('paid_at') !== null) && $invoice->isDirty(['paid_payment_attempt_id', 'paid_at'])) {
                throw ValidationException::withMessages(['payment' => 'پرداخت ثبت‌شده صورتحساب قابل جایگزینی نیست.']);
            }
        });
    }

    protected function casts(): array
    {
        return ['buyer_snapshot' => 'array', 'total_amount' => 'integer', 'issued_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime', 'paid_at' => 'immutable_datetime',
            'period_starts_at' => 'immutable_datetime', 'period_ends_at' => 'immutable_datetime', 'due_at' => 'immutable_datetime', 'grace_ends_at' => 'immutable_datetime',
            'applied_at' => 'immutable_datetime', 'applied_period_starts_at' => 'immutable_datetime', 'applied_period_ends_at' => 'immutable_datetime',
            'notice_sent_at' => 'immutable_datetime', 'due_notice_sent_at' => 'immutable_datetime', 'final_notice_sent_at' => 'immutable_datetime'];
    }

    public function walletTarget(): BelongsTo
    {
        return $this->belongsTo(self::class, 'wallet_target_invoice_id');
    }

    public function walletEntry(): HasOne
    {
        return $this->hasOne(WalletEntry::class);
    }

    /** Wallet settlement has its own evidence; it never impersonates a bank payment. */
    public function hasValidSettlement(): bool
    {
        if ($this->status !== 'paid' || $this->currency !== 'IRT') {
            return false;
        }
        if ($this->paid_payment_attempt_id === null) {
            return WalletEntry::query()->where('commerce_invoice_id', $this->id)
                ->where('tenant_id', $this->tenant_id)->where('kind', 'debit')
                ->where('amount', -$this->total_amount)->exists();
        }
        $payment = PaymentAttempt::query()->find($this->paid_payment_attempt_id);

        return $payment && $payment->status === 'settled' && $payment->verified_at && $payment->settled_at
            && $payment->commerce_invoice_id === $this->id && $payment->business_amount === $this->total_amount
            && $payment->business_currency === 'IRT' && $payment->gateway_unit === 'IRR'
            && $payment->gateway_amount === $this->total_amount * 10;
    }

    public function item(): HasOne
    {
        return $this->hasOne(CommerceInvoiceItem::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(CommerceInvoiceItem::class);
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
