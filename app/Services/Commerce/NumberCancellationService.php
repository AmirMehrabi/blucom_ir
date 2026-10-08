<?php

namespace App\Services\Commerce;

use App\Models\CommerceOrder;
use App\Models\NumberAssignment;
use App\Models\NumberSubscription;
use App\Models\SipNumber;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class NumberCancellationService
{
    public function __construct(private CommerceAudit $audit) {}

    public function cancel(User $actor, int $numberId, int $assignmentId, int $revision, string $reason, string $refundDecision): void
    {
        $this->authorize($actor, $reason);
        validator(['refund_decision' => $refundDecision], ['refund_decision' => ['required', 'in:no_refund,refund_pending']])->validate();
        $initial = NumberAssignment::query()->findOrFail($assignmentId);
        DB::transaction(function () use ($actor, $numberId, $assignmentId, $revision, $reason, $refundDecision, $initial) {
            // Match allocation/customer configuration lock order: tenant -> number -> assignment.
            Tenant::query()->lockForUpdate()->findOrFail($initial->tenant_id);
            $number = SipNumber::query()->lockForUpdate()->findOrFail($numberId);
            $assignment = NumberAssignment::query()->lockForUpdate()->findOrFail($assignmentId);
            if ($assignment->sip_number_id !== $number->id) {
                $this->conflict();
            }
            if ($assignment->released_at !== null && $assignment->cancellation_reason !== null) {
                return; // A retry cannot cancel a later assignment.
            }
            $order = CommerceOrder::query()->lockForUpdate()->findOrFail($assignment->commerce_order_id);
            $subscription = NumberSubscription::query()->where('number_assignment_id', $assignment->id)->lockForUpdate()->firstOrFail();
            if ($number->current_assignment_id !== $assignment->id || $number->tenant_id !== $assignment->tenant_id
                || $number->inventory_state !== 'assigned' || $number->inventory_revision !== $revision
                || $number->current_reservation_id !== null || $number->current_offer_id !== null
                || $assignment->released_at !== null || $order->tenant_id !== $assignment->tenant_id
                || $order->status !== 'allocated' || $subscription->tenant_id !== $assignment->tenant_id) {
                $this->conflict();
            }
            $number->inboundRoute()->delete();
            $number->outboundRoutes()->delete();
            $number->recordingSetting()->delete();
            $assignment->update(['released_at' => now(), 'cancellation_reason' => $reason, 'refund_decision' => $refundDecision]);
            $subscription->update(['status' => 'cancelled']);
            $order->update(['status' => 'service_cancelled']);
            // Retain the closed assignment pointer during quarantine for review and safe retries.
            DB::table('sip_numbers')->where('id', $number->id)->update([
                'tenant_id' => null, 'requested_by_user_id' => null, 'status' => SipNumber::STATUS_DISABLED,
                'inventory_state' => 'quarantined', 'enabled' => false, 'inbound_enabled' => false, 'outbound_enabled' => false,
                'label' => null, 'reviewed_at' => null, 'reviewed_by_user_id' => null,
                'readiness_evidence' => null, 'readiness_fingerprint' => null,
                'inventory_revision' => $number->inventory_revision + 1, 'updated_at' => now(),
            ]);
            $this->audit->record($actor, 'service.cancelled', 'number_assignment', $assignment->id, $reason,
                ['sip_number_id' => $number->id, 'tenant_id' => $assignment->tenant_id, 'order_id' => $order->id, 'refund_decision' => $refundDecision]);
        }, 3);
    }

    public function returnToStock(User $actor, int $numberId, int $assignmentId, int $revision, string $reason): void
    {
        $this->authorize($actor, $reason);
        DB::transaction(function () use ($actor, $numberId, $assignmentId, $revision, $reason) {
            $number = SipNumber::query()->lockForUpdate()->findOrFail($numberId);
            $assignment = NumberAssignment::query()->lockForUpdate()->findOrFail($assignmentId);
            if ($assignment->sip_number_id !== $number->id) {
                $this->conflict();
            }
            if ($assignment->returned_to_stock_at !== null) {
                return;
            }
            if ($number->inventory_state !== 'quarantined' || $number->current_assignment_id !== $assignment->id
                || $number->inventory_revision !== $revision || $number->tenant_id !== null
                || $number->current_reservation_id !== null || $number->current_offer_id !== null
                || $assignment->released_at === null || $assignment->cancellation_reason === null
                || $number->inboundRoute()->exists() || $number->outboundRoutes()->exists()) {
                $this->conflict();
            }
            DB::table('sip_numbers')->where('id', $number->id)->update([
                'current_assignment_id' => null, 'status' => SipNumber::STATUS_AVAILABLE, 'inventory_state' => 'available',
                'inventory_revision' => $number->inventory_revision + 1, 'updated_at' => now(),
            ]);
            $assignment->update(['returned_to_stock_at' => now()]);
            $this->audit->record($actor, 'service.returned_to_stock', 'number_assignment', $assignment->id, $reason, ['sip_number_id' => $number->id]);
        }, 3);
    }

    private function authorize(User $actor, string $reason): void
    {
        $actor = User::query()->findOrFail($actor->id);
        abort_unless($actor->isAdmin() && ! $actor->isDisabled(), 403);
        validator(['reason' => $reason], ['reason' => ['required', 'string', 'min:10', 'max:1000']])->validate();
    }

    private function conflict(): never
    {
        throw ValidationException::withMessages(['cancellation' => 'مالک یا وضعیت شماره تغییر کرده است؛ صفحه را تازه کنید.']);
    }
}
