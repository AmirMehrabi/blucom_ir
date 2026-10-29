<?php

namespace App\Services\Portal;

use App\Models\CommerceInvoice;
use App\Models\CommerceOrder;
use App\Models\Customer;
use App\Models\PaymentAttempt;
use App\Services\Commerce\CustomerCommercePresenter;
use App\Services\Commerce\WalletService;
use App\Support\Permissions;

class CustomerBillingSummary
{
    public function action(CommerceInvoice $invoice, Customer $customer): array
    {
        abort_unless($invoice->tenant_id === $customer->tenant_id && $customer->hasPermission(Permissions::BILLING_VIEW), 404);
        if ($invoice->commerce_order_id) {
            $order = CommerceOrder::query()->where('tenant_id', $customer->tenant_id)->findOrFail($invoice->commerce_order_id);

            return ['label' => $invoice->status === 'paid' ? 'مشاهده خرید و تنظیم خط' : 'بررسی وضعیت خرید و پرداخت', 'url' => route('customer.orders.show', $order->public_id), 'canPay' => false, 'pending' => false, 'continue' => null];
        }
        if (config('commerce.wallet_enabled') && in_array($invoice->kind, ['plan_renewal', 'plan_upgrade'], true)) {
            $wallet = app(WalletService::class)->summary($invoice, $customer);
            $funding = app(WalletService::class)->funding($invoice);
            if ($funding) {
                return ['label' => $wallet['pending'] ? 'پیگیری پرداخت در حال بررسی' : 'ادامه شارژ و تمدید',
                    'url' => route('customer.billing.invoice', $invoice->public_id), 'canPay' => ! $wallet['pending'] && $wallet['canManage'] && $wallet['ready'],
                    'pending' => $wallet['pending'], 'continue' => $wallet['canManage'] ? $wallet['continue'] : null,
                    'paymentsReady' => $wallet['ready']];
            }
        }
        $paymentsReady = app(CustomerCommercePresenter::class)->checkoutReady()
            || (config('commerce.wallet_enabled') && config('commerce.checkout_enabled') && $invoice->kind !== 'wallet_topup'
                && app(WalletService::class)->balance($customer->tenant_id) >= $invoice->total_amount);
        $attempt = $invoice->current_payment_attempt_id ? PaymentAttempt::query()->where('commerce_invoice_id', $invoice->id)->find($invoice->current_payment_attempt_id) : null;
        $pending = $invoice->status === 'issued' && $attempt && ! $attempt->hasUndeliveredZibalInitiation() && in_array($attempt->status, ['initiating', 'verifying', 'settling', 'reversing', 'unknown', 'pending_settlement', 'duplicate_payment', 'settled', 'test_succeeded'], true);
        $continue = $invoice->status === 'issued' && $invoice->expires_at?->isFuture() && $attempt?->status === 'redirect_ready' && $customer->hasPermission(Permissions::BILLING_MANAGE)
            ? route('customer.payments.show', $attempt->public_id) : null;

        return ['label' => $pending ? 'پیگیری پرداخت در حال بررسی' : ($invoice->status === 'paid' ? 'مشاهده رسید' : 'بررسی و پرداخت صورتحساب'),
            'url' => route('customer.billing.invoice', $invoice->public_id),
            'canPay' => $invoice->status === 'issued' && $invoice->paid_payment_attempt_id === null && ! $pending && ! $continue && $paymentsReady && $customer->hasPermission(Permissions::BILLING_MANAGE),
            'pending' => (bool) $pending, 'continue' => $continue, 'paymentsReady' => $paymentsReady];
    }

    public function next(Customer $customer): ?CommerceInvoice
    {
        return CommerceInvoice::query()->where('tenant_id', $customer->tenant_id)->where('status', 'issued')->where('kind', '!=', 'wallet_topup')->whereNull('paid_payment_attempt_id')
            ->where(fn ($q) => $q->whereNull('commerce_order_id')->orWhereIn('commerce_order_id', CommerceOrder::query()->where('tenant_id', $customer->tenant_id)->whereIn('status', ['reserved', 'reconciliation_required'])->where('expires_at', '>', now())->select('id')))
            ->orderByRaw('COALESCE(due_at, expires_at, issued_at)')->first();
    }
}
