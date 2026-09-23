@extends('layouts.portal')
@section('content')
<div class="mb-7 flex flex-col justify-between gap-4 sm:flex-row sm:items-end">
    <div>
        <h1 class="text-xl font-extrabold">سازمان‌ها (Tenants)</h1>
        <p class="mt-1 text-sm text-slate-500">حساب‌های سازمانی مشتریان، وضعیت و میزان مصرف منابع.</p>
    </div>
    <form method="GET" action="{{ route('admin.tenants.index') }}" class="flex gap-2">
        <input name="q" value="{{ request('q') }}" placeholder="جستجوی نام…" class="w-48 rounded-xl border border-slate-200 px-3 py-2 text-sm" />
        <button class="rounded-xl bg-slate-100 px-4 py-2 text-xs font-bold">جستجو</button>
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

<section class="panel overflow-hidden">
    <div class="border-b border-slate-100 p-5"><h2 class="font-bold">فهرست سازمان‌ها</h2></div>
    <div class="overflow-x-auto">
        <table class="w-full min-w-[720px] text-right text-sm">
            <thead class="bg-slate-50 text-[10px] font-bold uppercase tracking-wider text-slate-400">
                <tr>
                    <th class="px-5 py-3">نام</th>
                    <th class="px-5 py-3">مالک</th>
                    <th class="px-5 py-3">موبایل</th>
                    <th class="px-5 py-3">شماره‌ها</th>
                    <th class="px-5 py-3">داخلی‌ها</th>
                    <th class="px-5 py-3">وضعیت</th>
                    <th class="px-5 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($tenants as $tenant)
                    <tr class="hover:bg-slate-50">
                        <td class="px-5 py-4">
                            <a class="font-semibold text-blue-700 hover:underline" href="{{ route('admin.tenants.show', $tenant) }}">{{ $tenant->name }}</a>
                        </td>
                        <td class="px-5 py-4 text-slate-500">{{ $tenant->owner?->name ?? '—' }}</td>
                        <td class="px-5 py-4 text-slate-500" dir="ltr">{{ $tenant->owner?->mobile ?? '—' }}</td>
                        <td class="px-5 py-4">{{ $tenant->sip_numbers_count }}</td>
                        <td class="px-5 py-4">{{ $tenant->sip_extensions_count }}</td>
                        <td class="px-5 py-4">
                            <span class="text-xs font-bold {{ $tenant->status === 'active' ? 'text-emerald-600' : 'text-red-600' }}">● {{ $tenant->status === 'active' ? 'فعال' : 'غیرفعال' }}</span>
                        </td>
                        <td class="px-5 py-4">
                            <form method="POST" action="{{ route('admin.tenants.update', $tenant) }}">
                                @csrf
                                @method('PUT')
                                <input type="hidden" name="status" value="{{ $tenant->status === 'active' ? 'disabled' : 'active' }}" />
                                <button class="text-xs font-bold {{ $tenant->status === 'active' ? 'text-red-600' : 'text-emerald-600' }}">{{ $tenant->status === 'active' ? 'غیرفعال‌سازی' : 'فعال‌سازی' }}</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td class="px-5 py-6 text-slate-400" colspan="7">سازمانی ثبت نشده است.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="p-4">{{ $tenants->links() }}</div>
</section>
@endsection
