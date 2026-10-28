@extends('layouts.portal')
@section('title', 'تنظیم خط')
@section('content')
@php
    $route = $number->inboundRoute;
    $hasAnswer = $route && $route->enabled;
    $answerer = old('answerer', $route?->destination_type === 'queue' ? 'team' : ($route?->destination_type === 'ivr' ? 'menu' : ($route?->destination_type === 'extension' || $extensions->where('enabled', true)->isNotEmpty() ? 'existing' : 'new')));
    if (isset($selectedExtension)) {
        $answerer = old('answerer', 'existing');
    }
    $credentials = $credentials ?? null;
@endphp
<div class="mx-auto max-w-5xl space-y-6" data-line-workspace>
<a class="commerce-link inline-flex min-h-11 items-center text-sm font-bold" href="{{ route('customer.lines.index') }}">→ خط‌های من</a>
<header><h1 class="text-2xl font-black">تنظیم خط <bdi>{{ $number->number }}</bdi></h1><p class="mt-2 text-sm leading-7 text-slate-600">مشخص کنید تماس‌گیرنده به چه کسی برسد، چه ساعت‌هایی پاسخ می‌دهید و اگر کسی پاسخ نداد چه اتفاقی بیفتد.</p></header>
@if(isset($serviceReady) && !$serviceReady)<div role="status" class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm leading-7 text-amber-900">{{ $serviceMessage }}</div>@endif
@if($subscription)<p class="text-xs leading-7 text-slate-500">ظرفیت حساب: {{ $limits['extensions'] }} داخلی، {{ $limits['queues'] }} صف و {{ $limits['ivr_menus'] }} منو. {{ $subscription->status === 'pending_activation' ? 'ماه اول با ذخیره اولین مسیر تماس شروع می‌شود.' : '' }}</p>@endif
@include('customer.lines.partials.summary')
<nav aria-label="بخش‌های تنظیم خط" class="flex flex-wrap gap-2 text-sm">@foreach(['answer' => '۱. مسیر تماس', 'hours' => '۲. ساعت کاری', 'unanswered' => '۳. تماس بی‌پاسخ', 'check' => '۴. بررسی و آزمایش'] as $anchor => $label)<a href="#{{ $anchor }}" class="commerce-secondary">{{ $label }}</a>@endforeach</nav>
@include('customer.lines.partials.answer')
@include('customer.lines.partials.hours')
@include('customer.lines.partials.unanswered')
<section id="phones" class="panel scroll-mt-5 p-6"><h2 class="font-black">تلفن همکاران آماده است؟</h2><p class="mt-2 text-sm leading-7 text-slate-600">هر داخلی باید به Zoiper یا تلفن رومیزی وصل باشد. راهنمای اتصال، رمز و تنظیمات هر همکار در بخش داخلی‌هاست.</p>@if(auth()->user()->hasPermission('phones.manage'))<a href="{{ route('customer.extensions.index', ['number_id' => $number->id]) }}" class="commerce-secondary mt-4">مدیریت و اتصال داخلی‌ها ←</a>@endif</section>
@include('customer.lines.partials.outbound')
@include('customer.lines.partials.check')
</div>
@endsection
