<?php

namespace App\Services\Portal;

use App\Models\BillingFulfillmentRequest;
use App\Models\CommerceInvoice;
use App\Models\CommerceOrder;
use App\Models\LineConnectionRequest;
use App\Models\NumberTerm;
use App\Models\PaymentAttempt;
use App\Models\SipGateway;
use App\Models\SipNumber;
use App\Models\Tenant;
use Illuminate\Support\Collection;

class AdminWorkQueue
{
    public const TYPES = ['all' => 'همه کارها', 'allocation' => 'آماده‌سازی خرید', 'payment' => 'بررسی پرداخت', 'connection' => 'اتصال شماره', 'review' => 'تأیید تنظیمات', 'request' => 'درخواست مشتری', 'release' => 'بررسی آزادسازی'];

    public function rows(?int $tenantId = null): Collection
    {
        $rows = collect();
        $add = function ($kind, $id, $tenant, $title, $description, $date, $url, $action, $priority = 2) use ($rows) {
            $rows->push(compact('kind', 'id', 'tenant', 'title', 'description', 'date', 'url', 'action', 'priority'));
        };
        foreach (CommerceOrder::query()->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))->whereIn('status', ['paid_pending_allocation', 'paid_unfulfilled'])->with('item')->lazyById(100) as $order) {
            $add('allocation', $order->id, $order->tenant_id, 'خرید پرداخت‌شده در انتظار آماده‌سازی', $order->item?->snapshot['number'] ?? 'سفارش '.$order->id, $order->created_at, route('admin.fulfillment.index', ['tenant_id' => $order->tenant_id, 'order_id' => $order->id]), 'بررسی آماده‌سازی', 0);
        }
        foreach (PaymentAttempt::query()->when($tenantId !== null, fn ($q) => $q->whereIn('commerce_invoice_id', CommerceInvoice::query()->where('tenant_id', $tenantId)->select('id')))->whereIn('status', ['unknown', 'pending_settlement', 'duplicate_payment'])->lazyById(100) as $payment) {
            $invoice = CommerceInvoice::query()->find($payment->commerce_invoice_id);
            if ($invoice) {
                $add('payment', $payment->id, $invoice->tenant_id, 'نتیجه پرداخت نیازمند بررسی', $payment->statusLabel().' · '.$invoice->invoice_number, $payment->created_at, route('admin.payments.index', ['invoice_id' => $invoice->id]), 'بررسی پرداخت', 1);
            }
        }
        foreach (LineConnectionRequest::query()->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))->where('status', 'open')->lazyById(100) as $request) {
            $add('connection', $request->id, $request->tenant_id, 'درخواست اتصال شماره فعلی', $request->normalized_number.' · '.$request->provider_name, $request->created_at, route('admin.work.show', ['kind' => 'connection', 'id' => $request->id]), 'بررسی درخواست');
        }
        foreach (SipGateway::query()->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))->whereNotNull('tenant_id')->where('verification_status', 'pending')->lazyById(100) as $gateway) {
            $add('review', $gateway->id, $gateway->tenant_id, 'اتصال ارائه‌دهنده در انتظار تأیید', $gateway->display_name ?: $gateway->name, $gateway->created_at, route('admin.customer-connections.index', ['tenant_id' => $gateway->tenant_id]), 'بررسی تنظیمات');
        }
        foreach (SipNumber::query()->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))->whereNotNull('tenant_id')->where('status', 'pending')->lazyById(100) as $number) {
            $add('review', 'number-'.$number->id, $number->tenant_id, 'شماره در انتظار تأیید', $number->normalized_number, $number->created_at, route('admin.customer-connections.index', ['tenant_id' => $number->tenant_id]), 'بررسی شماره');
        }
        foreach (BillingFulfillmentRequest::query()->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))->where('status', 'open')->lazyById(100) as $request) {
            $add('request', $request->id, $request->tenant_id, $request->choice === 'refund' ? 'درخواست بررسی بازپرداخت' : 'درخواست شماره جایگزین', 'سفارش '.$request->commerce_order_id, $request->created_at, route('admin.work.show', ['kind' => 'request', 'id' => $request->id]), 'بررسی و ثبت نتیجه', 1);
        }
        foreach (NumberTerm::query()->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))->where('status', 'active')->where('release_review_at', '<=', now())->whereHas('assignment', fn ($q) => $q->whereNull('released_at'))->with('assignment.sipNumber')->lazyById(100) as $term) {
            if ($term->assignment?->sipNumber) {
                $add('release', $term->id, $term->tenant_id, 'شماره نیازمند تصمیم آزادسازی', $term->assignment->sipNumber->normalized_number, $term->release_review_at, route('admin.numbers.cancellation', $term->assignment->sip_number_id), 'بررسی آزادسازی', 1);
            }
        }
        $tenants = Tenant::query()->with('customerOwner')->whereIn('id', $rows->pluck('tenant'))->get()->keyBy('id');

        return $rows->map(function ($row) use ($tenants) {
            $tenant = $tenants->get($row['tenant']);

            return $row + ['business' => $tenant?->name ?? 'حساب نامشخص', 'customer_id' => $tenant?->owner_customer_id,
                'customer' => $tenant?->customerOwner?->name ?? '—'];
        })->sort(fn ($a, $b) => [$a['priority'], $a['date']?->timestamp ?? 0] <=> [$b['priority'], $b['date']?->timestamp ?? 0])->values();
    }
}
