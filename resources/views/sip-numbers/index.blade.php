@extends('layouts.portal')
@section('content')
<div class="mb-7 flex flex-col justify-between gap-4 sm:flex-row sm:items-end">
    <div>
        <h1 class="text-xl font-extrabold">شماره‌های SIP</h1>
        <p class="mt-1 text-sm text-slate-500">شماره‌های تخصیص‌یافته را مدیریت کنید، از سبد موجودی انتخاب بگیرید یا شماره خود را برای تأیید بفرستید.</p>
    </div>
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
        <h2 class="font-bold">درخواست شماره خود (BYOD)</h2>
        <p class="mt-1 text-xs text-slate-400">اگر شماره‌ای از اپراتور دارید ثبت کنید. پس از تأیید مدیر فعال می‌شود. (مثال: 982191093464 یا +982191093464)</p>
    </div>
    <form method="POST" action="{{ route('sip-numbers.store') }}" class="grid gap-4 p-5 sm:grid-cols-2">
        @csrf
        <div>
            <label class="mb-1 block text-xs font-bold text-slate-500">شماره / DID</label>
            <input name="number" value="{{ old('number') }}" required placeholder="982191093464"
                   class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm" dir="ltr" />
        </div>
        <div class="flex items-end">
            <button class="rounded-xl bg-slate-900 px-5 py-2.5 text-sm font-bold text-white">ارسال درخواست</button>
        </div>
    </form>
</section>

<section class="panel mb-6 overflow-hidden">
    <div class="border-b border-slate-100 p-5">
        <h2 class="font-bold">شماره‌های تخصیص‌یافته به شما</h2>
        <p class="mt-1 text-xs text-slate-400">مسیر ورودی/خروجی را از منوهای مرتبط پیکربندی کنید.</p>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full min-w-[760px] text-right text-sm">
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
                @forelse ($myNumbers as $number)
                    <tr class="hover:bg-slate-50">
                        <td class="px-5 py-4 font-semibold" dir="ltr">{{ $number->number }}</td>
                        <td class="px-5 py-4 text-slate-500" dir="ltr">{{ $number->normalized_number }}</td>
                        <td class="px-5 py-4 text-slate-500">{{ $number->providerGateway?->name ?? '—' }}</td>
                        <td class="px-5 py-4">
                            <form method="POST" action="{{ route('sip-numbers.update', $number) }}">
                                @csrf
                                @method('PUT')
                                <input type="hidden" name="inbound_enabled" value="{{ $number->inbound_enabled ? 0 : 1 }}" />
                                <button class="text-xs font-bold {{ $number->inbound_enabled ? 'text-emerald-600' : 'text-slate-400' }}">● {{ $number->inbound_enabled ? 'فعال' : 'غیرفعال' }}</button>
                            </form>
                        </td>
                        <td class="px-5 py-4">
                            <form method="POST" action="{{ route('sip-numbers.update', $number) }}">
                                @csrf
                                @method('PUT')
                                <input type="hidden" name="outbound_enabled" value="{{ $number->outbound_enabled ? 0 : 1 }}" />
                                <button class="text-xs font-bold {{ $number->outbound_enabled ? 'text-emerald-600' : 'text-slate-400' }}">● {{ $number->outbound_enabled ? 'فعال' : 'غیرفعال' }}</button>
                            </form>
                        </td>
                        <td class="px-5 py-4">
                            <form method="POST" action="{{ route('sip-numbers.update', $number) }}">
                                @csrf
                                @method('PUT')
                                <input type="hidden" name="status" value="{{ $number->status === 'assigned' ? 'disabled' : 'assigned' }}" />
                                <span class="text-xs font-bold {{ $number->statusBadgeClass() }}">● {{ $number->statusLabel() }}</span>
                                <button class="mr-2 text-[10px] font-bold text-slate-400">{{ $number->status === 'assigned' ? 'غیرفعال' : 'فعال' }}</button>
                            </form>
                        </td>
                        <td class="px-5 py-4 text-slate-500">{{ $number->inboundRoute?->destination?->extension ?? 'تعریف‌نشده' }}</td>
                        <td class="px-5 py-4 text-slate-500">{{ $number->outboundRoute?->gateway?->name ?? 'تعریف‌نشده' }}</td>
                        <td class="px-5 py-4">
                            <form method="POST" action="{{ route('sip-numbers.release', $number) }}" onsubmit="return confirm('شماره به سبد موجودی بازگردد؟ مسیرهای مرتبط حذف می‌شوند.')">
                                @csrf
                                <button class="text-xs font-bold text-amber-600">بازگشت به سبد</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td class="px-5 py-6 text-slate-400" colspan="9">شماره‌ای به شما تخصیص نیافته است.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>

<section class="panel mb-6 overflow-hidden">
    <div class="border-b border-slate-100 p-5">
        <h2 class="font-bold">سبد موجودی</h2>
        <p class="mt-1 text-xs text-slate-400">شماره‌های آزاد پلتفرم — برای تخصیص به فضای کاری خود انتخاب کنید.</p>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full min-w-[520px] text-right text-sm">
            <thead class="bg-slate-50 text-[10px] font-bold uppercase tracking-wider text-slate-400">
                <tr>
                    <th class="px-5 py-3">شماره</th>
                    <th class="px-5 py-3">نرمال‌شده</th>
                    <th class="px-5 py-3">دروازه</th>
                    <th class="px-5 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($availableNumbers as $number)
                    <tr class="hover:bg-slate-50">
                        <td class="px-5 py-4 font-semibold" dir="ltr">{{ $number->number }}</td>
                        <td class="px-5 py-4 text-slate-500" dir="ltr">{{ $number->normalized_number }}</td>
                        <td class="px-5 py-4 text-slate-500">{{ $number->providerGateway?->name ?? '—' }}</td>
                        <td class="px-5 py-4">
                            <form method="POST" action="{{ route('sip-numbers.assign', $number) }}">
                                @csrf
                                <button class="rounded-lg bg-slate-900 px-3 py-1.5 text-xs font-bold text-white">تخصیص به من</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td class="px-5 py-6 text-slate-400" colspan="4">شماره آزادی در سبد نیست.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>

<section class="panel overflow-hidden">
    <div class="border-b border-slate-100 p-5">
        <h2 class="font-bold">درخواست‌های من</h2>
        <p class="mt-1 text-xs text-slate-400">شماره‌هایی که برای تأیید مدیر فرستاده‌اید.</p>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full min-w-[480px] text-right text-sm">
            <thead class="bg-slate-50 text-[10px] font-bold uppercase tracking-wider text-slate-400">
                <tr>
                    <th class="px-5 py-3">شماره</th>
                    <th class="px-5 py-3">نرمال‌شده</th>
                    <th class="px-5 py-3">وضعیت</th>
                    <th class="px-5 py-3">ثبت‌شده</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($myRequests as $number)
                    <tr class="hover:bg-slate-50">
                        <td class="px-5 py-4 font-semibold" dir="ltr">{{ $number->number }}</td>
                        <td class="px-5 py-4 text-slate-500" dir="ltr">{{ $number->normalized_number }}</td>
                        <td class="px-5 py-4"><span class="text-xs font-bold {{ $number->statusBadgeClass() }}">● {{ $number->statusLabel() }}</span></td>
                        <td class="px-5 py-4 text-xs text-slate-400">{{ $number->created_at?->diffForHumans() }}</td>
                    </tr>
                @empty
                    <tr><td class="px-5 py-6 text-slate-400" colspan="4">درخواستی ثبت نکرده‌اید.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>
@endsection
