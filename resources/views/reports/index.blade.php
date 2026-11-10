@extends('layouts.portal')
@section('title', 'گزارش تماس‌ها')
@section('content')
<nav aria-label="گزارش‌ها" class="mb-5 flex gap-4 text-sm font-bold"><span aria-current="page">نمای کلی</span><a class="text-blue-700" href="{{ route('calls.index', $filters) }}">تماس‌ها</a></nav>
<header class="mb-6"><h1 class="text-2xl font-black">گزارش تماس‌ها</h1><p class="mt-2 text-sm text-slate-500">از وضعیت کلی تا جزئیات هر تماس، با یک فیلتر مشترک.</p><p class="mt-2 text-xs text-slate-500">زمان‌ها به وقت تهران است. تماس‌های پایان‌یافته پس از دریافت سابقه نمایش داده می‌شوند؛ وضعیت تأخیر دریافت هنوز قابل اندازه‌گیری نیست.</p></header>
@include('calls.filters', ['filterAction' => route('reports.index')])
@php
$cards = [
 ['کل تماس‌ها', 'total', []], ['ورودی', 'incoming', ['direction'=>'inbound']],
 ['تلاش خروجی', 'outgoing', ['direction'=>'outbound']], ['پاسخ‌داده‌شده', 'answered', ['status'=>'answered']],
 ['از دست‌رفته', 'missed', ['status'=>'missed']], ['ناموفق', 'failed', ['status'=>'failed']],
];
@endphp
<div class="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-3">
@foreach($cards as [$label, $key, $drill])
@continue(collect($drill)->contains(fn ($value, $dimension) => !empty($filters[$dimension]) && $filters[$dimension] !== $value))
<a href="{{ route('calls.index', array_merge($filters, $drill)) }}" class="panel block rounded-2xl border border-transparent p-5 transition hover:border-blue-400 focus-visible:outline focus-visible:outline-2 focus-visible:outline-blue-600"><span class="text-sm text-slate-600">{{ $label }}</span><strong class="my-3 block text-3xl tabular-nums">{{ number_format($stats->$key) }}</strong><span class="text-xs font-bold text-blue-700">مشاهده تماس‌ها ←</span></a>
@endforeach
</div>
<p class="mb-6 text-xs leading-6 text-slate-500">تعدادها بر اساس سابقه فعلی تماس هستند. وضعیت «پاسخ‌داده‌شده» هنوز تأیید پاسخ اپراتور نیست. گزارش مدت مکالمه انسانی و عملکرد اپراتورها پس از تکمیل داده‌های لازم اضافه می‌شود. آمار نوع تماس و وضعیت تماس دو دسته‌بندی جداگانه‌اند.</p>
<section class="panel overflow-hidden"><div class="flex items-center justify-between gap-3 p-5"><h2 class="font-bold">آخرین تماس‌های این بازه</h2><a href="{{ route('calls.index', $filters) }}" class="text-sm font-bold text-blue-700">همه تماس‌ها</a></div>
<div class="overflow-x-auto"><table class="w-full text-right text-sm"><thead class="bg-slate-50 text-xs text-slate-500"><tr><th class="p-4">زمان</th><th class="p-4">مبدأ</th><th class="p-4">مقصد</th><th class="p-4">وضعیت</th><th class="p-4">جزئیات</th></tr></thead><tbody class="divide-y divide-slate-100">
@forelse($recentCalls as $call)<tr><td class="whitespace-nowrap p-4" dir="ltr">{{ $call->started_at->setTimezone($timezone)->format('Y/m/d H:i') }}</td><td class="p-4" dir="ltr">{{ $call->source_number ?: '—' }}</td><td class="p-4" dir="ltr">{{ $call->destination_number ?: '—' }}</td><td class="p-4">{{ ['answered'=>'پاسخ‌داده‌شده','missed'=>'از دست‌رفته','failed'=>'ناموفق'][$call->status] ?? 'نامشخص' }}</td><td class="p-4"><a class="font-bold text-blue-700" href="{{ route('calls.show', $call) }}">جزئیات تماس</a></td></tr>
@empty<tr><td colspan="5" class="p-10 text-center"><p class="font-bold">تماسی مطابق این فیلترها ثبت نشده است.</p><p class="mt-2 text-sm text-slate-500">بازه زمانی را بزرگ‌تر کنید یا فیلترها را پاک کنید.</p><a class="mt-4 inline-block font-bold text-blue-700" href="{{ route('reports.index', ['range'=>'30d']) }}">نمایش ۳۰ روز اخیر بدون فیلتر</a></td></tr>@endforelse
</tbody></table></div></section>
@endsection
