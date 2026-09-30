@extends('layouts.portal')
@section('title', 'صدای تماس‌ها')
@section('content')
@php
    $canDownload = auth()->user()->hasPermission('recordings.download');
    $canDelete = auth()->user()->hasPermission('recordings.delete');
    $canManage = auth()->user()->hasPermission('recordings.manage');
    $quotaBytes = $storageSetting ? $storageSetting->quota_mb * 1048576 : null;
    $percent = $quotaBytes ? min(100, round(($usedBytes + $reservedBytes) / $quotaBytes * 100)) : 0;
    $playerData = function ($recording) use ($canDownload, $canDelete, $timezone) {
        $call = $recording->callRecord;
        return ['id' => $recording->id, 'title' => ($recording->direction === 'inbound' ? 'تماس ورودی' : 'تماس خروجی').' · '.$recording->created_at->setTimezone($timezone)->format('Y/m/d H:i'),
            'participants' => ($call?->source_number ?: '—').' → '.($call?->destination_number ?: '—'),
            'number' => $recording->sipNumber?->number ?: '—',
            'agent' => $call?->sipExtension?->display_name ?: ($call?->sipExtension?->extension ?: '—'),
            'expiry' => $recording->expires_at?->setTimezone($timezone)->format('Y/m/d') ?: '—',
            'audio' => route('recordings.audio', $recording),
            'download' => $canDownload ? route('recordings.download', $recording) : null,
            'delete' => $canDelete ? route('recordings.destroy', $recording) : null];
    };
@endphp
<div class="mb-6 flex flex-wrap items-end justify-between gap-4">
    <div><span class="mb-3 inline-block rounded-full bg-blue-50 px-3 py-1 text-xs font-bold text-blue-700">آرشیو مکالمه‌ها</span><h1 class="text-2xl font-black">صدای تماس‌ها</h1><p class="mt-2 text-sm text-slate-500">مکالمه را پیدا کنید، گوش دهید و در صورت نیاز دانلود کنید.</p></div>
    @if($canManage)<a href="{{ route('recordings.settings') }}" class="rounded-xl border border-slate-200 bg-white px-5 py-3 text-sm font-bold text-blue-700">تنظیم ضبط و نگهداری</a>@endif
</div>
<div class="mb-5 grid gap-4 sm:grid-cols-3">
    <section class="panel p-5"><p class="text-xs text-slate-500">فضای استفاده‌شده</p><p class="mt-2 text-xl font-black" dir="ltr">{{ number_format($usedBytes / 1048576, 1) }} MB @if($storageSetting)<span class="text-xs font-normal text-slate-500">/ {{ number_format($storageSetting->quota_mb) }} MB</span>@endif</p>@if($storageSetting)<progress aria-label="فضای استفاده‌شده و رزروشده" class="mt-3 h-2 w-full accent-blue-600" value="{{ $percent }}" max="100">{{ $percent }}%</progress>@endif</section>
    <section class="panel p-5"><p class="text-xs text-slate-500">فضای رزروشده برای تماس‌های جاری</p><p class="mt-2 text-xl font-black" dir="ltr">{{ number_format($reservedBytes / 1048576, 1) }} MB</p><p class="mt-2 text-xs text-slate-500">بعد از پایان پردازش آزاد می‌شود.</p></section>
    <section class="panel p-5"><p class="text-xs text-slate-500">حذف خودکار در ۷ روز آینده</p><p class="mt-2 text-xl font-black">{{ $expiringCount }} <span class="text-xs font-normal text-slate-500">فایل</span></p><p class="mt-2 text-xs text-slate-500">تاریخچه تماس‌ها باقی می‌ماند.</p></section>
