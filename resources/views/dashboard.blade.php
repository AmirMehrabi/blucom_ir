@extends('layouts.portal')
@section('title', 'داشبورد')
@section('content')
@php
    $isAdmin = $mode === 'admin';
    if ($canViewCalls) {
        $s = $callSummary;
        $seconds = $s['billableToday'];
        $talkTime = sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
        $metrics = [
            ['label' => 'تماس‌های امروز', 'value' => number_format($s['totalToday']), 'hint' => 'ورودی و خروجی', 'tone' => 'bg-blue-50 text-blue-600'],
            ['label' => 'نرخ پاسخ‌گویی', 'value' => $s['answerRate'].'٪', 'hint' => number_format($s['answeredToday']).' تماس پاسخ‌داده‌شده', 'tone' => 'bg-emerald-50 text-emerald-600'],
            ['label' => 'تماس‌های از دست‌رفته', 'value' => number_format($s['missedToday']), 'hint' => 'ورودی بدون پاسخ', 'tone' => 'bg-amber-50 text-amber-600'],
            ['label' => 'زمان مکالمه امروز', 'value' => $talkTime, 'hint' => 'مدت پس از پاسخ', 'tone' => 'bg-violet-50 text-violet-600'],
        ];
        $callMix = [
            ['label' => 'پاسخ‌داده‌شده', 'value' => $s['answeredToday'], 'tone' => 'bg-blue-600'],
            ['label' => 'از دست‌رفته', 'value' => $s['missedToday'], 'tone' => 'bg-amber-400'],
            ['label' => 'ناموفق', 'value' => $s['failedToday'], 'tone' => 'bg-slate-300'],
        ];
    }
@endphp

<div class="flex flex-wrap items-start justify-between gap-4">
    <div><span class="mb-3 inline-block rounded-full bg-[#eaf2ff] px-3 py-1 text-[11px] font-bold text-[#0050d0]">نمای کلی</span><h1 class="text-2xl font-black text-[#0f172a] sm:text-[30px]">داشبورد تماس‌ها</h1><p class="mt-2 text-sm leading-6 text-[#64748b]">{{ $isAdmin ? 'عملکرد تماس‌ها و آمادگی سرویس‌ها در پلتفرم' : 'عملکرد تماس‌ها و آمادگی خط‌های شما' }}</p></div>
    @if ($canViewCalls)<a href="{{ route('calls.index') }}" class="rounded-xl border border-[#e2e8f0] bg-white px-4 py-2.5 text-xs font-bold text-[#475569] shadow-sm hover:border-[#0069ff] hover:text-[#0069ff]">همه تماس‌ها ←</a>@endif
</div>

@if (! $isAdmin && auth()->user()->hasPermission('providers.manage') && auth()->user()->hasPermission('numbers.manage') && auth()->user()->hasPermission('phones.manage') && auth()->user()->hasPermission('lines.view') && $configuration[0]['value'] === 0)
<section class="mt-6 flex flex-wrap items-center justify-between gap-4 rounded-2xl border border-blue-200 bg-blue-50 p-5 sm:p-6">
    <div><h2 class="font-black text-blue-950">خط خود را راه‌اندازی کنید</h2><p class="mt-1 text-xs leading-6 text-blue-900">اتصال ارائه‌دهنده، شماره و پاسخ‌گو را با راهنمای مرحله‌به‌مرحله تنظیم کنید.</p></div>
    <a href="{{ route('customer.setup.wizard') }}" class="rounded-xl bg-blue-600 px-5 py-3 text-xs font-bold text-white">شروع یا ادامه راه‌اندازی</a>
</section>
@endif

