@extends('layouts.portal')
@section('title', 'داشبورد')
@section('content')
@php
    $isAdmin = $mode === 'admin';
    $s = $callSummary;
    $formatRate = fn($value) => $value === null ? '—' : number_format($value, $value == floor($value) ? 0 : 1).'٪';
    $comparison = function ($current, $previous, $rate = false, $lowerBetter = false) {
        if ($previous === null || $current === null) return ['text'=>'داده کافی برای مقایسه نیست', 'tone'=>'text-slate-500'];
        $change = $current - $previous;
        if ($change == 0) return ['text'=>'بدون تغییر نسبت به بازه قبل', 'tone'=>'text-slate-500'];
        $tone = $rate || $lowerBetter ? (($lowerBetter ? $change < 0 : $change > 0) ? 'text-emerald-700' : 'text-amber-700') : 'text-slate-600';
        if (!$rate && $previous == 0) return ['text'=>'بازه قبل: ۰ تماس', 'tone'=>$tone];
        $value = $rate ? abs($change) : abs($change * 100 / $previous);
        return ['text'=>($change > 0 ? '↑ ' : '↓ ').number_format($value, 1).($rate ? ' واحد درصد' : '٪').' نسبت به بازه قبل', 'tone'=>$tone];
    };
    if ($canViewCalls) {
        $historyFilters = array_filter($filters) + ['range'=>'custom', 'from'=>$s['window']['start']->toDateString(), 'to'=>$s['window']['end']->toDateString()];
        $metrics = [
            ['label'=>'تماس‌های ورودی','value'=>number_format($s['incoming']), 'hint'=>number_format($s['incoming_answered']).' پاسخ از '.number_format($s['incoming']).' تماس', 'url'=>route('calls.index',$historyFilters+['direction'=>'inbound']), 'comparison'=>$comparison($s['incoming'],$s['previous']['incoming']), 'icon'=>'M19 5 5 19 M5 5v14h14', 'tone'=>'bg-blue-50 text-blue-700'],
            ['label'=>'نرخ پاسخ ورودی','value'=>$formatRate($s['answerRate']), 'hint'=>$s['incoming'] ? 'پاسخ‌داده‌شده ÷ همه تماس‌های ورودی' : 'هنوز تماس ورودی ثبت نشده', 'url'=>route('calls.index',$historyFilters+['direction'=>'inbound']), 'comparison'=>$comparison($s['answerRate'],$s['previous']['answerRate'],true), 'icon'=>'m5 12 4 4L19 6', 'tone'=>'bg-emerald-50 text-emerald-700'],
            ['label'=>'ورودی‌های از دست‌رفته','value'=>number_format($s['missed']), 'hint'=>$s['incoming'] ? $formatRate(round($s['missed']*100/$s['incoming'],1)).' از تماس‌های ورودی' : 'ورودی بدون پاسخ', 'url'=>route('calls.index',$historyFilters+['direction'=>'inbound','status'=>'missed']), 'comparison'=>$comparison($s['missed'],$s['previous']['missed'],false,true), 'icon'=>'M6 6l12 12 M18 6 6 18', 'tone'=>'bg-amber-50 text-amber-700'],
            ['label'=>'تماس‌های خروجی','value'=>number_format($s['outgoing']), 'hint'=>number_format($s['outgoing_answered']).' پاسخ از '.number_format($s['outgoing']).' تماس', 'url'=>route('calls.index',$historyFilters+['direction'=>'outbound']), 'comparison'=>$comparison($s['outgoing'],$s['previous']['outgoing']), 'icon'=>'M5 19 19 5 M5 5h14v14', 'tone'=>'bg-slate-100 text-slate-600'],
        ];
        $seconds = $s['talk_seconds'];
        $talkTime = sprintf('%02d:%02d:%02d',intdiv($seconds,3600),intdiv($seconds%3600,60),$seconds%60);
        $outcomes = [
            ['label'=>'پاسخ‌داده‌شده','value'=>$s['incoming_answered'],'color'=>'bg-blue-600','status'=>'answered'],
            ['label'=>'از دست‌رفته','value'=>$s['missed'],'color'=>'bg-amber-500','status'=>'missed'],
            ['label'=>'ناموفق','value'=>$s['incoming_failed'],'color'=>'bg-red-500','status'=>'failed'],
        ];
    }
@endphp

