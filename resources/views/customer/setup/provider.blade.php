@extends('layouts.customer')
@section('title', 'اتصال ارائه‌دهنده')
@section('content')
<div class="grid gap-8 lg:grid-cols-[minmax(0,2fr)_minmax(260px,1fr)] lg:gap-10">
    <div>
        <h1 class="text-2xl font-extrabold text-[#071a3b]">ابتدا ارائه‌دهنده خط خود را وصل کنید</h1>
        <p class="mt-2 text-slate-600">اطلاعات حسابی را که برای شماره کاری خود از ارائه‌دهنده گرفته‌اید وارد کنید.</p>

        @if ($gateways->isNotEmpty())
            <div class="mt-7 space-y-3">
                @foreach ($gateways as $gateway)
                    <div class="panel flex items-center justify-between gap-4 border-r-4 {{ $gateway->verification_status === 'approved' ? 'border-r-emerald-500' : ($gateway->verification_status === 'rejected' ? 'border-r-red-500' : 'border-r-amber-500') }} p-4">
                        <div>
                            <p class="font-bold">{{ $gateway->display_name ?: $gateway->provider_name ?: $gateway->name }}</p>
                            <p class="mt-1 text-xs text-slate-500" dir="ltr">{{ $gateway->host }}</p>
                        </div>
                        <span class="rounded-full px-3 py-1 text-xs font-bold {{ $gateway->verification_status === 'approved' ? 'bg-emerald-50 text-emerald-700' : ($gateway->verification_status === 'rejected' ? 'bg-red-50 text-red-700' : 'bg-amber-50 text-amber-700') }}">{{ $gateway->verification_status === 'approved' ? 'اطلاعات تأیید شد' : ($gateway->verification_status === 'rejected' ? 'نیازمند اصلاح' : 'در انتظار بررسی') }}</span>
                        @if ($gateway->verification_status !== 'approved')<a href="{{ route('customer.setup.provider', ['edit' => $gateway->id]) }}" class="text-xs font-bold text-blue-700">ویرایش</a>@endif
                    </div>
                @endforeach
            </div>
        @endif

        <form method="POST" action="{{ $editing ? route('customer.setup.providers.update', $editing) : route('customer.setup.providers.store') }}" class="panel mt-7 overflow-hidden">
            @csrf
            @if ($editing) @method('PUT') @endif
            <div class="border-b border-slate-100 bg-slate-50 px-6 py-4"><h2 class="font-bold">{{ $editing ? 'اصلاح اتصال' : ($gateways->isEmpty() ? 'اتصال ارائه‌دهنده' : 'افزودن ارائه‌دهنده دیگر') }}</h2></div>
            <div class="space-y-5 p-6">
                <div class="grid gap-5 sm:grid-cols-2">
                    <label class="block text-sm font-bold text-slate-700">نام دلخواه برای این اتصال
                        <input name="display_name" value="{{ old('display_name', $editing?->display_name) }}" maxlength="100" required placeholder="مثال: خط اصلی شرکت" class="mt-2 w-full rounded-xl border border-slate-200 px-4 py-3 font-normal focus:border-blue-500 focus:outline-none" />
                    </label>
                    <label class="block text-sm font-bold text-slate-700">نام شرکت ارائه‌دهنده
                        <input name="provider_name" value="{{ old('provider_name', $editing?->provider_name) }}" maxlength="100" required placeholder="مثال: شرکت ارائه‌دهنده من" class="mt-2 w-full rounded-xl border border-slate-200 px-4 py-3 font-normal focus:border-blue-500 focus:outline-none" />
                    </label>
                </div>
                <fieldset>
                    <legend class="mb-2 text-sm font-bold text-slate-700">روش اتصال</legend>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <label class="flex cursor-pointer items-center gap-2 rounded-xl border border-slate-200 p-4 text-sm font-semibold"><input type="radio" name="connection_method" value="credentials" @checked(old('connection_method', $editing?->connection_method ?? 'credentials') === 'credentials') /> نام کاربری و رمز عبور دارم</label>
                        <label class="flex cursor-pointer items-center gap-2 rounded-xl border border-slate-200 p-4 text-sm font-semibold"><input type="radio" name="connection_method" value="ip" @checked(old('connection_method', $editing?->connection_method) === 'ip') /> ارائه‌دهنده با IP وصل می‌شود</label>
                    </div>
                </fieldset>
                <label class="block text-sm font-bold text-slate-700">آدرس سرور ارائه‌دهنده
                    <input name="host" value="{{ old('host', $editing?->host) }}" maxlength="255" required placeholder="sip.provider.example" dir="ltr" class="mt-2 w-full rounded-xl border border-slate-200 px-4 py-3 text-left font-normal focus:border-blue-500 focus:outline-none" />
                </label>
                <div id="credential-fields" class="grid gap-5 sm:grid-cols-2">
                    <label class="block text-sm font-bold text-slate-700">نام کاربری حساب
                        <input name="username" value="{{ old('username', $editing?->username) }}" maxlength="100" autocomplete="off" dir="ltr" class="mt-2 w-full rounded-xl border border-slate-200 px-4 py-3 text-left font-normal focus:border-blue-500 focus:outline-none" />
                    </label>
                    <label class="block text-sm font-bold text-slate-700">رمز عبور
                        <input name="password" type="password" maxlength="255" autocomplete="new-password" dir="ltr" class="mt-2 w-full rounded-xl border border-slate-200 px-4 py-3 text-left font-normal focus:border-blue-500 focus:outline-none" />
                    </label>
                    <p class="text-xs leading-5 text-slate-500 sm:col-span-2">{{ $editing ? 'برای ارسال دوباره، رمز حساب را دوباره وارد کنید. ' : '' }}رمز عبور رمزنگاری می‌شود و پس از ذخیره دوباره نمایش داده نخواهد شد.</p>
                </div>
                <div id="ip-help" class="hidden rounded-xl border border-blue-100 bg-blue-50 p-4 text-sm leading-7 text-blue-900">برای این روش، ارائه‌دهنده باید آدرس IP سرور بلوکام را مجاز کند. آدرس مورد نیاز: <strong dir="ltr">{{ config('voip.sip_host') }}</strong>. اتصال پیش از بررسی تیم بلوکام فعال نمی‌شود.</div>
                <details class="rounded-xl border border-slate-200 p-4 text-sm">
                    <summary class="cursor-pointer font-bold text-slate-600">تنظیمات پیشرفته اتصال</summary>
                    <div class="mt-4 grid gap-4 sm:grid-cols-2">
                        <label class="font-semibold">پورت <input name="port" type="number" min="1" max="65535" value="{{ old('port', $editing?->port ?? 5060) }}" class="mt-2 w-full rounded-xl border border-slate-200 px-4 py-3" /></label>
                        <label class="font-semibold">پروتکل انتقال <select name="transport" class="mt-2 w-full rounded-xl border border-slate-200 px-4 py-3" dir="ltr"><option value="udp" @selected(old('transport', $editing?->transport ?? 'udp') === 'udp')>UDP</option><option value="tcp" @selected(old('transport', $editing?->transport) === 'tcp')>TCP</option><option value="tls" @selected(old('transport', $editing?->transport) === 'tls')>TLS</option></select></label>
                    </div>
                </details>
            </div>
            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 bg-slate-50 p-5">
                @if (auth()->user()->hasPermission('numbers.manage'))<a href="{{ route('customer.setup.number') }}" class="text-sm font-bold text-slate-600">ادامه با اتصال‌های قبلی</a>@elseif (auth()->user()->hasPermission('lines.view'))<a href="{{ route('customer.setup.lines') }}" class="text-sm font-bold text-slate-600">بازگشت به خط‌ها</a>@endif
                <button class="rounded-xl bg-blue-600 px-6 py-3 text-sm font-bold text-white hover:bg-blue-700">{{ $editing ? 'ارسال دوباره برای بررسی' : 'ذخیره و ادامه' }}</button>
            </div>
        </form>
    </div>
    <aside class="h-fit rounded-2xl bg-slate-100 p-6 lg:sticky lg:top-6">
        <h2 class="font-bold">این اطلاعات را از کجا پیدا کنم؟</h2>
        <p class="mt-3 text-sm leading-7 text-slate-600">معمولاً ارائه‌دهنده هنگام فعال‌سازی خط، آدرس سرور و مشخصات اتصال را در پنل خود یا از طریق پشتیبانی در اختیار شما می‌گذارد.</p>
        <p class="mt-4 text-sm leading-7 text-slate-600">اگر روش اتصال را نمی‌دانید، از ارائه‌دهنده بپرسید «نام کاربری و رمز عبور» می‌دهد یا اتصال را با IP مجاز می‌کند.</p>
    </aside>
</div>
<script>
    const methodInputs = document.querySelectorAll('input[name="connection_method"]');
    function showMethod() {
        const credentials = document.querySelector('input[name="connection_method"]:checked')?.value === 'credentials';
        document.getElementById('credential-fields').classList.toggle('hidden', !credentials);
        document.getElementById('ip-help').classList.toggle('hidden', credentials);
        document.querySelectorAll('#credential-fields input').forEach(input => { input.required = credentials; });
    }
    methodInputs.forEach(input => input.addEventListener('change', showMethod));
    showMethod();
</script>
@endsection
