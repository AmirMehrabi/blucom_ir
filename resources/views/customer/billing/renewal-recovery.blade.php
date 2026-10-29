@if($subscription)
@php($renewalDue = $subscription->period_ends_at?->lte(now()))
@php($suspended = !$subscription->grace_ends_at?->isFuture() || $subscription->status !== 'active' || $subscription->cancel_at?->isPast())
<section class="panel {{ $renewalDue || $suspended ? 'border-amber-200' : 'border-blue-200' }} p-6" aria-labelledby="renewal-heading">
    <h2 id="renewal-heading" class="text-lg font-bold">{{ $suspended ? 'بازفعال‌سازی اشتراک و تماس‌ها' : ($renewalDue ? 'اشتراک در مهلت پرداخت است' : 'پرداخت ماهانه بعدی') }}</h2>
    <p class="mt-2 text-sm text-slate-500">پلن {{ $subscription->planVersion->plan->name }} · ماهانه</p>
    <p class="mt-3 text-xl font-black">{{ \App\Services\Commerce\CustomerCommercePresenter::money($nextInvoice?->kind === 'plan_renewal' ? $nextInvoice->total_amount : $subscription->monthly_amount) }} تومان</p>
    @if($suspended)
        <p class="mt-3 text-sm leading-7 text-slate-600">تماس‌ها متوقف شده‌اند. با پرداخت یک ماه، اشتراک دوباره فعال می‌شود؛ برای ماه‌های غیرفعال هزینه‌ای اضافه نمی‌شود. شماره‌های خرید یک‌باره و تنظیمات شما محفوظ‌اند.</p>
    @elseif($renewalDue)
        <p class="mt-3 text-sm leading-7 text-amber-900">برای جلوگیری از توقف تماس‌ها، تا {{ \App\Services\Commerce\CustomerCommercePresenter::date($subscription->grace_ends_at) }} پرداخت را تکمیل کنید.</p>
    @else
        <p class="mt-3 text-sm leading-7 text-slate-600">سررسید بعدی: {{ $subscription->period_ends_at ? \App\Services\Commerce\CustomerCommercePresenter::date($subscription->period_ends_at) : 'پس از آماده‌شدن خط' }}. @if($subscription->cancel_at)تمدید این اشتراک لغو شده است.@elseif($subscription->wallet_auto_renew)مبلغ در سررسید از کیف پول پرداخت می‌شود.@elseتمدید خودکار خاموش است؛ برای ادامه خدمات باید پرداخت را انجام دهید.@endif</p>
    @endif
    @if($nextInvoice?->kind === 'plan_renewal' && $nextAction['pending'])
        <p role="status" class="mt-4 rounded-xl bg-amber-50 p-4 text-sm leading-7 text-amber-900">نتیجه پرداخت در حال بررسی است؛ پرداخت دوباره لازم نیست.</p>
        <a href="{{ $nextAction['url'] }}" class="commerce-secondary mt-4">پیگیری پرداخت در حال بررسی</a>
    @elseif(!$subscription->cancel_at || $subscription->cancel_at->isPast())
        @if($walletShortfall > 0)<p class="mt-3 text-sm leading-7">موجودی موردنیاز: <strong>{{ \App\Services\Commerce\CustomerCommercePresenter::money($walletShortfall) }} تومان</strong>. مبلغ دقیق پیش از پرداخت نمایش داده می‌شود.</p>@else<p class="mt-3 text-sm leading-7 text-emerald-800">موجودی کیف پول برای این پرداخت کافی است.</p>@endif
        @if($nextInvoice?->kind === 'plan_renewal')
            <a class="commerce-button mt-4" href="{{ $nextAction['url'] }}">{{ $suspended ? 'بررسی و بازفعال‌سازی' : 'بررسی و تکمیل تمدید' }}</a>
        @elseif(($renewalDue || $suspended) && auth('customer')->user()->hasPermission('billing.manage'))
            <form method="POST" action="{{ route('customer.wallet.renew') }}" class="mt-4">@csrf<button class="commerce-button">بررسی مبلغ و بازفعال‌سازی</button></form>
        @endif
    @endif
    <a class="commerce-link mt-4 block text-sm" href="{{ route('customer.billing.index', ['tab'=>'subscription']) }}">مدیریت پلن و تمدید خودکار</a>
</section>
@endif