<header class="flex flex-wrap items-start justify-between gap-5">
    <div><h1 class="text-2xl font-black text-slate-900 sm:text-[30px]">{{ $isAdmin ? 'نمای کلی پلتفرم' : 'داشبورد تماس‌ها' }}</h1></div>
    <form method="POST" action="{{ route('dashboard.preference') }}" aria-label="انتخاب بازه آماری" class="rounded-2xl border border-slate-200 bg-white p-1 shadow-sm">
        @csrf
        <div class="flex gap-1">@foreach($periods as $key=>$label)<button type="submit" name="period" value="{{ $key }}" aria-pressed="{{ $period === $key ? 'true' : 'false' }}" @class(['min-h-11 rounded-xl px-5 text-sm font-bold transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600','bg-blue-600 text-white shadow-sm'=>$period===$key,'text-slate-600 hover:bg-slate-50'=>$period!==$key])>{{ $label }}</button>@endforeach</div>
    </form>
</header>

@if (! $isAdmin && auth()->user()->hasPermission('providers.manage') && auth()->user()->hasPermission('numbers.manage') && auth()->user()->hasPermission('phones.manage') && auth()->user()->hasPermission('lines.view') && $configuration[0]['value'] === 0)
<section class="mt-6 flex flex-wrap items-center justify-between gap-4 rounded-2xl border border-blue-200 bg-blue-50 p-5"><div><h2 class="font-black text-blue-950">خط خود را راه‌اندازی کنید</h2><p class="mt-1 text-xs leading-6 text-blue-900">اتصال ارائه‌دهنده، شماره و پاسخ‌گو را با راهنمای مرحله‌به‌مرحله تنظیم کنید.</p></div><a href="{{ route('customer.setup.wizard') }}" class="rounded-xl bg-blue-600 px-5 py-3 text-xs font-bold text-white">شروع یا ادامه راه‌اندازی</a></section>
@endif

@if(count($attention['alerts']))
<section class="mt-6 rounded-2xl border border-amber-200 bg-white p-5" aria-labelledby="attention-title"><div class="mb-3 flex flex-wrap items-center justify-between gap-2"><h2 id="attention-title" class="text-sm font-black text-slate-900">نیازمند بررسی</h2><p class="text-[11px] text-slate-500">وضعیت فعلی سرویس · مستقل از بازه آماری</p></div><div class="divide-y divide-slate-100">@foreach($attention['alerts'] as $alert)<div class="flex flex-wrap items-center justify-between gap-3 py-3"><div class="flex items-start gap-3"><span @class(['mt-1 grid size-6 shrink-0 place-items-center rounded-full text-xs font-black','bg-red-50 text-red-700'=>$alert['tone']==='red','bg-amber-50 text-amber-700'=>$alert['tone']==='amber']) aria-hidden="true">!</span><div><h3 class="text-sm font-bold">{{ $alert['title'] }}</h3><p class="mt-1 max-w-2xl text-xs leading-6 text-slate-600">{{ $alert['description'] }}</p></div></div><a href="{{ $alert['url'] }}" class="inline-flex min-h-10 items-center rounded-xl border border-slate-200 px-3 text-xs font-bold text-blue-700 hover:bg-blue-50">{{ $alert['action'] }} ←</a></div>@endforeach</div></section>
@endif

@if($canViewCalls)
<div class="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
    @foreach($metrics as $metric)
    <a href="{{ $metric['url'] }}" class="panel group block p-5 transition hover:border-blue-300 hover:shadow-md focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600" aria-label="{{ $metric['label'] }}؛ مشاهده تماس‌های این بازه">
        <div class="flex items-center justify-between gap-3"><h2 class="text-xs font-bold text-slate-600">{{ $metric['label'] }}</h2><span class="grid size-9 place-items-center rounded-xl {{ $metric['tone'] }}"><svg aria-hidden="true" class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="{{ $metric['icon'] }}"/></svg></span></div>
        <p class="mt-4 w-fit text-3xl font-black tabular-nums tracking-tight text-slate-900" dir="ltr">{{ $metric['value'] }}</p>
        <p class="mt-3 text-[11px] text-slate-600">{{ $metric['hint'] }}</p>
        <div class="mt-4 flex items-center justify-between gap-2 border-t border-slate-100 pt-3"><p class="text-[11px] {{ $metric['comparison']['tone'] }}">{{ $metric['comparison']['text'] }}</p><span class="text-blue-600 opacity-50 group-hover:opacity-100" aria-hidden="true">←</span></div>
    </a>
    @endforeach
</div>
<p class="mt-3 text-[11px] leading-6 text-slate-500">مقایسه با بازه قبلیِ {{ $s['window']['days'] === 1 ? 'یک‌روزه' : $s['window']['days'].' روزه' }} تا همین ساعت · نرخ پاسخ، وضعیت ثبت‌شده در تاریخچه را نشان می‌دهد.</p>

