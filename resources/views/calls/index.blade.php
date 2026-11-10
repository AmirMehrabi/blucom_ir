@extends('layouts.portal')
@section('title', 'تاریخچه تماس‌ها')
@section('content')
<nav aria-label="گزارش‌ها" class="mb-5 flex gap-4 text-sm font-bold"><a class="text-blue-700" href="{{ route('reports.index', $filters) }}">نمای کلی</a><span aria-current="page">تماس‌ها</span></nav>
<div class="mb-6 flex flex-wrap items-end justify-between gap-4">
    <div><span class="mb-3 inline-block rounded-full bg-[#eaf2ff] px-3 py-1 text-[11px] font-bold text-[#0050d0]">گزارش تماس</span><h1 class="text-2xl font-black text-[#0f172a]">تاریخچه تماس‌ها</h1><p class="mt-2 text-sm text-[#64748b]">وضعیت نهایی و مدت تماس‌های ثبت‌شده</p></div>
</div>
@include('calls.filters')
@include('reports.export-action')
@if(isset($filters['hour']))<p class="mb-4 text-xs text-slate-600">نمایش تماس‌های ساعت <bdi>{{ sprintf('%02d:00',$filters['hour']) }} — {{ sprintf('%02d:00',$filters['hour']+1) }}</bdi> به وقت تهران · <a class="font-bold text-blue-700" href="{{ route('calls.index',Illuminate\Support\Arr::except($filters,['hour'])) }}">حذف فیلتر ساعت</a></p>@endif
<section class="panel overflow-hidden">
    <div class="overflow-x-auto"><table class="w-full min-w-[900px] text-right"><thead class="bg-[#f8fafd] text-[11px] font-bold text-[#64748b]"><tr><th class="px-5 py-4">زمان</th><th class="px-4 py-4">نوع</th><th class="px-4 py-4">مبدأ</th><th class="px-4 py-4">مقصد</th><th class="px-4 py-4">تیم / داخلی</th><th class="px-4 py-4">وضعیت</th><th class="px-4 py-4">انتظار</th><th class="px-4 py-4">کل تماس</th><th class="px-5 py-4">پاسخ شبکه</th><th class="px-5 py-4">صدای تماس</th><th class="px-4 py-4"><span class="sr-only">جزئیات</span></th></tr></thead><tbody class="divide-y divide-slate-100 text-xs text-[#475569]">
        @forelse ($calls as $call)
            <tr><td class="whitespace-nowrap px-5 py-4">{{ $call->started_at->setTimezone($timezone)->format('Y/m/d H:i:s') }}</td><td class="px-4 py-4">{{ $call->direction === 'inbound' ? 'ورودی' : 'خروجی' }}</td><td dir="ltr" class="px-4 py-4 text-right">{{ $call->source_number ?: '—' }}</td><td dir="ltr" class="px-4 py-4 text-right">{{ $call->destination_number ?: '—' }}</td><td class="px-4 py-4">@if ($call->ivrMenu)<span class="block">{{ $call->ivrMenu->name }} · {{ $call->ivr_digit === null ? 'بدون انتخاب' : 'کلید '.$call->ivr_digit }}</span>@endif{{ $call->callQueue?->name ? 'تیم '.$call->callQueue->name : ($call->sipExtension?->extension ?: '—') }}</td><td class="px-4 py-4">@include('calls.outcome')</td><td dir="ltr" class="px-4 py-4 text-right tabular-nums">{{ $call->queue_wait_seconds === null ? '—' : sprintf('%02d:%02d', intdiv($call->queue_wait_seconds, 60), $call->queue_wait_seconds % 60) }}</td><td dir="ltr" class="px-4 py-4 text-right tabular-nums">{{ sprintf('%02d:%02d', intdiv($call->duration_seconds, 60), $call->duration_seconds % 60) }}</td><td dir="ltr" class="px-5 py-4 text-right tabular-nums">{{ sprintf('%02d:%02d', intdiv($call->billable_seconds, 60), $call->billable_seconds % 60) }}</td><td class="px-5 py-4">@if(auth()->user()->hasPermission('recordings.view'))@forelse($call->recordings as $recording)@if($recording->status === 'ready' && $recording->expires_at?->isFuture())<a class="whitespace-nowrap rounded-xl bg-blue-50 px-3 py-2 text-xs font-bold text-blue-700" href="{{ route('recordings.index',['play'=>$recording->id]) }}">▶ پخش</a>@else<span class="text-xs text-slate-500">{{ $recording->statusLabel() }}</span>@endif @empty<span class="text-xs text-slate-400">ضبط نشده</span>@endforelse @else — @endif</td><td class="px-4 py-4"><a class="text-xs font-bold text-blue-700" href="{{ route('calls.show',$call) }}">جزئیات</a></td></tr>
        @empty
            <tr><td colspan="11" class="px-5 py-12 text-center text-sm text-slate-500">در این بازه تماسی ثبت نشده است.</td></tr>
        @endforelse
    </tbody></table></div>
    @if ($calls->hasPages())<div class="border-t border-slate-100 px-5 py-4">{{ $calls->links() }}</div>@endif
</section>
@endsection
