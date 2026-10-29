<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>ورود به تلفن اینترنتی · بلوکام</title>
    @include('shared.brand-icons')
    @vite(['resources/css/app.css', 'resources/js/app.js', 'resources/js/phone.js'])
    <style>
        .phone-login-bg { background: radial-gradient(ellipse at 82% 12%,rgba(0,105,255,.11),transparent 34%),radial-gradient(ellipse at 6% 88%,rgba(16,185,129,.08),transparent 30%),#f5f8fd; }
        .phone-login-field { width:100%;height:54px;border:1px solid #dbe3ef;border-radius:15px;background:#fff;padding:0 16px;color:#0f172a;outline:none;transition:border-color .18s,box-shadow .18s,background .18s; }
        .phone-login-field:focus { border-color:#3986ff;box-shadow:0 0 0 4px rgba(0,105,255,.11); }
        .phone-login-field::placeholder { color:#a0aec0; }
        .phone-login-button { min-height:54px;border-radius:15px;background:#0668f9;color:white;font-weight:800;box-shadow:0 9px 20px rgba(0,105,255,.2);transition:transform .18s,background .18s,box-shadow .18s; }
        .phone-login-button:hover { background:#0050d0;box-shadow:0 12px 25px rgba(0,105,255,.25);transform:translateY(-1px); }
        @media (prefers-reduced-motion:reduce) { *,*::before,*::after { transition-duration:.01ms!important;scroll-behavior:auto!important; } }
    </style>
</head>
<body class="phone-login-bg min-h-screen text-slate-900 antialiased">
<div class="mx-auto grid min-h-screen max-w-[1440px] items-center gap-10 px-5 py-8 lg:grid-cols-[1.05fr_.95fr] lg:px-12 xl:px-20">
    <main class="order-2 mx-auto w-full max-w-[470px] lg:order-1">
        <a href="https://blucom.ir" class="mb-9 inline-flex items-center gap-3 rounded-xl focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-blue-600" aria-label="بلو‌کام">
            @include('shared.brand-mark', ['brandMarkClass' => 'size-11 shrink-0'])
            <span class="text-xl font-black tracking-tight text-[#031b4e]">بلو<span class="text-[#0069ff]">کام</span></span>
            <span class="mr-1 border-r border-slate-200 pr-3 text-xs font-bold text-slate-500">تلفن اینترنتی</span>
        </a>
        <section class="rounded-[28px] border border-white bg-white p-6 shadow-[0_24px_70px_rgba(15,41,77,.09)] sm:p-9">
            <div class="mb-7 flex items-center gap-3">
                <span class="grid size-12 place-items-center rounded-2xl bg-blue-50 text-blue-600"><svg class="size-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M5 4h4l2 5-3 2a15 15 0 0 0 5 5l2-3 5 2v4a2 2 0 0 1-2 2C10 21 3 14 3 6a2 2 0 0 1 2-2Z"/><path stroke-linecap="round" d="M15 4h5v5m-5-5 5 5"/></svg></span>
                <div><p class="text-xs font-bold text-blue-600">ورود امن به حساب داخلی</p><h1 class="mt-1 text-2xl font-black tracking-tight text-slate-900">تلفنتان را وصل کنید</h1></div>
            </div>
            <p class="mb-7 text-sm leading-7 text-slate-500">شماره خط و اطلاعات داخلی خود را وارد کنید تا فضای تلفن سازمانتان باز شود.</p>

            <div id="phone-login-error" @if (!$errors->any()) hidden @endif role="alert" class="mb-5 flex gap-2 rounded-2xl border border-red-100 bg-red-50 px-4 py-3 text-sm leading-6 text-red-700"><svg class="mt-1 size-4 shrink-0" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16Zm0-11a.75.75 0 0 1 .75.75v3.5a.75.75 0 0 1-1.5 0v-3.5A.75.75 0 0 1 10 7Zm0 7a1 1 0 1 0 0-2 1 1 0 0 0 0 2Z" clip-rule="evenodd"/></svg><span>{{ $errors->first('credentials') ?: $errors->first('line_number') ?: $errors->first('username') ?: $errors->first('password') }}</span></div>

            <form id="phone-login-form" method="POST" action="{{ route('phone.login.submit') }}" class="space-y-5" autocomplete="on">
                @csrf
                <label class="block"><span class="mb-2 block text-sm font-bold text-slate-700">شماره خط شما</span>
                    <div class="relative"><span class="absolute inset-y-0 right-4 flex items-center text-slate-400"><svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M7 3h10a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2Zm3 14h4"/></svg></span>
                    <input class="phone-login-field pr-12" type="tel" name="line_number" inputmode="tel" autocomplete="tel" dir="ltr" placeholder="+98 21 1234 5678" value="{{ old('line_number') }}" required autofocus></div>
                    <span class="mt-2 block text-xs leading-5 text-slate-400">شماره‌ای که برای کسب‌وکارتان فعال شده است.</span>
                </label>
                <label class="block"><span class="mb-2 block text-sm font-bold text-slate-700">نام کاربری داخلی</span>
                    <div class="relative"><span class="absolute inset-y-0 right-4 flex items-center text-slate-400"><svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="8" r="4"/><path stroke-linecap="round" stroke-linejoin="round" d="M4 21a8 8 0 0 1 16 0"/></svg></span>
                    <input class="phone-login-field pr-12" type="text" name="username" autocomplete="username" autocapitalize="none" spellcheck="false" dir="ltr" placeholder="مثلاً 1001" value="{{ old('username') }}" required></div>
                </label>
                <label class="block"><span class="mb-2 block text-sm font-bold text-slate-700">رمز عبور داخلی</span>
                    <div class="relative"><span class="absolute inset-y-0 right-4 flex items-center text-slate-400"><svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="4" y="10" width="16" height="11" rx="2"/><path stroke-linecap="round" d="M8 10V7a4 4 0 0 1 8 0v3"/></svg></span>
                    <input id="phone-password" class="phone-login-field pl-16 pr-12" type="password" name="password" autocomplete="current-password" placeholder="رمز داخلی را وارد کنید" required>
                    <button type="button" id="toggle-password" class="absolute inset-y-0 left-3 rounded-lg px-2 text-xs font-bold text-slate-400 transition hover:bg-slate-50 hover:text-blue-700 focus-visible:outline-2 focus-visible:outline-blue-600" aria-label="نمایش رمز عبور">نمایش</button></div>
                </label>
                <button type="submit" class="phone-login-button flex w-full items-center justify-center gap-2.5">ورود به تلفن<span aria-hidden="true">←</span></button>
            </form>

            <div class="mt-6 flex items-start gap-3 rounded-2xl bg-[#f7f9fc] p-4"><span class="mt-0.5 grid size-8 shrink-0 place-items-center rounded-xl bg-white text-emerald-600 shadow-sm"><svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m5 12 4 4L19 6"/></svg></span><p class="text-xs leading-6 text-slate-500">اطلاعات ورود شما فقط برای تأیید داخلی استفاده می‌شود. برای حفظ امنیت، رمز را روی دستگاه عمومی ذخیره نکنید.</p></div>
            <p class="mt-6 text-center text-xs text-slate-400">برای دریافت یا بازنشانی اطلاعات داخلی با مدیر حساب خود تماس بگیرید.</p>
        </section>
        <p class="mt-6 text-center text-xs text-slate-400">© {{ now()->year }} بلوکام · تماس‌ها در بستر امن سازمان شما</p>
    </main>

    <aside class="phone-login-art order-1 hidden min-h-[640px] flex-col justify-between overflow-hidden rounded-[36px] bg-[#031b4e] p-10 text-white lg:order-2 lg:flex xl:p-14">
        <div class="relative z-10"><div class="inline-flex items-center gap-2 rounded-full border border-white/15 bg-white/[.07] px-3 py-2 text-xs font-bold text-blue-100"><span class="size-2 rounded-full bg-emerald-400 shadow-[0_0_0_4px_rgba(52,211,153,.12)]"></span>تلفن سازمانی، همیشه در دسترس</div>
            <h2 class="mt-10 max-w-xl text-4xl font-black leading-[1.55] xl:text-5xl">یک فضای ساده برای<br><span class="text-[#8dc3ff]">گفت‌وگوهای مهم</span></h2>
            <p class="mt-5 max-w-lg text-sm leading-8 text-blue-100/70">با داخلی سازمانتان وارد شوید و تماس‌ها، وضعیت خط و فعالیت‌های اخیر را یک‌جا ببینید.</p>
        </div>
        <div class="relative z-10 mx-auto my-12 w-full max-w-[440px] rotate-[-2deg] rounded-[30px] border border-white/15 bg-white/[.08] p-5 shadow-2xl backdrop-blur-sm">
            <div class="flex items-center justify-between"><div class="flex items-center gap-3"><span class="grid size-10 place-items-center rounded-2xl bg-blue-400/20 text-blue-100"><svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M5 4h4l2 5-3 2a15 15 0 0 0 5 5l2-3 5 2v4a2 2 0 0 1-2 2C10 21 3 14 3 6a2 2 0 0 1 2-2Z"/></svg></span><div><p class="text-sm font-black">داشبورد تلفن</p><p class="mt-1 text-[11px] text-blue-100/60">همه چیز در یک نگاه</p></div></div><span class="rounded-full bg-white/10 px-3 py-1.5 text-[10px] font-bold text-blue-100">نمونه</span></div>
            <div class="mt-6 rounded-[24px] bg-white p-5 text-slate-900"><div class="flex items-center justify-between"><span class="text-xs font-bold text-slate-500">داخلی من</span><span class="rounded-full bg-blue-50 px-2 py-1 text-[9px] font-bold text-blue-600">نمونه</span></div><p class="mt-2 text-3xl font-black tracking-wide text-[#031b4e]" dir="ltr">204</p><div class="mt-5 flex items-center justify-between border-t border-slate-100 pt-4"><span class="text-xs text-slate-500">تماس امروز</span><strong class="text-sm font-black">۱۲ تماس</strong></div></div>
            <div class="mt-4 flex items-center justify-center gap-3"><span class="grid size-12 place-items-center rounded-full bg-white/10"><svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" d="M12 3v18M8 7v10M4 10v4M16 7v10M20 10v4"/></svg></span><span class="grid size-12 place-items-center rounded-full bg-emerald-400 text-[#03234d] shadow-lg shadow-emerald-950/30"><svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 4h4l2 5-3 2a15 15 0 0 0 5 5l2-3 5 2v4a2 2 0 0 1-2 2C10 21 3 14 3 6a2 2 0 0 1 2-2Z"/></svg></span><span class="grid size-12 place-items-center rounded-full bg-white/10"><svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M3 6h18M8 6V4h8v2m-9 0 1 14h8l1-14"/></svg></span></div>
        </div>
        <div class="relative z-10 flex items-center justify-between text-xs text-blue-100/45"><span>ارتباط روشن، تجربه‌ای آرام</span><span>بلو‌کام</span></div>
        <div class="pointer-events-none absolute -left-24 top-1/4 size-96 rounded-full bg-blue-500/15 blur-3xl"></div><div class="pointer-events-none absolute -bottom-28 -right-24 size-96 rounded-full bg-cyan-400/10 blur-3xl"></div>
    </aside>
</div>
<script>document.getElementById('toggle-password')?.addEventListener('click', function () { const input = document.getElementById('phone-password'); const visible = input.type === 'password'; input.type = visible ? 'text' : 'password'; this.textContent = visible ? 'پنهان' : 'نمایش'; this.setAttribute('aria-label', visible ? 'پنهان‌کردن رمز عبور' : 'نمایش رمز عبور'); });</script>
</body>
</html>
