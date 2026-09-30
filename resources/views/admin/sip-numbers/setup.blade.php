@extends('layouts.portal')
@section('title', 'راه‌اندازی شماره')
@section('content')
@php
    $steps = [
        ['label' => 'شماره', 'done' => $numberReady],
        ['label' => 'ارائه‌دهنده', 'done' => $providerReady],
        ['label' => 'تماس ورودی', 'done' => $numberReady && $number->inbound_enabled && $answerReady],
        ['label' => 'تماس خروجی', 'done' => ! $number->outbound_enabled || $outboundReady],
    ];
    $completeSteps = collect($steps)->where('done', true)->count();
    $route = $number->inboundRoute;
@endphp
<div class="mx-auto max-w-5xl space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <a href="{{ route('admin.sip-numbers.index') }}" class="text-xs font-bold text-blue-700">← همه شماره‌ها</a>
            <h1 class="mt-2 text-2xl font-black">راه‌اندازی شماره <span dir="ltr" class="inline-block">{{ $number->normalized_number }}</span></h1>
            <p class="mt-2 text-sm text-slate-500">مالک: بلوکام · برچسب: {{ $number->label ?: 'بدون برچسب' }}</p>
        </div>
        <span class="rounded-full bg-blue-50 px-4 py-2 text-sm font-bold text-blue-800">{{ $completeSteps }} از ۴ مرحله پیکربندی</span>
    </div>
    @if (session('status'))<div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800">{{ session('status') }}</div>@endif
    @if ($errors->any())<div class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">{{ $errors->first() }}</div>@endif

    <nav class="grid gap-2 sm:grid-cols-4" aria-label="مراحل راه‌اندازی شماره">
        @foreach ($steps as $step)
            <div class="rounded-xl border px-4 py-3 text-sm font-bold {{ $step['done'] ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-amber-200 bg-amber-50 text-amber-900' }}">{{ $loop->iteration }}. {{ $step['label'] }} <span class="block pt-1 text-xs font-normal">{{ $step['done'] ? 'پیکربندی شده' : 'نیازمند تنظیم' }}</span></div>
        @endforeach
    </nav>

    <section class="panel p-5 sm:p-6" aria-labelledby="number-step">
        <h2 id="number-step" class="font-black">۱. شماره و مالکیت</h2>
        <p class="mt-2 text-sm text-slate-600">این شماره متعلق به بلوکام است و فقط با تنظیمات همین مالک مسیر‌یابی می‌شود. برای شماره‌های مشتری، از «درخواست‌های بررسی» استفاده کنید.</p>
        <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-3">
            <div class="rounded-xl bg-slate-50 p-3"><dt class="text-slate-500">شماره</dt><dd dir="ltr" class="mt-1 font-bold">{{ $number->normalized_number }}</dd></div>
            <div class="rounded-xl bg-slate-50 p-3"><dt class="text-slate-500">وضعیت</dt><dd class="mt-1 font-bold">{{ $number->statusLabel() }}{{ $number->enabled ? ' · فعال' : ' · غیرفعال' }}</dd></div>
            <div class="rounded-xl bg-slate-50 p-3"><dt class="text-slate-500">دسترسی</dt><dd class="mt-1 font-bold">ورودی {{ $number->inbound_enabled ? 'فعال' : 'غیرفعال' }} · خروجی {{ $number->outbound_enabled ? 'فعال' : 'غیرفعال' }}</dd></div>
        </dl>
        <a href="{{ route('admin.sip-numbers.index') }}" class="mt-4 inline-block text-sm font-bold text-blue-700">ویرایش شماره و دسترسی‌ها ←</a>
    </section>

    <section class="panel p-5 sm:p-6" aria-labelledby="provider-step">
        <h2 id="provider-step" class="font-black">۲. اتصال ارائه‌دهنده</h2>
        <p class="mt-2 text-sm text-slate-600">دروازه SIP این شماره را مشخص کنید. تغییر این انتخاب فقط رابطه شماره و دروازه را در پایگاه داده ذخیره می‌کند.</p>
        <form method="POST" action="{{ route('admin.sip-numbers.gateway', $number) }}" class="mt-4 flex flex-wrap items-end gap-3">
            @csrf @method('PUT')
            <label class="min-w-[220px] flex-1 text-xs font-bold text-slate-600">دروازه این شماره
                <select name="provider_gateway_id" required class="mt-2 w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm">
                    <option value="">— انتخاب دروازه —</option>
                    @foreach ($gateways as $gateway)<option value="{{ $gateway->id }}" @selected(old('provider_gateway_id', $number->provider_gateway_id) == $gateway->id)>{{ $gateway->name }} · {{ $gateway->host }}:{{ $gateway->port }}</option>@endforeach
                </select>
            </label>
            <button class="rounded-xl bg-slate-900 px-5 py-2.5 text-sm font-bold text-white" @disabled($gateways->isEmpty())>ذخیره دروازه</button>
        </form>
        @if ($number->providerGateway)<p class="mt-3 text-xs text-slate-600">دروازه انتخاب‌شده: <strong>{{ $number->providerGateway->name }}</strong></p>@endif
        <a href="{{ route('sip-gateways.index') }}" class="mt-3 inline-block text-sm font-bold text-blue-700">مدیریت دروازه‌های SIP ←</a>
        <p class="mt-3 rounded-xl bg-amber-50 p-3 text-xs leading-6 text-amber-900">ثبت دروازه در این صفحه تأیید ثبت SIP یا فعال بودن آن در FreeSWITCH نیست. وضعیت دروازه را روی سرور بررسی کنید. {{ config('voip.gateway_xml_enabled') ? '' : 'انتقال دروازه‌ها به XML-CURL هنوز فعال نشده است.' }}</p>
    </section>

    <section class="panel p-5 sm:p-6" aria-labelledby="inbound-step">
        <h2 id="inbound-step" class="font-black">۳. مقصد تماس ورودی</h2>
        <p class="mt-2 text-sm text-slate-600">تماس‌های این شماره می‌توانند به یک داخلی، تیم پاسخ‌گویی یا منوی تماس منتشرشده برسند.</p>
        <div class="mt-4 rounded-xl bg-slate-50 p-4 text-sm"><span class="text-slate-500">مقصد فعلی:</span> <strong>{{ $route?->destinationLabel() ?? 'تعیین نشده' }}</strong> @if ($route && ! $route->enabled)<span class="text-amber-700">· مسیر غیرفعال</span>@endif</div>
        <div class="mt-4 flex flex-wrap gap-3">
            <a href="{{ route('inbound-routes.index', ['sip_number_id' => $number->id]) }}#{{ $route ? 'route-'.$route->id : 'new-inbound-route' }}" class="rounded-xl bg-blue-600 px-4 py-2.5 text-sm font-bold text-white">{{ $route ? 'تغییر مقصد' : 'تعیین مقصد' }}</a>
            <a href="{{ route('sip-extensions.index') }}" class="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-bold">مدیریت داخلی‌ها</a>
            @if (config('voip.queues_enabled'))<a href="{{ route('teams.index') }}" class="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-bold">مدیریت تیم‌ها</a>@endif
            <a href="{{ route('ivr-menus.index', ['number_id' => $number->id]) }}" class="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-bold">ساخت یا ویرایش منوی تماس</a>
        </div>
        @unless ($number->inbound_enabled)<p class="mt-3 text-xs font-bold text-amber-800">ورودی این شماره غیرفعال است؛ آن را در فهرست شماره‌ها فعال کنید.</p>@endunless
    </section>

    <section class="panel p-5 sm:p-6" aria-labelledby="outbound-step">
        <h2 id="outbound-step" class="font-black">۴. تماس خروجی و شماره‌نمایش</h2>
        <p class="mt-2 text-sm text-slate-600">برای هر داخلی مجاز، این شماره را به‌عنوان شماره‌نمایش و دروازه خروجی تأییدشده را انتخاب کنید. اگر دروازه‌ای به شماره متصل است، همان دروازه را برای خروجی انتخاب کنید.</p>
        @if ($outboundRoutes->isNotEmpty())
            <ul class="mt-4 divide-y divide-slate-100 rounded-xl border border-slate-100 text-sm">
                @foreach ($outboundRoutes as $outbound)<li class="flex flex-wrap justify-between gap-2 p-3"><span>داخلی <strong dir="ltr">{{ $outbound->sipExtension?->extension ?? 'ناموجود' }}</strong> ← {{ $outbound->gateway?->name ?? 'دروازه ناموجود' }}</span><span class="{{ $outbound->enabled ? 'text-emerald-700' : 'text-slate-500' }}">{{ $outbound->enabled ? 'فعال' : 'غیرفعال' }}</span></li>@endforeach
            </ul>
        @endif
        <a href="{{ route('outbound-routes.index', ['sip_number_id' => $number->id]) }}#new-outbound-route" class="mt-4 inline-block rounded-xl bg-blue-600 px-4 py-2.5 text-sm font-bold text-white">مدیریت مسیرهای خروجی</a>
        @unless ($number->outbound_enabled)<p class="mt-3 text-xs font-bold text-slate-600">تماس خروجی برای این شماره غیرفعال است؛ این مرحله اختیاری است.</p>@endunless
    </section>

    <section class="panel p-5 sm:p-6" aria-labelledby="test-step">
        <h2 id="test-step" class="font-black">۵. بررسی و آزمایش واقعی</h2>
        <p class="mt-2 text-sm text-slate-600">تکمیل مراحل بالا فقط وضعیت پیکربندی در بلوکام را نشان می‌دهد. نتیجه تماس واقعی هنوز در این صفحه ثبت یا تأیید نمی‌شود.</p>
        <ol class="mt-4 list-decimal space-y-2 pr-5 text-sm text-slate-700">
            <li>ثبت SIP دروازه و تلفن را در FreeSWITCH و نرم‌افزار تلفن بررسی کنید.</li>
            <li>از بیرون با <span dir="ltr" class="inline-block font-bold">{{ $number->normalized_number }}</span> تماس بگیرید و مقصد را بررسی کنید.</li>
            @if ($number->outbound_enabled)<li>از داخلی مجاز تماس خروجی بگیرید و شماره‌نمایش را بررسی کنید.</li>@endif
            <li>نتیجه تماس‌ها را در تاریخچه تماس بررسی کنید.</li>
        </ol>
        <a href="{{ route('calls.index') }}" class="mt-4 inline-block text-sm font-bold text-blue-700">مشاهده تماس‌ها ←</a>
    </section>
</div>
@endsection