</div>
@if(!config('voip.recordings.enabled'))<p role="status" class="mb-5 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">ضبط تماس هنوز در سرور فعال نشده است. تنظیمات شما ذخیره می‌شود و پس از فعال‌سازی برای تماس‌های جدید اعمال خواهد شد.</p>@endif
<form method="GET" action="{{ route('recordings.index') }}" class="panel mb-5 space-y-4 p-5">
    <div class="flex flex-wrap items-end gap-3">
        <label class="min-w-44 flex-1 text-xs font-bold text-slate-600">جست‌وجوی شماره<input name="search" value="{{ $filters['search'] ?? '' }}" maxlength="64" dir="ltr" inputmode="tel" placeholder="شماره تماس‌گیرنده یا مقصد" class="mt-2 w-full rounded-xl border border-slate-200 px-3 py-3 text-sm font-normal"></label>
        <label class="text-xs font-bold text-slate-600">شماره کسب‌وکار<select name="number" class="mt-2 block rounded-xl border border-slate-200 bg-white px-3 py-3 text-sm font-normal"><option value="">همه شماره‌ها</option>@foreach($numbers as $number)<option value="{{ $number->id }}" @selected(($filters['number'] ?? '') == $number->id)>{{ $number->number }}</option>@endforeach</select></label>
        <label class="text-xs font-bold text-slate-600">نوع تماس<select name="direction" class="mt-2 block rounded-xl border border-slate-200 bg-white px-3 py-3 text-sm font-normal"><option value="">همه</option><option value="inbound" @selected(($filters['direction'] ?? '') === 'inbound')>ورودی</option><option value="outbound" @selected(($filters['direction'] ?? '') === 'outbound')>خروجی</option></select></label>
        <label class="text-xs font-bold text-slate-600">وضعیت ضبط<select name="status" class="mt-2 block rounded-xl border border-slate-200 bg-white px-3 py-3 text-sm font-normal"><option value="">همه</option>@foreach(['ready'=>'آماده پخش','recording'=>'در حال ضبط','processing'=>'در حال آماده‌سازی','failed'=>'ناموفق','skipped'=>'فضای ناکافی','empty'=>'بدون مکالمه','expired'=>'منقضی‌شده','deleted'=>'حذف‌شده'] as $value=>$label)<option value="{{ $value }}" @selected(($filters['status'] ?? '') === $value)>{{ $label }}</option>@endforeach</select></label>
        <button class="rounded-xl bg-blue-600 px-6 py-3 text-sm font-bold text-white hover:bg-blue-700">جست‌وجو</button><a href="{{ route('recordings.index') }}" class="px-2 py-3 text-xs font-bold text-slate-500">پاک کردن فیلترها</a>
    </div>
    <details @if(isset($filters['from']) || isset($filters['to']) || isset($filters['extension']) || isset($filters['team'])) open @endif><summary class="cursor-pointer text-xs font-bold text-blue-700">بازه زمانی، داخلی و تیم</summary><div class="mt-3 flex flex-wrap gap-3">
        <label class="text-xs text-slate-600">از تاریخ<input type="date" name="from" value="{{ $filters['from'] ?? '' }}" class="mt-2 block rounded-xl border border-slate-200 px-3 py-2.5" dir="ltr"></label>
        <label class="text-xs text-slate-600">تا تاریخ<input type="date" name="to" value="{{ $filters['to'] ?? '' }}" class="mt-2 block rounded-xl border border-slate-200 px-3 py-2.5" dir="ltr"></label>
        <label class="text-xs text-slate-600">داخلی<select name="extension" class="mt-2 block rounded-xl border border-slate-200 bg-white px-3 py-2.5"><option value="">همه داخلی‌ها</option>@foreach($extensions as $extension)<option value="{{ $extension->id }}" @selected(($filters['extension'] ?? '') == $extension->id)>{{ $extension->display_name }} · {{ $extension->extension }}</option>@endforeach</select></label>
        <label class="text-xs text-slate-600">تیم<select name="team" class="mt-2 block rounded-xl border border-slate-200 bg-white px-3 py-2.5"><option value="">همه تیم‌ها</option>@foreach($teams as $team)<option value="{{ $team->id }}" @selected(($filters['team'] ?? '') == $team->id)>{{ $team->name }}</option>@endforeach</select></label>
    </div></details>
