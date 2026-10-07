<?php

namespace App\Services\Commerce;

use App\Models\CommerceInvoice;
use App\Models\CommerceOrder;
use App\Models\Customer;
use App\Models\NumberReservation;
use App\Models\PaymentAttempt;
use App\Models\SipNumber;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class NumberReservationService
{
    public function __construct(private CommerceAudit $audit) {}

    /** Release stock without deleting financial evidence or claiming a bank refund. */
    public function cancel(Customer|User $actor, string $publicId, string $reason = ''): CommerceOrder
    {
        return DB::transaction(function () use ($actor, $publicId, $reason): CommerceOrder {
            if ($actor instanceof Customer) {
                $tenant = Tenant::query()->lockForUpdate()->find($actor->tenant_id);
                $actor = Customer::query()->lockForUpdate()->findOrFail($actor->id);
                abort_unless($tenant !== null && $actor->canAccessTenant($tenant)
                    && $actor->hasPermission(Permissions::BILLING_MANAGE)
                    && $actor->hasPermission(Permissions::BILLING_VIEW), 403);
            } else {
                $actor = User::query()->findOrFail($actor->id);
                abort_unless($actor->isAdmin() && ! $actor->isDisabled(), 403);
                validator(['reason' => $reason], ['reason' => ['required', 'string', 'min:10', 'max:1000']])->validate();
            }
            $initial = CommerceOrder::query()->where('public_id', $publicId)
                ->when($actor instanceof Customer, fn ($query) => $query->where('tenant_id', $actor->tenant_id))->firstOrFail();
            $number = SipNumber::query()->lockForUpdate()->findOrFail($initial->item->sip_number_id);
            $reservation = NumberReservation::query()->where('commerce_order_id', $initial->id)->lockForUpdate()->firstOrFail();
            $order = CommerceOrder::query()->lockForUpdate()->findOrFail($initial->id);
            $invoice = CommerceInvoice::query()->where('commerce_order_id', $order->id)->lockForUpdate()->firstOrFail();
            if ($reservation->status === 'cancelled') {
                return $order;
            }
            $attempts = PaymentAttempt::query()->where('commerce_invoice_id', $invoice->id)->lockForUpdate()->get();
            $unresolved = $attempts->contains(fn ($attempt) => ! $attempt->isTestPayment()
                && ! in_array($attempt->status, ['initiation_failed', 'reversed'], true));
            $processing = $attempts->contains(fn ($attempt) => $attempt->status !== 'reversed'
                && ($attempt->verified_at !== null || $attempt->settled_at !== null
                    || ($attempt->operation_token !== null && $attempt->operation_expires_at?->isFuture())));
            if ($actor instanceof Customer && ($invoice->paid_payment_attempt_id !== null || $processing)) {
                throw ValidationException::withMessages(['reservation' => 'پرداخت در حال پردازش یا تأیید شده است. برای آزادسازی این رزرو با پشتیبانی تماس بگیرید.']);
            }
            if ($reservation->status !== 'held' || $number->current_reservation_id !== $reservation->id
                || $number->inventory_state !== 'reserved' || $number->tenant_id !== null
                || $number->requested_by_user_id !== null || $number->current_assignment_id !== null
                || $number->status !== SipNumber::STATUS_AVAILABLE
                || $reservation->tenant_id !== $order->tenant_id || $invoice->tenant_id !== $order->tenant_id
                || $invoice->customer_id !== $order->customer_id
                || ! in_array($order->status, ['reserved', 'paid_pending_allocation', 'paid_unfulfilled', 'reconciliation_required'], true)
                || ! in_array($invoice->status, ['issued', 'paid', 'reconciliation_required'], true)) {
                throw ValidationException::withMessages(['reservation' => 'این رزرو قابل آزادسازی نیست؛ وضعیت شماره و سفارش را بررسی کنید.']);
            }
            $status = $invoice->paid_payment_attempt_id !== null ? 'paid_unfulfilled' : ($unresolved ? 'reconciliation_required' : 'cancelled');
            $reservation->update(['status' => 'cancelled', 'released_at' => now()]);
            $order->update(['status' => $status]);
            if ($invoice->paid_payment_attempt_id === null) {
                $invoice->update(['status' => $status]);
            }
            $number->update(['current_reservation_id' => null, 'inventory_state' => 'available',
                'inventory_revision' => $number->inventory_revision + 1]);
            $metadata = ['order_id' => $order->id, 'outcome' => $status];
            if ($actor instanceof Customer) {
                $this->audit->customer($actor, 'reservation.cancelled', 'number_reservation', $reservation->id, $metadata);
            } else {
                $this->audit->record($actor, 'reservation.cancelled', 'number_reservation', $reservation->id, $reason, $metadata);
            }

            return $order;
        }, 3);
    }

    public function expire(int $id): bool
    {
        $numberId = NumberReservation::query()->findOrFail($id)->sip_number_id;

        return DB::transaction(function () use ($id, $numberId) {
            $number = SipNumber::query()->lockForUpdate()->findOrFail($numberId);
            $reservation = NumberReservation::query()->lockForUpdate()->findOrFail($id);

            return $this->releaseExpired($number, $reservation);
        }, 3);
    }

    /** Caller must hold the DID lock; locks thereafter are reservation -> order -> invoice -> attempts. */
    public function expireCurrent(SipNumber $number): bool
    {
        if ($number->current_reservation_id === null) {
            return false;
        }
        $reservation = NumberReservation::query()->lockForUpdate()->findOrFail($number->current_reservation_id);

        return $this->releaseExpired($number, $reservation);
    }

    private function releaseExpired(SipNumber $number, NumberReservation $reservation): bool
    {
        if ($reservation->status !== 'held' || $reservation->expires_at->isFuture()) {
            return false;
        }
        // A stale expiry worker may never clear a newer reservation or an assignment.
        if ($number->current_reservation_id !== $reservation->id || $number->id !== $reservation->sip_number_id
            || $number->inventory_state !== 'reserved' || $number->tenant_id !== null
            || $number->requested_by_user_id !== null || $number->current_assignment_id !== null
            || $number->status !== SipNumber::STATUS_AVAILABLE) {
            throw ValidationException::withMessages(['reservation' => 'وضعیت رزرو نیاز به بررسی دارد.']);
        }
        $order = CommerceOrder::query()->lockForUpdate()->findOrFail($reservation->commerce_order_id);
        $invoice = CommerceInvoice::query()->where('commerce_order_id', $order->id)->lockForUpdate()->firstOrFail();
        if ($order->tenant_id !== $reservation->tenant_id || $invoice->tenant_id !== $order->tenant_id
            || $invoice->customer_id !== $order->customer_id
            || ! in_array($order->status, ['reserved', 'paid_pending_allocation', 'paid_unfulfilled', 'reconciliation_required'], true)
            || ! in_array($invoice->status, ['issued', 'paid', 'reconciliation_required'], true)
            || ! $order->expires_at->equalTo($reservation->expires_at) || ! $invoice->expires_at->equalTo($reservation->expires_at)
            || ! $order->item()->where('sip_number_id', $number->id)->exists()) {
            throw ValidationException::withMessages(['reservation' => 'سوابق رزرو نیاز به بررسی دارد.']);
        }
        // Expiry never changes confirmed payment evidence or makes a late payment eligible for a newer hold.
        $attempts = PaymentAttempt::query()->where('commerce_invoice_id', $invoice->id)->lockForUpdate()->get();
        $realAttempts = $attempts->contains(fn ($attempt) => ! $attempt->isTestPayment());
        $status = $invoice->paid_payment_attempt_id !== null ? 'paid_unfulfilled' : ($realAttempts ? 'reconciliation_required' : 'expired');
        $reservation->update(['status' => 'expired', 'released_at' => now()]);
        $order->update(['status' => $status]);
        if ($invoice->paid_payment_attempt_id === null) {
            $invoice->update(['status' => $status]);
        }
        $number->update([
            'current_reservation_id' => null, 'inventory_state' => 'available',
            'inventory_revision' => $number->inventory_revision + 1,
        ]);
        $this->audit->system('reservation.expired', 'number_reservation', $reservation->id, ['order_id' => $order->id, 'outcome' => $status]);

        return true;
    }

    public function expireDue(int $limit = 100): array
    {
        $ids = NumberReservation::query()->where('status', 'held')->where('expires_at', '<=', now())
            ->orderBy('expires_at')->orderBy('id')->limit($limit)->pluck('id');
        $expired = 0;
        $review = 0;
        foreach ($ids as $id) {
            try {
                $expired += (int) $this->expire($id);
            } catch (ValidationException) {
                $review++;
            }
        }

        return compact('expired', 'review');
    }
}
