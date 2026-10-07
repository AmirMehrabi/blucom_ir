@extends('layouts.portal')
@section('title', 'بررسی پرداخت‌ها')
@section('content')
<h1 class="text-2xl font-black">بررسی پرداخت‌ها</h1>
<p class="mt-3 text-sm leading-7 text-slate-500">نتیجه بانکی و وضعیت سفارش مستقل هستند. پرداخت تأییدشده تا تکمیل تخصیص، خط را فعال نمی‌کند.</p>
<a class="mt-3 inline-block text-sm font-bold text-blue-700" href="{{ route('admin.payment-gateways.index') }}">تنظیمات درگاه‌ها</a>
<form method="GET" class="my-5 flex flex-wrap gap-3"><label class="text-sm">وضعیت<select name="status" class="mr-2 rounded-xl border bg-white p-3"><option value="">همه</option>@foreach(\App\Models\PaymentAttempt::statusLabels() as $status => $label)<option value="{{ $status }}" @selected(request('status') === $status)>{{ $label }}</option>@endforeach</select></label><button class="rounded-xl border bg-white px-4 text-sm font-bold">فیلتر</button></form>
@forelse($attempts as $attempt)
    @php($invoice = $invoices[$attempt->commerce_invoice_id])
    <section class="panel mb-4 p-5">
        <div class="flex flex-wrap justify-between gap-3"><h2 class="break-all text-sm font-bold">{{ $invoice->invoice_number }}</h2><span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-bold" dir="ltr">{{ $attempt->statusLabel() }}</span></div>
        <p class="mt-3 text-sm">{{ $invoice->buyer_snapshot['business_name'] }} · {{ number_format($attempt->business_amount) }} تومان · تلاش {{ $attempt->id }}</p>
        <p class="mt-2 text-sm text-slate-500">صورتحساب: {{ $invoice->status === 'paid' ? 'پرداخت‌شده' : ($invoice->status === 'issued' ? 'صادرشده' : 'نیازمند بررسی') }} @if($attempt->last_code !== null) · کد پاسخ بانک: {{ $attempt->last_code }} @endif</p>
        @if(!in_array($attempt->status, ['settled', 'duplicate_payment', 'reversed'], true) && $attempt->ref_id && ($attempt->sale_reference || $attempt->candidate_sale_reference))
            <form method="POST" action="{{ route('admin.payments.reconcile', $attempt->id) }}" class="mt-4 flex flex-wrap items-end gap-3">@csrf<label class="min-w-0 flex-1 text-sm font-bold">دلیل بررسی<input name="reason" required maxlength="1000" class="mt-2 w-full rounded-xl border p-3"></label><button class="min-h-11 rounded-xl bg-blue-600 px-4 py-3 text-sm font-bold text-white">استعلام و تکمیل بررسی</button></form>
        @elseif($attempt->status === 'unknown' || $attempt->status === 'initiating')
            <p class="mt-3 text-sm text-amber-800">مرجع قابل استعلام موجود نیست. این تلاش باید در پنل پذیرنده بررسی شود؛ پرداخت دوباره آغاز نمی‌شود.</p>
        @endif
        @if($attempt->verified_at && !$attempt->settled_at && !$invoice->paid_payment_attempt_id && in_array($attempt->status, ['pending_settlement', 'unknown'], true))
            <details class="mt-4"><summary class="cursor-pointer text-sm font-bold text-red-700">درخواست برگشت پرداخت تسویه‌نشده</summary><p class="mt-2 text-sm leading-7 text-slate-500">فقط اگر بانک تسویه‌نشدن را تأیید کند، برگشت درخواست می‌شود. این اقدام، استرداد پرداخت تسویه‌شده نیست.</p><form method="POST" action="{{ route('admin.payments.reverse', $attempt->id) }}" class="mt-3 flex flex-wrap items-end gap-3">@csrf<label class="min-w-0 flex-1 text-sm font-bold">دلیل برگشت<input name="reason" required maxlength="1000" class="mt-2 w-full rounded-xl border p-3"></label><button class="rounded-xl border border-red-200 p-3 text-sm font-bold text-red-700">استعلام و درخواست برگشت</button></form></details>
        @endif
    </section>
@empty
    <p class="panel p-6 text-sm text-slate-500">هنوز پرداختی ثبت نشده است.</p>
@endforelse
{{ $attempts->links() }}
@endsection