<div class="mt-5 grid gap-5 xl:grid-cols-[minmax(0,1.8fr)_minmax(290px,1fr)]">
    <section class="panel min-w-0 p-5 sm:p-6" aria-labelledby="call-trend-title">
        <div class="flex flex-wrap items-center justify-between gap-3"><div><h2 id="call-trend-title" class="text-base font-black">روند تماس‌ها</h2><p class="mt-1 text-xs text-slate-500">{{ $period==='daily' ? 'ساعت‌به‌ساعت امروز' : 'روزبه‌روز در '.$s['window']['label'] }} · برای دیدن تماس‌ها، روی ستون انتخاب کنید</p></div><div class="flex gap-4 text-xs text-slate-600"><span class="flex items-center gap-1.5"><i class="size-2 rounded-full bg-blue-600" aria-hidden="true"></i>ورودی</span><span class="flex items-center gap-1.5"><i class="size-2 rounded-full bg-slate-400" aria-hidden="true"></i>خروجی</span></div></div>
        @if(!$s['total'])<div class="mt-6 grid min-h-60 place-items-center rounded-xl bg-slate-50 text-center"><div><p class="text-sm font-bold text-slate-600">در این بازه تماسی ثبت نشده است</p><p class="mt-2 text-xs text-slate-500">بازه دیگری را انتخاب کنید.</p></div></div>
        @else
        <div class="mt-6 flex gap-2"><div aria-hidden="true" class="flex h-48 w-7 shrink-0 flex-col justify-between text-[10px] tabular-nums text-slate-500"><span>{{ $s['chartMax'] }}</span><span>{{ (int)floor($s['chartMax']/2) }}</span><span>۰</span></div>
            <div class="min-w-0 flex-1 overflow-x-auto pb-2" tabindex="0" role="group" aria-label="نمودار تماس‌ها؛ هر ستون لینک به تاریخچه دارد">
                <div style="min-width: {{ max(360,count($s['series'])*30) }}px">
                    <div class="relative h-48 border-b border-slate-200"><div aria-hidden="true" class="pointer-events-none absolute inset-0 flex flex-col justify-between"><span class="border-t border-dashed border-slate-200"></span><span class="border-t border-dashed border-slate-200"></span><span></span></div>
                        <div class="relative flex h-full gap-1">@foreach($s['series'] as $bucket)
                            @php $bucketFilters = array_filter($filters)+['range'=>'custom','from'=>$bucket['date'],'to'=>$bucket['date']]; if($bucket['hour']!==null) $bucketFilters['hour']=$bucket['hour']; @endphp
                            <a href="{{ route('calls.index',$bucketFilters) }}" aria-label="{{ $bucket['date'] }} {{ $bucket['hour']!==null ? 'ساعت '.$bucket['hour'] : '' }}؛ {{ $bucket['in'] }} ورودی و {{ $bucket['out'] }} خروجی" class="group relative flex h-full min-w-0 flex-1 items-end justify-center gap-0.5 rounded-t-lg hover:bg-blue-50 focus-visible:bg-blue-50 focus-visible:outline-2 focus-visible:outline-blue-600">
                                <span class="pointer-events-none absolute top-0 z-10 hidden whitespace-nowrap rounded-lg bg-slate-900 px-2 py-1 text-[10px] text-white group-hover:block group-focus-visible:block">{{ $bucket['in'] }} ورودی · {{ $bucket['out'] }} خروجی</span>
                                <span class="w-2 rounded-t-sm bg-blue-600 sm:w-2.5" style="height: {{ $bucket['in']/$s['chartMax']*100 }}%"></span><span class="w-2 rounded-t-sm bg-slate-400 sm:w-2.5" style="height: {{ $bucket['out']/$s['chartMax']*100 }}%"></span>
                            </a>
                        @endforeach</div>
                    </div>
                    <div class="mt-3 flex gap-1 text-center text-[9px] tabular-nums text-slate-500" aria-hidden="true">@foreach($s['series'] as $bucket)<span class="min-w-0 flex-1" dir="ltr">{{ $bucket['label'] }}</span>@endforeach</div>
                </div>
            </div>
        </div>
        @endif
    </section>
    <section class="panel p-5 sm:p-6" aria-labelledby="outcomes-title"><h2 id="outcomes-title" class="text-base font-black">نتیجه تماس‌های ورودی</h2><p class="mt-1 text-xs text-slate-500">{{ $s['window']['label'] }} · {{ number_format($s['incoming']) }} تماس</p>
        @if($s['incoming'])<div class="my-6 flex h-3 overflow-hidden rounded-full bg-slate-100" aria-hidden="true">@foreach($outcomes as $item)<span class="{{ $item['color'] }}" style="width: {{ $item['value']/$s['incoming']*100 }}%"></span>@endforeach</div>@else<p class="my-6 text-sm text-slate-500">هنوز تماس ورودی ثبت نشده است.</p>@endif
        <div class="space-y-1">@foreach($outcomes as $item)<a href="{{ route('calls.index',$historyFilters+['direction'=>'inbound','status'=>$item['status']]) }}" class="flex min-h-10 items-center justify-between rounded-lg px-2 text-xs hover:bg-slate-50"><span class="flex items-center gap-2 text-slate-600"><i class="size-2 rounded-full {{ $item['color'] }}" aria-hidden="true"></i>{{ $item['label'] }}</span><span class="flex items-center gap-3"><small class="text-slate-500">{{ $s['incoming'] ? $formatRate(round($item['value']*100/$s['incoming'],1)) : '—' }}</small><strong class="tabular-nums">{{ number_format($item['value']) }}</strong></span></a>@endforeach</div>
        <dl class="mt-4 space-y-3 border-t border-slate-100 pt-4 text-xs"><div class="flex justify-between gap-3"><dt class="text-slate-600">نرخ پاسخ خروجی</dt><dd class="font-bold">{{ $formatRate($s['outgoingRate']) }}</dd></div><div class="flex justify-between gap-3"><dt class="text-slate-600">مجموع زمان مکالمه</dt><dd class="font-bold tabular-nums" dir="ltr">{{ $talkTime }}</dd></div></dl>
    </section>
