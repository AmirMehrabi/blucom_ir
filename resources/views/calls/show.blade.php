@extends('layouts.portal')
@section('title', 'جزئیات تماس')
@section('content')
<a href="{{ route('calls.index') }}" class="text-sm font-bold text-blue-700">← تاریخچه تماس‌ها</a>
<h1 class="mt-4 text-2xl font-black">جزئیات تماس</h1>
<section class="panel mt-6 p-5 sm:p-7">
    <div class="flex flex-wrap items-center gap-3"><span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-bold">{{ $call->direction === 'inbound' ? 'ورودی' : 'خروجی' }}</span><span @class(['rounded-full px-3 py-1 text-xs font-bold', 'bg-emerald-50 text-emerald-700' => $call->status === 'answered', 'bg-amber-50 text-amber-700' => $call->status === 'missed', 'bg-red-50 text-red-700' => $call->status === 'failed'])>{{ ['answered'=>'پاسخ‌داده‌شده','missed'=>'از دست‌رفته','failed'=>'ناموفق'][$call->status] ?? $call->status }}</span></div>
    <dl class="mt-6 grid gap-6 text-sm sm:grid-cols-2 lg:grid-cols-3">
        @foreach (['مبدأ' => $call->source_number, 'مقصد' => $call->destination_number, 'خط' => $call->sipNumber?->number, 'داخلی' => $call->sipExtension?->extension, 'تیم' => $call->callQueue?->name, 'منوی تماس' => $call->ivrMenu?->name] as $label=>$value)
            <div><dt class="text-xs text-slate-500">{{ $label }}</dt><dd class="mt-2 w-fit font-bold" dir="auto">{{ $value ?: '—' }}</dd></div>
        @endforeach
        @foreach (['شروع'=>$call->started_at,'پاسخ'=>$call->answered_at,'پایان'=>$call->ended_at] as $label=>$value)<div><dt class="text-xs text-slate-500">{{ $label }} · تهران</dt><dd class="mt-2 w-fit tabular-nums" dir="ltr">{{ $value?->setTimezone($timezone)->format('Y/m/d H:i:s') ?: '—' }}</dd></div>@endforeach
        @foreach (['کل تماس'=>$call->duration_seconds,'مکالمه'=>$call->billable_seconds,'انتظار'=>$call->queue_wait_seconds] as $label=>$seconds)<div><dt class="text-xs text-slate-500">{{ $label }}</dt><dd class="mt-2 w-fit font-bold tabular-nums" dir="ltr">{{ $seconds === null ? '—' : sprintf('%02d:%02d',intdiv($seconds,60),$seconds%60) }}</dd></div>@endforeach
    </dl>
    @if(auth()->user()->hasPermission('recordings.view'))<div class="mt-7 border-t border-slate-100 pt-5"><h2 class="mb-3 text-sm font-bold">صدای تماس</h2>@forelse($call->recordings as $recording)@include('dashboard.recording-action', ['recording'=>$recording]) @empty<p class="text-sm text-slate-500">این تماس ضبط نشده است.</p>@endforelse</div>@endif
</section>
@endsection