</form>
<p class="mb-3 text-xs text-slate-500">فایل‌ها کمی پس از پایان تماس آماده می‌شوند. {{ $recordings->total() }} نتیجه</p>
<section class="panel overflow-hidden">
    <div class="overflow-x-auto"><table class="w-full min-w-[850px] text-right"><thead class="bg-slate-50 text-xs text-slate-500"><tr><th class="px-5 py-4">زمان / نوع</th><th class="px-4 py-4">مبدأ ← مقصد</th><th class="px-4 py-4">شماره کسب‌وکار / پاسخ‌گو</th><th class="px-4 py-4">مدت ضبط</th><th class="px-4 py-4">وضعیت / حذف خودکار</th><th class="px-5 py-4">صدای تماس</th></tr></thead><tbody class="divide-y divide-slate-100 text-sm">
    @forelse($recordings as $recording)
        @php($ready = $recording->status === 'ready' && $recording->expires_at?->isFuture())
        <tr class="hover:bg-blue-50/30"><td class="px-5 py-5"><span class="block whitespace-nowrap text-xs tabular-nums">{{ $recording->created_at->setTimezone($timezone)->format('Y/m/d H:i') }}</span><span class="mt-1 block text-xs text-slate-500">{{ $recording->direction === 'inbound' ? 'ورودی' : 'خروجی' }}</span></td>
            <td class="px-4 py-5"><span dir="ltr" class="block whitespace-nowrap text-right">{{ $recording->callRecord?->source_number ?: '—' }}</span><span dir="ltr" class="mt-1 block whitespace-nowrap text-right text-xs text-slate-500">→ {{ $recording->callRecord?->destination_number ?: '—' }}</span></td>
            <td class="px-4 py-5"><span dir="ltr" class="block text-right">{{ $recording->sipNumber?->number ?: '—' }}</span><span class="mt-1 block text-xs text-slate-500">{{ $recording->callRecord?->callQueue?->name ?: ($recording->callRecord?->sipExtension?->display_name ?: $recording->callRecord?->sipExtension?->extension ?: '—') }}</span></td>
            <td dir="ltr" class="px-4 py-5 text-right tabular-nums">{{ $recording->duration_seconds === null ? '—' : sprintf('%02d:%02d', intdiv($recording->duration_seconds,60), $recording->duration_seconds % 60) }}</td>
            <td class="px-4 py-5"><span class="inline-block rounded-full px-3 py-1 text-xs font-bold {{ $ready ? 'bg-emerald-50 text-emerald-700' : ($recording->status === 'failed' ? 'bg-red-50 text-red-700' : 'bg-slate-100 text-slate-600') }}">{{ $recording->status === 'ready' && !$ready ? 'منقضی‌شده' : $recording->statusLabel() }}</span>@if($ready)<span class="mt-2 block text-xs text-slate-500">تا {{ $recording->expires_at->setTimezone($timezone)->format('Y/m/d') }}</span>@endif</td>
            <td class="px-5 py-5">@if($ready)<div class="flex items-center gap-3"><a data-recording-open data-player="{{ json_encode($playerData($recording)) }}" href="{{ route('recordings.index', array_merge($filters,['play'=>$recording->id])) }}" class="whitespace-nowrap rounded-xl bg-blue-600 px-4 py-2 text-xs font-bold text-white">▶ پخش</a>@if($canDownload)<a href="{{ route('recordings.download',$recording) }}" class="text-xs font-bold text-blue-700">دانلود</a>@endif</div>@elseif($canDelete && !in_array($recording->status,['recording','processing','deleted','expired']))<form method="POST" action="{{ route('recordings.destroy',$recording) }}" onsubmit="return confirm('صدای تماس برای همیشه حذف شود؟ تاریخچه تماس باقی می‌ماند.')">@csrf @method('DELETE')<input type="hidden" name="confirm" value="1"><button class="text-xs text-red-700">حذف فایل ناموفق</button></form>@else<span class="text-xs text-slate-400">—</span>@endif</td></tr>
    @empty
        <tr><td colspan="6" class="px-6 py-14 text-center"><p class="font-bold">صدای تماسی پیدا نشد.</p><p class="mt-2 text-sm text-slate-500">فیلترها را تغییر دهید یا ضبط یک شماره را فعال کنید. تماس‌های قبلی قابل ضبط نیستند.</p>@if($canManage)<a href="{{ route('recordings.settings') }}" class="mt-5 inline-block rounded-xl bg-blue-600 px-5 py-3 text-sm font-bold text-white">تنظیم ضبط شماره‌ها</a>@endif</td></tr>
    @endforelse
    </tbody></table></div>
    @if($recordings->hasPages())<div class="border-t border-slate-100 p-5">{{ $recordings->links() }}</div>@endif
