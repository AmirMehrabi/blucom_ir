<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\CommerceInvoice;
use App\Models\CommerceOrder;
use App\Models\Customer;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Services\Commerce\CommerceAudit;
use App\Services\Commerce\RecurringPaymentService;
use App\Services\Commerce\SubscriptionBillingService;
use App\Services\Commerce\WalletService;
use App\Support\Permissions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WalletController extends Controller
{
    public function pay(Request $request, string $invoice, WalletService $wallet, RecurringPaymentService $payments)
    {
        $data = $request->validate(['idempotency_key' => ['required', 'uuid']]);
        $record = CommerceInvoice::query()->where('public_id', $invoice)
            ->where('tenant_id', $request->user('customer')->tenant_id)->firstOrFail();
        try {
            $funding = $wallet->prepare($request->user('customer'), $invoice, $data['idempotency_key']);
            if ($funding) {
                $attempt = $payments->initiate($request->user('customer'), $funding->public_id, $data['idempotency_key'], false);

                return redirect()->route('customer.payments.show', $attempt->public_id);
            }
        } catch (ValidationException $exception) {
            return redirect()->to($this->destination($record))->withErrors($exception->errors());
        }

        return redirect()->to($this->destination($record))->with('status', 'پرداخت از کیف پول انجام شد. موجودی و رسید به‌روز هستند.');
    }

    public function topup(Request $request, WalletService $wallet, RecurringPaymentService $payments)
    {
        $data = $request->validate(['amount' => ['required', 'integer', 'min:1000', 'max:1000000000000'], 'idempotency_key' => ['required', 'uuid']]);
        try {
            $invoice = $wallet->topup($request->user('customer'), (int) $data['amount'], $data['idempotency_key']);
            $attempt = $payments->initiate($request->user('customer'), $invoice->public_id, $data['idempotency_key'], false);
        } catch (ValidationException $exception) {
            return redirect()->route('customer.billing.index', ['tab' => 'transactions'])->withErrors($exception->errors());
        }

        return redirect()->route('customer.payments.show', $attempt->public_id);
    }

    public function renew(Request $request, SubscriptionBillingService $billing)
    {
        abort_unless(config('commerce.wallet_enabled'), 404);
        $customer = $request->user('customer');
        abort_unless($customer->hasPermission(Permissions::BILLING_MANAGE) && $customer->hasPermission(Permissions::BILLING_VIEW), 403);
        $subscription = TenantSubscription::query()->where('tenant_id', $customer->tenant_id)->firstOrFail();
        try {
            $invoice = $subscription->cancel_at?->isPast()
                ? $billing->requestReactivation($customer) : $billing->issuePlan($subscription->id);
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors());
        }

        return $invoice ? redirect()->route('customer.billing.invoice', $invoice->public_id)
            : redirect()->route('customer.billing.index')->with('status', 'اشتراک هنوز به تمدید نیاز ندارد.');
    }

    public function autoRenew(Request $request)
    {
        abort_unless(config('commerce.wallet_enabled'), 404);
        $data = $request->validate(['enabled' => ['required', 'boolean']]);
        DB::transaction(function () use ($request, $data) {
            $actor = $request->user('customer');
            $tenant = Tenant::query()->lockForUpdate()->findOrFail($actor->tenant_id);
            $actor = Customer::query()->findOrFail($actor->id);
            abort_unless($actor->canAccessTenant($tenant) && $actor->hasPermission(Permissions::BILLING_MANAGE), 403);
            $subscription = TenantSubscription::query()->where('tenant_id', $tenant->id)->lockForUpdate()->firstOrFail();
            $subscription->update(['wallet_auto_renew' => (bool) $data['enabled']]);
            app(CommerceAudit::class)->customer($actor, 'subscription.wallet_renewal_changed',
                'tenant_subscription', $subscription->id, ['enabled' => (bool) $data['enabled']]);
        });

        return back()->with('status', $data['enabled'] ? 'تمدید خودکار از موجودی کیف پول فعال شد.' : 'تمدید خودکار خاموش شد؛ دوره پرداخت‌شده شما محفوظ است.');
    }

    private function destination(CommerceInvoice $invoice): string
    {
        return $invoice->commerce_order_id
            ? route('customer.orders.show', CommerceOrder::query()->findOrFail($invoice->commerce_order_id)->public_id)
            : route('customer.billing.invoice', $invoice->public_id);
    }
}
