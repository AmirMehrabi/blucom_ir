@extends('layouts.portal')
@section('title', $invoice->kind === 'wallet_topup' ? 'شارژ کیف پول' : 'پرداخت صورتحساب')
@section('content')
<section class="panel mx-auto max-w-2xl p-6 sm:p-8" data-commerce-page>
    <h1 class="text-2xl font-black">{{ $invoice->kind === 'wallet_topup' ? 'شارژ کیف پول و ادامه خرید' : 'پرداخت صورتحساب' }}</h1>
    <p class="mt-4 text-3xl font-black">{{ \App\Services\Commerce\CustomerCommercePresenter::money($invoice->total_amount) }} <small class="text-base font-normal">تومان</small></p>
    @if($invoice->kind === 'wallet_topup')<p class="mt-3 text-sm leading-8 text-slate-600">پس از تأیید بانک، مبلغ به کیف پول شما اضافه می‌شود.@if($invoice->wallet_target_invoice_id) همین خرید خودکار ادامه پیدا می‌کند. اگر خرید ممکن نباشد، پول در کیف پول شما محفوظ می‌ماند.@endif</p>@endif
    @if($canPay && $payment->provider === 'mellat')
        <form method="POST" action="{{ $bankUrl }}" class="mt-6" data-commerce-submit><input type="hidden" name="RefId" value="{{ $payment->ref_id }}"><button class="commerce-button" data-busy-label="در حال ورود به درگاه…">ورود به درگاه بانک ملت</button></form>
    @elseif($canPay && $payment->provider === 'zibal')
        <a class="commerce-button mt-6" href="{{ 'https://gateway.zibal.ir/start/'.$payment->ref_id }}">ورود به درگاه زیبال</a>
    @elseif($invoice->status === 'paid')
        <p role="status" class="mt-5 rounded-xl bg-emerald-50 p-4 text-sm leading-7 text-emerald-900">پرداخت شما تأیید شده است. موجودی و نتیجه خرید را بررسی کنید.</p>
        <a class="commerce-button mt-4" href="{{ route('customer.payments.return', $payment->public_id) }}">مشاهده نتیجه و ادامه</a>
    @elseif($payment->status === 'initiation_failed' || $payment->hasUndeliveredZibalInitiation())
        <p role="status" class="mt-5 rounded-xl bg-amber-50 p-4 text-sm leading-7 text-amber-900">صفحه بانک آماده نشد. خرید شما محفوظ است؛ مبلغ و وضعیت را بررسی کنید و دوباره ادامه دهید.</p>
        <a class="commerce-button mt-4" href="{{ route('customer.billing.invoice', $invoice->public_id) }}">بررسی و تلاش دوباره</a>
    @else
        <p role="status" class="mt-5 rounded-xl bg-amber-50 p-4 text-sm leading-7 text-amber-900">نتیجه پرداخت در حال بررسی است. پرداخت دوباره لازم نیست. با تأیید بانک، موجودی و خرید به‌روز می‌شوند.</p>
        <a class="commerce-secondary mt-4" href="{{ route('customer.payments.return', $payment->public_id) }}">به‌روزرسانی وضعیت</a>
    @endif
    <a class="commerce-link mt-6 block" href="{{ route('customer.billing.invoice', $invoice->public_id) }}">مشاهده جزئیات و رسید</a>
    <a class="commerce-link mt-4 block" href="{{ route('customer.billing.index') }}">بازگشت به صورتحساب‌ها</a>
</section>
@endsection
