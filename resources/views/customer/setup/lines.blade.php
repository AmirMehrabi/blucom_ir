@extends('layouts.customer')
@section('title', 'خط‌های من')
@section('content')
<div class="flex flex-wrap items-end justify-between gap-4">
    <div><h1 class="text-2xl font-extrabold text-[#071a3b]">خط‌ها</h1><p class="mt-2 text-slate-600">شماره‌ها، ارائه‌دهندگان و پاسخ‌گوها را یک‌جا ببینید.</p></div>
    <div class="flex flex-wrap gap-2">@if (auth()->user()->hasPermission('providers.manage'))<a href="{{ route('customer.setup.provider') }}" class="rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm font-bold text-slate-700">افزودن ارائه‌دهنده</a>@endif @if (auth()->user()->hasPermission('numbers.manage'))<a href="{{ route('customer.setup.number') }}" class="rounded-xl bg-blue-600 px-4 py-3 text-sm font-bold text-white">افزودن شماره</a>@endif</div>
</div>

<p class="mt-6 rounded-xl border border-blue-100 bg-blue-50 p-4 text-sm leading-7 text-blue-900">وضعیت‌های این صفحه نتیجه بررسی اطلاعات هستند. برای اطمینان از آماده بودن تماس، بعد از تنظیم تلفن یک تماس آزمایشی انجام دهید.</p>
<section class="mt-5 grid gap-4 md:grid-cols-2">
    @forelse ($numbers as $number)
        <article class="panel p-5">
            @php($detailsApproved = $number->status === 'assigned' && $number->providerGateway?->verification_status === 'approved')
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div><h2 class="text-lg font-extrabold" dir="ltr">{{ $number->number }}</h2><p class="mt-1 text-xs text-slate-500">{{ $number->providerGateway?->display_name ?: 'ارائه‌دهنده نامشخص' }}</p></div>
                <span class="rounded-full px-3 py-1 text-xs font-bold {{ $detailsApproved ? 'bg-emerald-50 text-emerald-700' : ($number->status === 'disabled' ? 'bg-slate-100 text-slate-600' : 'bg-amber-50 text-amber-700') }}">{{ $detailsApproved ? 'اطلاعات تأیید شد' : ($number->status === 'assigned' ? 'اتصال ارائه‌دهنده نیازمند بررسی' : $number->statusLabel()) }}</span>
            </div>
            <div class="mt-5 border-t border-slate-100 pt-4 text-sm text-slate-600">پاسخ‌گو: <strong class="text-slate-900">{{ $number->inboundRoute?->destinationLabel() ?? 'هنوز انتخاب نشده' }}</strong></div>
            <div class="mt-4 flex gap-4 text-sm font-bold">@if ($number->status === 'disabled' && auth()->user()->hasPermission('numbers.manage'))<a class="text-blue-700" href="{{ route('customer.setup.numbers.edit', $number) }}">اصلاح درخواست</a>@elseif ($number->status !== 'disabled' && auth()->user()->hasPermission('phones.manage'))<a class="text-blue-700" href="{{ route('customer.setup.answer', $number) }}">{{ $number->inboundRoute ? 'تغییر پاسخ‌گو' : 'تعیین پاسخ‌گو' }}</a>@endif @if ($number->inboundRoute?->destination instanceof \App\Models\SipExtension && auth()->user()->hasPermission('phones.manage'))<a class="text-blue-700" href="{{ route('customer.setup.phone', $number->inboundRoute->destination) }}">تنظیم تلفن</a>@endif</div>
        </article>
    @empty
        <div class="panel p-8 text-center md:col-span-2"><p class="font-bold">هنوز شماره‌ای ثبت نشده است.</p><p class="mt-2 text-sm text-slate-500">ابتدا ارائه‌دهنده خط را وصل کنید و سپس شماره را وارد کنید.</p>@if (auth()->user()->hasPermission('providers.manage'))<a class="mt-5 inline-block rounded-xl bg-blue-600 px-5 py-3 text-sm font-bold text-white" href="{{ route('customer.setup.provider') }}">شروع راه‌اندازی</a>@endif</div>
    @endforelse
</section>

@if ($gateways->isNotEmpty())
<section class="mt-10"><h2 class="mb-4 text-lg font-bold">ارائه‌دهندگان من</h2><div class="grid gap-3 md:grid-cols-2">@foreach ($gateways as $gateway)<div class="panel flex items-center justify-between gap-4 p-4"><div><p class="font-semibold">{{ $gateway->display_name ?: $gateway->name }}</p><p class="text-xs text-slate-500">{{ $gateway->provider_name }}</p></div><span class="text-xs font-bold {{ $gateway->verification_status === 'approved' ? 'text-emerald-700' : 'text-amber-700' }}">{{ $gateway->verification_status === 'approved' ? 'اطلاعات تأیید شد' : ($gateway->verification_status === 'rejected' ? 'نیازمند اصلاح' : 'در انتظار بررسی') }}</span></div>@endforeach</div></section>
@endif
@endsection
