@extends('layouts.portal')
@section('title', 'تاریخچه تماس‌ها')
@section('content')
<div class="mb-6 flex flex-wrap items-end justify-between gap-4">
    <div><span class="mb-3 inline-block rounded-full bg-[#eaf2ff] px-3 py-1 text-[11px] font-bold text-[#0050d0]">گزارش تماس</span><h1 class="text-2xl font-black text-[#0f172a]">تاریخچه تماس‌ها</h1><p class="mt-2 text-sm text-[#64748b]">وضعیت نهایی و مدت تماس‌های ثبت‌شده</p></div>
</div>
<form method="GET" action="{{ route('calls.index') }}" class="panel mb-5 flex flex-wrap items-end gap-3 p-4 sm:p-5">
    <label class="text-xs font-bold text-[#475569]">بازه زمانی<select name="range" class="mt-2 block rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-xs"><option value="7d" @selected($range === '7d')>۷ روز اخیر</option><option value="30d" @selected($range === '30d')>۳۰ روز اخیر</option><option value="all" @selected($range === 'all')>همه</option></select></label>
    <label class="text-xs font-bold text-[#475569]">نوع تماس<select name="direction" class="mt-2 block rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-xs"><option value="">همه</option><option value="inbound" @selected(($filters['direction'] ?? '') === 'inbound')>ورودی</option><option value="outbound" @selected(($filters['direction'] ?? '') === 'outbound')>خروجی</option></select></label>
    <label class="text-xs font-bold text-[#475569]">وضعیت<select name="status" class="mt-2 block rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-xs"><option value="">همه</option><option value="answered" @selected(($filters['status'] ?? '') === 'answered')>پاسخ‌داده‌شده</option><option value="missed" @selected(($filters['status'] ?? '') === 'missed')>از دست‌رفته</option><option value="failed" @selected(($filters['status'] ?? '') === 'failed')>ناموفق</option></select></label>
    <button class="rounded-xl bg-[#0069ff] px-5 py-2.5 text-xs font-bold text-white hover:bg-[#0050d0]">نمایش</button>
</form>
<section class="panel overflow-hidden">
    <div class="overflow-x-auto"><table class="w-full min-w-[900px] text-right"><thead class="bg-[#f8fafd] text-[11px] font-bold text-[#64748b]"><tr><th class="px-5 py-4">زمان</th><th class="px-4 py-4">نوع</th><th class="px-4 py-4">مبدأ</th><th class="px-4 py-4">مقصد</th><th class="px-4 py-4">تیم / داخلی</th><th class="px-4 py-4">وضعیت</th><th class="px-4 py-4">انتظار</th><th class="px-4 py-4">کل تماس</th><th class="px-5 py-4">مکالمه</th></tr></thead><tbody class="divide-y divide-slate-100 text-xs text-[#475569]">
        @forelse ($calls as $call)
            <tr><td class="whitespace-nowrap px-5 py-4">{{ $call->started_at->setTimezone($timezone)->format('Y/m/d H:i:s') }}</td><td class="px-4 py-4">{{ $call->direction === 'inbound' ? 'ورودی' : 'خروجی' }}</td><td dir="ltr" class="px-4 py-4 text-right">{{ $call->source_number ?: '—' }}</td><td dir="ltr" class="px-4 py-4 text-right">{{ $call->destination_number ?: '—' }}</td><td class="px-4 py-4">@if ($call->ivrMenu)<span class="block">{{ $call->ivrMenu->name }} · {{ $call->ivr_digit === null ? 'بدون انتخاب' : 'کلید '.$call->ivr_digit }}</span>@endif{{ $call->callQueue?->name ? 'تیم '.$call->callQueue->name : ($call->sipExtension?->extension ?: '—') }}</td><td class="px-4 py-4"><span @class(['rounded-full px-2.5 py-1 text-[10px] font-bold', 'bg-emerald-50 text-emerald-700' => $call->status === 'answered', 'bg-amber-50 text-amber-700' => $call->status === 'missed', 'bg-red-50 text-red-700' => $call->status === 'failed']) title="{{ $call->hangup_cause }}">{{ ['answered' => 'پاسخ‌داده‌شده', 'missed' => 'از دست‌رفته', 'failed' => 'ناموفق'][$call->status] ?? $call->status }}</span></td><td dir="ltr" class="px-4 py-4 text-right tabular-nums">{{ $call->queue_wait_seconds === null ? '—' : sprintf('%02d:%02d', intdiv($call->queue_wait_seconds, 60), $call->queue_wait_seconds % 60) }}</td><td dir="ltr" class="px-4 py-4 text-right tabular-nums">{{ sprintf('%02d:%02d', intdiv($call->duration_seconds, 60), $call->duration_seconds % 60) }}</td><td dir="ltr" class="px-5 py-4 text-right tabular-nums">{{ sprintf('%02d:%02d', intdiv($call->billable_seconds, 60), $call->billable_seconds % 60) }}</td></tr>
        @empty
            <tr><td colspan="9" class="px-5 py-12 text-center text-sm text-slate-500">در این بازه تماسی ثبت نشده است.</td></tr>
        @endforelse
    </tbody></table></div>
    @if ($calls->hasPages())<div class="border-t border-slate-100 px-5 py-4">{{ $calls->links() }}</div>@endif
</section>
@endsection
