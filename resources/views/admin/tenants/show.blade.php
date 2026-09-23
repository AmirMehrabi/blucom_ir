@extends('layouts.portal')
@section('content')
<div class="mb-7 flex flex-col justify-between gap-4 sm:flex-row sm:items-end">
    <div>
        <a href="{{ route('admin.tenants.index') }}" class="text-xs font-bold text-blue-600">→ فهرست سازمان‌ها</a>
        <h1 class="mt-1 text-xl font-extrabold">{{ $tenant->name }}</h1>
        <p class="mt-1 text-sm text-slate-500">
            وضعیت:
            <span class="font-bold {{ $tenant->status === 'active' ? 'text-emerald-600' : 'text-red-600' }}">● {{ $tenant->status === 'active' ? 'فعال' : 'غیرفعال' }}</span>
            · مالک: {{ $tenant->owner?->name ?? '—' }} <span dir="ltr">{{ $tenant->owner?->mobile }}</span>
        </p>
    </div>
    <form method="POST" action="{{ route('admin.tenants.update', $tenant) }}">
        @csrf
        @method('PUT')
        <input type="hidden" name="status" value="{{ $tenant->status === 'active' ? 'disabled' : 'active' }}" />
        <button class="rounded-xl {{ $tenant->status === 'active' ? 'bg-red-600' : 'bg-emerald-600' }} px-4 py-2.5 text-sm font-bold text-white">
            {{ $tenant->status === 'active' ? 'غیرفعال‌سازی' : 'فعال‌سازی' }}
        </button>
    </form>
</div>

@if (session('status'))
    <div class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-700">{{ session('status') }}</div>
@endif

<div class="grid gap-6 xl:grid-cols-2">
    <section class="panel overflow-hidden">
        <div class="border-b border-slate-100 p-5"><h2 class="font-bold">شماره‌ها</h2></div>
        <table class="w-full text-right text-sm">
            <thead class="bg-slate-50 text-[10px] font-bold uppercase tracking-wider text-slate-400">
                <tr><th class="px-4 py-3">شماره</th><th class="px-4 py-3">وضعیت</th><th class="px-4 py-3">دروازه</th><th class="px-4 py-3">مسیر ورودی</th></tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($tenant->sipNumbers as $number)
                    <tr>
                        <td class="px-4 py-3 font-semibold" dir="ltr">{{ $number->normalized_number }}</td>
                        <td class="px-4 py-3"><span class="text-xs font-bold {{ $number->statusBadgeClass() }}">{{ $number->statusLabel() }}</span></td>
                        <td class="px-4 py-3 text-slate-500">{{ $number->providerGateway?->name ?? '—' }}</td>
                        <td class="px-4 py-3 text-slate-500">{{ $number->inboundRoute?->destination?->extension ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td class="px-4 py-5 text-slate-400" colspan="4">شماره‌ای ندارد.</td></tr>
                @endforelse
            </tbody>
        </table>
    </section>

    <section class="panel overflow-hidden">
        <div class="border-b border-slate-100 p-5"><h2 class="font-bold">داخلی‌ها</h2></div>
        <table class="w-full text-right text-sm">
            <thead class="bg-slate-50 text-[10px] font-bold uppercase tracking-wider text-slate-400">
                <tr><th class="px-4 py-3">داخلی</th><th class="px-4 py-3">نام</th><th class="px-4 py-3">فعال</th></tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($tenant->sipExtensions as $extension)
                    <tr>
                        <td class="px-4 py-3 font-semibold" dir="ltr">{{ $extension->extension }}</td>
                        <td class="px-4 py-3 text-slate-500">{{ $extension->display_name ?? '—' }}</td>
                        <td class="px-4 py-3">{{ $extension->enabled ? 'بله' : 'خیر' }}</td>
                    </tr>
                @empty
                    <tr><td class="px-4 py-5 text-slate-400" colspan="3">داخلی‌ای ندارد.</td></tr>
                @endforelse
            </tbody>
        </table>
    </section>

    <section class="panel overflow-hidden">
        <div class="border-b border-slate-100 p-5"><h2 class="font-bold">مسیرهای ورودی</h2></div>
        <table class="w-full text-right text-sm">
            <thead class="bg-slate-50 text-[10px] font-bold uppercase tracking-wider text-slate-400">
                <tr><th class="px-4 py-3">DID</th><th class="px-4 py-3">داخلی</th><th class="px-4 py-3">فعال</th></tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($tenant->inboundRoutes as $route)
                    <tr>
                        <td class="px-4 py-3" dir="ltr">{{ $route->sipNumber?->normalized_number }}</td>
                        <td class="px-4 py-3">{{ $route->destination?->extension ?? '—' }}</td>
                        <td class="px-4 py-3">{{ $route->enabled ? 'بله' : 'خیر' }}</td>
                    </tr>
                @empty
                    <tr><td class="px-4 py-5 text-slate-400" colspan="3">مسیری ندارد.</td></tr>
                @endforelse
            </tbody>
        </table>
    </section>

    <section class="panel overflow-hidden">
        <div class="border-b border-slate-100 p-5"><h2 class="font-bold">مسیرهای خروجی</h2></div>
        <table class="w-full text-right text-sm">
            <thead class="bg-slate-50 text-[10px] font-bold uppercase tracking-wider text-slate-400">
                <tr><th class="px-4 py-3">نمایش خروجی</th><th class="px-4 py-3">دروازه</th><th class="px-4 py-3">فعال</th></tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($tenant->outboundRoutes as $route)
                    <tr>
                        <td class="px-4 py-3" dir="ltr">{{ $route->sipNumber?->normalized_number }}</td>
                        <td class="px-4 py-3">{{ $route->gateway?->name ?? '—' }}</td>
                        <td class="px-4 py-3">{{ $route->enabled ? 'بله' : 'خیر' }}</td>
                    </tr>
                @empty
                    <tr><td class="px-4 py-5 text-slate-400" colspan="3">مسیری ندارد.</td></tr>
                @endforelse
            </tbody>
        </table>
    </section>
</div>
@endsection
