<?php

namespace App\Services\Commerce;

use App\Models\CommerceInvoice;
use App\Models\CommerceOrder;
use App\Models\NumberAssignment;
use App\Models\NumberReservation;
use App\Models\NumberSubscription;
use App\Models\PaymentAttempt;
use App\Models\SipNumber;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class PaidOrderAllocationService
{
    public function __construct(private NumberReadinessService $readiness, private CommerceAudit $audit) {}

    /** Financial settlement is committed independently; retries cannot allocate twice. */
    public function allocate(int $orderId, ?User $repairActor = null, ?string $reason = null): ?NumberAssignment
    {
        if ($repairActor !== null) {
            abort_unless($repairActor->isAdmin() && ! $repairActor->isDisabled(), 403);
            validator(['reason' => $reason], ['reason' => ['required', 'string', 'min:10', 'max:1000']])->validate();
        }

        return DB::transaction(function () use ($orderId, $repairActor, $reason) {
            $initial = CommerceOrder::query()->with('item')->findOrFail($orderId);
            $tenant = Tenant::query()->lockForUpdate()->findOrFail($initial->tenant_id);
            $number = SipNumber::query()->lockForUpdate()->findOrFail($initial->item->sip_number_id);
            $hold = NumberReservation::query()->where('commerce_order_id', $orderId)->lockForUpdate()->firstOrFail();
            $order = CommerceOrder::query()->lockForUpdate()->findOrFail($orderId);
            $invoice = CommerceInvoice::query()->where('commerce_order_id', $orderId)->lockForUpdate()->firstOrFail();
            $payment = PaymentAttempt::query()->lockForUpdate()->find($invoice->paid_payment_attempt_id);
            $item = $order->item;
            if ($invoice->status !== 'paid' || $payment === null || $payment->status !== 'settled'
                || ! $payment->verified_at || ! $payment->settled_at || $payment->commerce_invoice_id !== $invoice->id
                || $invoice->tenant_id !== $order->tenant_id || $invoice->customer_id !== $order->customer_id
                || $invoice->total_amount !== $order->total_amount || $item->amount !== $order->total_amount
                || $payment->business_amount !== $order->total_amount || $payment->gateway_amount !== $order->total_amount * 10
                || $payment->business_currency !== 'IRT' || $payment->gateway_unit !== 'IRR' || $order->currency !== 'IRT' || $invoice->currency !== 'IRT' || $item->currency !== 'IRT') {
                throw ValidationException::withMessages(['allocation' => 'پرداخت تسویه‌شده معتبر برای این سفارش موجود نیست.']);
            }
            $existing = NumberAssignment::query()->where('commerce_order_id', $orderId)->lockForUpdate()->first();
            if ($existing !== null) {
                // Released assignments remain historical and can never claim the number again.
                return $existing;
            }
            if (($item->snapshot['number'] ?? null) !== $number->normalized_number
                || ($item->snapshot['plan_version_id'] ?? null) !== $item->plan_version_id
                || ($item->snapshot['monthly_amount'] ?? null) !== $item->amount
                || ($item->snapshot['currency'] ?? null) !== 'IRT') {
                throw ValidationException::withMessages(['allocation' => 'جزئیات سفارش با شماره و پلن پرداخت‌شده مطابقت ندارد.']);
            }
            $live = $hold->status === 'held' && $hold->expires_at->isFuture()
                && $number->current_reservation_id === $hold->id && $number->inventory_state === 'reserved';
            $repairable = $repairActor !== null && in_array($hold->status, ['held', 'expired'], true)
                && ($number->current_reservation_id === null || $number->current_reservation_id === $hold->id)
                && in_array($number->inventory_state, ['available', 'reserved'], true);
            $stock = clone $number;
            $stock->current_reservation_id = null;
            $stock->inventory_state = 'available';
            if (! $tenant->isActive() || (! $live && ! $repairable) || $hold->tenant_id !== $tenant->id
                || $hold->sip_number_id !== $number->id || $number->current_offer_id !== $item->number_offer_id
                || $this->readiness->issues($stock, $number->providerGateway) !== []) {
                $order->update(['status' => 'paid_unfulfilled']);

                return null;
            }
            $assignment = NumberAssignment::query()->create([
                'sip_number_id' => $number->id, 'tenant_id' => $tenant->id, 'commerce_order_id' => $order->id,
                'payment_attempt_id' => $payment->id, 'assigned_at' => now(),
            ]);
            NumberSubscription::query()->create([
                'number_assignment_id' => $assignment->id, 'tenant_id' => $tenant->id,
                'plan_version_id' => $item->plan_version_id, 'snapshot' => $item->snapshot,
                'monthly_amount' => $item->amount, 'currency' => 'IRT', 'status' => 'pending_activation',
            ]);
            // Only this audited allocation boundary may change commerce stock ownership.
            DB::table('sip_numbers')->where('id', $number->id)->update([
                'tenant_id' => $tenant->id, 'status' => SipNumber::STATUS_ASSIGNED, 'inventory_state' => 'assigned',
                'current_assignment_id' => $assignment->id, 'current_reservation_id' => null, 'current_offer_id' => null,
                'inventory_revision' => $number->inventory_revision + 1, 'updated_at' => now(),
            ]);
            $hold->update(['status' => 'allocated', 'released_at' => now()]);
            $order->update(['status' => 'allocated']);
            $metadata = ['order_id' => $order->id, 'sip_number_id' => $number->id, 'tenant_id' => $tenant->id,
                'is_test' => $payment->isTestPayment()];
            if ($repairActor) {
                $this->audit->record($repairActor, 'allocation.repaired', 'number_assignment', $assignment->id, $reason, $metadata);
            } else {
                $this->audit->system('allocation.completed', 'number_assignment', $assignment->id, $metadata, 'Verified paid order allocation');
            }

            return $assignment;
        }, 3);
    }

    public function afterSettlement(PaymentAttempt $attempt): PaymentAttempt
    {
        if ($attempt->status === 'settled') {
            $invoice = CommerceInvoice::query()->findOrFail($attempt->commerce_invoice_id);
            try {
                $this->allocate($invoice->commerce_order_id);
            } catch (\Throwable $exception) {
                // Preserve the bank result and leave a recoverable pending allocation.
                Log::error('Paid order allocation deferred', ['order_id' => $invoice->commerce_order_id, 'exception_class' => $exception::class]);
            }
        }

        return $attempt;
    }
}
