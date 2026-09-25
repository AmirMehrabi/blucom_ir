<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'پنل بلوکام') · بلوکام</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-[#f6f8fb] text-slate-900">
@php
    $panelUser = auth()->user();
    $navigation = [
        ['label' => 'داشبورد', 'route' => 'dashboard', 'permission' => 'dashboard.view', 'icon' => '◫'],
        ['label' => 'خط‌ها', 'route' => 'customer.setup.lines', 'permission' => 'lines.view', 'icon' => '◉'],
        ['label' => 'راه‌اندازی خط', 'route' => 'customer.setup.provider', 'permission' => 'providers.manage', 'icon' => '＋'],
        ['label' => 'افزودن شماره', 'route' => 'customer.setup.number', 'permission' => 'numbers.manage', 'icon' => '＃'],
    ];
    $adminNavigation = [
        ['label' => 'بررسی اتصال‌ها', 'route' => 'admin.customer-connections.index', 'icon' => '✓'],
        ['label' => 'دروازه‌های SIP', 'route' => 'sip-gateways.index', 'icon' => '⌁'],
        ['label' => 'شماره‌های DID', 'route' => 'admin.sip-numbers.index', 'icon' => '＃'],
        ['label' => 'داخلی‌ها', 'route' => 'sip-extensions.index', 'icon' => '◎'],
        ['label' => 'مسیرهای ورودی', 'route' => 'inbound-routes.index', 'icon' => '↙'],
        ['label' => 'مسیرهای خروجی', 'route' => 'outbound-routes.index', 'icon' => '↗'],
        ['label' => 'کاربران', 'route' => 'users.index', 'icon' => '♙'],
    ];
@endphp
<div class="min-h-screen lg:flex">
    <aside class="hidden w-[256px] shrink-0 flex-col bg-[#10233d] px-4 py-6 text-white lg:flex">
        <a href="{{ auth()->user()->homePath() }}" class="flex items-center gap-3 px-3 text-xl font-black"><span class="grid size-10 place-items-center rounded-xl bg-blue-500 text-lg">ب</span><span>بلوکام<small class="block text-[10px] font-medium tracking-wide text-slate-300">پنل مدیریت تماس</small></span></a>
        <div class="mt-10 px-3 text-[11px] font-bold text-slate-400">کارهای روزانه</div>
        <nav class="mt-3 space-y-1" aria-label="فهرست اصلی">
            @foreach ($navigation as $item)
                @if ($panelUser->hasPermission($item['permission']))
                    <a href="{{ route($item['route']) }}" @class(['flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-semibold transition', 'bg-white/12 text-white' => request()->routeIs($item['route']), 'text-slate-300 hover:bg-white/8 hover:text-white' => !request()->routeIs($item['route'])])><span aria-hidden="true" class="w-5 text-center text-lg">{{ $item['icon'] }}</span>{{ $item['label'] }}</a>
                @endif
            @endforeach
        </nav>
        @if ($panelUser->isAdmin())
            <div class="mt-8 px-3 text-[11px] font-bold text-slate-400">مدیریت</div>
            <nav class="mt-3 space-y-1" aria-label="مدیریت سیستم">
                @foreach ($adminNavigation as $item)
                    <a href="{{ route($item['route']) }}" @class(['flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-semibold transition', 'bg-white/12 text-white' => request()->routeIs($item['route']), 'text-slate-300 hover:bg-white/8 hover:text-white' => !request()->routeIs($item['route'])])><span aria-hidden="true" class="w-5 text-center text-lg">{{ $item['icon'] }}</span>{{ $item['label'] }}</a>
                @endforeach
            </nav>
        @endif
        <div class="mt-auto border-t border-white/10 px-3 pt-5"><p class="truncate text-sm font-bold">{{ $panelUser->name }}</p><p class="mt-1 text-xs text-slate-400">{{ $panelUser->isAdmin() ? 'مدیر' : 'اپراتور' }}</p></div>
    </aside>
    <div class="min-w-0 flex-1">
        <header class="flex items-center justify-between gap-3 border-b border-slate-200 bg-white px-5 py-4 sm:px-8">
            <div><span class="text-sm font-black text-[#10233d]">@yield('title', 'پنل بلوکام')</span><span class="mr-3 hidden text-xs text-slate-400 sm:inline">{{ $panelUser->name }}</span></div>
            <form method="POST" action="{{ route('logout') }}">@csrf<button class="rounded-lg border border-slate-200 px-3 py-2 text-xs font-bold text-slate-600 hover:bg-slate-50">خروج</button></form>
        </header>
        <details class="border-b border-slate-200 bg-white px-5 py-3 lg:hidden"><summary class="cursor-pointer text-sm font-bold text-[#10233d]">☰ فهرست</summary><nav class="mt-3 grid grid-cols-2 gap-2" aria-label="فهرست موبایل">
            @foreach ($navigation as $item)
                @if ($panelUser->hasPermission($item['permission']))<a href="{{ route($item['route']) }}" class="rounded-xl bg-slate-50 px-3 py-3 text-sm font-semibold">{{ $item['label'] }}</a>@endif
            @endforeach
            @if ($panelUser->isAdmin())@foreach ($adminNavigation as $item)<a href="{{ route($item['route']) }}" class="rounded-xl bg-slate-50 px-3 py-3 text-sm font-semibold">{{ $item['label'] }}</a>@endforeach @endif
        </nav></details>
        <main class="mx-auto max-w-[1440px] p-5 sm:p-8">
            @if (session('status'))<div class="mb-5 rounded-xl border border-emerald-200 bg-emerald-50 px-5 py-3 text-sm font-semibold text-emerald-800" role="status">{{ session('status') }}</div>@endif
            @if ($errors->any())<div class="mb-5 rounded-xl border border-red-200 bg-red-50 px-5 py-3 text-sm text-red-800" role="alert"><p class="font-bold">لطفاً موارد زیر را بررسی کنید:</p><ul class="mt-2 list-disc space-y-1 pr-5">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
            @yield('before-content')
            @yield('content')
        </main>
    </div>
</div>
</body>
</html>
