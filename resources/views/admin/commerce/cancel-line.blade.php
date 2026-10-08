@extends('layouts.portal')
@section('title', 'لغو سرویس و آزادسازی شماره')
@section('content')
<a class="text-sm text-blue-700" href="{{ route('admin.sip-numbers.index', ['scope' => $number->tenant_id ? null : 'stock']) }}">← شماره‌ها</a>
<h1 class="my-4 text-2xl font-black">لغو سرویس و آزادسازی شماره</h1>
@if(session('status'))<p class="mb-4 rounded-xl bg-emerald-50 p-4 text-emerald-800">{{ session('status') }}</p>@endif
@if($errors->any())<p role="alert" class="mb-4 rounded-xl bg-red-50 p-4 text-red-800">{{ $errors->first() }}</p>@endif
<div class="panel mb-5 space-y-3 p-5">
<p dir="ltr" class="text-xl font-bold">{{ $number->normalized_number }}</p>
<p>مالک خرید: {{ $owner->name }}</p>
<p>سفارش: {{ $order->public_id }}</p>
<p>فاکتور: {{ $order->invoice->invoice_number }} · وضعیت پرداخت: {{ $order->invoice->status }} · {{ number_format($order->invoice->total_amount) }} تومان</p>
<p>مسیر ورودی: {{ $number->inboundRoute?->destinationLabel() ?? 'ندارد' }} · مسیرهای خروجی: {{ $number->outboundRoutes->count() }}</p>
<p class="text-sm text-slate-600">لغو سرویس، تماس‌های جدید این شماره را قطع می‌کند و مسیرهای آن را حذف می‌کند. تماس‌های جاری تا پایان ادامه دارند. تلفن‌ها، سوابق تماس و مدارک خرید و پرداخت محفوظ می‌مانند. این عملیات بازپرداخت بانکی انجام نمی‌دهد.</p>
</div>
@if($number->inventory_state === 'assigned')
<form method="POST" action="{{ route('admin.numbers.cancel-service', $number) }}" class="panel space-y-4 p-5">
@csrf
<input type="hidden" name="assignment_id" value="{{ $number->current_assignment_id }}">
<input type="hidden" name="revision" value="{{ $number->inventory_revision }}">
<label class="block font-bold">دلیل لغو<textarea name="reason" required minlength="10" maxlength="1000" class="mt-2 w-full rounded-lg border p-3">{{ old('reason') }}</textarea></label>
<label class="block font-bold">تصمیم مالی<select name="refund_decision" required class="mt-2 w-full rounded-lg border p-3"><option value="">انتخاب کنید</option><option value="no_refund" @selected(old('refund_decision') === 'no_refund')>بدون بازپرداخت</option><option value="refund_pending" @selected(old('refund_decision') === 'refund_pending')>بازپرداخت نیازمند پیگیری جداگانه</option></select></label>
<label class="block"><input type="checkbox" name="confirm" value="1" required> تأیید می‌کنم مشتری دسترسی به این شماره را از دست می‌دهد.</label>
<button class="rounded-xl bg-red-700 p-3 font-bold text-white">لغو سرویس و انتقال به قرنطینه</button>
</form>
@else
<div class="panel mb-5 space-y-3 p-5"><h2 class="font-bold">سرویس لغو شده؛ شماره در قرنطینه است</h2><p>زمان لغو: {{ $number->currentAssignment->released_at }}</p><p>دلیل: {{ $number->currentAssignment->cancellation_reason }}</p><p>تصمیم مالی: {{ $number->currentAssignment->refund_decision === 'refund_pending' ? 'بازپرداخت نیازمند پیگیری جداگانه' : 'بدون بازپرداخت' }}</p></div>
<form method="POST" action="{{ route('admin.numbers.return-to-stock', $number) }}" class="panel space-y-4 p-5">
@csrf
<input type="hidden" name="assignment_id" value="{{ $number->current_assignment_id }}">
<input type="hidden" name="revision" value="{{ $number->inventory_revision }}">
<p>شماره به موجودی آزاد و منتشرنشده برمی‌گردد. تنظیمات تماس غیرفعال می‌مانند؛ پیش از فروش دوباره، تنظیمات را آماده کنید، بررسی فنی تازه ثبت کنید و پیشنهاد جدید منتشر کنید.</p>
<label class="block font-bold">دلیل تأیید آزادسازی<textarea name="reason" required minlength="10" maxlength="1000" class="mt-2 w-full rounded-lg border p-3">{{ old('reason') }}</textarea></label>
<label class="block"><input type="checkbox" name="confirm" value="1" required> بررسی کرده‌ام که شماره برای بازگشت به موجودی آماده است.</label>
<button class="rounded-xl bg-slate-900 p-3 font-bold text-white">بازگشت به موجودی آزاد</button>
</form>
@endif
@endsection
