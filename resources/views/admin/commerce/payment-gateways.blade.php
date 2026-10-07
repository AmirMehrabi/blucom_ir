@extends('layouts.portal')
@section('title', 'درگاه‌های پرداخت')
@section('content')
<div class="flex flex-wrap items-start justify-between gap-4">
    <div><h1 class="text-2xl font-black">درگاه‌های پرداخت</h1><p class="mt-2 text-sm leading-7 text-slate-500">اتصال پرداخت مشتریان و مشخصات پذیرنده را مدیریت کنید.</p></div>
    <a href="{{ route('admin.payments.index') }}" class="rounded-xl border bg-white px-4 py-3 text-sm font-bold text-blue-700">بررسی پرداخت‌ها</a>
</div>
@if (!config('commerce.checkout_enabled'))
    <p class="mt-5 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">خرید عمومی هنوز فعال نیست. ذخیره و فعال‌سازی درگاه، خرید شماره و فعال‌سازی خط را به‌تنهایی فعال نمی‌کند.</p>
@endif
@foreach ($gateways as $gateway)
<section class="panel mt-6 max-w-4xl p-5 sm:p-7">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div><h2 class="text-lg font-black">به‌پرداخت ملت</h2><p class="mt-1 text-sm text-slate-500">پرداخت امن از طریق درگاه بانک ملت</p></div>
        <span class="rounded-full px-3 py-1.5 text-xs font-bold {{ $gateway['enabled'] ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600' }}">{{ $gateway['enabled'] ? 'فعال' : 'غیرفعال' }}</span>
    </div>
    <form method="POST" action="{{ route('admin.payment-gateways.update', $gateway['provider']) }}" class="mt-6 space-y-6">
        @csrf @method('PUT')
        <input type="hidden" name="revision" value="{{ $gateway['revision'] }}">
        <input type="hidden" name="enabled" value="0">
        <label class="flex items-center gap-3 rounded-xl bg-slate-50 p-4 text-sm font-bold"><input type="checkbox" name="enabled" value="1" @checked(old('enabled', $gateway['enabled'])) class="size-5 accent-blue-600">فعال برای پرداخت‌های جدید</label>
        <div><h3 class="font-bold">مشخصات پذیرنده</h3><p class="mt-2 text-sm leading-7 text-slate-500">{{ $gateway['configured'] ? 'مشخصات ذخیره شده است. برای نگه‌داشتن هر مقدار، فیلد آن را خالی بگذارید.' : 'شناسه ترمینال، نام کاربری و رمز ارائه‌شده توسط به‌پرداخت را وارد کنید.' }} مقادیر ذخیره‌شده نمایش داده نمی‌شوند.</p></div>
        <div class="grid gap-5 sm:grid-cols-2">
            <label class="text-sm font-bold" for="merchant-terminal">شناسه ترمینال<input id="merchant-terminal" name="merchant_terminal_id" type="text" inputmode="numeric" pattern="[1-9][0-9]{0,17}" maxlength="18" autocomplete="off" dir="ltr" class="mt-2 w-full rounded-xl border p-3 font-normal focus-visible:outline-2 focus-visible:outline-blue-600"></label>
            <label class="text-sm font-bold" for="merchant-username">نام کاربری پذیرنده<input id="merchant-username" name="merchant_username" type="text" maxlength="255" autocomplete="off" dir="ltr" class="mt-2 w-full rounded-xl border p-3 font-normal focus-visible:outline-2 focus-visible:outline-blue-600"></label>
            <label class="text-sm font-bold sm:col-span-2" for="merchant-password">رمز پذیرنده<input id="merchant-password" name="merchant_password" type="password" maxlength="500" autocomplete="new-password" dir="ltr" class="mt-2 w-full rounded-xl border p-3 font-normal focus-visible:outline-2 focus-visible:outline-blue-600"></label>
        </div>
        <div class="rounded-xl border p-4">
            <h3 class="text-sm font-bold">واحد مبلغ درگاه: ریال</h3><p class="mt-2 text-sm leading-7 text-slate-500">قیمت مشتری به تومان است؛ مبلغ ارسالی به بانک یک‌بار در ۱۰ ضرب می‌شود.</p>
            <input type="hidden" name="amount_unit_confirmed" value="0">
            <label class="mt-3 flex items-start gap-3 text-sm leading-7"><input type="checkbox" name="amount_unit_confirmed" value="1" @checked(old('amount_unit_confirmed', $gateway['amount_unit_confirmed'])) class="mt-1 size-5 shrink-0 accent-blue-600">طبق قرارداد پذیرنده، مبلغ ورودی این درگاه به ریال است.</label>
        </div>
        <div class="text-sm text-slate-500"><p class="font-bold text-slate-700">دامنه بازگشت پرداخت</p><p dir="ltr" class="mt-2 break-all text-right">https://{{ config('portal.customer_domain') }}/payments/mellat/callback/…</p><p class="mt-2 leading-7">غیرفعال‌کردن درگاه، بررسی پرداخت‌هایی را که قبلاً شروع شده‌اند متوقف نمی‌کند.</p></div>
        <button class="min-h-11 rounded-xl bg-blue-600 px-6 py-3 text-sm font-bold text-white hover:bg-blue-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600">ذخیره تنظیمات</button>
    </form>
</section>
@endforeach
@endsection
