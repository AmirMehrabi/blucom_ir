@extends('layouts.portal')
@section('content')
<div class="mb-7 flex flex-col justify-between gap-4 sm:flex-row sm:items-end">
    <div>
        <h1 class="text-xl font-extrabold">شماره‌های SIP</h1>
        <p class="mt-1 text-sm text-slate-500">شماره‌های (DID) خود را تعریف کنید و مسیر ورودی/خروجی هر شماره را تنظیم کنید.</p>
    </div>
</div>

@if (session('status'))
    <div class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-700">{{ session('status') }}</div>
@endif
@if ($errors->any())
    <div class="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-semibold text-red-700">
        <ul class="list-disc space-y-1 pr-5">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<section class="panel mb-6 overflow-hidden">
    <div class="border-b border-slate-100 p-5">
        <h2 class="font-bold">ثبت شماره جدید</h2>
        <p class="mt-1 text-xs text-slate-400">شماره به صورت E.164 ذخیره می‌شود (مثال: 982191093464 یا +982191093464).</p>
    </div>
    <form method="POST" action="{{ route('sip-numbers.store') }}" class="grid gap-4 p-5 sm:grid-cols-2">
        @csrf
        <div>
            <label class="mb-1 block text-xs font-bold text-slate-500">شماره / DID</label>
            <input name="number" value="{{ old('number') }}" required placeholder="982191093464"
                   class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm" />
        </div>
        <div>
            <label class="mb-1 block text-xs font-bold text-slate-500">دروازه خروجی (اختیاری)</label>
            <select name="provider_gateway_id" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm">
                <option value="">— بدون انتخاب —</option>
                @foreach ($gateways as $gateway)
                    <option value="{{ $gateway->id }}" @selected((string) old('provider_gateway_id') === (string) $gateway->id)>{{ $gateway->name }}</option>
                @endforeach
            </select>
        </div>
        <label class="flex items-center gap-2 text-sm">
            <input type="hidden" name="inbound_enabled" value="0" />
            <input type="checkbox" name="inbound_enabled" value="1" @checked(old('inbound_enabled', true)) />
            فعال‌سازی تماس ورودی (DID ورودی)
        </label>
        <label class="flex items-center gap-2 text-sm">
            <input type="hidden" name="outbound_enabled" value="0" />
            <input type="checkbox" name="outbound_enabled" value="1" @checked(old('outbound_enabled', true)) />
            فعال‌سازی تماس خروجی (نمایش شماره در تماس خروجی)
        </label>
        <div class="sm:col-span-2">
            <button class="rounded-xl bg-slate-900 px-5 py-2.5 text-sm font-bold text-white">ثبت شماره</button>
        </div>
    </form>
</section>

<section class="panel overflow-hidden">
    <div class="border-b border-slate-100 p-5">
        <h2 class="font-bold">شماره‌های ثبت‌شده</h2>
        <p class="mt-1 text-xs text-slate-400">مسیر ورودی و خروجی هر شماره را از منوهای مرتبط پیکربندی کنید.</p>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full min-w-[720px] text-right text-sm">
            <thead class="bg-slate-50 text-[10px] font-bold uppercase tracking-wider text-slate-400">
                <tr>
                    <th class="px-5 py-3">شماره</th>
                    <th class="px-5 py-3">نرمال‌شده</th>
                    <th class="px-5 py-3">دروازه</th>
                    <th class="px-5 py-3">ورودی</th>
                    <th class="px-5 py-3">خروجی</th>
                    <th class="px-5 py-3">وضعیت</th>
                    <th class="px-5 py-3">مسیر ورودی</th>
                    <th class="px-5 py-3">مسیر خروجی</th>
                    <th class="px-5 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($numbers as $number)
                    <tr class="hover:bg-slate-50">
                        <td class="px-5 py-4 font-semibold" dir="ltr">{{ $number->number }}</td>
                        <td class="px-5 py-4 text-slate-500" dir="ltr">{{ $number->normalized_number }}</td>
                        <td class="px-5 py-4 text-slate-500">{{ $number->providerGateway?->name ?? '—' }}</td>
                        <td class="px-5 py-4">{{ $number->inbound_enabled ? 'فعال' : 'غیرفعال' }}</td>
                        <td class="px-5 py-4">{{ $number->outbound_enabled ? 'فعال' : 'غیرفعال' }}</td>
                        <td class="px-5 py-4">
                            <span class="text-xs font-bold {{ $number->status === 'active' ? 'text-emerald-600' : 'text-red-600' }}">● {{ $number->status === 'active' ? 'فعال' : 'غیرفعال' }}</span>
                        </td>
                        <td class="px-5 py-4 text-slate-500">
                            {{ $number->inboundRoute?->destination?->extension ?? 'تعریف‌نشده' }}
                        </td>
                        <td class="px-5 py-4 text-slate-500">
                            {{ $number->outboundRoute?->gateway?->name ?? 'تعریف‌نشده' }}
                        </td>
                        <td class="px-5 py-4">
                            <form method="POST" action="{{ route('sip-numbers.destroy', $number) }}" onsubmit="return confirm('حذف شماره؟')">
                                @csrf
                                @method('DELETE')
                                <button class="text-xs font-bold text-red-600">حذف</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td class="px-5 py-6 text-slate-400" colspan="9">هنوز شماره‌ای ثبت نکرده‌اید.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>
@endsection
