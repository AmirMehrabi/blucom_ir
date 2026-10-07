@extends('layouts.portal')
@section('title', 'پرداخت شماره')
@section('content')
<section class="panel mx-auto max-w-xl p-6 sm:p-8">
    <h1 class="text-xl font-black">پرداخت شماره</h1>
    <p class="mt-5 text-2xl font-black">{{ number_format($amount) }} <span class="text-sm font-normal">تومان</span></p>
    @if($canPay)
        <p class="mt-4 text-sm leading-7 text-slate-500">رزرو تا {{ $expires->timezone('Asia/Tehran')->format('H:i') }} معتبر است. برای پرداخت به درگاه امن به‌پرداخت ملت بروید.</p>
        <form method="POST" action="{{ $bankUrl }}" class="mt-6"><input type="hidden" name="RefId" value="{{ $refId }}"><button class="min-h-11 w-full rounded-xl bg-blue-600 p-3 font-bold text-white">ادامه در بانک ملت</button></form>
    @elseif($status === 'settled')
        <p class="mt-5 rounded-xl bg-emerald-50 p-4 text-sm leading-7 text-emerald-800">پرداخت بانکی تأیید شد. سفارش برای تخصیص یا بررسی خط ثبت شده است؛ سرویس هنوز فعال نشده است.</p>
    @elseif($status === 'reversed')
        <p class="mt-5 text-sm leading-7 text-slate-600">بانک برگشت این پرداخت را تأیید کرده است. سفارش هنوز پرداخت نشده است.</p>
    @elseif($status === 'initiation_failed')
        <p class="mt-5 text-sm leading-7 text-red-700">درخواست پرداخت پذیرفته نشد. تنظیمات درگاه باید بررسی شود.</p>
    @elseif($status === 'redirect_ready')
        <p class="mt-5 text-sm leading-7 text-amber-800">ادامه پرداخت برای این حساب یا رزرو در دسترس نیست. وضعیت سفارش و دسترسی حساب را بررسی کنید.</p>
    @else
        <p class="mt-5 text-sm leading-7 text-amber-800">نتیجه پرداخت هنوز قطعی نیست و نیاز به بررسی دارد. پرداخت دیگری برای این سفارش آغاز نشده است.</p>
    @endif
</section>
@endsection
