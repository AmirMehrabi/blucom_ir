@extends('layouts.portal')
@section('title', 'خرید شماره')
@section('content')
<div data-commerce-page>
    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div><h1 class="text-2xl font-black sm:text-3xl">شمارهٔ کسب‌وکارتان را انتخاب کنید</h1><p class="mt-3 max-w-2xl leading-8 text-slate-600">یک شماره، همراه با امکانات پاسخ‌گویی کسب‌وکار شما. قیمت‌ها به تومان و برای یک ماه هستند.</p></div>
        @if(auth('customer')->user()->hasPermission('billing.view'))<a class="commerce-secondary" href="{{ route('customer.orders.index') }}">سفارش‌ها و پرداخت‌های من</a>@endif
    </div>
    @include('customer.commerce.partials.steps', ['step'=>1])
    @if(!$enabled)
        <section class="panel px-6 py-12 text-center"><h2 class="text-xl font-bold">خرید شماره فعلاً در دسترس نیست</h2><p class="mx-auto mt-3 max-w-lg leading-8 text-slate-600">لطفاً بعداً دوباره به این صفحه سر بزنید. سفارش‌ها و پرداخت‌های قبلی شما همچنان قابل پیگیری هستند.</p></section>
    @else
        <div class="grid gap-5 md:grid-cols-2 xl:grid-cols-3">
            @forelse($offers as $selection)
            <article class="panel flex min-w-0 flex-col p-6">
                <div class="flex items-center gap-3"><span class="grid size-10 shrink-0 place-items-center rounded-xl bg-blue-50 text-blue-700"><svg aria-hidden="true" class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path stroke-linecap="round" stroke-linejoin="round" d="M6 3h12v18H6z M9 7h6 M9 11h6 M9 15h3"/></svg></span><p class="text-sm text-slate-500">شمارهٔ قابل انتخاب</p></div>
                <h2 class="mt-5 text-2xl font-black tracking-wide"><bdi dir="ltr">{{ $selection['number'] }}</bdi></h2>
                <p class="mt-3 break-words font-bold">{{ $selection['planName'] }}</p>
                <ul class="mt-5 flex-1 space-y-2 text-sm text-slate-600">@foreach($selection['features'] as $feature)<li class="flex items-center gap-2"><span aria-hidden="true" class="size-1.5 rounded-full bg-blue-500"></span>{{ $feature }}</li>@endforeach</ul>
                <div class="mt-6 border-t pt-5"><p class="text-sm text-slate-500">هزینهٔ ماهانهٔ شماره و پلن</p><p class="mt-2 text-2xl font-black">{{ $selection['amount'] }} <span class="text-sm font-normal">تومان</span></p></div>
                <a href="{{ route('customer.numbers.review', $selection['offerId']) }}" class="commerce-button mt-5" aria-label="بررسی خرید شمارهٔ {{ $selection['number'] }}">انتخاب و بررسی خرید <span aria-hidden="true">←</span></a>
            </article>
            @empty
            <section class="panel px-6 py-12 text-center md:col-span-2 xl:col-span-3"><h2 class="text-xl font-bold">فعلاً شماره‌ای برای انتخاب موجود نیست</h2><p class="mx-auto mt-3 max-w-lg leading-8 text-slate-600">شماره‌های آمادهٔ خرید در همین صفحه نمایش داده می‌شوند. لطفاً بعداً دوباره بررسی کنید.</p><a class="commerce-secondary mt-6" href="{{ route('customer.numbers.index') }}">بررسی دوباره</a></section>
            @endforelse
        </div>
        @include('customer.commerce.partials.pagination', ['pages'=>$offers])
    @endif
</div>
@endsection
