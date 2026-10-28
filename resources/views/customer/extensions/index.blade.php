@extends('layouts.portal')
@section('title', 'داخلی‌ها')
@section('content')
<header class="flex flex-wrap items-start justify-between gap-4"><div><h1 class="text-2xl font-black">داخلی‌ها</h1><p class="mt-2 text-sm leading-7 text-slate-600">برای هر همکار یک داخلی بسازید، تلفن او را وصل کنید و مسیر تماس‌ها را انتخاب کنید.</p></div><a href="#new-extension" class="commerce-button">+ افزودن داخلی</a></header>
@if($setupNumber)<a href="{{ route('customer.lines.show', $setupNumber) }}#answer" class="commerce-link mt-4 inline-flex min-h-11 items-center">بازگشت به تنظیم خط {{ $setupNumber->number }}</a>@endif
<section class="panel mt-6 overflow-x-auto"><table class="w-full min-w-[600px] text-right text-sm"><thead class="bg-slate-50"><tr><th class="p-4">نام / شماره داخلی</th><th class="p-4">وضعیت</th><th class="p-4">اتصال تلفن</th><th class="p-4">شماره خروجی</th><th class="p-4">مدیریت</th></tr></thead><tbody>
@forelse($extensions as $extension)
@php($registration = $live->get($extension->id)['registered'] ?? null)
<tr class="border-t border-slate-100"><td class="p-4 font-bold">{{ $extension->display_name }}<bdi class="mt-1 block text-xs text-slate-500">{{ $extension->extension }}</bdi></td><td class="p-4">{{ $extension->enabled ? 'فعال' : 'غیرفعال' }}</td><td class="p-4">{{ !$extension->enabled ? 'داخلی غیرفعال' : ($registration === true ? 'تلفن متصل است' : ($registration === false ? 'تلفن ثبت نشده' : 'وضعیت اتصال نامشخص')) }}</td><td class="p-4"><bdi>{{ $extension->outboundRoute?->enabled && $extension->outboundRoute?->tenant_id === $extension->tenant_id && $extension->outboundRoute?->sipNumber?->tenant_id === $extension->tenant_id ? $extension->outboundRoute->sipNumber->number : 'تنظیم نشده' }}</bdi></td><td class="p-4"><a class="commerce-link inline-flex min-h-11 items-center font-bold" href="{{ route('customer.extensions.show', ['extension' => $extension->id] + ($setupNumber ? ['number_id' => $setupNumber->id] : [])) }}">اتصال و تنظیمات ←</a></td></tr>
@empty<tr><td colspan="5" class="p-6 text-slate-600">هنوز داخلی ندارید. برای شروع، نام همکار و شماره داخلی را در فرم زیر وارد کنید.</td></tr>@endforelse
</tbody></table></section>
<p class="mt-3 text-xs leading-7 text-slate-500">برای به‌روزکردن وضعیت اتصال، صفحه را تازه کنید. وضعیت زنده با مجوز مشاهده وضعیت زنده نمایش داده می‌شود.</p>
<section id="new-extension" class="panel mt-6 scroll-mt-5 p-6"><h2 class="font-black">افزودن داخلی</h2><form action="{{ route('customer.extensions.store') }}" method="POST" class="mt-4 grid gap-4 sm:grid-cols-2">@csrf
@if($setupNumber)<input type="hidden" name="number_id" value="{{ $setupNumber->id }}">@endif
<label class="block text-sm font-bold">نام همکار یا تلفن<input required name="display_name" maxlength="100" value="{{ old('display_name') }}" placeholder="مثلاً سارا، پذیرش یا فروش" class="mt-2 w-full rounded-xl border border-slate-200 px-4 py-3"></label>
@include('customer.lines.partials.extension-number', ['inputId' => 'new-extension-number', 'formName' => 'answer'])
<div class="sm:col-span-2"><button class="commerce-button">ساخت داخلی و اتصال تلفن</button><p class="mt-3 text-xs leading-7 text-slate-500">رمز امن به‌صورت خودکار ساخته می‌شود. پس از اتصال تلفن، پاسخ‌گوی خط و مجوز تماس خروجی را جداگانه تنظیم کنید. ظرفیت داخلی‌ها طبق اشتراک حساب بررسی می‌شود.</p></div></form></section>
@endsection
