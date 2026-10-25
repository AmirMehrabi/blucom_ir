@extends('layouts.portal')
@section('title', 'سفارش‌ها و پرداخت‌های من')
@section('content')
<div data-commerce-page>
    <div class="flex flex-wrap items-start justify-between gap-4"><div><h1 class="text-2xl font-black sm:text-3xl">سفارش‌ها و پرداخت‌های من</h1><p class="mt-3 leading-8 text-slate-600">جزئیات سفارش، نتیجهٔ پرداخت و وضعیت آماده‌سازی شماره را پیگیری کنید.</p></div>@if(config('commerce.catalog_enabled') && auth('customer')->user()->hasPermission('numbers.purchase'))<a class="commerce-button" href="{{ route('customer.numbers.index') }}">انتخاب شمارهٔ جدید</a>@endif</div>
    @include('customer.billing.nav', ['tab'=>'orders'])
    @include('customer.billing.orders-list')
</div>
@endsection
