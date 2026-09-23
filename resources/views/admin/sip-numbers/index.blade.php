@extends('layouts.portal')
@section('content')
<div class="mb-7 flex flex-col justify-between gap-4 sm:flex-row sm:items-end">
    <div>
        <h1 class="text-xl font-extrabold">شماره‌های SIP (موجودی)</h1>
        <p class="mt-1 text-sm text-slate-500">شماره به سبد اضافه کنید، تخصیص دهید یا درخواست‌های BYOD را بررسی کنید.</p>
    </div>
    <form method="GET" action="{{ route('admin.sip-numbers.index') }}" class="flex gap-2">
        <input name="q" value="{{ request('q') }}" placeholder="جستجوی شماره…" class="w-40 rounded-xl border border-slate-200 px-3 py-2 text-sm" dir="ltr" />
        <select name="status" class="rounded-xl border border-slate-200 px-3 py-2 text-sm">
            <option value="">همه وضعیت‌ها</option>
            @foreach (['available' => 'موجود', 'assigned' => 'تخصیص‌یافته', 'pending' => 'در انتظار', 'disabled' => 'غیرفعال'] as $value => $label)
                <option value="{{ $value }}" @selected(request('status') === $value)>{{ $label }}</option>
            @endforeach
        </select>
        <button class="rounded-xl bg-slate-100 px-4 py-2 text-xs font-bold">فیلتر</button>
    </form>
</div>

@if (session('status'))
    <div class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-700">{{ session('status') }}</div>
