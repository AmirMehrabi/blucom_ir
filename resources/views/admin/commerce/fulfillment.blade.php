@extends('layouts.portal')
@section('title', 'تخصیص سفارش‌های پرداخت‌شده')
@section('content')
<h1 class="text-2xl font-black">تخصیص سفارش‌های پرداخت‌شده</h1>
<p class="mt-3 text-sm leading-7 text-slate-600">پرداخت تسویه‌شده به‌صورت خودکار تخصیص می‌یابد. ترمیم فقط برای همان شماره آزاد و بدون رزرو مشتری دیگر ممکن است. شروع ماه اول با ذخیره اولین پاسخ‌گوی مشتری است.</p>
@if(session('status'))<p role="status" class="mt-4 rounded-xl bg-blue-50 p-4">{{ session('status') }}</p>@endif
@if($errors->any())<div role="alert" class="mt-4 rounded-xl bg-red-50 p-4">{{ $errors->first() }}</div>@endif
<div class="mt-6 space-y-4">
@forelse($orders as $order)
<section class="panel p-5"><div class="flex flex-wrap justify-between gap-3"><h2 class="font-bold">سفارش {{ $order->id }} · <bdi dir="ltr">{{ $order->item->snapshot['number'] }}</bdi></h2><span>{{ $order->status }}</span></div>
<p class="mt-2 text-sm">{{ number_format($order->total_amount) }} تومان · حساب کسب‌وکار {{ $order->tenant_id }}</p>
@if($order->status !== 'allocated')
<form class="mt-4 flex flex-wrap gap-3" method="POST" action="{{ route('admin.fulfillment.repair', $order->id) }}">@csrf
<label class="flex-1">دلیل ترمیم<input class="field mt-2 w-full" name="reason" required minlength="10" maxlength="1000" placeholder="دلیل بررسی و تخصیص مجدد"></label><button class="btn-primary self-end" type="submit">بررسی و ترمیم تخصیص</button></form>
@endif
</section>
@empty<p class="panel p-6">سفارش پرداخت‌شده‌ای برای تخصیص وجود ندارد.</p>@endforelse
</div><div class="mt-6">{{ $orders->links() }}</div>
@endsection
