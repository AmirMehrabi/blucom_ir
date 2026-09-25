@extends('layouts.customer')
@section('title', 'اتصال تلفن')
@section('content')
<div class="mx-auto max-w-4xl">
    <h1 class="text-2xl font-extrabold text-[#071a3b]">تلفن {{ $extension->display_name ?: $extension->extension }} را وصل کنید</h1>
    <p class="mt-2 text-slate-600">می‌توانید از نرم‌افزار تلفن مانند Zoiper یا یک تلفن رومیزی استفاده کنید. اطلاعات زیر را در بخش حساب تلفنی دستگاه وارد کنید.</p>
    <div class="mt-7 grid gap-6 lg:grid-cols-[minmax(0,1.4fr)_minmax(260px,1fr)]">
        <section class="panel overflow-hidden">
            <div class="border-b border-slate-100 bg-slate-50 px-6 py-4"><h2 class="font-bold">مشخصات تلفن</h2></div>
            <dl class="divide-y divide-slate-100 px-6">
                <div class="flex justify-between gap-4 py-4"><dt class="text-sm text-slate-500">آدرس سرور</dt><dd class="font-bold" dir="ltr">{{ config('voip.sip_host') }}</dd></div>
                <div class="flex justify-between gap-4 py-4"><dt class="text-sm text-slate-500">پورت</dt><dd class="font-bold" dir="ltr">{{ config('voip.sip_port') }}</dd></div>
                <div class="flex justify-between gap-4 py-4"><dt class="text-sm text-slate-500">نام کاربری</dt><dd class="font-bold" dir="ltr">{{ $extension->extension }}</dd></div>
                <div class="flex justify-between gap-4 py-4"><dt class="text-sm text-slate-500">رمز عبور</dt><dd class="text-left font-bold" dir="ltr">{{ is_array($credentials) && ($credentials['extension'] ?? null) === $extension->extension ? $credentials['password'] : 'برای امنیت، فقط هنگام ساخت یا بازنشانی نمایش داده می‌شود.' }}</dd></div>
            </dl>
            <div class="border-t border-slate-100 bg-slate-50 p-5">
                @if (is_array($credentials) && ($credentials['extension'] ?? null) === $extension->extension)
                    <p class="mb-3 text-sm font-semibold text-amber-800">این رمز را اکنون در جای امن نگه دارید. پس از خروج از صفحه دوباره نمایش داده نمی‌شود.</p>
                @endif
                <form method="POST" action="{{ route('customer.setup.phone.reset', $extension) }}" onsubmit="return confirm('رمز قبلی تلفن غیرفعال می‌شود. ادامه می‌دهید؟')">@csrf<button class="text-sm font-bold text-blue-700">ساخت رمز جدید</button></form>
            </div>
        </section>
        <aside class="rounded-2xl bg-slate-100 p-6">
            <h2 class="font-bold">مراحل اتصال</h2>
            <ol class="mt-4 list-decimal space-y-3 pr-5 text-sm leading-7 text-slate-700">
                <li>در نرم‌افزار یا تلفن رومیزی، «افزودن حساب» را باز کنید.</li>
                <li>آدرس سرور، نام کاربری و رمز بالا را وارد کنید.</li>
                <li>پس از ثبت حساب روی دستگاه، یک تماس آزمایشی بگیرید.</li>
            </ol>
            <p class="mt-5 rounded-xl bg-white p-3 text-xs leading-6 text-slate-600">این صفحه وضعیت ثبت دستگاه را زنده بررسی نمی‌کند. اگر اتصال برقرار نشد، مشخصات را دوباره بررسی کنید یا از پشتیبانی کمک بگیرید.</p>
        </aside>
    </div>
    <div class="mt-7 flex flex-wrap justify-between gap-3">
        <a href="{{ route('customer.setup.lines') }}" class="rounded-xl bg-blue-600 px-6 py-3 text-sm font-bold text-white hover:bg-blue-700">دیدن خط‌های من</a>
        <a href="{{ route('customer.setup.number') }}" class="rounded-xl border border-slate-200 bg-white px-5 py-3 text-sm font-bold text-slate-600">افزودن شماره دیگر</a>
    </div>
</div>
@endsection
