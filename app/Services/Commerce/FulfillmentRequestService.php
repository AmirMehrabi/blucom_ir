<?php

namespace App\Services\Commerce;

use App\Models\BillingFulfillmentRequest;
use App\Models\CommerceOrder;
use App\Models\Customer;
use App\Models\Tenant;
use App\Support\Permissions;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FulfillmentRequestService
{
    public function __construct(private CommerceAudit $audit) {}

    public function request(Customer $actor, string $publicOrderId, string $choice): BillingFulfillmentRequest
    {
        validator(['choice' => $choice], ['choice' => ['required', 'in:refund,replacement']])->validate();
        return DB::transaction(function () use ($actor, $publicOrderId, $choice) {
            $tenant = Tenant::query()->lockForUpdate()->findOrFail($actor->tenant_id);
            $actor = Customer::query()->lockForUpdate()->findOrFail($actor->id);
            abort_unless($actor->canAccessTenant($tenant) && $actor->hasPermission(Permissions::BILLING_MANAGE), 403);
            $order = CommerceOrder::query()->where('public_id', $publicOrderId)->where('tenant_id', $tenant->id)
                ->with(['item', 'invoice'])->lockForUpdate()->firstOrFail();
            if ($order->item?->billing_model !== 'annual_number' || ! in_array($order->status, ['paid_pending_allocation', 'paid_unfulfilled'], true)
                || $order->invoice?->status !== 'paid' || ! $order->invoice->paid_at?->lte(now()->subDay())) {
                throw ValidationException::withMessages(['fulfillment' => 'درخواست پس از ۲۴ ساعت از پرداخت و برای شماره آماده‌نشده ثبت می‌شود.']);
            }
            $existing = BillingFulfillmentRequest::query()->where('commerce_order_id', $order->id)->first();
            if ($existing) {
                return $existing;
            }
            $request = BillingFulfillmentRequest::query()->create([
                'commerce_order_id' => $order->id, 'tenant_id' => $tenant->id,
                'customer_id' => $actor->id, 'choice' => $choice, 'status' => 'open',
            ]);
            $this->audit->customer($actor, 'billing.unready_request', 'billing_fulfillment_request', $request->id,
                ['order_id' => $order->id, 'choice' => $choice]);
            return $request;
        }, 3);
    }
}
