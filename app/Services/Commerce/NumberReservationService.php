<?php

namespace App\Services\Commerce;

use App\Models\CommerceInvoice;
use App\Models\CommerceOrder;
use App\Models\NumberReservation;
use App\Models\PaymentAttempt;
use App\Models\SipNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class NumberReservationService
{
    public function __construct(private CommerceAudit $audit) {}

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
        $status = $invoice->paid_payment_attempt_id !== null ? 'paid_unfulfilled' : ($attempts->isEmpty() ? 'expired' : 'reconciliation_required');
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
