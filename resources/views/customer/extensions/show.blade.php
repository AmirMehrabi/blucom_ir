@extends('layouts.portal')
@section('title', 'تنظیم داخلی')
@section('content')
<a href="{{ route('customer.extensions.index') }}" class="commerce-link inline-flex min-h-11 items-center font-bold">→ داخلی‌ها</a>
<h1 class="mt-3 text-2xl font-black">{{ $phone->display_name }} · <bdi>{{ $phone->extension }}</bdi></h1>
<a href="{{ route('customer.extensions.pickup') }}" class="commerce-secondary mt-4">تنظیم برداشت تماس همکار</a>
<section class="panel mt-6 p-6"><h2 class="mb-3 font-bold">دریافت تماس جدید</h2>@include('extensions.availability', ['extension'=>$phone])<p class="mt-3 text-xs leading-7 text-slate-500">تماس جاری قطع نمی‌شود و تماس خروجی مجاز می‌ماند. تماس‌های جدید به مسیر جایگزین می‌روند؛ اگر مسیر جایگزین ندارید، تماس پایان می‌یابد. این وضعیت تا ادامه دستی حفظ می‌شود.</p></section>
<section class="panel mt-6 p-6"><h2 class="font-black">۱. اتصال تلفن</h2><p class="mt-2 text-sm leading-7 text-slate-600">اطلاعات اتصال را در Zoiper یا تلفن SIP وارد کنید. رمز فقط پس از ساخت یا بازنشانی نمایش داده می‌شود.</p>
@include('customer.lines.partials.phones')
@include('customer.lines.partials.phone-guide')
<p data-copy-status role="status" aria-live="polite" class="mt-3 text-xs text-slate-600"></p></section>
<section class="panel mt-6 p-6"><h2 class="font-black">۲. تنظیمات داخلی</h2><form method="POST" action="{{ route('customer.extensions.update', $phone) }}" class="mt-4 space-y-4">@csrf @method('PUT')<input type="hidden" name="enabled" value="{{ (int) $phone->enabled }}"><label class="block text-sm font-bold">نام همکار یا تلفن<input required name="display_name" maxlength="100" value="{{ old('display_name', $phone->display_name) }}" class="mt-2 w-full rounded-xl border border-slate-200 px-4 py-3"></label><button class="commerce-button">ذخیره نام</button></form></section>
@include('recordings.destination-setting', ['destination' => 'extension', 'record' => $phone])
<section id="follow-me" class="panel mt-6 scroll-mt-6 p-4 sm:p-6">
    @include('shared.follow-me', [
        'source' => $phone, 'isQueue' => false,
        'action' => route('customer.extensions.follow-me', ['extension' => $phone->id, 'number_id' => $setupNumber?->id]),
    ])
</section>
<section class="panel mt-6 p-6"><h2 class="font-black">۳. مسیر تماس‌ها</h2><p class="mt-2 text-sm leading-7 text-slate-600">ساخت داخلی به‌تنهایی مسیر تماس را تغییر نمی‌دهد. برای دریافت تماس، این داخلی را در خط، منوی صوتی یا تیم انتخاب کنید.</p>
@if($phone->enabled && auth()->user()->hasPermission('lines.view'))
@if($setupNumber)<a class="commerce-button mt-4" href="{{ route('customer.lines.show', ['number' => $setupNumber->id, 'extension_id' => $phone->id]) }}#answer">انتخاب این داخلی برای خط {{ $setupNumber->number }}</a>@endif
<h3 class="mt-5 text-sm font-bold">خط و شماره تماس خروجی</h3><p class="mt-2 text-xs leading-7 text-slate-600">در تنظیم خط، پاسخ‌گوی تماس ورودی و داخلی‌های مجاز برای تماس خروجی با شماره همان خط را انتخاب کنید.</p>
<div class="mt-3 flex flex-wrap gap-3">@forelse($numbers as $number)<a class="commerce-secondary" href="{{ route('customer.lines.show', ['number' => $number->id, 'extension_id' => $phone->id]) }}#answer"><bdi>{{ $number->number }}</bdi> · تنظیم مسیر</a>@empty<a class="commerce-link" href="{{ route('customer.start') }}">افزودن خط ←</a>@endforelse</div>
@endif
<dl class="mt-5 space-y-3 text-sm"><div><dt class="font-bold">خط‌هایی که مستقیم به این داخلی می‌رسند</dt><dd class="mt-1 text-slate-600">{{ $inboundRoutes->filter(fn($item) => $item->sipNumber?->tenant_id === $phone->tenant_id)->map(fn($item) => ($item->sipNumber?->number ?? '').($item->enabled ? '' : ' (مسیر غیرفعال)'))->implode('، ') ?: 'تنظیم نشده' }}</dd></div><div><dt class="font-bold">عضویت در تیم‌ها</dt><dd class="mt-1 text-slate-600">{{ $teams->pluck('name')->implode('، ') ?: 'عضو تیمی نیست' }}</dd></div><div><dt class="font-bold">منوهای منتشرشده با مقصد این داخلی</dt><dd class="mt-1 text-slate-600">{{ $menus->pluck('name')->implode('، ') ?: 'تنظیم نشده' }}</dd></div><div><dt class="font-bold">شماره تماس خروجی فعلی</dt><dd class="mt-1 text-slate-600"><bdi>{{ $phone->outboundRoute?->enabled && $phone->outboundRoute?->tenant_id === $phone->tenant_id && $phone->outboundRoute?->sipNumber?->tenant_id === $phone->tenant_id ? $phone->outboundRoute->sipNumber->number : 'تنظیم نشده' }}</bdi></dd></div></dl>
<div class="mt-5 flex flex-wrap gap-3"><a class="commerce-secondary" href="{{ route('ivr-menus.index') }}">تنظیم منوی صوتی</a>@if(config('voip.queues_enabled'))<a class="commerce-secondary" href="{{ route('teams.index') }}">تنظیم تیم پاسخ‌گویی</a>@endif</div></section>
<section class="panel mt-6 p-6"><h2 class="font-bold">آخرین تغییرات دریافت تماس</h2><p class="mt-2 text-xs text-slate-500">توقف دریافت تماس و استراحت تیمی جداگانه ثبت می‌شوند. زمان‌ها به وقت تهران است.</p><ul class="mt-4 divide-y divide-slate-100">@forelse($availabilityEvents as $event)<li class="flex flex-wrap justify-between gap-3 py-3 text-sm"><span>{{ ['paused'=>'توقف دریافت تماس','resumed'=>'ادامه دریافت تماس','break_started'=>'شروع استراحت تیمی','break_ended'=>'پایان استراحت تیمی'][$event->action] ?? 'تغییر وضعیت' }}</span><bdi class="text-xs text-slate-500">{{ \Carbon\CarbonImmutable::parse($event->occurred_at,'UTC')->setTimezone(config('voip.display_timezone','Asia/Tehran'))->format('Y/m/d H:i') }}</bdi></li>@empty<li class="py-4 text-sm text-slate-500">هنوز تغییری ثبت نشده است.</li>@endforelse</ul></section>
@endsection
