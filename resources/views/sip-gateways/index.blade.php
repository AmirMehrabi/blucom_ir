@extends('layouts.portal')
@section('title', 'دروازه‌های SIP')
@section('content')
<div class="mb-6 flex flex-wrap items-start justify-between gap-4">
    <div><h1 class="text-xl font-extrabold">دروازه‌های SIP</h1><p class="mt-1 text-sm text-slate-500">اتصال‌های ارائه‌دهنده، مالکیت و میزان استفاده آن‌ها را در یک‌جا ببینید.</p></div>
    <a href="{{ route('sip-gateways.create') }}" class="rounded-xl bg-blue-600 px-5 py-3 text-sm font-bold text-white">+ دروازه جدید</a>
</div>
@if (session('status'))<div role="status" class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ session('status') }}</div>@endif
@if ($errors->any())<div role="alert" class="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ $errors->first() }}</div>@endif
@unless (config('voip.gateway_xml_enabled'))<div class="mb-5 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">تنظیمات دروازه فعلاً فقط در پایگاه داده ذخیره می‌شود؛ همگام‌سازی XML-CURL دروازه فعال نیست.</div>@endunless
<section class="panel overflow-hidden">
    <div class="border-b border-slate-100 p-5"><h2 class="font-bold">اتصال‌های ثبت‌شده</h2></div>
    <div class="overflow-x-auto"><table class="w-full min-w-[760px] text-right text-sm">
        <thead class="bg-slate-50 text-[10px] font-bold uppercase tracking-wider text-slate-500"><tr><th class="px-5 py-3">نام / میزبان</th><th class="px-5 py-3">مالک</th><th class="px-5 py-3">وابستگی‌ها</th><th class="px-5 py-3">تنظیمات</th><th class="px-5 py-3">وضعیت پیکربندی</th><th class="px-5 py-3">اقدامات</th></tr></thead>
        <tbody class="divide-y divide-slate-100">
            @forelse ($gateways as $gateway)
                <tr class="hover:bg-slate-50">
                    <td class="px-5 py-4"><a href="{{ route('sip-gateways.edit', $gateway) }}" class="font-bold text-blue-700" dir="ltr">{{ $gateway->name }}</a><span class="mt-1 block text-xs text-slate-500" dir="ltr">{{ $gateway->host }}:{{ $gateway->port }} · {{ strtoupper($gateway->transport) }}</span></td>
                    <td class="px-5 py-4">{{ $gateway->tenant?->name ?? 'زیرساخت بلوکام' }}</td>
                    <td class="px-5 py-4"><a href="{{ route('admin.sip-numbers.index', ['gateway_id' => $gateway->id]) }}" class="font-bold text-blue-700">{{ $gateway->sip_numbers_count }} شماره</a><span class="block text-xs text-slate-500">{{ $gateway->outbound_routes_count }} مسیر خروجی</span></td>
                    <td class="px-5 py-4 text-xs text-slate-600">{{ $gateway->register ? 'ثبت SIP تنظیم شده' : 'بدون ثبت SIP' }}<span class="block">{{ $gateway->approved_for_outbound ? 'مجاز برای خروجی' : 'خروجی مجاز نیست' }}</span></td>
                    <td class="px-5 py-4"><span class="rounded-full px-3 py-1 text-xs font-bold {{ $gateway->enabled ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600' }}">{{ $gateway->enabled ? 'فعال در تنظیمات' : 'غیرفعال' }}</span><span class="mt-2 block text-[11px] text-slate-500">این وضعیت، سلامت اتصال زنده نیست.</span></td>
                    <td class="px-5 py-4"><div class="flex flex-wrap items-center gap-3"><a href="{{ route('sip-gateways.edit', $gateway) }}" class="text-xs font-bold text-blue-700">ویرایش</a><form method="POST" action="{{ route('sip-gateways.status', $gateway) }}" onsubmit="return confirm('{{ $gateway->enabled ? 'غیرفعال‌سازی این دروازه ممکن است بر تماس‌های وابسته اثر بگذارد. ادامه می‌دهید؟' : 'این دروازه فعال شود؟' }}')">@csrf @method('PATCH')<input type="hidden" name="enabled" value="{{ $gateway->enabled ? 0 : 1 }}"><button class="text-xs font-bold text-slate-700">{{ $gateway->enabled ? 'غیرفعال‌سازی' : 'فعال‌سازی' }}</button></form></div></td>
                </tr>
            @empty
                <tr><td colspan="6" class="px-5 py-10 text-center text-slate-500">هنوز دروازه‌ای ثبت نشده است. <a href="{{ route('sip-gateways.create') }}" class="font-bold text-blue-700">دروازه جدید بسازید</a>.</td></tr>
            @endforelse
        </tbody>
    </table></div>
</section>
@endsection
