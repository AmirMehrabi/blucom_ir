@extends('layouts.portal')
@section('title', 'تخصیص سفارش‌های پرداخت‌شده')
@section('content')
<h1 class="text-2xl font-black">تخصیص سفارش‌های پرداخت‌شده</h1>
<p class="mt-3 text-sm leading-7 text-slate-600">پرداخت تسویه‌شده به‌صورت خودکار تخصیص می‌یابد. ترمیم فقط برای همان شماره آزاد و بدون رزرو مشتری دیگر ممکن است. دوره پلن و شماره سالانه از آماده‌شدن فنی شماره آغاز می‌شود.</p>
@if(session('status'))<p role="status" class="mt-4 rounded-xl bg-blue-50 p-4">{{ session('status') }}</p>@endif
@if($errors->any())<div role="alert" class="mt-4 rounded-xl bg-red-50 p-4">{{ $errors->first() }}</div>@endif
<section class="panel mt-6 p-5"><h2 class="text-lg font-bold">درخواست‌های شماره آماده‌نشده پس از ۲۴ ساعت</h2><div class="mt-4 space-y-3">@forelse($unreadyRequests as $request)<div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm"><p class="font-bold">سفارش {{ $request->commerce_order_id }} · حساب {{ $request->tenant_id }} · {{ $request->customer?->name ?? '—' }} · <bdi dir="ltr">{{ $request->customer?->mobile ?? '—' }}</bdi> · {{ $request->choice === 'refund' ? 'درخواست بررسی بازپرداخت' : 'درخواست شماره جایگزین' }}</p><p class="mt-2">پرداخت: {{ $request->order?->invoice?->paid_at ? \App\Services\Commerce\CustomerCommercePresenter::date($request->order->invoice->paid_at) : '—' }}</p><p class="mt-2 text-amber-900">نتیجه بانکی یا جایگزینی را جداگانه ثبت و با مشتری هماهنگ کنید؛ این فهرست به‌تنهایی پول را برنمی‌گرداند.</p></div>@empty<p class="text-sm text-slate-500">درخواستی ثبت نشده است.</p>@endforelse</div></section>
<section class="panel mt-6 p-5"><h2 class="text-lg font-bold">شماره‌های سالانه نیازمند تصمیم آزادسازی</h2><p class="mt-2 text-sm text-slate-600">این شماره‌ها بیش از ۳۰ روز از پایان مهلت ۷ روزه گذشته‌اند. آزادسازی و بازفروش فقط پس از بررسی دستی انجام می‌شود.</p><div class="mt-4 space-y-3">@forelse($reviewTerms as $term)<div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200 p-4 text-sm"><span><bdi dir="ltr">{{ $term->assignment?->sipNumber?->normalized_number ?? '—' }}</bdi> · حساب {{ $term->tenant_id }}</span>@if($term->assignment?->sipNumber)<a class="font-bold text-blue-700" href="{{ route('admin.numbers.cancellation', $term->assignment->sip_number_id) }}">بررسی شماره</a>@endif</div>@empty<p class="text-sm text-slate-500">شماره‌ای در انتظار بررسی آزادسازی نیست.</p>@endforelse</div></section>
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
