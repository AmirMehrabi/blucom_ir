@extends('layouts.portal')
@section('content')
<div class="mb-7">
    <h1 class="text-xl font-extrabold">داخلی‌های SIP</h1>
    <p class="mt-1 text-sm text-slate-500">داخلی بسازید تا Zoiper یا سایر سافت‌فون‌ها ثبت شوند.</p>
</div>

@if (session('status'))
    <div class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-700">{{ session('status') }}</div>
@endif
@php $credentials = session('extension_credentials'); @endphp
@if ($credentials)
    <div class="mb-4 rounded-xl border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-900">
        <p class="font-bold">اطلاعات اتصال (فقط همین نمایش داده می‌شود):</p>
        <p class="mt-1" dir="ltr">User: {{ $credentials['extension'] }} · Password: {{ $credentials['password'] }} · Server: {{ $credentials['host'] }}:{{ $credentials['port'] ?? 5060 }}</p>
    </div>
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
    <div class="border-b border-slate-100 p-5"><h2 class="font-bold">داخلی جدید</h2></div>
    <form method="POST" action="{{ route('sip-extensions.store') }}" class="grid gap-4 p-5 sm:grid-cols-2">
        @csrf
        <div>
            <label class="mb-1 block text-xs font-bold text-slate-500">شماره داخلی</label>
            <input name="extension" value="{{ old('extension') }}" required placeholder="1000" pattern="[1-9][0-9]{2,8}"
                   class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm" dir="ltr" />
        </div>
        <div>
            <label class="mb-1 block text-xs font-bold text-slate-500">نام نمایشی</label>
            <input name="display_name" value="{{ old('display_name') }}" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm" />
        </div>
        <div>
            <label class="mb-1 block text-xs font-bold text-slate-500">رمز عبور (اختیاری — در غیر این صورت تولید می‌شود)</label>
            <input name="password" type="password" autocomplete="new-password" minlength="8"
                   class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm" dir="ltr" />
        </div>
        <div class="flex items-end">
            <button class="rounded-xl bg-slate-900 px-5 py-2.5 text-sm font-bold text-white">ثبت داخلی</button>
        </div>
    </form>
</section>

<section class="panel overflow-hidden">
    <div class="border-b border-slate-100 p-5"><h2 class="font-bold">داخلی‌ها</h2></div>
    <div class="overflow-x-auto">
        <table class="w-full min-w-[560px] text-right text-sm">
            <thead class="bg-slate-50 text-[10px] font-bold uppercase tracking-wider text-slate-400">
                <tr>
                    <th class="px-5 py-3">داخلی</th>
                    <th class="px-5 py-3">نام</th>
                    <th class="px-5 py-3">وضعیت</th>
                    <th class="px-5 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($extensions as $extension)
                    <tr class="hover:bg-slate-50">
                        <td class="px-5 py-4 font-semibold" dir="ltr">{{ $extension->extension }}</td>
                        <td class="px-5 py-4 text-slate-500">
                            <form method="POST" action="{{ route('sip-extensions.update', $extension) }}" class="flex flex-wrap gap-2">
                                @csrf @method('PUT')
                                <input name="display_name" value="{{ $extension->display_name }}" placeholder="نام نمایشی" class="rounded-lg border border-slate-200 p-2">
                                <input name="password" type="password" autocomplete="new-password" placeholder="رمز جدید (اختیاری)" class="rounded-lg border border-slate-200 p-2">
                                <button class="font-bold text-blue-700">ذخیره / تغییر رمز</button>
                                <button name="generate_password" value="1" class="font-bold text-amber-700">تولید رمز جدید</button>
                            </form>
                        </td>
                        <td class="px-5 py-4">
                            <form method="POST" action="{{ route('sip-extensions.update', $extension) }}">
                                @csrf
                                @method('PUT')
                                <input type="hidden" name="enabled" value="{{ $extension->enabled ? 0 : 1 }}" />
                                <button class="text-xs font-bold {{ $extension->enabled ? 'text-emerald-600' : 'text-slate-400' }}">● {{ $extension->enabled ? 'فعال' : 'غیرفعال' }}</button>
                            </form>
                        </td>
                        <td class="px-5 py-4">
                            <form method="POST" action="{{ route('sip-extensions.destroy', $extension) }}" onsubmit="return confirm('حذف داخلی؟')">
                                @csrf
                                @method('DELETE')
                                <button class="text-xs font-bold text-red-600">حذف</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td class="px-5 py-6 text-slate-400" colspan="4">داخلی‌ای ثبت نشده است.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>
@endsection
