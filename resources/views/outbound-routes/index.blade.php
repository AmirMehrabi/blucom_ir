@extends('layouts.portal')
@section('content')
<div class="mb-7">
    <h1 class="text-xl font-extrabold">مسیر تماس خروجی (DID خروجی)</h1>
    <p class="mt-1 text-sm text-slate-500">برای هر داخلی شماره‌نمایش و دروازه خروجی تعیین کنید.</p>
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
        <h2 class="font-bold">تعریف مسیر جدید</h2>
        <p class="mt-1 text-xs text-slate-400">شماره‌نمایش خروجی همان DID انتخاب‌شده است. فقط دروازه‌های فعال سیستم قابل انتخاب هستند.</p>
    </div>
    <form method="POST" action="{{ route('outbound-routes.store') }}" class="grid gap-4 p-5 sm:grid-cols-2">
        @csrf
        <div>
            <label class="mb-1 block text-xs font-bold text-slate-500">داخلی</label>
            <select name="sip_extension_id" required class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm">
                <option value="">— انتخاب داخلی —</option>
                @foreach ($extensions as $extension)
                    <option value="{{ $extension->id }}" @selected((string) old('sip_extension_id') === (string) $extension->id)>{{ $extension->extension }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="mb-1 block text-xs font-bold text-slate-500">شماره (نمایش خروجی / DID)</label>
            <select name="sip_number_id" required class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm">
                <option value="">— انتخاب شماره —</option>
                @foreach ($numbers as $number)
                    <option value="{{ $number->id }}" @selected((string) old('sip_number_id') === (string) $number->id)>{{ $number->normalized_number }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="mb-1 block text-xs font-bold text-slate-500">دروازه خروجی</label>
            <select name="gateway_id" required class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm">
                <option value="">— انتخاب دروازه —</option>
                @foreach ($gateways as $gateway)
                    <option value="{{ $gateway->id }}" @selected((string) old('gateway_id') === (string) $gateway->id)>{{ $gateway->name }}</option>
                @endforeach
            </select>
        </div>
        <label class="flex items-center gap-2 text-sm">
            <input type="hidden" name="enabled" value="0" />
            <input type="checkbox" name="enabled" value="1" @checked(old('enabled', true)) />
            فعال
        </label>
        <div class="flex items-end">
            <button class="rounded-xl bg-slate-900 px-5 py-2.5 text-sm font-bold text-white">ثبت مسیر</button>
        </div>
    </form>
</section>

<section class="panel overflow-hidden">
    <div class="border-b border-slate-100 p-5"><h2 class="font-bold">مسیرهای تعریف‌شده</h2></div>
    <div class="overflow-x-auto">
        <table class="w-full min-w-[680px] text-right text-sm">
            <thead class="bg-slate-50 text-[10px] font-bold uppercase tracking-wider text-slate-400">
                <tr>
                    <th class="px-5 py-3">داخلی</th>
                    <th class="px-5 py-3">نمایش خروجی (DID)</th>
                    <th class="px-5 py-3">دروازه</th>
                    <th class="px-5 py-3">وضعیت</th>
                    <th class="px-5 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($routes as $route)
                    <tr class="hover:bg-slate-50">
                        <td class="px-5 py-4" dir="ltr">{{ $route->sipExtension?->extension ?? '—' }}</td>
                        <td class="px-5 py-4 font-semibold" dir="ltr">{{ $route->sipNumber?->normalized_number }}</td>
                        <td class="px-5 py-4">{{ $route->gateway?->name ?? '—' }}</td>
                        <td class="px-5 py-4">
                            <form method="POST" action="{{ route('outbound-routes.update', $route) }}" class="flex flex-wrap gap-2">
                                @csrf
                                @method('PUT')
                                <select name="sip_number_id" class="rounded border border-slate-200 p-1 text-xs">
                                    @foreach ($numbers as $number)
                                        <option value="{{ $number->id }}" @selected($route->sip_number_id === $number->id)>{{ $number->normalized_number }}</option>
                                    @endforeach
                                </select>
                                <select name="gateway_id" class="rounded border border-slate-200 p-1 text-xs">
                                    @foreach ($gateways as $gateway)
                                        <option value="{{ $gateway->id }}" @selected($route->gateway_id === $gateway->id)>{{ $gateway->name }}</option>
                                    @endforeach
                                </select>
                                <label class="text-xs"><input type="hidden" name="enabled" value="0"><input type="checkbox" name="enabled" value="1" @checked($route->enabled)> فعال</label>
                                <button class="text-xs font-bold text-blue-700">ذخیره</button>
                            </form>
                        </td>
                        <td class="px-5 py-4">
                            <form method="POST" action="{{ route('outbound-routes.destroy', $route) }}" onsubmit="return confirm('حذف مسیر؟')">
                                @csrf
                                @method('DELETE')
                                <button class="text-xs font-bold text-red-600">حذف</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td class="px-5 py-6 text-slate-400" colspan="5">مسیری تعریف نشده است.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>
@endsection
