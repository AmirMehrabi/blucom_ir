<?php

namespace App\Services\Commerce;

use App\Models\CommerceOrder;
use App\Models\CommerceInvoice;
use App\Models\Customer;
use App\Models\NumberAssignment;
use App\Models\NumberTerm;
use App\Models\PaymentAttempt;
use App\Models\SipNumber;
use App\Models\Tenant;
use App\Support\Permissions;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CustomerNumberReleaseService
{
    public function __construct(private CommerceAudit $audit) {}

    public function release(Customer $actor, int $numberId, int $assignmentId, int $revision): void
    {
        $initial = NumberAssignment::query()->findOrFail($assignmentId);
        DB::transaction(function () use ($actor, $numberId, $assignmentId, $revision, $initial) {
            $tenant = Tenant::query()->lockForUpdate()->findOrFail($initial->tenant_id);
            $actor = Customer::query()->lockForUpdate()->findOrFail($actor->id);
            abort_unless($actor->tenant_id === $tenant->id && $actor->canAccessTenant($tenant)
                && $actor->hasPermission(Permissions::BILLING_MANAGE), 403);
            $number = SipNumber::query()->lockForUpdate()->findOrFail($numberId);
            $assignment = NumberAssignment::query()->lockForUpdate()->findOrFail($assignmentId);
            $term = NumberTerm::query()->where('number_assignment_id', $assignment->id)->lockForUpdate()->firstOrFail();
            if ($number->current_assignment_id !== $assignment->id || $number->tenant_id !== $tenant->id
                || $number->inventory_state !== 'assigned' || $number->inventory_revision !== $revision
                || $assignment->released_at !== null || $term->tenant_id !== $tenant->id || $term->status !== 'active') {
                throw ValidationException::withMessages(['number' => 'مالکیت یا وضعیت شماره تغییر کرده است؛ صفحه را تازه کنید.']);
            }
            $open = CommerceInvoice::query()->where('number_term_id', $term->id)
                ->where('status', 'issued')->lockForUpdate()->get();
            foreach ($open as $invoice) {
                if (PaymentAttempt::query()->where('commerce_invoice_id', $invoice->id)
                    ->whereNotIn('status', ['initiation_failed', 'reversed'])->exists()) {
                    throw ValidationException::withMessages(['number' => 'پرداخت تمدید در جریان است؛ پس از تعیین نتیجه دوباره اقدام کنید.']);
                }
                $invoice->update(['status' => 'void']);
            }
            $number->inboundRoute()->delete();
            $number->outboundRoutes()->delete();
            $number->recordingSetting()->delete();
            $assignment->update(['released_at' => now(), 'cancellation_reason' => 'Customer requested early release', 'refund_decision' => 'no_refund']);
            $term->update(['status' => 'cancelled']);
            CommerceOrder::query()->whereKey($assignment->commerce_order_id)->update(['status' => 'service_cancelled']);
            DB::table('sip_numbers')->where('id', $number->id)->update([
                'tenant_id' => null, 'requested_by_user_id' => null, 'status' => SipNumber::STATUS_DISABLED,
                'inventory_state' => 'quarantined', 'enabled' => false, 'inbound_enabled' => false,
                'outbound_enabled' => false, 'label' => null, 'reviewed_at' => null,
                'reviewed_by_user_id' => null, 'readiness_evidence' => null, 'readiness_fingerprint' => null,
                'inventory_revision' => $number->inventory_revision + 1, 'updated_at' => now(),
            ]);
            $this->audit->customer($actor, 'number.early_release', 'number_assignment', $assignment->id,
                ['sip_number_id' => $number->id, 'unused_term_refunded' => false]);
        }, 3);
    }
}