</div>

<section class="panel mt-5 min-w-0 overflow-hidden" aria-labelledby="recent-calls-title">
    <div class="flex items-center justify-between gap-3 p-5 sm:px-6"><div><h2 id="recent-calls-title" class="text-base font-black">تماس‌های اخیر</h2><p class="mt-1 text-xs text-slate-500">{{ $s['window']['label'] }}</p></div><a href="{{ route('calls.index',$historyFilters) }}" class="text-xs font-bold text-blue-700">مشاهده همه ←</a></div>
    @if($s['recentCalls']->isEmpty())<p class="px-5 pb-8 text-center text-sm text-slate-500">در این بازه تماسی ثبت نشده است.</p>
    @else
    <div class="hidden overflow-x-auto md:block"><table class="w-full text-right"><thead class="bg-slate-50 text-[11px] text-slate-600"><tr><th class="px-6 py-3">مبدأ / مقصد</th><th class="px-4 py-3">نوع / نتیجه</th><th class="px-4 py-3">تیم / داخلی</th><th class="px-4 py-3">مکالمه</th><th class="px-4 py-3">زمان · تهران</th>@if($canViewRecordings)<th class="px-4 py-3">صدای تماس</th>@endif<th class="px-4 py-3"><span class="sr-only">جزئیات</span></th></tr></thead><tbody class="divide-y divide-slate-100">
    @foreach($s['recentCalls'] as $call)<tr class="text-xs"><td class="px-6 py-4"><span class="block w-fit font-bold" dir="ltr">{{ $call->source_number ?: '—' }}</span><span class="mt-1 block w-fit text-slate-500" dir="ltr">{{ $call->destination_number ?: '—' }}</span></td><td class="px-4 py-4"><span class="mb-2 block text-slate-500">{{ $call->direction==='inbound' ? 'ورودی' : 'خروجی' }}</span>@include('dashboard.call-status')</td><td class="px-4 py-4 text-slate-600">{{ $call->callQueue?->name ?: ($call->sipExtension?->display_name ?: '—') }}@if($call->sipExtension)<span class="mt-1 block text-[11px] text-slate-500">داخلی <bdi>{{ $call->sipExtension->extension }}</bdi></span>@endif</td><td class="px-4 py-4 tabular-nums" dir="ltr">{{ sprintf('%02d:%02d',intdiv($call->billable_seconds,60),$call->billable_seconds%60) }}</td><td class="whitespace-nowrap px-4 py-4 text-slate-500"><bdi>{{ $call->started_at->setTimezone($s['window']['timezone'])->format('m/d H:i') }}</bdi></td>@if($canViewRecordings)<td class="px-4 py-4">@forelse($call->recordings as $recording)@include('dashboard.recording-action') @empty<span class="text-slate-500">ضبط نشده</span>@endforelse</td>@endif<td class="px-4 py-4"><a href="{{ route('calls.show',$call) }}" class="inline-flex min-h-10 items-center font-bold text-blue-700">جزئیات ←</a></td></tr>@endforeach
    </tbody></table></div>
    <div class="divide-y divide-slate-100 md:hidden">@foreach($s['recentCalls'] as $call)<article class="p-5"><div class="flex items-center justify-between gap-2"><span class="text-xs font-bold text-slate-600">{{ $call->direction==='inbound' ? 'ورودی' : 'خروجی' }}</span>@include('dashboard.call-status')</div><dl class="mt-4 grid grid-cols-2 gap-3 text-xs"><div><dt class="text-slate-500">مبدأ</dt><dd class="mt-1 w-fit font-bold" dir="ltr">{{ $call->source_number ?: '—' }}</dd></div><div><dt class="text-slate-500">مقصد</dt><dd class="mt-1 w-fit font-bold" dir="ltr">{{ $call->destination_number ?: '—' }}</dd></div><div><dt class="text-slate-500">تیم / داخلی</dt><dd class="mt-1">{{ $call->callQueue?->name ?: ($call->sipExtension?->extension ?: '—') }}</dd></div><div><dt class="text-slate-500">زمان · تهران</dt><dd class="mt-1 w-fit" dir="ltr">{{ $call->started_at->setTimezone($s['window']['timezone'])->format('m/d H:i') }} · {{ sprintf('%02d:%02d',intdiv($call->billable_seconds,60),$call->billable_seconds%60) }}</dd></div></dl><div class="mt-4 flex flex-wrap items-center justify-between gap-2">@if($canViewRecordings)<div>@forelse($call->recordings as $recording)@include('dashboard.recording-action') @empty<span class="text-xs text-slate-500">ضبط نشده</span>@endforelse</div>@endif<a href="{{ route('calls.show',$call) }}" class="inline-flex min-h-10 items-center text-xs font-bold text-blue-700">جزئیات ←</a></div></article>@endforeach</div>
    @endif
