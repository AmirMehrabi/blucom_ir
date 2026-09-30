@extends('layouts.portal')
@section('title', 'مقصد تماس‌های ورودی')
@section('content')
<div class="mb-6 flex flex-wrap items-start justify-between gap-4">
    <div><h1 class="text-xl font-extrabold">مقصد تماس‌های ورودی</h1><p class="mt-1 text-sm text-slate-500">مقصد و شرایط زمانی هر شماره را مرور کنید؛ تغییرات در صفحه خود شماره انجام می‌شود.</p></div>
    <a href="{{ $selectedNumberId ? route('admin.sip-numbers.inbound', $selectedNumberId) : route('admin.sip-numbers.index') }}" class="rounded-xl bg-blue-600 px-5 py-3 text-sm font-bold text-white">{{ $selectedNumberId ? 'تنظیم تماس این شماره' : '+ انتخاب شماره' }}</a>
</div>
@if (session('status'))<div role="status" class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ session('status') }}</div>@endif
@if ($errors->any())<div role="alert" class="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ $errors->first() }}</div>@endif
<form method="GET" action="{{ route('inbound-routes.index') }}" class="mb-5 flex flex-wrap items-end gap-3 rounded-xl border border-slate-200 bg-white p-4"><label class="text-xs font-bold text-slate-600">مالک<select name="tenant_id" class="mt-2 block min-w-56 rounded-lg border border-slate-200 bg-white p-2 text-sm"><option value="">همه مالک‌ها</option>@foreach($tenants as $tenant)<option value="{{ $tenant->id }}" @selected($selectedTenantId === $tenant->id)>{{ $tenant->name }}</option>@endforeach</select></label><button class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-bold text-white">نمایش</button>@if($selectedTenantId || $selectedNumberId)<a href="{{ route('inbound-routes.index') }}" class="p-2 text-xs font-bold text-blue-700">پاک کردن فیلتر</a>@endif</form>
<section class="panel overflow-hidden">
    <div class="border-b border-slate-100 p-5"><h2 class="font-bold">مسیرهای موجود</h2></div>
    <div class="overflow-x-auto"><table class="w-full min-w-[700px] text-right text-sm">
        <thead class="bg-slate-50 text-[10px] font-bold uppercase tracking-wider text-slate-500"><tr><th class="px-5 py-3">شماره / مالک</th><th class="px-5 py-3">پاسخ‌گوی ساعات باز</th><th class="px-5 py-3">شرایط زمانی</th><th class="px-5 py-3">وضعیت مؤثر</th><th class="px-5 py-3">اقدام</th></tr></thead>
        <tbody class="divide-y divide-slate-100">
            @forelse ($routes as $route)
                <tr class="hover:bg-slate-50"><td class="px-5 py-4"><a href="{{ route('admin.sip-numbers.setup', ['sip_number' => $route->sip_number_id, 'tab' => 'inbound']) }}" class="font-bold text-blue-700" dir="ltr">{{ $route->sipNumber?->normalized_number }}</a><span class="mt-1 block text-xs text-slate-500">{{ $route->tenant?->name }}</span></td>
                    <td class="px-5 py-4">{{ $route->destinationLabel() }}</td>
                    <td class="px-5 py-4">{{ $route->schedule ? 'بر اساس زمان‌بندی' : 'همیشه' }}@if($route->schedule)<span class="mt-1 block text-xs text-slate-500">خارج از ساعات: {{ $route->closedDestinationLabel() }}</span>@endif</td>
                    <td class="px-5 py-4"><span class="rounded-full px-3 py-1 text-xs font-bold {{ $route->enabled && $route->sipNumber?->enabled && $route->sipNumber?->inbound_enabled ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600' }}">{{ $route->enabled && $route->sipNumber?->enabled && $route->sipNumber?->inbound_enabled ? 'قابل دریافت' : 'غیرفعال' }}</span></td>
                    <td class="px-5 py-4"><a href="{{ route('admin.sip-numbers.inbound', $route->sip_number_id) }}" class="text-xs font-bold text-blue-700">ویرایش مقصد و زمان</a></td></tr>
            @empty
                <tr><td colspan="5" class="px-5 py-10 text-center text-slate-500">هنوز مسیر ورودی‌ای تعریف نشده است. <a href="{{ route('admin.sip-numbers.index') }}" class="font-bold text-blue-700">یک شماره انتخاب کنید</a>.</td></tr>
            @endforelse
        </tbody>
    </table></div>
</section>
@endsection
