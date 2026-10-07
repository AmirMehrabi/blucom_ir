@extends('layouts.portal')
@section('title', 'رزرو شماره‌ها')
@section('content')
<h1 class="text-2xl font-black">رزرو شماره‌ها</h1>
<p class="mt-3 text-sm leading-7 text-slate-500">رزرو را لغو کنید تا شماره دوباره قابل خرید باشد. آزادسازی رزرو، پرداخت بانکی را برگشت نمی‌زند. پرداخت‌های انجام‌شده یا نامشخص برای پیگیری محفوظ می‌مانند.</p>
<a class="mt-3 inline-block text-sm font-bold text-blue-700" href="{{ route('admin.payments.index') }}">بررسی و برگشت پرداخت‌ها</a>
<form method="GET" class="my-5 flex gap-3"><label class="text-sm">وضعیت رزرو<select name="status" class="mr-2 rounded-xl border bg-white p-3">@foreach(['held' => 'رزرو شده', 'cancelled' => 'لغو شده', 'expired' => 'منقضی شده', 'allocated' => 'تخصیص یافته'] as $value => $label)<option value="{{ $value }}" @selected($status === $value)>{{ $label }}</option>@endforeach</select></label><button class="rounded-xl border bg-white px-4 font-bold">نمایش</button></form>
@forelse($orders as $order)
<section class="panel mb-4 p-5">
    <div class="flex flex-wrap justify-between gap-3"><h2 class="font-bold" dir="ltr">{{ $order->item->snapshot['number'] }}</h2><span class="text-sm text-slate-500">سفارش {{ $order->id }}</span></div>
    <p class="mt-3 text-sm">{{ $order->invoice->buyer_snapshot['business_name'] }} · {{ $order->invoice->buyer_snapshot['customer_name'] }} · {{ number_format($order->total_amount) }} تومان</p>
    <p class="mt-2 text-sm text-slate-500">مهلت رزرو: {{ \App\Services\Commerce\CustomerCommercePresenter::date($order->reservation->expires_at) }} · {{ $order->invoice->paid_payment_attempt_id ? 'پرداخت تأیید شده' : ($order->invoice->current_payment_attempt_id ? 'پرداخت شروع شده؛ نتیجه را بررسی کنید' : 'پرداخت شروع نشده') }}</p>
    @if($order->reservation->status === 'held')
    <details class="mt-4"><summary class="cursor-pointer text-sm font-bold text-red-700">لغو رزرو و آزادسازی شماره</summary>
        <p class="mt-3 text-sm leading-7 text-amber-800">این شماره دوباره قابل خرید می‌شود. اگر پرداختی وجود داشته باشد، بررسی یا استرداد آن باید از بخش پرداخت‌ها پیگیری شود.</p>
        <form method="POST" action="{{ route('admin.reservations.cancel', $order->public_id) }}" class="mt-3 flex flex-wrap items-end gap-3">@csrf<label class="min-w-0 flex-1 text-sm font-bold">دلیل لغو<input name="reason" required minlength="10" maxlength="1000" class="mt-2 w-full rounded-xl border p-3"></label><button class="rounded-xl border border-red-200 p-3 text-sm font-bold text-red-700">تأیید لغو و آزادسازی</button></form>
    </details>
    @endif
</section>
@empty
<p class="panel p-6 text-sm text-slate-500">رزروی با این وضعیت وجود ندارد.</p>
@endforelse
{{ $orders->links() }}
@endsection
