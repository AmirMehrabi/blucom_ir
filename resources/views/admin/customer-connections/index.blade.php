@extends('layouts.portal')
@section('title', 'بررسی اتصال‌ها')
@section('content')
<div class="mb-7"><h1 class="text-2xl font-black">بررسی اتصال‌ها</h1><p class="mt-2 text-sm leading-7 text-slate-500">پیش از تأیید، مالکیت شماره و مشخصات ارائه‌دهنده را بررسی کنید. تأیید اینجا به‌تنهایی به معنی ثبت موفق در FreeSWITCH نیست.</p></div>

<section class="panel overflow-hidden">
    <h2 class="border-b border-slate-100 p-5 font-bold">اتصال‌های ارائه‌دهنده</h2>
    <div class="overflow-x-auto"><table class="w-full min-w-[620px] text-right text-sm">
        <thead class="bg-slate-50 text-xs text-slate-500"><tr><th class="p-4">اتصال</th><th class="p-4">سرور</th><th class="p-4">روش</th><th class="p-4">وضعیت</th><th class="p-4">اقدام</th></tr></thead>
        <tbody class="divide-y divide-slate-100">
        @forelse ($gateways as $gateway)
            <tr><td class="p-4 font-semibold">{{ $gateway->display_name }}<span class="block text-xs font-normal text-slate-500">{{ $gateway->provider_name }}</span></td><td class="p-4" dir="ltr">{{ $gateway->host }}:{{ $gateway->port }}</td><td class="p-4">{{ $gateway->connection_method === 'ip' ? 'IP' : 'نام کاربری' }}</td><td class="p-4">{{ $gateway->verification_status === 'approved' ? 'تأییدشده' : ($gateway->verification_status === 'rejected' ? 'ردشده' : 'در انتظار') }}</td><td class="p-4"><div class="flex gap-3">@if ($gateway->verification_status === 'pending')<form method="POST" action="{{ route('admin.customer-connections.gateways.approve', $gateway) }}">@csrf<button class="font-bold text-emerald-700">تأیید</button></form>@endif @if ($gateway->verification_status !== 'rejected')<form method="POST" action="{{ route('admin.customer-connections.gateways.reject', $gateway) }}">@csrf<button class="font-bold text-red-700">رد</button></form>@endif</div></td></tr>
        @empty
            <tr><td colspan="5" class="p-5 text-slate-500">اتصالی ثبت نشده است.</td></tr>
        @endforelse
        </tbody>
    </table></div>
</section>

<section class="panel mt-7 overflow-hidden">
    <h2 class="border-b border-slate-100 p-5 font-bold">شماره‌ها</h2>
    <div class="overflow-x-auto"><table class="w-full min-w-[560px] text-right text-sm">
        <thead class="bg-slate-50 text-xs text-slate-500"><tr><th class="p-4">شماره</th><th class="p-4">ارائه‌دهنده</th><th class="p-4">وضعیت</th><th class="p-4">اقدام</th></tr></thead>
        <tbody class="divide-y divide-slate-100">
        @forelse ($numbers as $number)
            <tr><td class="p-4 font-semibold" dir="ltr">{{ $number->number }}</td><td class="p-4">{{ $number->providerGateway?->display_name }}</td><td class="p-4">{{ $number->statusLabel() }}</td><td class="p-4"><div class="flex gap-3">@if ($number->status === 'pending')<form method="POST" action="{{ route('admin.customer-connections.numbers.approve', $number) }}">@csrf<button class="font-bold text-emerald-700">تأیید</button></form><form method="POST" action="{{ route('admin.customer-connections.numbers.reject', $number) }}">@csrf<button class="font-bold text-red-700">رد</button></form>@endif</div></td></tr>
        @empty
            <tr><td colspan="4" class="p-5 text-slate-500">شماره‌ای ثبت نشده است.</td></tr>
        @endforelse
        </tbody>
    </table></div>
</section>
@endsection
