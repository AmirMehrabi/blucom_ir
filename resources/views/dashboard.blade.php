@extends('layouts.portal')
@section('content')
<div class="mb-7 flex flex-col justify-between gap-4 sm:flex-row sm:items-end">
    <div>
        <p class="text-sm text-slate-500">نمای کلی وضعیت زیرساخت صوتی شما</p>
        <p class="mt-1 text-xs text-slate-400">{{ ($mode ?? 'customer') === 'admin' ? 'مدیریت پلتفرم بلوکام' : 'فضای کاری سازمانی' }}</p>
    </div>
    @if (($mode ?? 'customer') === 'admin')
        <a href="{{ route('admin.sip-numbers.index') }}" class="rounded-xl bg-blue-600 px-4 py-2.5 text-sm font-bold text-white shadow-lg shadow-blue-600/20">+ ثبت شماره در موجودی</a>
    @else
        <a href="{{ route('sip-numbers.index') }}" class="rounded-xl bg-blue-600 px-4 py-2.5 text-sm font-bold text-white shadow-lg shadow-blue-600/20">شماره‌های SIP</a>
    @endif
</div>

<div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
    @foreach ($stats as $stat)
        <div class="panel p-5">
            <span class="eyebrow">{{ $stat['label'] }}</span>
            <div class="mt-4 text-2xl font-extrabold tracking-tight">{{ $stat['value'] }}</div>
            <div class="mt-2 text-xs font-medium text-slate-400">
                <span class="font-bold text-{{ $stat['color'] }}-600">{{ $stat['hint'] }}</span>
            </div>
        </div>
    @endforeach
</div>

@if (! empty($secondaryStats))
    <div class="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ($secondaryStats as $stat)
            <div class="panel p-4">
                <span class="eyebrow">{{ $stat['label'] }}</span>
                <div class="mt-2 text-xl font-extrabold">{{ $stat['value'] }}</div>
            </div>
        @endforeach
    </div>
@endif

<section class="panel mt-6 overflow-hidden">
    <div class="flex items-center justify-between border-b border-slate-100 p-5">
        <div>
            <h2 class="font-bold">{{ ($mode ?? 'customer') === 'admin' ? 'آخرین شماره‌ها' : 'شماره‌های شما' }}</h2>
            <p class="mt-1 text-xs text-slate-400">از آخرین موارد ثبت‌شده</p>
        </div>
        @if (($mode ?? 'customer') === 'admin')
            <a href="{{ route('admin.sip-numbers.index') }}" class="rounded-lg bg-slate-900 px-3 py-2 text-xs font-bold text-white">مشاهده همه</a>
        @else
            <a href="{{ route('sip-numbers.index') }}" class="rounded-lg bg-slate-900 px-3 py-2 text-xs font-bold text-white">مشاهده همه</a>
        @endif
    </div>
    <div class="overflow-x-auto">
        <table class="w-full min-w-[560px] text-right text-sm">
            <thead class="bg-slate-50 text-[10px] font-bold uppercase tracking-wider text-slate-400">
                <tr>
                    <th class="px-5 py-3">شماره</th>
                    <th class="px-5 py-3">مشتری</th>
                    <th class="px-5 py-3">وضعیت</th>
                    <th class="px-5 py-3">مسیر ورودی</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($numbers as $number)
                    <tr class="hover:bg-slate-50">
                        <td class="px-5 py-4 font-semibold" dir="ltr">{{ $number->normalized_number }}</td>
                        <td class="px-5 py-4 text-slate-500">{{ $number->tenant?->name ?? '—' }}</td>
                        <td class="px-5 py-4">
                            <span class="text-xs font-bold {{ $number->statusBadgeClass() }}">● {{ $number->statusLabel() }}</span>
                        </td>
                        <td class="px-5 py-4 text-slate-500">{{ $number->inboundRoute?->destination?->extension ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td class="px-5 py-6 text-slate-400" colspan="4">شماره‌ای ثبت نشده است.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>
@endsection
