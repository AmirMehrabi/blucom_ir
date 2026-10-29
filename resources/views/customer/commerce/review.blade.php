@extends('layouts.portal')
@section('title', 'بررسی خرید شماره')
@section('content')
<div data-commerce-page>
    @if($isTest)@include('customer.commerce.partials.test-payment')@endif
    <a class="commerce-link mb-5 inline-flex min-h-11 items-center gap-2 font-bold" href="{{ route('customer.numbers.index') }}"><span aria-hidden="true">→</span> انتخاب شمارهٔ دیگر</a>
    @include('customer.commerce.partials.steps', ['step'=>2])
    <h1 class="text-2xl font-black sm:text-3xl">جزئیات خرید را بررسی کنید</h1><p class="mt-3 leading-8 text-slate-600">پیش از رزرو، شماره و مبلغ قابل پرداخت امروز را بررسی و تأیید کنید.</p>
    <div class="mt-7 grid items-start gap-6 xl:grid-cols-[minmax(0,1fr)_360px]">
        <div class="space-y-6">
            @if($planChoices->isNotEmpty())<form method="GET" action="{{ route('customer.numbers.review', $offerId) }}" class="panel p-6"><label for="plan-choice" class="text-sm font-bold">پلن ماهانه خود را انتخاب کنید</label><div class="mt-3 flex flex-wrap gap-3"><select id="plan-choice" name="plan" class="field w-full min-w-0 flex-1" aria-describedby="plan-choice-help">@foreach($planChoices as $planChoice)<option value="{{ $planChoice->id }}" @selected($quote['plan_version_id'] === $planChoice->id)>{{ $planChoice->plan->name }} · {{ number_format($planChoice->monthly_price) }} تومان/ماه · {{ $planChoice->limits['numbers'] ?? 0 }} شماره · {{ $planChoice->limits['extensions'] ?? 0 }} داخلی · {{ $planChoice->limits['queues'] ?? 0 }} تیم · {{ $planChoice->limits['ivr_menus'] ?? 0 }} منو</option>@endforeach</select><button class="commerce-secondary" type="submit">به‌روزرسانی مبلغ</button></div><p id="plan-choice-help" class="mt-3 text-sm text-slate-600">{{ $selection['billingModel'] === 'one_time_number' ? 'هزینه شماره فقط یک‌بار پرداخت می‌شود؛ پلن انتخابی ماهانه تمدید می‌شود.' : 'مبلغ شماره سالانه ثابت است؛ هزینه پلن انتخابی به مجموع پرداخت امروز اضافه می‌شود.' }}</p></form>@endif
            <section class="panel p-6 sm:p-8"><p class="text-sm text-slate-500">شمارهٔ انتخاب‌شده</p><h2 class="mt-3 break-all text-3xl font-black"><bdi dir="ltr">{{ $selection['number'] }}</bdi></h2><h3 class="mb-6 mt-5 text-lg font-bold">{{ $selection['planName'] }}</h3>@include('customer.commerce.partials.features')</section>
            <section class="rounded-2xl border border-blue-100 bg-blue-50 p-5 leading-8 text-blue-950"><h2 class="font-bold">پس از ثبت سفارش چه می‌شود؟</h2><p class="mt-2">شماره به مدت {{ $minutes }} دقیقه برای شما رزرو می‌شود تا پرداخت را انجام دهید. {{ $isTest ? 'پس از پرداخت آزمایشی موفق، مبلغ سفارش ثبت می‌شود و شماره و اشتراک به حساب شما اضافه می‌شوند. برای فعال‌سازی، پاسخ‌گوی خط را تنظیم کنید.' : ($selection['billingModel'] === 'one_time_number' ? 'پس از تأیید پرداخت، همین خرید خودکار تکمیل می‌شود و می‌توانید پاسخ‌گوی خط را تنظیم کنید. اگر رزرو هنگام پرداخت پایان یابد، مبلغ در کیف پول شما محفوظ می‌ماند.' : ($selection['billingModel'] === 'annual_number' ? 'پس از پرداخت، شماره آماده‌سازی می‌شود و دوره‌های پلن و شماره از آماده‌شدن فنی آن آغاز می‌شوند. اگر طی ۲۴ ساعت آماده نشود، می‌توانید بررسی بازپرداخت یا شماره جایگزین بخواهید.' : 'پس از پرداخت، سفارش برای بررسی و آماده‌سازی ثبت می‌شود؛ خط تا آماده‌شدن، امکان برقراری تماس ندارد.')) }}</p></section>
        </div>
        <aside class="panel p-6 xl:sticky xl:top-6" aria-label="خلاصهٔ خرید">
            <h2 class="text-lg font-bold">خلاصهٔ خرید</h2><p class="mt-4 leading-8 text-slate-600">{{ $selection['billingModel'] === 'one_time_number' ? 'شماره را فقط یک‌بار می‌خرید. برای استفاده از خدمات، پلن کسب‌وکار ماهانه پرداخت می‌شود.' : ($selection['billingModel'] === 'annual_number' ? 'شماره سالانه است؛ پلن، در اولین خرید، جداگانه برای یک ماه محاسبه می‌شود.' : 'مبلغ این سفارش شامل شماره و امکانات پلن انتخاب‌شده است.') }}</p><div class="mt-5 border-y py-5">@if($selection['purchaseAmount'])<dl class="space-y-3 text-sm"><div class="flex justify-between gap-3"><dt>خرید یک‌باره شماره</dt><dd class="font-bold">{{ $selection['purchaseAmount'] }} تومان</dd></div>@if($selection['planAmount'] !== '۰')<div class="flex justify-between gap-3"><dt>ماه اول پلن</dt><dd class="font-bold">{{ $selection['planAmount'] }} تومان</dd></div>@endif</dl><p class="mt-4 text-sm text-slate-500">مجموع پرداخت امروز</p>@elseif($selection['annualAmount'])<p class="text-sm">شماره: {{ $selection['annualAmount'] }} تومان / سال</p>@if($selection['planAmount'] !== '۰')<p class="mt-2 text-sm">پلن: {{ $selection['planAmount'] }} تومان / ماه</p>@endif<p class="mt-4 text-sm text-slate-500">مجموع قابل پرداخت امروز</p>@else<p class="text-sm text-slate-500">مبلغ قابل پرداخت برای یک ماه</p>@endif<p class="mt-2 text-3xl font-black">{{ $selection['amount'] }} <span class="text-base font-normal">تومان</span></p></div>
            @if($selection['billingModel'] === 'one_time_number')
                <div class="mt-4 space-y-2 rounded-xl bg-blue-50 p-4 text-sm leading-7 text-blue-950">
                    <p>موجودی کیف پول: <strong>{{ \App\Services\Commerce\CustomerCommercePresenter::money($walletBalance) }} تومان</strong></p>
                    <p>مبلغ شارژ امروز: <strong>{{ \App\Services\Commerce\CustomerCommercePresenter::money(max(0, $quote['monthly_amount'] - $walletBalance)) }} تومان</strong></p>
                    <p>پرداخت ماهانه بعدی: <strong>{{ $selection['nextMonthlyAmount'] }} تومان</strong></p>
                    <p>تمدید ماهانه از موجودی کیف پول انجام می‌شود؛ برداشت خودکار از حساب بانکی نداریم. پیش از تمدید می‌توانید آن را خاموش کنید.</p>
                </div>
            @endif
            <form method="POST" action="{{ route('customer.orders.reserve') }}" class="mt-5" data-commerce-submit>@csrf
                @foreach(['offer_id', 'plan_version_id', 'monthly_amount', 'currency'] as $field)<input type="hidden" name="{{ $field }}" value="{{ $quote[$field] }}">@endforeach
                <input type="hidden" name="idempotency_key" value="{{ $key }}">
                <label class="flex cursor-pointer items-start gap-3 leading-8"><input type="checkbox" name="confirmed" value="1" required class="mt-2 size-5 shrink-0 accent-blue-600">{{ $selection['billingModel'] === 'one_time_number' ? 'خرید یک‌باره شماره، مبلغ امروز و تمدید ماهانه از کیف پول را تأیید می‌کنم.' : 'مبلغ و جزئیات سفارش را بررسی کردم.' }}</label>
                <button class="commerce-button mt-5 w-full" @disabled(!$canReserve) data-busy-label="در حال ثبت سفارش…">{{ $selection['billingModel'] === 'one_time_number' ? (max(0, $quote['monthly_amount'] - $walletBalance) > 0 ? 'شارژ و تکمیل خرید' : 'پرداخت از کیف پول و تکمیل خرید') : 'رزرو و ادامهٔ خرید' }}</button>
                <p hidden class="mt-3 text-sm leading-7 text-slate-600" data-submit-message role="status">لطفاً منتظر بمانید؛ سفارش شما در حال ثبت است.</p>
            </form>
            @if(!$canReserve && $walletFundingIsTest)<p role="note" class="mt-4 text-sm leading-7 text-amber-900">درگاه در حالت آزمایشی است؛ شارژ کیف پول واقعی انجام نمی‌شود. برای این خرید، درگاه واقعی باید فعال باشد.</p>@elseif(!$canReserve)<p class="mt-4 text-sm leading-7 text-amber-900">تکمیل خرید در حال حاضر برای این حساب در دسترس نیست. برای پیگیری، با پشتیبانی یا مدیر حساب خود تماس بگیرید.</p>@endif
            <p class="mt-5 text-center text-sm text-slate-500">{{ $isTest ? 'پرداخت آزمایشی با ' : 'پرداخت با ' }}{{ $paymentProviderName }}</p>
        </aside>
    </div>
</div>
@endsection