</section>
<dialog data-recording-player @if($playing && $playing->status === 'ready' && $playing->expires_at?->isFuture()) data-initial="{{ json_encode($playerData($playing)) }}" @endif class="fixed inset-0 m-auto max-h-[90vh] w-[calc(100%-2rem)] max-w-xl overflow-y-auto rounded-3xl border-0 bg-white p-0 shadow-2xl backdrop:bg-slate-950/45" aria-labelledby="recording-player-title">
    <div class="flex items-start justify-between gap-4 border-b border-slate-100 p-6"><div><p class="text-xs font-bold text-blue-700">صدای تماس</p><h2 id="recording-player-title" data-player-title class="mt-2 text-lg font-black"></h2></div><button data-player-close class="rounded-xl px-3 py-2 text-slate-500 hover:bg-slate-100" aria-label="بستن پخش‌کننده">✕</button></div>
    <div class="space-y-5 p-6"><p data-player-participants dir="ltr" class="text-center font-bold"></p><audio data-player-audio controls preload="none" class="w-full" controlslist="nodownload"></audio><p data-player-error role="alert" hidden class="text-sm text-red-700">پخش فایل ممکن نشد. ممکن است فایل منقضی یا حذف شده باشد.</p><div class="flex flex-wrap items-center justify-center gap-3" dir="ltr"><button data-player-seek="-10" class="rounded-xl bg-slate-100 px-4 py-2 text-sm" aria-label="۱۰ ثانیه عقب">−10s</button><button data-player-seek="10" class="rounded-xl bg-slate-100 px-4 py-2 text-sm" aria-label="۱۰ ثانیه جلو">+10s</button><label class="flex items-center gap-2 text-xs">سرعت<select data-player-speed class="rounded-lg border border-slate-200 p-2"><option value="0.75">0.75×</option><option value="1" selected>1×</option><option value="1.25">1.25×</option><option value="1.5">1.5×</option><option value="2">2×</option></select></label></div>
        <dl class="grid grid-cols-2 gap-4 rounded-2xl bg-slate-50 p-4 text-sm"><div><dt class="text-xs text-slate-500">شماره کسب‌وکار</dt><dd data-player-number dir="ltr" class="mt-2 text-right font-bold"></dd></div><div><dt class="text-xs text-slate-500">پاسخ‌گو</dt><dd data-player-agent class="mt-2 font-bold"></dd></div><div class="col-span-2"><dt class="text-xs text-slate-500">حذف خودکار صدا</dt><dd data-player-expiry class="mt-2 font-bold"></dd></div></dl>
        <div class="flex items-center justify-between gap-3"><a data-player-download class="rounded-xl bg-blue-600 px-5 py-3 text-sm font-bold text-white" hidden>دانلود WAV</a><form data-player-delete method="POST" hidden onsubmit="return confirm('این صدا برای همیشه حذف شود؟ نسخه‌های دانلودشده حذف نمی‌شوند.')">@csrf @method('DELETE')<input type="hidden" name="confirm" value="1"><button class="rounded-xl border border-red-200 px-4 py-3 text-sm font-bold text-red-700">حذف صدا</button></form></div><p class="text-xs leading-6 text-slate-500">حذف صدا، تاریخچه تماس را حذف نمی‌کند. دانلود فقط برای کاربران دارای مجوز در دسترس است.</p>
    </div>
</dialog>
@if($playing && !($playing->status === 'ready' && $playing->expires_at?->isFuture()))<p role="status" class="mt-5 rounded-xl bg-slate-100 p-4 text-sm">{{ $playing->statusLabel() }}؛ این فایل اکنون قابل پخش نیست.</p>@endif
@endsection
