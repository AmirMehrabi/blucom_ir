@extends('layouts.customer')
@section('title', 'شماره من')
@section('content')
<div class="mx-auto max-w-3xl">
    <h1 class="text-2xl font-extrabold text-[#071a3b]">{{ $editing ? 'درخواست شماره را اصلاح کنید' : 'شماره خود را اضافه کنید' }}</h1>
    <p class="mt-2 text-slate-600">شماره‌ای را وارد کنید که از ارائه‌دهنده خود گرفته‌اید. هر شماره به یکی از اتصال‌های شما پیوند می‌خورد.</p>
    <form method="POST" action="{{ $editing ? route('customer.setup.numbers.update', $editing) : route('customer.setup.numbers.store') }}" class="panel mt-7 overflow-hidden">
        @csrf
        @if ($editing) @method('PUT') @endif
        <div class="space-y-5 p-6">
            <label class="block text-sm font-bold text-slate-700">شماره خط
                <input name="number" value="{{ old('number', $editing?->number) }}" required maxlength="32" inputmode="tel" dir="ltr" placeholder="مثال: 02191093464" class="mt-2 w-full rounded-xl border border-slate-200 px-4 py-3 text-left text-base font-normal focus:border-blue-500 focus:outline-none" />
                <span class="mt-2 block text-xs font-normal text-slate-500">شماره را همان‌طور که ارائه‌دهنده به شما داده است وارد کنید.</span>
            </label>
            <label class="block text-sm font-bold text-slate-700">این شماره به کدام ارائه‌دهنده متصل است؟
                <select name="gateway_id" required class="mt-2 w-full rounded-xl border border-slate-200 bg-white px-4 py-3 font-normal focus:border-blue-500 focus:outline-none">
                    <option value="">انتخاب اتصال</option>
                    @foreach ($gateways as $gateway)
                        <option value="{{ $gateway->id }}" @selected(old('gateway_id', $editing?->provider_gateway_id) == $gateway->id)>{{ $gateway->display_name }} — {{ $gateway->provider_name }}</option>
                    @endforeach
                </select>
            </label>
            <p class="rounded-xl bg-amber-50 p-4 text-sm leading-7 text-amber-900">برای جلوگیری از اتصال اشتباه، مالکیت و تنظیمات شماره بررسی می‌شود. تا زمان تأیید، تماس‌های این شماره فعال نیستند.</p>
        </div>
        <div class="flex flex-wrap justify-between gap-3 border-t border-slate-100 bg-slate-50 p-5">
            @if (auth()->user()->hasPermission('providers.manage'))<a href="{{ route('customer.setup.provider') }}" class="rounded-xl px-4 py-3 text-sm font-bold text-slate-600">بازگشت به ارائه‌دهنده</a>@endif
            <button class="rounded-xl bg-blue-600 px-6 py-3 text-sm font-bold text-white hover:bg-blue-700">{{ $editing ? 'ارسال دوباره برای بررسی' : 'ذخیره شماره و ادامه' }}</button>
        </div>
    </form>

    @if ($numbers->isNotEmpty())
        <section class="mt-8">
            <div class="mb-3 flex items-center justify-between"><h2 class="font-bold">شماره‌های ثبت‌شده</h2>@if (auth()->user()->hasPermission('lines.view'))<a class="text-sm font-bold text-blue-700" href="{{ route('customer.setup.lines') }}">دیدن همه</a>@endif</div>
            <div class="space-y-3">
                @foreach ($numbers as $number)
                    <div class="panel flex flex-wrap items-center justify-between gap-3 p-4">
                        <div><strong dir="ltr" class="block">{{ $number->number }}</strong><span class="text-xs text-slate-500">{{ $number->providerGateway?->display_name }}</span></div>
                        <div class="flex items-center gap-4"><span class="text-xs font-bold {{ $number->statusBadgeClass() }}">{{ $number->statusLabel() }}</span>@if (in_array($number->status, ['pending', 'disabled']))<a href="{{ route('customer.setup.numbers.edit', $number) }}" class="text-sm font-bold text-blue-700">ویرایش درخواست</a>@endif @if (in_array($number->status, ['pending', 'assigned']) && auth()->user()->hasPermission('phones.manage'))<a href="{{ route('customer.setup.answer', $number) }}" class="text-sm font-bold text-blue-700">تعیین پاسخ‌گو</a>@endif</div>
                    </div>
                @endforeach
            </div>
        </section>
    @endif
</div>
@endsection
