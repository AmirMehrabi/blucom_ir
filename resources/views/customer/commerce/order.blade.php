@extends('layouts.portal')
@section('title', 'جزئیات سفارش')
@section('content')
<div data-commerce-page>
    <a class="commerce-link mb-5 inline-flex min-h-11 items-center gap-2 font-bold" href="{{ route('customer.orders.index') }}"><span aria-hidden="true">→</span> همهٔ سفارش‌های من</a>
    <div class="flex flex-wrap items-center justify-between gap-3"><div><h1 class="text-2xl font-black sm:text-3xl">سفارش {{ $order['reference'] }}</h1><span class="commerce-status commerce-status-{{ $order['tone'] }} mt-3 inline-block border-0 px-3 py-1 text-sm font-bold">{{ $order['badge'] }}</span></div><a class="commerce-secondary" href="{{ route('customer.orders.show', $order['publicId']) }}">به‌روزرسانی وضعیت</a></div>
    <section class="commerce-status commerce-status-{{ $order['tone'] }} mt-6" aria-labelledby="order-status"><h2 id="order-status" class="text-lg font-bold">{{ $order['title'] }}</h2><p class="mt-2">{{ $order['description'] }}</p></section>
    <div class="mt-6 grid items-start gap-6 xl:grid-cols-[minmax(0,1fr)_360px]">
        <section class="panel p-6 sm:p-8"><p class="text-sm text-slate-500">شمارهٔ انتخاب‌شده</p><h2 class="mt-3 break-all text-3xl font-black"><bdi dir="ltr">{{ $order['number'] }}</bdi></h2><h3 class="mb-6 mt-5 text-lg font-bold">{{ $order['planName'] }}</h3>@include('customer.commerce.partials.features', ['selection'=>$order])
            <dl class="mt-6 space-y-4 border-t pt-5 text-sm"><div><dt class="text-slate-500">نام خریدار</dt><dd class="mt-1 break-words font-bold">{{ $order['buyer'] }}</dd></div><div><dt class="text-slate-500">کسب‌وکار</dt><dd class="mt-1 break-words font-bold">{{ $order['business'] }}</dd></div><div><dt class="text-slate-500">شمارهٔ پیش‌فاکتور</dt><dd class="mt-1 break-all"><bdi dir="ltr">{{ $order['invoiceNumber'] }}</bdi></dd></div><div><dt class="text-slate-500">زمان ثبت سفارش</dt><dd class="mt-1">{{ $order['issued'] }}</dd></div>@if($order['paidAt'])<div><dt class="text-slate-500">زمان تأیید پرداخت</dt><dd class="mt-1">{{ $order['paidAt'] }}</dd></div>@endif</dl>
        </section>
        <aside class="panel p-6 xl:sticky xl:top-6" aria-label="پرداخت سفارش"><h2 class="text-lg font-bold">{{ $order['paid'] ? 'خلاصهٔ پرداخت' : 'پرداخت سفارش' }}</h2><p class="mt-5 text-sm text-slate-500">{{ $order['paid'] ? 'مبلغ پرداخت‌شده' : 'مبلغ قابل پرداخت' }}</p><p class="mt-2 text-3xl font-black">{{ $order['amount'] }} <span class="text-base font-normal">تومان</span></p>
            @if($order['live'] && !$order['paid'])<div class="mt-5 rounded-xl bg-slate-50 p-4 text-sm leading-7" data-commerce-expiry="{{ $order['expiryIso'] }}" data-server-now="{{ $order['nowIso'] }}"><p class="font-bold">زمان باقی‌مانده: <span data-countdown>{{ $order['remaining'] }} دقیقه</span></p><p class="mt-1 text-slate-600">رزرو تا {{ $order['expires'] }} معتبر است.</p><p hidden data-expiry-message class="mt-2 text-amber-900" role="status">مهلت رزرو پایان یافت. پیش از پرداخت، وضعیت سفارش را دوباره بررسی کنید.</p></div>@endif
            @if($order['setupUrl'])<a class="commerce-button mt-5 w-full" href="{{ $order['setupUrl'] }}">تنظیم خط و اتصال تلفن</a>
            @elseif($order['canStart'])<form class="mt-5" method="POST" action="{{ route('customer.payments.initiate', $order['invoiceId']) }}" data-commerce-submit>@csrf<input type="hidden" name="idempotency_key" value="{{ $key }}"><button class="commerce-button w-full" data-busy-label="در حال اتصال به درگاه…" data-expiry-action>پرداخت با {{ $order['paymentProviderName'] }}</button><p hidden data-submit-message role="status" class="mt-3 text-sm leading-7 text-slate-600">لطفاً منتظر بمانید؛ در حال آماده‌کردن صفحهٔ پرداخت هستیم.</p></form>
            @elseif($order['canContinue'])<a class="commerce-button mt-5 w-full" href="{{ $order['continueUrl'] }}" data-expiry-action>ادامهٔ پرداخت با {{ $order['paymentProviderName'] }}</a>
            @elseif(!$order['paid'] && $order['live'] && $order['badge'] !== 'در حال بررسی')<p class="mt-5 text-sm leading-7 text-amber-900">پرداخت آنلاین در حال حاضر برای این سفارش در دسترس نیست. کمی بعد دوباره بررسی کنید یا با پشتیبانی تماس بگیرید.</p>@endif
            <p class="mt-5 text-sm leading-7 text-slate-500">{{ $order['paid'] ? 'پرداخت به‌تنهایی به معنای آماده‌بودن خط برای تماس نیست.' : 'مبلغ این سفارش شامل شماره و امکانات پلن برای یک ماه است.' }}</p>
            <a class="commerce-link mt-5 inline-flex min-h-11 items-center text-sm font-bold" href="{{ route('contact') }}">تماس با پشتیبانی</a>
        </aside>
    </div>
</div>
@endsection
