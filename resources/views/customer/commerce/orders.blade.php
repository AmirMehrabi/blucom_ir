@extends('layouts.portal')
@section('title', 'سفارش‌ها و پرداخت‌های من')
@section('content')
<div data-commerce-page>
    <div class="flex flex-wrap items-start justify-between gap-4"><div><h1 class="text-2xl font-black sm:text-3xl">سفارش‌ها و پرداخت‌های من</h1><p class="mt-3 leading-8 text-slate-600">جزئیات سفارش، نتیجهٔ پرداخت و وضعیت آماده‌سازی شماره را پیگیری کنید.</p></div>@if(config('commerce.catalog_enabled') && auth('customer')->user()->hasPermission('numbers.purchase'))<a class="commerce-button" href="{{ route('customer.numbers.index') }}">انتخاب شمارهٔ جدید</a>@endif</div>
    <div class="mt-7 space-y-4">
        @forelse($orders as $order)
        <article class="panel flex min-w-0 flex-wrap items-center justify-between gap-5 p-5 sm:p-6">
            <div class="min-w-0"><p class="text-sm text-slate-500">سفارش {{ $order['reference'] }} · {{ $order['issued'] }}</p>@if($order['isTest'])<span class="mt-2 inline-block rounded-full bg-amber-50 px-3 py-1 text-xs font-bold text-amber-800">پرداخت آزمایشی</span>@endif<h2 class="mt-3 text-2xl font-black"><bdi dir="ltr">{{ $order['number'] }}</bdi></h2><p class="mt-2 break-words text-slate-600">{{ $order['planName'] }}</p></div>
            <div class="flex min-w-0 flex-wrap items-center gap-4"><div><span class="commerce-status commerce-status-{{ $order['tone'] }} inline-block border-0 px-3 py-1 text-sm font-bold">{{ $order['badge'] }}</span><p class="mt-3 font-bold">{{ $order['amount'] }} تومان</p></div>@if($order['setupUrl'])<a class="commerce-button" href="{{ $order['setupUrl'] }}">تنظیم خط</a>@endif<a class="commerce-secondary" href="{{ route('customer.orders.show', $order['publicId']) }}" aria-label="مشاهدهٔ جزئیات سفارش {{ $order['reference'] }}">جزئیات و پیگیری <span aria-hidden="true">←</span></a></div>
        </article>
        @empty
        <section class="panel px-6 py-12 text-center"><h2 class="text-xl font-bold">هنوز سفارشی ثبت نکرده‌اید</h2><p class="mx-auto mt-3 max-w-lg leading-8 text-slate-600">پس از انتخاب و رزرو شماره، جزئیات خرید و پرداخت شما در این صفحه نمایش داده می‌شود.</p>@if(config('commerce.catalog_enabled') && auth('customer')->user()->hasPermission('numbers.purchase'))<a class="commerce-button mt-6" href="{{ route('customer.numbers.index') }}">انتخاب شماره</a>@endif</section>
        @endforelse
    </div>
    @include('customer.commerce.partials.pagination', ['pages'=>$orders])
</div>
@endsection
