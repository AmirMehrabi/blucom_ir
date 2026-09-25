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
    $quickCreate = $panelUser->isAdmin()
        ? ['label' => 'افزودن شماره', 'route' => 'admin.sip-numbers.index']
        : ($panelUser->hasPermission('numbers.manage')
            ? ['label' => 'افزودن شماره', 'route' => 'customer.setup.number']
            : ($panelUser->hasPermission('providers.manage') ? ['label' => 'راه‌اندازی خط', 'route' => 'customer.setup.provider'] : null));
    $currentLabel = trim($__env->yieldContent('title')) ?: 'داشبورد';
    $breadcrumbs = [['label' => 'خانه', 'route' => 'dashboard']];
    foreach (array_merge($navigation, $panelUser->isAdmin() ? $adminNavigation : []) as $navItem) {
        if (request()->routeIs($navItem['route']) && $navItem['label'] !== 'داشبورد') {
            $breadcrumbs[] = ['label' => $navItem['label'], 'route' => $navItem['route']];
            break;
        }
    }
    if (count($breadcrumbs) === 1 && $currentLabel !== 'داشبورد') {
        $breadcrumbs[] = ['label' => $currentLabel, 'route' => null];
    }
    $recentNotifications = $panelUser->notifications()->latest()->limit(6)->get();
    $unreadNotificationCount = $panelUser->unreadNotifications()->count();
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
        <details class="group relative mt-auto border-t border-white/10 pt-4">
            <summary class="menu-summary flex cursor-pointer items-center gap-3 rounded-xl border border-white/10 bg-white/5 px-3 py-3 transition hover:bg-white/10">
                <span class="grid size-10 shrink-0 place-items-center rounded-xl bg-blue-500/25 text-sm font-black text-blue-100">{{ mb_substr($panelUser->name, 0, 1) }}</span>
                <span class="min-w-0 flex-1"><span class="block truncate text-sm font-bold">{{ $panelUser->name }}</span><span class="mt-1 block text-xs text-slate-400">{{ $panelUser->isAdmin() ? 'مدیر' : 'اپراتور' }}</span></span>
                <svg aria-hidden="true" class="size-4 text-slate-300 transition group-open:rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m6 9 6 6 6-6"/></svg>
            </summary>
            <div class="absolute bottom-full right-0 z-30 mb-3 w-full overflow-hidden rounded-2xl border border-slate-200 bg-white p-2 text-slate-800 shadow-[0_16px_40px_rgba(15,23,42,0.22)]">
                <div class="border-b border-slate-100 px-3 py-3"><p class="truncate text-sm font-bold">{{ $panelUser->name }}</p><p class="mt-1 truncate text-xs text-slate-500">{{ $panelUser->mobile ?: $panelUser->email }}</p></div>
                <form method="POST" action="{{ route('logout') }}">@csrf<button class="mt-2 flex w-full items-center gap-2 rounded-xl px-3 py-2.5 text-right text-sm font-bold text-red-600 transition hover:bg-red-50"><svg aria-hidden="true" class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M10 17l5-5-5-5m5 5H3m9-9h6a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-6"/></svg>خروج از حساب</button></form>
            </div>
        </details>
    </aside>
    <div class="min-w-0 flex-1">
        <header class="flex min-h-[72px] items-center justify-between gap-3 border-b border-slate-200 bg-white px-5 py-3 sm:px-8">
            <nav class="flex min-w-0 items-center gap-2 overflow-x-auto py-1" aria-label="مسیر فعلی">
                @foreach ($breadcrumbs as $index => $crumb)
                    @if ($index > 0)<svg aria-hidden="true" class="size-3.5 shrink-0 text-slate-400" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2.2"><path stroke-linecap="round" stroke-linejoin="round" d="m7 4 6 6-6 6"/></svg>@endif
                    @if ($crumb['route'] && $index < count($breadcrumbs) - 1)
                        <a href="{{ route($crumb['route']) }}" class="shrink-0 rounded-full bg-slate-100 px-3 py-1.5 text-xs font-bold text-slate-500 hover:bg-blue-50 hover:text-blue-700">{{ $crumb['label'] }}</a>
                    @else
                        <span aria-current="page" class="shrink-0 rounded-full bg-blue-600 px-3 py-1.5 text-xs font-bold text-white">{{ $crumb['label'] }}</span>
                    @endif
                @endforeach
            </nav>
            <div class="flex shrink-0 items-center gap-3">
                @if ($quickCreate)
                    <a href="{{ route($quickCreate['route']) }}" class="inline-flex h-10 items-center gap-2 rounded-xl bg-[#10233d] px-3 text-xs font-bold text-white shadow-sm transition hover:bg-[#1d3b62] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600 sm:px-4"><svg aria-hidden="true" class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path stroke-linecap="round" d="M12 5v14M5 12h14"/></svg><span class="hidden sm:inline">{{ $quickCreate['label'] }}</span><span class="sm:hidden">ایجاد</span></a>
                @endif
                <details class="group relative">
                    <summary aria-label="اعلان‌ها، {{ $unreadNotificationCount }} خوانده‌نشده" class="menu-summary relative grid size-10 cursor-pointer place-items-center rounded-xl border border-slate-200 bg-white text-slate-600 transition hover:border-blue-200 hover:bg-blue-50 hover:text-blue-700 group-open:border-blue-200 group-open:bg-blue-50 group-open:text-blue-700">
                        <svg aria-hidden="true" class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M18 9a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/></svg>
                        @if ($unreadNotificationCount)<span class="absolute -left-1.5 -top-1.5 grid min-w-5 place-items-center rounded-full border-2 border-white bg-red-500 px-1 text-[10px] font-black leading-4 text-white">{{ $unreadNotificationCount > 9 ? '9+' : $unreadNotificationCount }}</span>@endif
                    </summary>
                    <div class="absolute left-0 z-40 mt-3 w-[min(23rem,calc(100vw-2rem))] overflow-hidden rounded-2xl border border-slate-200 bg-white text-right shadow-[0_18px_48px_rgba(15,23,42,0.18)]">
                        <div class="flex items-center justify-between gap-4 border-b border-slate-100 px-5 py-4"><div><h2 class="text-sm font-black text-slate-800">اعلان‌ها</h2><p class="mt-1 text-[11px] text-slate-500">{{ $unreadNotificationCount ? $unreadNotificationCount.' اعلان خوانده‌نشده' : 'همه اعلان‌ها خوانده شده‌اند' }}</p></div>@if ($unreadNotificationCount)<form method="POST" action="{{ route('notifications.read-all') }}">@csrf<button class="shrink-0 rounded-lg px-2 py-1.5 text-[11px] font-bold text-blue-600 hover:bg-blue-50">خواندن همه</button></form>@endif</div>
                        <div class="max-h-80 overflow-y-auto">
                            @forelse ($recentNotifications as $notification)
                                @php($notificationData = $notification->data)
                                <div class="border-b border-slate-100 px-5 py-4 last:border-b-0 {{ $notification->read_at ? 'bg-white' : 'bg-blue-50/50' }}">
                                    <div class="flex items-start gap-3"><span class="mt-1.5 size-2 shrink-0 rounded-full {{ $notification->read_at ? 'bg-slate-200' : 'bg-blue-500' }}"></span><div class="min-w-0 flex-1">@if (!empty($notificationData['url']))<a href="{{ $notificationData['url'] }}" class="block text-sm font-bold leading-6 text-slate-800 hover:text-blue-700">{{ $notificationData['title'] ?? 'اعلان جدید' }}</a>@else<p class="text-sm font-bold leading-6 text-slate-800">{{ $notificationData['title'] ?? 'اعلان جدید' }}</p>@endif
                                    @if (!empty($notificationData['message']))<p class="mt-1 text-xs leading-5 text-slate-500">{{ $notificationData['message'] }}</p>@endif
                                    <div class="mt-3 flex items-center justify-between gap-3"><time class="text-[10px] text-slate-400">{{ $notification->created_at?->diffForHumans() }}</time>@unless($notification->read_at)<form method="POST" action="{{ route('notifications.read', $notification->id) }}">@csrf<button class="rounded-lg px-2 py-1 text-[10px] font-bold text-blue-600 hover:bg-blue-100">خواندم</button></form>@endunless</div></div></div>
                                </div>
                            @empty
                                <div class="px-6 py-10 text-center"><span class="mx-auto grid size-11 place-items-center rounded-full bg-slate-100 text-slate-400"><svg aria-hidden="true" class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M18 9a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/></svg></span><p class="mt-3 text-sm font-bold text-slate-700">اعلانی ندارید</p><p class="mt-1 text-xs text-slate-500">اعلان‌های جدید اینجا نمایش داده می‌شوند.</p></div>
                            @endforelse
                        </div>
                    </div>
                </details>
            </div>
        </header>
        <details class="border-b border-slate-200 bg-white px-5 py-3 lg:hidden"><summary class="cursor-pointer text-sm font-bold text-[#10233d]">☰ فهرست</summary><nav class="mt-3 grid grid-cols-2 gap-2" aria-label="فهرست موبایل">
            @foreach ($navigation as $item)
                @if ($panelUser->hasPermission($item['permission']))<a href="{{ route($item['route']) }}" class="rounded-xl bg-slate-50 px-3 py-3 text-sm font-semibold">{{ $item['label'] }}</a>@endif
            @endforeach
            @if ($panelUser->isAdmin())@foreach ($adminNavigation as $item)<a href="{{ route($item['route']) }}" class="rounded-xl bg-slate-50 px-3 py-3 text-sm font-semibold">{{ $item['label'] }}</a>@endforeach @endif
        </nav>
        <div class="mt-4 flex items-center justify-between border-t border-slate-200 pt-3">
            <div class="min-w-0"><p class="truncate text-xs font-bold text-slate-800">{{ $panelUser->name }}</p><p class="mt-1 text-[10px] text-slate-500">{{ $panelUser->isAdmin() ? 'مدیر' : 'اپراتور' }}</p></div>
            <form method="POST" action="{{ route('logout') }}">@csrf<button class="rounded-lg border border-slate-200 px-3 py-2 text-xs font-bold text-slate-600 hover:bg-slate-100">خروج</button></form>
        </div>
        </details>
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
