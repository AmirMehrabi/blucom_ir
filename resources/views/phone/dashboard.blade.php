<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>تلفن من · بلوکام</title>
    @include('shared.brand-icons')
    @vite(['resources/css/app.css', 'resources/js/app.js', 'resources/js/phone.js'])
    <style>
        .phone-app { --phone-ink:#0f172a;--phone-muted:#718096;--phone-blue:#0668f9;--phone-line:#e8edf4;min-height:100vh;background:#f4f7fb;color:var(--phone-ink); }
        .phone-sidebar { width:252px;background:#031b4e; }
        .phone-nav-link { display:flex;align-items:center;gap:12px;min-height:46px;border-radius:13px;padding:0 14px;color:#c0cfe7;font-size:13px;font-weight:700;transition:background .18s,color .18s,transform .18s; }
        .phone-nav-link:hover { background:rgba(255,255,255,.08);color:#fff;transform:translateX(-2px); }
        .phone-nav-link.active { color:#fff;background:linear-gradient(90deg,rgba(26,112,255,.85),rgba(34,126,255,.5));box-shadow:inset 0 0 0 1px rgba(255,255,255,.08); }
        .phone-card { border:1px solid #e9edf4;border-radius:22px;background:#fff;box-shadow:0 3px 12px rgba(15,36,67,.025); }
        .phone-key { min-height:54px;border:1px solid #edf0f5;border-radius:15px;background:#fff;color:#15243b;font-weight:800;transition:background .14s,border .14s,transform .14s; }
        .phone-key:hover { background:#f4f8ff;border-color:#c6dcff;transform:translateY(-1px); }
        .phone-key:active { transform:translateY(1px); }
        .phone-toast { opacity:0;pointer-events:none;transform:translate(-50%,12px);transition:opacity .2s,transform .2s; }
        .phone-toast.show { opacity:1;transform:translate(-50%,0); }
        @media(max-width:900px) { .phone-sidebar { width:76px; } .phone-nav-label,.phone-sidebar-copy { display:none; } .phone-nav-link { justify-content:center;padding:0; } }
        @media(max-width:680px) { .phone-sidebar { display:none; } .phone-main { padding-bottom:96px!important; } .phone-mobile-nav { display:grid!important; } }
        @media(prefers-reduced-motion:reduce) { *,*::before,*::after { scroll-behavior:auto!important;transition-duration:.01ms!important; } }
    </style>
</head>
<body class="phone-app antialiased">
<div class="flex min-h-screen">
    <aside class="phone-sidebar fixed inset-y-0 right-0 z-20 hidden flex-col px-4 py-5 md:flex">
        <a href="{{ route('phone.dashboard') }}" class="flex items-center gap-3 px-2" aria-label="بلو‌کام">
            @include('shared.brand-mark', ['brandMarkClass' => 'size-10 shrink-0'])
            <span class="phone-sidebar-copy text-lg font-black text-white">بلو<span class="text-blue-300">کام</span></span>
        </a>
        <div class="mt-9 rounded-2xl border border-white/10 bg-white/[.06] p-3.5">
            <p class="phone-sidebar-copy text-[10px] font-bold text-blue-200/65">خط انتخاب‌شده</p>
            <p class="mt-1 truncate text-sm font-black text-white" dir="ltr">{{ $number->normalized_number }}</p>
            <div class="phone-sidebar-copy mt-2 flex items-center gap-1.5 text-[10px] text-emerald-200"><span class="size-1.5 rounded-full bg-emerald-400"></span>فعال در حساب</div>
        </div>
        <nav class="mt-7 space-y-1" aria-label="ناوبری تلفن">
            <a href="#overview" class="phone-nav-link active"><svg class="size-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="3" width="8" height="8" rx="2"/><rect x="13" y="3" width="8" height="5" rx="2"/><rect x="13" y="10" width="8" height="11" rx="2"/><rect x="3" y="13" width="8" height="8" rx="2"/></svg><span class="phone-nav-label">نمای کلی</span></a>
            <a href="#dialer" class="phone-nav-link"><svg class="size-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M5 4h4l2 5-3 2a15 15 0 0 0 5 5l2-3 5 2v4a2 2 0 0 1-2 2C10 21 3 14 3 6a2 2 0 0 1 2-2Z"/></svg><span class="phone-nav-label">شماره‌گیر</span></a>
            <a href="#recent-calls" class="phone-nav-link"><svg class="size-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M3 12a9 9 0 1 0 3-6.7L3 8m0-5v5h5m4-2v6l4 2"/></svg><span class="phone-nav-label">تماس‌های اخیر</span></a>
            <a href="#my-line" class="phone-nav-link"><svg class="size-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M7 3h10a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2Zm3 14h4"/></svg><span class="phone-nav-label">خط من</span></a>
        </nav>
        <div class="mt-auto rounded-2xl border border-white/10 bg-white/[.06] p-4 phone-sidebar-copy"><span class="grid size-9 place-items-center rounded-xl bg-blue-400/15 text-blue-200"><svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="9"/><path stroke-linecap="round" d="M12 11v5m0-8h.01"/></svg></span><p class="mt-3 text-xs font-bold text-white">به راهنمایی نیاز دارید؟</p><p class="mt-1 text-[10px] leading-5 text-blue-100/60">مدیر حساب شما می‌تواند در تنظیم داخلی کمکتان کند.</p></div>
        <form method="POST" action="{{ route('phone.logout') }}" class="mt-4">@csrf<button class="phone-nav-link w-full text-right hover:text-red-200"><svg class="size-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M10 17l5-5-5-5m5 5H3m9-9h6a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-6"/></svg><span class="phone-nav-label">خروج از حساب</span></button></form>
    </aside>

    <div class="min-w-0 flex-1 md:mr-[76px] xl:mr-[252px]">
        <header class="sticky top-0 z-10 flex h-[72px] items-center justify-between border-b border-slate-200/80 bg-white/90 px-4 backdrop-blur-xl sm:px-7 xl:px-10">
            <div class="flex min-w-0 items-center gap-3">
                <span class="grid size-10 shrink-0 place-items-center rounded-[14px] bg-blue-50 text-blue-600 md:hidden"><svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M5 4h4l2 5-3 2a15 15 0 0 0 5 5l2-3 5 2v4a2 2 0 0 1-2 2C10 21 3 14 3 6a2 2 0 0 1 2-2Z"/></svg></span>
                <div class="min-w-0"><p class="truncate text-sm font-black text-slate-800">فضای تلفن من</p><p class="mt-0.5 hidden text-[11px] text-slate-400 sm:block">{{ $extension->display_name ?: 'داشبورد داخلی' }} <span dir="ltr">· {{ $extension->extension }}</span></p></div>
            </div>
            <div class="flex items-center gap-2 sm:gap-3">
                <span id="phone-connection-status" class="inline-flex items-center gap-2 rounded-full bg-slate-100 px-3 py-2 text-[10px] font-bold text-slate-600">برای تماس وصل شوید</span>
                <button id="connect-phone" type="button" class="rounded-xl bg-blue-600 px-3 py-2.5 text-xs font-bold text-white transition hover:bg-blue-700">اتصال تلفن</button>
                <button type="button" id="audio-check" class="grid size-10 place-items-center rounded-xl border border-slate-200 bg-white text-slate-500 transition hover:bg-slate-50 hover:text-blue-600" aria-label="بررسی دستگاه صوتی" title="بررسی دستگاه صوتی"><svg class="size-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v18M8 7v10M4 10v4M16 7v10M20 10v4"/></svg></button>
                <div class="grid size-9 place-items-center rounded-full bg-[#e8f1ff] text-xs font-black text-blue-700">{{ mb_substr($extension->display_name ?: $extension->extension, 0, 1) }}</div>
            </div>
        </header>

        <main id="overview" class="phone-main mx-auto max-w-[1440px] space-y-6 p-4 sm:p-6 xl:p-10">
            <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-end">
                <div><div class="flex items-center gap-2 text-[11px] font-bold text-slate-400"><span>تلفن سازمانی</span><span class="text-slate-300">/</span><span class="text-blue-600">نمای کلی</span></div><h1 class="mt-3 text-2xl font-black tracking-tight text-slate-900 sm:text-[28px]">سلام، {{ $extension->display_name ?: 'همکار عزیز' }} <span aria-hidden="true">👋</span></h1><p class="mt-2 text-sm leading-6 text-slate-500">وضعیت داخلی و تماس‌های اخیرتان را اینجا دنبال کنید.</p></div>
                <div class="flex items-center gap-2 self-start rounded-xl border border-slate-200 bg-white px-3.5 py-2.5 text-xs font-semibold text-slate-500 sm:self-auto"><svg class="size-4 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="5" width="18" height="16" rx="2"/><path stroke-linecap="round" d="M16 3v4M8 3v4M3 10h18"/></svg>امروز <span id="localized-date" class="font-bold text-slate-700">امروز</span></div>
            </div>

            <div class="flex gap-2 rounded-2xl border border-blue-100 bg-blue-50/80 px-4 py-3.5 text-xs leading-6 text-blue-900 sm:px-5"><svg class="mt-1 size-4 shrink-0 text-blue-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="9"/><path stroke-linecap="round" d="M12 11v5m0-8h.01"/></svg><p><strong>تلفن مرورگری:</strong> برای دریافت تماس، این صفحه را باز و متصل نگه دارید. تماس‌های اخیر و آمار این داشبورد فعلاً دادهٔ نمونه هستند.</p></div>

            <section id="my-line" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4" aria-label="وضعیت خط و تماس‌ها">
                <article class="phone-card relative overflow-hidden p-5"><div class="flex items-start justify-between"><span class="grid size-11 place-items-center rounded-2xl bg-blue-50 text-blue-600"><svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M7 3h10a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2Zm3 14h4"/></svg></span><span class="rounded-full bg-emerald-50 px-2.5 py-1 text-[10px] font-bold text-emerald-700">فعال در حساب</span></div><p class="mt-4 text-xs font-semibold text-slate-500">شماره خط</p><p class="mt-1 text-lg font-black tracking-wide text-slate-900" dir="ltr">{{ $number->normalized_number }}</p></article>
                <article class="phone-card p-5"><div class="flex items-start justify-between"><span class="grid size-11 place-items-center rounded-2xl bg-violet-50 text-violet-600"><svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="8" r="4"/><path stroke-linecap="round" stroke-linejoin="round" d="M4 21a8 8 0 0 1 16 0"/></svg></span><span class="text-[10px] text-slate-400">حساب داخلی</span></div><p class="mt-4 text-xs font-semibold text-slate-500">داخلی شما</p><p class="mt-1 text-2xl font-black tracking-wider text-slate-900" dir="ltr">{{ $extension->extension }}</p></article>
                <article class="phone-card p-5"><div class="flex items-start justify-between"><span class="grid size-11 place-items-center rounded-2xl bg-emerald-50 text-emerald-600"><svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M5 4h4l2 5-3 2a15 15 0 0 0 5 5l2-3 5 2v4a2 2 0 0 1-2 2C10 21 3 14 3 6a2 2 0 0 1 2-2Z"/></svg></span><span class="text-[10px] text-slate-400">نمونه</span></div><p class="mt-4 text-xs font-semibold text-slate-500">تماس امروز</p><div class="mt-1 flex items-end justify-between"><p class="text-2xl font-black text-slate-900">۱۲ <span class="text-xs font-semibold text-slate-400">تماس</span></p><span class="text-xs font-bold text-emerald-600">↑ ۱۸٪</span></div></article>
                <article class="phone-card p-5"><div class="flex items-start justify-between"><span class="grid size-11 place-items-center rounded-2xl bg-orange-50 text-orange-600"><svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="9"/><path stroke-linecap="round" stroke-linejoin="round" d="M12 7v5l3 2"/></svg></span><span class="text-[10px] text-slate-400">نمونه</span></div><p class="mt-4 text-xs font-semibold text-slate-500">زمان مکالمه</p><p class="mt-1 text-2xl font-black text-slate-900">۴۸ <span class="text-xs font-semibold text-slate-400">دقیقه</span></p></article>
            </section>

            <section class="grid items-start gap-5 xl:grid-cols-[minmax(0,1fr)_360px]">
                <article id="recent-calls" class="phone-card overflow-hidden">
                    <header class="flex items-center justify-between border-b border-slate-100 px-5 py-5 sm:px-6"><div><h2 class="text-sm font-black text-slate-800">تماس‌های اخیر</h2><p class="mt-1 text-[11px] text-slate-400">آخرین فعالیت‌های داخلی شما · دادهٔ نمونه</p></div><button type="button" data-toast="تاریخچه کامل تماس پس از اتصال سامانه نمایش داده می‌شود." class="rounded-xl px-3 py-2 text-xs font-bold text-blue-600 transition hover:bg-blue-50">مشاهده همه</button></header>
                    <div class="divide-y divide-slate-100">
                        @foreach($sampleCalls as $call)
                            <div class="flex items-center gap-3.5 px-5 py-4 transition hover:bg-slate-50/70 sm:px-6">
                                <span @class(['grid size-10 shrink-0 place-items-center rounded-full', 'bg-emerald-50 text-emerald-600' => $call['type'] === 'incoming' && $call['status'] === 'answered', 'bg-blue-50 text-blue-600' => $call['type'] === 'outgoing', 'bg-red-50 text-red-500' => $call['status'] === 'missed'])><svg class="size-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M5 4h4l2 5-3 2a15 15 0 0 0 5 5l2-3 5 2v4a2 2 0 0 1-2 2C10 21 3 14 3 6a2 2 0 0 1 2-2Z"/>@if($call['type'] === 'incoming')<path stroke-linecap="round" stroke-linejoin="round" d="m8 7-3 3m0-3v3h3"/>@else<path stroke-linecap="round" stroke-linejoin="round" d="m16 17 3-3m0 3v-3h-3"/>@endif</svg></span>
                                <div class="min-w-0 flex-1"><p class="truncate text-sm font-bold text-slate-800">{{ $call['name'] }}</p><p class="mt-1 text-[11px] text-slate-400" dir="ltr">{{ $call['number'] }}</p></div><div class="hidden text-left sm:block"><p class="text-xs font-bold text-slate-600">{{ $call['time'] }}</p><p class="mt-1 text-[10px] text-slate-400">{{ $call['duration'] }}</p></div><span @class(['rounded-full px-2 py-1 text-[10px] font-bold', 'bg-emerald-50 text-emerald-700' => $call['status'] === 'answered', 'bg-red-50 text-red-600' => $call['status'] === 'missed'])>{{ $call['status'] === 'missed' ? 'بی‌پاسخ' : ($call['type'] === 'incoming' ? 'دریافتی' : 'گرفته‌شده') }}</span><button type="button" data-call-number="{{ $call['number'] }}" class="grid size-9 shrink-0 place-items-center rounded-xl text-slate-400 transition hover:bg-blue-50 hover:text-blue-600" aria-label="تماس با {{ $call['name'] }}"><svg class="size-[17px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M5 4h4l2 5-3 2a15 15 0 0 0 5 5l2-3 5 2v4a2 2 0 0 1-2 2C10 21 3 14 3 6a2 2 0 0 1 2-2Z"/></svg></button>
                            </div>
                        @endforeach
                    </div>
                </article>

                <article id="dialer" class="phone-card p-5 sm:p-6">
                    <div class="flex items-start justify-between"><div><h2 class="text-sm font-black text-slate-800">شماره‌گیر</h2><p class="mt-1 text-[11px] text-slate-400">شمارهٔ مقصد را وارد کنید</p></div><span class="grid size-9 place-items-center rounded-xl bg-blue-50 text-blue-600"><svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4h6v6H4zM14 4h6v6h-6zM4 14h6v6H4zM14 14h6v6h-6z"/></svg></span></div>
                    <label class="mt-5 block"><span class="sr-only">شماره مقصد</span><input id="dial-input" type="tel" inputmode="tel" autocomplete="off" dir="ltr" placeholder="شماره را وارد کنید" class="h-[56px] w-full rounded-2xl border border-slate-200 bg-[#fbfcfe] px-4 text-center text-lg font-bold tracking-wider text-slate-800 outline-none transition placeholder:text-sm placeholder:font-normal placeholder:tracking-normal focus:border-blue-400 focus:bg-white focus:ring-4 focus:ring-blue-500/10"></label>
                    <div class="mt-4 grid grid-cols-3 gap-2.5" dir="ltr">
                        @foreach([['1',''],['2','ABC'],['3','DEF'],['4','GHI'],['5','JKL'],['6','MNO'],['7','PQRS'],['8','TUV'],['9','WXYZ'],['*',''],['0','+'],['#','']] as [$digit,$letters])<button type="button" class="phone-key flex flex-col items-center justify-center" data-digit="{{ $digit }}"><span class="text-lg leading-6">{{ $digit }}</span>@if($letters)<span class="text-[8px] font-semibold tracking-[.15em] text-slate-400">{{ $letters }}</span>@endif</button>@endforeach
                    </div>
                    <div class="mt-4 grid grid-cols-[48px_1fr_48px] items-center gap-2"><button type="button" id="dial-delete" class="grid size-12 place-items-center rounded-2xl text-slate-400 transition hover:bg-slate-100 hover:text-slate-700" aria-label="پاک‌کردن رقم آخر"><svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M21 4H8l-5 8 5 8h13V4Z"/><path stroke-linecap="round" d="m11 9 6 6m0-6-6 6"/></svg></button><button type="button" id="dial-call" class="flex h-12 items-center justify-center gap-2 rounded-2xl bg-emerald-600 text-sm font-black text-white transition hover:bg-emerald-700"><svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M5 4h4l2 5-3 2a15 15 0 0 0 5 5l2-3 5 2v4a2 2 0 0 1-2 2C10 21 3 14 3 6a2 2 0 0 1 2-2Z"/></svg>تماس</button><button type="button" id="dial-clear" class="grid size-12 place-items-center rounded-2xl text-slate-400 transition hover:bg-slate-100 hover:text-slate-700" aria-label="پاک‌کردن شماره"><svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="m6 6 12 12M18 6 6 18"/></svg></button></div>
                    <div class="mt-3 flex items-center justify-between gap-2"><span id="active-call-status" class="text-xs font-semibold text-slate-500">آمادهٔ تماس</span><span id="call-duration" class="font-mono text-xs text-slate-400">00:00</span></div>
                    <div class="mt-3 grid grid-cols-2 gap-2"><button id="mute-call" type="button" disabled class="rounded-xl border border-slate-200 px-3 py-2 text-xs font-bold text-slate-600 disabled:opacity-40">بی‌صدا</button><button id="hangup-call" type="button" disabled class="rounded-xl border border-red-200 px-3 py-2 text-xs font-bold text-red-600 disabled:opacity-40">پایان تماس</button></div>
                </article>
            </section>

            <section class="grid gap-4 lg:grid-cols-2">
                <article class="phone-card flex items-center gap-4 p-5 sm:p-6"><span class="grid size-12 shrink-0 place-items-center rounded-2xl bg-violet-50 text-violet-600"><svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3 4 7v5c0 5 3.5 8 8 9 4.5-1 8-4 8-9V7l-8-4Z"/><path stroke-linecap="round" stroke-linejoin="round" d="m9 12 2 2 4-4"/></svg></span><div class="min-w-0 flex-1"><h2 class="text-sm font-black text-slate-800">حریم خصوصی داخلی</h2><p class="mt-1 text-xs leading-6 text-slate-500">رمز شما در داشبورد نمایش داده نمی‌شود. برای تغییر آن از مدیر حساب کمک بگیرید.</p></div><span class="rounded-full bg-emerald-50 px-2.5 py-1 text-[10px] font-bold text-emerald-700">محافظت‌شده</span></article>
                <article class="phone-card flex items-center gap-4 p-5 sm:p-6"><span class="grid size-12 shrink-0 place-items-center rounded-2xl bg-amber-50 text-amber-600"><svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 4h.01M10.3 3.9 2.6 17.2A2 2 0 0 0 4.3 20h15.4a2 2 0 0 0 1.7-2.8L13.7 3.9a2 2 0 0 0-3.4 0Z"/></svg></span><div class="min-w-0 flex-1"><h2 class="text-sm font-black text-slate-800">تماس‌های ورودی</h2><p class="mt-1 text-xs leading-6 text-slate-500">برای دریافت تماس، تلفن مرورگر باید باز و متصل باشد.</p></div><button type="button" data-toast="وضعیت زندهٔ اتصال تلفن پس از راه‌اندازی WebRTC نمایش داده می‌شود." class="shrink-0 rounded-xl border border-slate-200 px-3 py-2 text-[10px] font-bold text-slate-600 transition hover:border-blue-200 hover:text-blue-700">درباره اتصال</button></article>
            </section>
            <footer class="pb-2 text-center text-[10px] text-slate-400">تماس‌های اخیر و گزارش‌ها فعلاً دادهٔ نمونه هستند · بلوکام</footer>
        </main>
    </div>
</div>

<nav class="phone-mobile-nav fixed inset-x-0 bottom-0 z-30 hidden grid-cols-4 border-t border-slate-200 bg-white/95 px-2 pb-[max(env(safe-area-inset-bottom),8px)] pt-2 backdrop-blur-xl" aria-label="ناوبری تلفن">
    <a href="#overview" class="flex flex-col items-center gap-1 rounded-xl py-2 text-blue-600"><svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="3" width="8" height="8" rx="2"/><rect x="13" y="3" width="8" height="5" rx="2"/><rect x="13" y="10" width="8" height="11" rx="2"/><rect x="3" y="13" width="8" height="8" rx="2"/></svg><span class="text-[9px] font-bold">خانه</span></a>
    <a href="#dialer" class="flex flex-col items-center gap-1 rounded-xl py-2 text-slate-400"><svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4h6v6H4zM14 4h6v6h-6zM4 14h6v6H4zM14 14h6v6h-6z"/></svg><span class="text-[9px] font-bold">شماره‌گیر</span></a>
    <a href="#recent-calls" class="flex flex-col items-center gap-1 rounded-xl py-2 text-slate-400"><svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M3 12a9 9 0 1 0 3-6.7L3 8m0-5v5h5m4-2v6l4 2"/></svg><span class="text-[9px] font-bold">تماس‌ها</span></a>
    <form method="POST" action="{{ route('phone.logout') }}" class="flex flex-col items-center"><input type="hidden" name="_token" value="{{ csrf_token() }}"><button class="flex flex-col items-center gap-1 rounded-xl py-2 text-slate-400"><svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M10 17l5-5-5-5m5 5H3m9-9h6a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-6"/></svg><span class="text-[9px] font-bold">خروج</span></button></form>
</nav>
<audio id="remote-audio" autoplay playsinline class="hidden"></audio>
<section id="connect-password-dialog" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-950/40 p-4" aria-labelledby="connect-dialog-title" role="dialog" aria-modal="true">
    <div class="w-full max-w-sm rounded-3xl bg-white p-6 shadow-2xl"><h2 id="connect-dialog-title" class="text-lg font-black text-slate-900">اتصال داخلی</h2><p class="mt-2 text-sm leading-6 text-slate-500">برای ثبت تلفن مرورگری، رمز داخلی خود را وارد کنید. رمز فقط تا زمانی که این صفحه باز است در حافظهٔ مرورگر می‌ماند.</p><label class="mt-5 block"><span class="mb-2 block text-xs font-bold text-slate-700">رمز عبور داخلی</span><input id="reconnect-password" type="password" autocomplete="current-password" class="h-12 w-full rounded-xl border border-slate-200 px-4 text-left outline-none focus:border-blue-400 focus:ring-4 focus:ring-blue-500/10" dir="ltr"></label><div class="mt-5 flex gap-2"><button id="reconnect-phone" type="button" class="flex-1 rounded-xl bg-blue-600 px-4 py-3 text-sm font-bold text-white">اتصال</button><button id="close-connect-dialog" type="button" class="rounded-xl border border-slate-200 px-4 py-3 text-sm font-bold text-slate-600">بعداً</button></div></div>
</section>
<section id="incoming-call" class="fixed inset-x-4 top-4 z-50 mx-auto hidden max-w-md rounded-3xl border border-emerald-200 bg-white p-5 shadow-2xl" role="dialog" aria-live="assertive" aria-label="تماس ورودی">
    <div class="flex items-center gap-3"><span class="grid size-12 place-items-center rounded-2xl bg-emerald-50 text-emerald-700"><svg class="size-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M5 4h4l2 5-3 2a15 15 0 0 0 5 5l2-3 5 2v4a2 2 0 0 1-2 2C10 21 3 14 3 6a2 2 0 0 1 2-2Z"/></svg></span><div class="min-w-0 flex-1"><p class="text-xs font-bold text-emerald-700">تماس ورودی</p><p id="incoming-caller" class="mt-1 truncate text-base font-black text-slate-900">تماس‌گیرنده</p></div></div>
    <div class="mt-4 grid grid-cols-2 gap-2"><button id="answer-call" type="button" class="rounded-xl bg-emerald-600 px-4 py-3 text-sm font-bold text-white">پاسخ</button><button id="decline-call" type="button" class="rounded-xl bg-red-50 px-4 py-3 text-sm font-bold text-red-700">رد تماس</button></div>
</section>
<div id="phone-toast" class="phone-toast fixed bottom-24 left-1/2 z-50 max-w-[calc(100vw-32px)] rounded-2xl bg-slate-900 px-4 py-3 text-center text-xs font-semibold leading-6 text-white shadow-xl sm:bottom-6" role="status" aria-live="polite"></div>
</body>
</html>
