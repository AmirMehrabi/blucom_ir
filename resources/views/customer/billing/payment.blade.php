@extends('layouts.portal')
@section('title', 'پرداخت صورتحساب')
@section('content')
<section class="panel mx-auto max-w-2xl p-7"><h1 class="text-2xl font-black">پرداخت صورتحساب</h1><p class="mt-4" dir="ltr">{{ $invoice->invoice_number }}</p><p class="mt-3 text-xl font-bold">{{ number_format($invoice->total_amount) }} تومان</p>
    @if($canPay && $payment->provider === 'mellat')<form method="POST" action="{{ $bankUrl }}" class="mt-6"><input type="hidden" name="RefId" value="{{ $payment->ref_id }}"><button class="rounded-xl bg-blue-700 px-5 py-3 font-bold text-white">ورود به درگاه بانک ملت</button></form>
    @elseif($canPay && $payment->provider === 'zibal')<a class="mt-6 inline-block rounded-xl bg-blue-700 px-5 py-3 font-bold text-white" href="{{ 'https://gateway.zibal.ir/start/'.$payment->ref_id }}">ورود به درگاه زیبال</a>
    @else<p class="mt-5 leading-8 text-amber-900">نتیجه پرداخت در حال بررسی است. پیش از شروع پرداخت دیگری، وضعیت صورتحساب را دوباره بررسی کنید.</p>@endif
    <a class="mt-6 block font-bold text-blue-700" href="{{ route('customer.billing.index') }}">بازگشت به صورتحساب‌ها</a>
</section>
@endsection
