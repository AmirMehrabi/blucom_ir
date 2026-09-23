@extends('layouts.portal')
@section('content')
<div class="mb-7">
    <h1 class="text-xl font-extrabold">دروازه‌های ارائه‌دهنده (Gateways)</h1>
    <p class="mt-1 text-sm text-slate-500">ترانک‌های SIP ارائه‌دهنده را مدیریت کنید. رمز عبور هرگز نمایش داده نمی‌شود.</p>
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
    <div class="border-b border-slate-100 p-5"><h2 class="font-bold">دروازه جدید</h2></div>
    <form method="POST" action="{{ route('sip-gateways.store') }}" class="grid gap-4 p-5 sm:grid-cols-2 lg:grid-cols-3">
        @csrf
        <div>
            <label class="mb-1 block text-xs font-bold text-slate-500">نام (فقط حروف، عدد، _ و -)</label>
            <input name="name" value="{{ old('name') }}" required pattern="[a-zA-Z0-9_-]+" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm" dir="ltr" />
        </div>
        <div>
            <label class="mb-1 block text-xs font-bold text-slate-500">هاست</label>
            <input name="host" value="{{ old('host') }}" required class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm" dir="ltr" />
        </div>
        <div>
            <label class="mb-1 block text-xs font-bold text-slate-500">پورت</label>
            <input name="port" type="number" min="1" max="65535" value="{{ old('port', 5060) }}" required class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm" dir="ltr" />
        </div>
        <div>
            <label class="mb-1 block text-xs font-bold text-slate-500">ترنسپورت</label>
            <select name="transport" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm">
                @foreach (['udp', 'tcp', 'tls'] as $transport)
                    <option value="{{ $transport }}" @selected(old('transport', 'udp') === $transport)>{{ strtoupper($transport) }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="mb-1 block text-xs font-bold text-slate-500">نام کاربری</label>
            <input name="username" value="{{ old('username') }}" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm" dir="ltr" />
        </div>
        <div>
            <label class="mb-1 block text-xs font-bold text-slate-500">رمز عبور</label>
            <input name="password" type="password" autocomplete="new-password" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm" dir="ltr" />
        </div>
        <div>
            <label class="mb-1 block text-xs font-bold text-slate-500">پروفایل</label>
            <select name="profile" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm">
                <option value="external" @selected(old('profile', 'external') === 'external')>external</option>
                <option value="internal" @selected(old('profile') === 'internal')>internal</option>
            </select>
        </div>
        <div>
            <label class="mb-1 block text-xs font-bold text-slate-500">کانتکست</label>
            <select name="context" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm">
                <option value="public" @selected(old('context', 'public') === 'public')>public</option>
                <option value="default" @selected(old('context') === 'default')>default</option>
            </select>
        </div>
        <label class="flex items-center gap-2 self-end pb-2 text-sm">
            <input type="hidden" name="enabled" value="0" />
            <input type="checkbox" name="enabled" value="1" @checked(old('enabled', true)) />
            فعال
        </label>
        <div class="sm:col-span-2 lg:col-span-3">
            <button class="rounded-xl bg-slate-900 px-5 py-2.5 text-sm font-bold text-white">ثبت دروازه</button>
        </div>
    </form>
</section>

<section class="panel overflow-hidden">
    <div class="border-b border-slate-100 p-5"><h2 class="font-bold">دروازه‌ها</h2></div>
    <div class="overflow-x-auto">
        <table class="w-full min-w-[720px] text-right text-sm">
            <thead class="bg-slate-50 text-[10px] font-bold uppercase tracking-wider text-slate-400">
                <tr>
                    <th class="px-5 py-3">نام</th>
                    <th class="px-5 py-3">هاست</th>
                    <th class="px-5 py-3">پروفایل</th>
                    <th class="px-5 py-3">کانتکست</th>
                    <th class="px-5 py-3">وضعیت</th>
                    <th class="px-5 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($gateways as $gateway)
                    <tr class="hover:bg-slate-50">
                        <td class="px-5 py-4 font-semibold" dir="ltr">{{ $gateway->name }}</td>
                        <td class="px-5 py-4 text-slate-500" dir="ltr">{{ $gateway->host }}:{{ $gateway->port }}</td>
                        <td class="px-5 py-4">{{ $gateway->profile }}</td>
                        <td class="px-5 py-4">{{ $gateway->context }}</td>
                        <td class="px-5 py-4">
                            <form method="POST" action="{{ route('sip-gateways.update', $gateway) }}">
                                @csrf
                                @method('PUT')
                                <input type="hidden" name="enabled" value="{{ $gateway->enabled ? 0 : 1 }}" />
                                <button class="text-xs font-bold {{ $gateway->enabled ? 'text-emerald-600' : 'text-slate-400' }}">● {{ $gateway->enabled ? 'فعال' : 'غیرفعال' }}</button>
                            </form>
                        </td>
                        <td class="px-5 py-4">
                            <form method="POST" action="{{ route('sip-gateways.destroy', $gateway) }}" onsubmit="return confirm('حذف دروازه؟')">
                                @csrf
                                @method('DELETE')
                                <button class="text-xs font-bold text-red-600">حذف</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td class="px-5 py-6 text-slate-400" colspan="6">دروازه‌ای ثبت نشده است.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>
@endsection