@if ($canViewCalls)
<div class="mt-7 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
    @foreach ($metrics as $metric)
        <article class="panel p-5 sm:p-6"><div class="flex items-center justify-between gap-3"><p class="text-xs font-bold text-[#64748b]">{{ $metric['label'] }}</p><span class="grid size-10 place-items-center rounded-xl {{ $metric['tone'] }}"><svg aria-hidden="true" class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 3v12m-4-4 4 4 4-4M4 19h16"/></svg></span></div><div class="mt-4 text-[30px] font-black leading-none text-[#0f172a]" dir="ltr">{{ $metric['value'] }}</div><p class="mt-4 text-[11px] text-[#64748b]">{{ $metric['hint'] }}</p></article>
    @endforeach
</div>
<div class="mt-5 grid gap-5 xl:grid-cols-[minmax(0,1.7fr)_minmax(290px,1fr)]">
    <section class="panel min-w-0 p-5 sm:p-6" aria-labelledby="call-trend-title"><div class="flex flex-wrap items-start justify-between gap-4"><div><h2 id="call-trend-title" class="text-base font-black text-[#0f172a]">روند تماس‌ها</h2><p class="mt-1 text-xs text-[#94a3b8]">تماس‌های ورودی و خروجی در ۷ روز اخیر</p></div><div class="flex gap-4 text-[11px] font-bold text-[#64748b]"><span>🔵 ورودی</span><span>⚪ خروجی</span></div></div>
        <div class="mt-7 overflow-x-auto"><div class="min-w-[480px]"><div class="relative h-52 border-b border-[#e2e8f0]"><div aria-hidden="true" class="pointer-events-none absolute inset-0 flex flex-col justify-between"><span class="border-t border-dashed border-[#e2e8f0]"></span><span class="border-t border-dashed border-[#e2e8f0]"></span><span class="border-t border-dashed border-[#e2e8f0]"></span><span></span></div><div class="relative grid h-full grid-cols-7 items-end gap-3 px-2">@foreach ($s['week'] as $day)<div class="flex h-full items-end justify-center gap-1.5"><div class="w-4 rounded-t-md bg-[#0069ff] sm:w-5" style="height: {{ round($day['in'] / $s['chartMax'] * 100) }}%" title="{{ $day['day'] }}، ورودی: {{ $day['in'] }} تماس"></div><div class="w-4 rounded-t-md bg-[#b9d6ff] sm:w-5" style="height: {{ round($day['out'] / $s['chartMax'] * 100) }}%" title="{{ $day['day'] }}، خروجی: {{ $day['out'] }} تماس"></div></div>@endforeach</div></div><div class="mt-3 grid grid-cols-7 gap-3 px-2 text-center text-[10px] font-semibold text-[#94a3b8]">@foreach ($s['week'] as $day)<span>{{ $day['day'] }}</span>@endforeach</div></div></div>
    </section>
    <section class="panel p-5 sm:p-6" aria-labelledby="answer-rate-title"><h2 id="answer-rate-title" class="text-base font-black text-[#0f172a]">کیفیت پاسخ‌گویی</h2><p class="mt-1 text-xs text-[#94a3b8]">خلاصه تماس‌های امروز</p><div class="my-6 flex justify-center"><div class="grid size-36 place-items-center rounded-full" style="background: conic-gradient(#0069ff 0 {{ $s['answerRate'] }}%, #eaf2ff {{ $s['answerRate'] }}% 100%)"><div class="grid size-28 place-items-center rounded-full bg-white text-center"><div><strong class="block text-3xl font-black text-[#0f172a]">{{ $s['answerRate'] }}٪</strong><span class="mt-1 block text-[10px] font-bold text-[#94a3b8]">نرخ پاسخ</span></div></div></div></div><div class="space-y-3 border-t border-[#f1f5f9] pt-4">@foreach ($callMix as $item)<div class="flex items-center justify-between text-xs"><span class="flex items-center gap-2 text-[#64748b]"><i class="size-2 rounded-full {{ $item['tone'] }}"></i>{{ $item['label'] }}</span><strong class="font-black text-[#0f172a]">{{ number_format($item['value']) }}</strong></div>@endforeach</div></section>
</div>
@endif

<div class="mt-5 grid gap-5 xl:grid-cols-[minmax(0,1.7fr)_minmax(290px,1fr)]">
    @if ($canViewCalls)<section class="panel min-w-0 overflow-hidden" aria-labelledby="recent-calls-title"><div class="flex items-start justify-between gap-3 px-5 py-5 sm:px-6"><div><h2 id="recent-calls-title" class="text-base font-black text-[#0f172a]">تماس‌های اخیر</h2><p class="mt-1 text-xs text-[#94a3b8]">تماس‌های ثبت‌شده در سیستم</p></div><a href="{{ route('calls.index') }}" class="text-xs font-bold text-[#0069ff]">مشاهده همه</a></div><div class="overflow-x-auto"><table class="w-full min-w-[630px] text-right"><thead class="bg-[#f8fafd] text-[11px] font-bold text-[#94a3b8]"><tr><th class="px-5 py-3 sm:px-6">شماره‌ها</th><th class="px-4 py-3">نوع</th><th class="px-4 py-3">وضعیت</th><th class="px-4 py-3">مکالمه</th><th class="px-5 py-3 sm:px-6">زمان</th></tr></thead><tbody class="divide-y divide-[#f1f5f9]">@forelse ($s['recentCalls'] as $call)<tr class="text-xs text-[#475569]"><td class="px-5 py-3.5 sm:px-6"><span dir="ltr" class="block w-fit font-bold text-[#0f172a]">{{ $call->source_number ?: '—' }}</span><span dir="ltr" class="mt-1 block w-fit text-[11px] text-[#94a3b8]">{{ $call->destination_number ?: '—' }}</span></td><td class="px-4 py-3.5">{{ $call->direction === 'inbound' ? 'ورودی' : 'خروجی' }}</td><td class="px-4 py-3.5">{{ ['answered' => 'پاسخ‌داده‌شده', 'missed' => 'از دست‌رفته', 'failed' => 'ناموفق'][$call->status] ?? $call->status }}</td><td class="px-4 py-3.5 tabular-nums" dir="ltr">{{ sprintf('%02d:%02d', intdiv($call->billable_seconds, 60), $call->billable_seconds % 60) }}</td><td class="whitespace-nowrap px-5 py-3.5 text-[#94a3b8] sm:px-6">{{ $call->started_at->setTimezone($s['timezone'])->format('Y/m/d H:i') }}</td></tr>@empty<tr><td colspan="5" class="px-5 py-9 text-center text-sm text-slate-500">هنوز تماسی ثبت نشده است.</td></tr>@endforelse</tbody></table></div></section>@endif
    <div class="space-y-5"><section class="panel p-5 sm:p-6" aria-labelledby="configuration-title"><h2 id="configuration-title" class="text-base font-black text-[#0f172a]">آمادگی سرویس</h2><p class="mt-1 text-xs text-[#94a3b8]">وضعیت پیکربندی فعلی</p><div class="mt-5 divide-y divide-[#f1f5f9]">@foreach ($configuration as $item)<div class="flex items-center justify-between gap-3 py-3 first:pt-0 last:pb-0"><div><p class="text-xs font-bold text-[#0f172a]">{{ $item['label'] }}</p><p class="mt-1 text-[10px] text-[#94a3b8]">{{ $item['note'] }}</p></div><strong class="text-lg font-black text-[#031B4E]">{{ number_format($item['value']) }}</strong></div>@endforeach</div></section><section class="rounded-3xl bg-[#031B4E] p-5 text-white sm:p-6"><h2 class="text-base font-black">{{ $isAdmin ? 'اتصال‌ها را بررسی کنید' : 'خط‌های خود را مدیریت کنید' }}</h2><p class="mt-2 text-xs leading-6 text-blue-100/70">{{ $isAdmin ? 'درخواست‌های جدید و وضعیت اتصال ارائه‌دهندگان را در یک‌جا پیگیری کنید.' : 'شماره‌ها، پاسخ‌گوها و اتصال ارائه‌دهنده را در یک‌جا ببینید.' }}</p>@if ($isAdmin)<a href="{{ route('admin.customer-connections.index') }}" class="mt-5 inline-flex items-center gap-2 rounded-xl bg-white px-4 py-2.5 text-xs font-bold text-[#031B4E]">بررسی اتصال‌ها ←</a>@elseif (auth()->user()->hasPermission('lines.view'))<a href="{{ route('customer.setup.lines') }}" class="mt-5 inline-flex items-center gap-2 rounded-xl bg-white px-4 py-2.5 text-xs font-bold text-[#031B4E]">مشاهده خط‌ها ←</a>@endif</section></div>
</div>
@endsection
