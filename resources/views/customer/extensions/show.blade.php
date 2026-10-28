@extends('layouts.portal')
@section('title', 'تنظیم داخلی')
@section('content')
<a href="{{ route('customer.extensions.index') }}" class="commerce-link inline-flex min-h-11 items-center font-bold">→ داخلی‌ها</a>
<h1 class="mt-3 text-2xl font-black">{{ $phone->display_name }} · <bdi>{{ $phone->extension }}</bdi></h1>
<section class="panel mt-6 p-6"><h2 class="font-black">۱. اتصال تلفن</h2><p class="mt-2 text-sm leading-7 text-slate-600">اطلاعات اتصال را در Zoiper یا تلفن SIP وارد کنید. رمز فقط پس از ساخت یا بازنشانی نمایش داده می‌شود.</p>
@include('customer.lines.partials.phones')
@include('customer.lines.partials.phone-guide')
<p data-copy-status role="status" aria-live="polite" class="mt-3 text-xs text-slate-600"></p></section>
<section class="panel mt-6 p-6"><h2 class="font-black">۲. تنظیمات داخلی</h2><form method="POST" action="{{ route('customer.extensions.update', $phone) }}" class="mt-4 space-y-4">@csrf @method('PUT')<input type="hidden" name="enabled" value="{{ (int) $phone->enabled }}"><label class="block text-sm font-bold">نام همکار یا تلفن<input required name="display_name" maxlength="100" value="{{ old('display_name', $phone->display_name) }}" class="mt-2 w-full rounded-xl border border-slate-200 px-4 py-3"></label><button class="commerce-button">ذخیره نام</button></form></section>
<section class="panel mt-6 p-6"><h2 class="font-black">۳. مسیر تماس‌ها</h2><p class="mt-2 text-sm leading-7 text-slate-600">ساخت داخلی به‌تنهایی مسیر تماس را تغییر نمی‌دهد. برای دریافت تماس، این داخلی را در خط، منوی صوتی یا تیم انتخاب کنید.</p>
@if($phone->enabled && auth()->user()->hasPermission('lines.view'))
@if($setupNumber)<a class="commerce-button mt-4" href="{{ route('customer.lines.show', ['number' => $setupNumber->id, 'extension_id' => $phone->id]) }}#answer">انتخاب این داخلی برای خط {{ $setupNumber->number }}</a>@endif
<h3 class="mt-5 text-sm font-bold">خط و شماره تماس خروجی</h3><p class="mt-2 text-xs leading-7 text-slate-600">در تنظیم خط، پاسخ‌گوی تماس ورودی و داخلی‌های مجاز برای تماس خروجی با شماره همان خط را انتخاب کنید.</p>
<div class="mt-3 flex flex-wrap gap-3">@forelse($numbers as $number)<a class="commerce-secondary" href="{{ route('customer.lines.show', ['number' => $number->id, 'extension_id' => $phone->id]) }}#answer"><bdi>{{ $number->number }}</bdi> · تنظیم مسیر</a>@empty<a class="commerce-link" href="{{ route('customer.start') }}">افزودن خط ←</a>@endforelse</div>
@endif
<dl class="mt-5 space-y-3 text-sm"><div><dt class="font-bold">خط‌هایی که مستقیم به این داخلی می‌رسند</dt><dd class="mt-1 text-slate-600">{{ $inboundRoutes->filter(fn($item) => $item->sipNumber?->tenant_id === $phone->tenant_id)->map(fn($item) => ($item->sipNumber?->number ?? '').($item->enabled ? '' : ' (مسیر غیرفعال)'))->implode('، ') ?: 'تنظیم نشده' }}</dd></div><div><dt class="font-bold">عضویت در تیم‌ها</dt><dd class="mt-1 text-slate-600">{{ $teams->pluck('name')->implode('، ') ?: 'عضو تیمی نیست' }}</dd></div><div><dt class="font-bold">منوهای منتشرشده با مقصد این داخلی</dt><dd class="mt-1 text-slate-600">{{ $menus->pluck('name')->implode('، ') ?: 'تنظیم نشده' }}</dd></div><div><dt class="font-bold">شماره تماس خروجی فعلی</dt><dd class="mt-1 text-slate-600"><bdi>{{ $phone->outboundRoute?->enabled && $phone->outboundRoute?->tenant_id === $phone->tenant_id && $phone->outboundRoute?->sipNumber?->tenant_id === $phone->tenant_id ? $phone->outboundRoute->sipNumber->number : 'تنظیم نشده' }}</bdi></dd></div></dl>
<div class="mt-5 flex flex-wrap gap-3"><a class="commerce-secondary" href="{{ route('ivr-menus.index') }}">تنظیم منوی صوتی</a>@if(config('voip.queues_enabled'))<a class="commerce-secondary" href="{{ route('teams.index') }}">تنظیم تیم پاسخ‌گویی</a>@endif</div></section>
@endsection
