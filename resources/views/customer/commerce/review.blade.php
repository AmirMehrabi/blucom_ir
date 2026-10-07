@extends('layouts.portal')
@section('title', 'بررسی خرید شماره')
@section('content')
<div data-commerce-page>
    @if($isTest)@include('customer.commerce.partials.test-payment')@endif
    <a class="commerce-link mb-5 inline-flex min-h-11 items-center gap-2 font-bold" href="{{ route('customer.numbers.index') }}"><span aria-hidden="true">→</span> انتخاب شمارهٔ دیگر</a>
    @include('customer.commerce.partials.steps', ['step'=>2])
    <h1 class="text-2xl font-black sm:text-3xl">جزئیات خرید را بررسی کنید</h1><p class="mt-3 leading-8 text-slate-600">پیش از رزرو، شماره و مبلغ پلن را بررسی و تأیید کنید.</p>
    <div class="mt-7 grid items-start gap-6 xl:grid-cols-[minmax(0,1fr)_360px]">
        <div class="space-y-6">
            <section class="panel p-6 sm:p-8"><p class="text-sm text-slate-500">شمارهٔ انتخاب‌شده</p><h2 class="mt-3 break-all text-3xl font-black"><bdi dir="ltr">{{ $selection['number'] }}</bdi></h2><h3 class="mb-6 mt-5 text-lg font-bold">{{ $selection['planName'] }}</h3>@include('customer.commerce.partials.features')</section>
            <section class="rounded-2xl border border-blue-100 bg-blue-50 p-5 leading-8 text-blue-950"><h2 class="font-bold">پس از ثبت سفارش چه می‌شود؟</h2><p class="mt-2">شماره به مدت {{ $minutes }} دقیقه برای شما رزرو می‌شود تا پرداخت را انجام دهید. {{ $isTest ? 'پس از پرداخت آزمایشی موفق، مبلغ سفارش ثبت می‌شود و شماره و اشتراک به حساب شما اضافه می‌شوند. برای فعال‌سازی، پاسخ‌گوی خط را تنظیم کنید.' : 'پس از پرداخت، سفارش برای بررسی و آماده‌سازی ثبت می‌شود؛ خط تا آماده‌شدن، امکان برقراری تماس ندارد.' }}</p></section>
        </div>
        <aside class="panel p-6 xl:sticky xl:top-6" aria-label="خلاصهٔ خرید">
            <h2 class="text-lg font-bold">خلاصهٔ خرید</h2><p class="mt-4 leading-8 text-slate-600">مبلغ این سفارش شامل شماره و امکانات پلن انتخاب‌شده است.</p><div class="mt-5 border-y py-5"><p class="text-sm text-slate-500">مبلغ قابل پرداخت برای یک ماه</p><p class="mt-2 text-3xl font-black">{{ $selection['amount'] }} <span class="text-base font-normal">تومان</span></p></div>
            <form method="POST" action="{{ route('customer.orders.reserve') }}" class="mt-5" data-commerce-submit>@csrf
                @foreach(['offer_id', 'plan_version_id', 'monthly_amount', 'currency'] as $field)<input type="hidden" name="{{ $field }}" value="{{ $quote[$field] }}">@endforeach
                <input type="hidden" name="idempotency_key" value="{{ $key }}">
                <label class="flex cursor-pointer items-start gap-3 leading-8"><input type="checkbox" name="confirmed" value="1" required class="mt-2 size-5 shrink-0 accent-blue-600">مبلغ و جزئیات سفارش را بررسی کردم.</label>
                <button class="commerce-button mt-5 w-full" @disabled(!$canReserve) data-busy-label="در حال ثبت سفارش…">رزرو و ادامهٔ خرید</button>
                <p hidden class="mt-3 text-sm leading-7 text-slate-600" data-submit-message role="status">لطفاً منتظر بمانید؛ سفارش شما در حال ثبت است.</p>
            </form>
            @unless($canReserve)<p class="mt-4 text-sm leading-7 text-amber-900">تکمیل خرید در حال حاضر برای این حساب در دسترس نیست. برای پیگیری، با پشتیبانی یا مدیر حساب خود تماس بگیرید.</p>@endunless
            <p class="mt-5 text-center text-sm text-slate-500">{{ $isTest ? 'پرداخت آزمایشی با ' : 'پرداخت با ' }}{{ $paymentProviderName }}</p>
        </aside>
    </div>
</div>
@endsection