@endif
@if ($errors->any())
    <div class="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-semibold text-red-500">
        <ul class="list-disc space-y-1 pr-5">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<section class="panel mb-6 overflow-hidden">
    <div class="border-b border-slate-100 p-5">
        <h2 class="font-bold">افزودن شماره به موجودی</h2>
        <p class="mt-1 text-xs text-slate-400">شماره بدون مشتری ساخته می‌شود (available). دروازه فقط برای ترانک ورودی/شناسه است.</p>
    </div>
    <form method="POST" action="{{ route('admin.sip-numbers.store') }}" class="grid gap-4 p-5 sm:grid-cols-2">
        @csrf
        <div>
            <label class="mb-1 block text-xs font-bold text-slate-500">شماره / DID</label>
            <input name="number" value="{{ old('number') }}" required placeholder="982191093464"
                   class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm" dir="ltr" />
        </div>
        <div>
            <label class="mb-1 block text-xs font-bold text-slate-500">دروازه / ترانک (اختیاری)</label>
            <select name="provider_gateway_id" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm">
                <option value="">— بدون انتخاب —</option>
                @foreach ($gateways as $gateway)
                    <option value="{{ $gateway->id }}" @selected((string) old('provider_gateway_id') === (string) $gateway->id)>{{ $gateway->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="sm:col-span-2">
            <button class="rounded-xl bg-slate-900 px-5 py-2.5 text-sm font-bold text-white">ثبت شماره</button>
        </div>
    </form>
</section>

<section class="panel overflow-hidden">
    <div class="border-b border-slate-100 p-5"><h2 class="font-bold">فهرست شماره‌ها</h2></div>
    <div class="overflow-x-auto">
        <table class="w-full min-w-[960px] text-right text-sm">
            <thead class="bg-slate-50 text-[10px] font-bold uppercase tracking-wider text-slate-400">
                <tr>
                    <th class="px-4 py-3">شماره</th>
                    <th class="px-4 py-3">نرمال‌شده</th>
                    <th class="px-4 py-3">وضعیت</th>
                    <th class="px-4 py-3">مشتری</th>
                    <th class="px-4 py-3">درخواست‌دهنده</th>
                    <th class="px-4 py-3">دروازه</th>
                    <th class="px-4 py-3">ورودی</th>
                    <th class="px-4 py-3">خروجی</th>
                    <th class="px-4 py-3">عملیات</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($numbers as $number)
                    <tr class="hover:bg-slate-50">
                        <td class="px-4 py-3 font-semibold" dir="ltr">{{ $number->number }}</td>
                        <td class="px-4 py-3 text-slate-500" dir="ltr">{{ $number->normalized_number }}</td>
                        <td class="px-4 py-3"><span class="text-xs font-bold {{ $number->statusBadgeClass() }}">● {{ $number->statusLabel() }}</span></td>
                        <td class="px-4 py-3 text-slate-500">{{ $number->tenant?->name ?? '—' }}</td>
                        <td class="px-4 py-3 text-slate-500">{{ $number->requestedBy?->mobile ?? $number->requestedBy?->name ?? '—' }}</td>
                        <td class="px-4 py-3 text-slate-500">{{ $number->providerGateway?->name ?? '—' }}</td>
                        <td class="px-4 py-3">{{ $number->inbound_enabled ? 'فعال' : 'غیرفعال' }}</td>
                        <td class="px-4 py-3">{{ $number->outbound_enabled ? 'فعال' : 'غیرفعال' }}</td>
                        <td class="px-4 py-3">
                            @if ($number->status === 'pending')
                                <form method="POST" action="{{ route('admin.sip-numbers.approve', $number) }}" class="mb-1 flex gap-1">
                                    @csrf
                                    <input type="hidden" name="disposition" value="assign" />
                                    <button class="rounded bg-emerald-600 px-2 py-1 text-[10px] font-bold text-white">تأیید و تخصیص</button>
                                </form>
                                <form method="POST" action="{{ route('admin.sip-numbers.approve', $number) }}" class="mb-1">
                                    @csrf
                                    <input type="hidden" name="disposition" value="available" />
                                    <button class="rounded bg-blue-600 px-2 py-1 text-[10px] font-bold text-white">تأیید → سبد</button>
                                </form>
                                <form method="POST" action="{{ route('admin.sip-numbers.reject', $number) }}" onsubmit="return confirm('رد درخواست؟')">
                                    @csrf
                                    <button class="rounded bg-red-50 px-2 py-1 text-[10px] font-bold text-red-600">رد</button>
                                </form>
                            @else
                                <form method="POST" action="{{ route('admin.sip-numbers.update', $number) }}" class="mb-1 grid grid-cols-2 gap-1">
                                    @csrf
                                    @method('PUT')
                                    <select name="tenant_id" class="rounded border border-slate-200 px-1 py-1 text-[10px]">
                                        <option value="">— بدون مشتری —</option>
                                        @foreach ($tenants as $tenant)
                                            <option value="{{ $tenant->id }}" @selected((string) old('tenant_id', $number->tenant_id) === (string) $tenant->id)>{{ $tenant->name }}</option>
                                        @endforeach
                                    </select>
                                    <select name="provider_gateway_id" class="rounded border border-slate-200 px-1 py-1 text-[10px]">
                                        <option value="">— دروازه —</option>
                                        @foreach ($gateways as $gateway)
                                            <option value="{{ $gateway->id }}" @selected((string) old('provider_gateway_id', $number->provider_gateway_id) === (string) $gateway->id)>{{ $gateway->name }}</option>
                                        @endforeach
                                    </select>
                                    <select name="status" class="rounded border border-slate-200 px-1 py-1 text-[10px]">
                                        @foreach (['available' => 'موجود', 'assigned' => 'تخصیص', 'disabled' => 'غیرفعال'] as $value => $label)
                                            <option value="{{ $value }}" @selected($number->status === $value)>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                    <button class="rounded bg-slate-900 px-2 py-1 text-[10px] font-bold text-white">ذخیره</button>
                                </form>
                                <form method="POST" action="{{ route('admin.sip-numbers.destroy', $number) }}" onsubmit="return confirm('حذف شماره؟')">
                                    @csrf
                                    @method('DELETE')
                                    <button class="text-[10px] font-bold text-red-600">حذف</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td class="px-5 py-6 text-slate-400" colspan="9">شماره‌ای ثبت نشده است.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="p-4">{{ $numbers->links() }}</div>
</section>
@endsection