</section>
@endif

<div class="mt-5 grid gap-5 {{ $attention['storage'] ? 'lg:grid-cols-2' : '' }}">
    <section class="panel p-5 sm:p-6" aria-labelledby="configuration-title"><div class="flex flex-wrap items-center justify-between gap-3"><div><h2 id="configuration-title" class="text-sm font-black">پیکربندی سرویس</h2><p class="mt-1 text-xs text-slate-500">تعداد موارد فعال در تنظیمات · وضعیت آنلاین نیست</p></div>@if($isAdmin)<a href="{{ route('admin.customer-connections.index') }}" class="text-xs font-bold text-blue-700">بررسی اتصال‌ها ←</a>@elseif(auth()->user()->hasPermission('lines.view'))<a href="{{ route('customer.setup.lines') }}" class="text-xs font-bold text-blue-700">مشاهده خط‌ها ←</a>@endif</div><dl class="mt-5 grid gap-5 sm:grid-cols-3">@foreach($configuration as $item)<div><dt class="text-xs font-bold text-slate-600">{{ $item['label'] }}</dt><dd class="mt-2 text-xl font-black tabular-nums">{{ number_format($item['value']) }}</dd><p class="mt-1 text-[11px] leading-5 text-slate-500">{{ $item['note'] }}</p></div>@endforeach</dl></section>
    @if($attention['storage'])@php $storage=$attention['storage']; @endphp<section class="panel p-5 sm:p-6"><div class="flex flex-wrap items-center justify-between gap-3"><h2 class="text-sm font-black">فضای ضبط تماس</h2><a href="{{ route(auth()->user()->hasPermission('recordings.manage') ? 'recordings.settings' : 'recordings.index') }}" class="text-xs font-bold text-blue-700">مدیریت ضبط‌ها ←</a></div><p class="mt-4 text-sm font-bold tabular-nums"><bdi>{{ number_format(($storage['used']+$storage['reserved'])/1048576,1) }} / {{ number_format($storage['quota']/1048576) }} MB</bdi><span class="mr-2 text-xs text-slate-500">{{ number_format($storage['percent'],1) }}٪</span></p><div class="mt-3 h-2 overflow-hidden rounded-full bg-slate-100" role="progressbar" aria-label="مصرف فضای ضبط" aria-valuenow="{{ min(100,$storage['percent']) }}" aria-valuemin="0" aria-valuemax="100"><div class="h-full rounded-full {{ $storage['percent']>=80 ? 'bg-amber-500' : 'bg-blue-600' }}" style="width: {{ min(100,$storage['percent']) }}%"></div></div><p class="mt-3 text-[11px] leading-6 text-slate-500">{{ number_format($storage['used']/1048576,1) }} MB فایل ذخیره‌شده · {{ number_format($storage['reserved']/1048576,1) }} MB رزروشده برای ضبط‌های در جریان</p></section>@endif
</div>
@endsection
