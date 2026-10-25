<?php

namespace App\Services\Portal;

use App\Models\CommerceOrder;
use App\Models\Customer;
use App\Services\Commerce\CustomerCommercePresenter;
use App\Support\Permissions;

class CustomerJourney
{
    public function summary(Customer $customer): array
    {
        $canLines = $customer->hasPermission(Permissions::LINES_VIEW);
        $canBilling = $customer->hasPermission(Permissions::BILLING_VIEW);
        $lines = $canLines ? $customer->tenant->sipNumbers()->where('status', 'assigned')->with('inboundRoute')->get() : collect();
        $unfinished = $lines->first(fn ($line) => ! $line->inboundRoute?->enabled);
        $pending = $canBilling ? CommerceOrder::query()->where('tenant_id', $customer->tenant_id)
            ->whereIn('status', ['reserved', 'paid_pending_allocation', 'paid_unfulfilled', 'reconciliation_required'])
            ->where(fn ($q) => $q->where('status', '!=', 'reserved')->orWhere('expires_at', '>', now()))->latest('id')->first() : null;
        $next = $unfinished && $customer->hasPermission(Permissions::PHONES_MANAGE)
            ? ['title' => 'پاسخ‌گوی خط را انتخاب کنید', 'description' => 'شماره به حساب شما اضافه شده است. مشخص کنید تماس‌ها به چه کسی برسند.', 'label' => 'ادامه تنظیم خط', 'url' => route('customer.lines.show', $unfinished->id)]
            : ($pending ? ['title' => 'خرید ناتمام خود را پیگیری کنید', 'description' => 'وضعیت همین سفارش را بررسی کنید؛ برای پرداخت در حال بررسی دوباره پرداخت نکنید.', 'label' => 'مشاهده سفارش', 'url' => route('customer.orders.show', $pending->public_id)]
                : ['title' => $lines->isEmpty() ? 'اولین خط کسب‌وکارتان را راه‌اندازی کنید' : 'خط‌های شما در یک جا', 'description' => $lines->isEmpty() ? 'شماره جدید بخرید یا شماره‌ای را که دارید وصل کنید.' : 'تنظیمات ذخیره‌شده را ببینید، راهنمای اتصال تلفن را باز کنید و تماس را بررسی کنید.', 'label' => $lines->isEmpty() ? 'افزودن خط' : 'مدیریت خط‌ها', 'url' => route($lines->isEmpty() ? 'customer.start' : 'customer.lines.index')]);

        return ['next' => $next, 'hasLines' => $lines->isNotEmpty(), 'canLines' => $canLines,
            'canBuy' => config('commerce.catalog_enabled') && app(CustomerCommercePresenter::class)->canPurchase($customer),
            'canConnect' => $canLines && $customer->hasPermission(Permissions::PHONES_MANAGE)];
    }
}
