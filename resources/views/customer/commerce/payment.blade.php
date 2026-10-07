@extends('layouts.portal')
@section('title', 'پرداخت سفارش')
@section('content')
<div data-commerce-page>
    @if($order['isTest'])@include('customer.commerce.partials.test-payment')@endif
    <a class="commerce-link mb-5 inline-flex min-h-11 items-center gap-2 font-bold" href="{{ route('customer.orders.show', $order['publicId']) }}"><span aria-hidden="true">→</span> جزئیات سفارش</a>
    @include('customer.commerce.partials.steps', ['step'=>3])
    <section class="panel mx-auto max-w-2xl p-6 sm:p-9">
        @if($canPay)
            <span aria-hidden="true" class="mb-5 grid size-12 place-items-center rounded-2xl bg-blue-50 text-blue-700"><svg class="size-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path stroke-linecap="round" stroke-linejoin="round" d="M4 7h16v14H4z M8 7V5a4 4 0 0 1 8 0v2 M12 12v4"/></svg></span>
            <h1 class="text-2xl font-black">پرداخت امن با {{ $provider === 'zibal' ? 'زیبال' : 'بانک ملت' }}</h1><p class="mt-3 leading-8 text-slate-600">برای تکمیل خرید این شماره، به درگاه {{ $provider === 'zibal' ? 'زیبال' : 'به‌پرداخت ملت' }} بروید.</p>
        @else
            <h1 class="text-2xl font-black">{{ $order['title'] }}</h1><p class="mt-3 leading-8 text-slate-600">{{ $order['description'] }}</p>
        @endif
        <div class="my-6 rounded-2xl bg-slate-50 p-5"><p class="text-sm text-slate-500">شمارهٔ سفارش</p><p class="mt-2 text-xl font-black"><bdi dir="ltr">{{ $order['number'] }}</bdi></p><div class="mt-4 flex flex-wrap items-center justify-between gap-3 border-t pt-4"><span class="text-slate-600">مبلغ قابل پرداخت</span><p class="text-2xl font-black">{{ $order['amount'] }} <span class="text-base font-normal">تومان</span></p></div></div>
        @if($canPay)
            <div class="mb-5 text-sm leading-7 text-slate-600" data-commerce-expiry="{{ $order['expiryIso'] }}" data-server-now="{{ $order['nowIso'] }}"><p>زمان باقی‌ماندهٔ رزرو: <strong data-countdown>{{ $order['remaining'] }} دقیقه</strong></p><p hidden data-expiry-message role="status" class="mt-2 text-amber-900">مهلت رزرو پایان یافت. وضعیت سفارش را پیش از پرداخت دوباره بررسی کنید.</p></div>
            @if($provider === 'zibal')
                <a class="commerce-button w-full" href="{{ $bankUrl }}" data-expiry-action>ورود به درگاه زیبال <span aria-hidden="true">←</span></a>
            @else
                <form method="POST" action="{{ $bankUrl }}" data-commerce-submit><input type="hidden" name="RefId" value="{{ $refId }}"><button class="commerce-button w-full" data-busy-label="در حال انتقال به بانک…" data-expiry-action>ورود به درگاه بانک ملت <span aria-hidden="true">←</span></button><p hidden data-submit-message role="status" class="mt-3 text-sm leading-7 text-slate-600">در حال انتقال به صفحهٔ امن بانک هستید؛ لطفاً منتظر بمانید.</p></form>
            @endif
            <p class="mt-5 text-sm leading-7 text-slate-500">اطلاعات کارت را فقط در صفحهٔ درگاه بانک وارد کنید. پس از پرداخت، برای پیگیری سفارش به بلوکام بازمی‌گردید.</p>
        @else
            <a class="commerce-button w-full" href="{{ route('customer.orders.show', $order['publicId']) }}">مشاهده و پیگیری سفارش</a>
        @endif
    </section>
</div>
@endsection
