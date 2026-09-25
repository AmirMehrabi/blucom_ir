<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'پنل بلوکام') · بلوکام</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-[#f5f8fd] text-[#0f172a]">
@php
    $panelUser = auth()->user();
    $navigation = [
        ['label' => 'داشبورد', 'route' => 'dashboard', 'permission' => 'dashboard.view', 'icon' => 'M3 3h7v7H3z M14 3h7v7h-7z M3 14h7v7H3z M14 14h7v7h-7z'],
        ['label' => 'خط‌ها', 'route' => 'customer.setup.lines', 'permission' => 'lines.view', 'icon' => 'M4 6h16 M4 12h16 M4 18h16 M7 6v12'],
        ['label' => 'راه‌اندازی خط', 'route' => 'customer.setup.provider', 'permission' => 'providers.manage', 'icon' => 'M12 3v12 M7 10l5 5 5-5 M4 19h16'],
        ['label' => 'افزودن شماره', 'route' => 'customer.setup.number', 'permission' => 'numbers.manage', 'icon' => 'M4 9h16 M4 15h16 M9 4 7 20 M17 4l-2 16'],
    ];
    $adminNavigation = [
        ['label' => 'بررسی اتصال‌ها', 'route' => 'admin.customer-connections.index', 'icon' => 'M4 12l5 5L20 6'],
        ['label' => 'دروازه‌های SIP', 'route' => 'sip-gateways.index', 'icon' => 'M4 7h16v10H4z M8 10h8 M8 14h4'],
        ['label' => 'شماره‌های DID', 'route' => 'admin.sip-numbers.index', 'icon' => 'M4 9h16 M4 15h16 M9 4 7 20 M17 4l-2 16'],
        ['label' => 'داخلی‌ها', 'route' => 'sip-extensions.index', 'icon' => 'M7 3h10v18H7z M10 6h4 M10 17h4'],
        ['label' => 'مسیرهای ورودی', 'route' => 'inbound-routes.index', 'icon' => 'M4 4v6h6 M4 10c3-4 7-5 11-2 M12 17h8 M17 14l3 3-3 3'],
        ['label' => 'مسیرهای خروجی', 'route' => 'outbound-routes.index', 'icon' => 'M20 20v-6h-6 M20 14c-3 4-7 5-11 2 M12 7H4 M7 4 4 7l3 3'],
        ['label' => 'کاربران', 'route' => 'users.index', 'icon' => 'M16 20H4v-2a6 6 0 0 1 12 0z M10 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8 M18 8a3 3 0 0 1 0 6 M19 20h2v-2a5 5 0 0 0-3-4.6'],
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
    <aside class="sticky top-0 hidden h-screen w-[256px] shrink-0 flex-col bg-[#031B4E] text-white lg:flex">
        <a href="{{ $panelUser->homePath() }}" class="flex h-[84px] items-center gap-3 border-b border-white/10 px-6 text-xl font-black">
            <span class="grid size-10 place-items-center rounded-xl bg-[#0069ff] text-lg shadow-[0_6px_18px_rgba(0,105,255,0.24)]">ب</span>
            <span>بلوکام<small class="mt-0.5 block text-[10px] font-medium tracking-wide text-blue-100/60">پنل مدیریت تماس</small></span>
        </a>
        <div class="min-h-0 flex-1 overflow-y-auto px-4 py-7">
        <div class="px-3 text-[10px] font-bold tracking-wide text-blue-100/55">فضای کاری</div>
        <nav class="mt-3 space-y-1" aria-label="فهرست اصلی">
            @foreach ($navigation as $item)
                @if ($panelUser->hasPermission($item['permission']))
                    <a href="{{ route($item['route']) }}" @class(['flex h-11 items-center gap-3 rounded-xl px-3 text-[13px] font-semibold transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white', 'bg-white/12 text-white' => request()->routeIs($item['route']), 'text-blue-100/75 hover:bg-white/8 hover:text-white' => !request()->routeIs($item['route'])])><svg aria-hidden="true" class="size-[18px] shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="{{ $item['icon'] }}"/></svg><span class="flex-1">{{ $item['label'] }}</span>@if (request()->routeIs($item['route']))<span aria-hidden="true" class="size-1.5 rounded-full bg-[#62a4ff]"></span>@endif</a>
                @endif
            @endforeach
        </nav>
        @if ($panelUser->isAdmin())
            <div class="mt-8 px-3 text-[10px] font-bold tracking-wide text-blue-100/55">مدیریت</div>
            <nav class="mt-3 space-y-1" aria-label="مدیریت سیستم">
                @foreach ($adminNavigation as $item)
                    <a href="{{ route($item['route']) }}" @class(['flex h-11 items-center gap-3 rounded-xl px-3 text-[13px] font-semibold transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white', 'bg-white/12 text-white' => request()->routeIs($item['route']), 'text-blue-100/75 hover:bg-white/8 hover:text-white' => !request()->routeIs($item['route'])])><svg aria-hidden="true" class="size-[18px] shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="{{ $item['icon'] }}"/></svg><span class="flex-1">{{ $item['label'] }}</span>@if (request()->routeIs($item['route']))<span aria-hidden="true" class="size-1.5 rounded-full bg-[#62a4ff]"></span>@endif</a>
                @endforeach
            </nav>
        @endif
        </div>
        <details class="group relative border-t border-white/10 px-4 py-4">
            <summary class="menu-summary flex cursor-pointer items-center gap-3 rounded-xl px-2 py-2 transition hover:bg-white/10">
                <span class="grid size-9 shrink-0 place-items-center rounded-full bg-[#0069ff] text-sm font-black text-white">{{ mb_substr($panelUser->name, 0, 1) }}</span>
                <span class="min-w-0 flex-1"><span class="block truncate text-xs font-bold">{{ $panelUser->name }}</span><span class="mt-1 block truncate text-[10px] text-blue-100/55">{{ $panelUser->mobile ?: $panelUser->email ?: ($panelUser->isAdmin() ? 'مدیر' : 'اپراتور') }}</span></span>
                <svg aria-hidden="true" class="size-4 text-blue-100/60 transition group-open:rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m6 9 6 6 6-6"/></svg>
            </summary>
            <div class="absolute bottom-full right-4 z-30 mb-2 w-[calc(100%-2rem)] overflow-hidden rounded-xl border border-slate-200 bg-white p-2 text-slate-800 shadow-[0_16px_40px_rgba(15,23,42,0.22)]">
                <div class="border-b border-slate-100 px-3 py-3"><p class="truncate text-sm font-bold">{{ $panelUser->name }}</p><p class="mt-1 truncate text-xs text-slate-500">{{ $panelUser->mobile ?: $panelUser->email }}</p></div>
                <form method="POST" action="{{ route('logout') }}">@csrf<button class="mt-2 flex w-full items-center gap-2 rounded-xl px-3 py-2.5 text-right text-sm font-bold text-red-600 transition hover:bg-red-50"><svg aria-hidden="true" class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M10 17l5-5-5-5m5 5H3m9-9h6a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-6"/></svg>خروج از حساب</button></form>
            </div>
        </details>
    </aside>
    <div class="min-w-0 flex-1">
        <header class="sticky top-0 z-20 flex min-h-[68px] items-center justify-between gap-3 border-b border-[#e2e8f0] bg-white px-4 sm:px-6 xl:px-10">
            <details class="group shrink-0 lg:hidden">
                <summary aria-label="باز کردن فهرست" class="menu-summary grid size-9 cursor-pointer place-items-center rounded-xl border border-[#e2e8f0] text-[#475569] hover:bg-[#eef5ff]"><svg aria-hidden="true" class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M4 7h16M4 12h16M4 17h16"/></svg></summary>
                <div class="fixed inset-x-0 top-[68px] z-50 max-h-[calc(100vh-68px)] overflow-y-auto bg-[#031B4E] px-5 py-6 text-white shadow-xl">
                    <div class="mb-6 flex items-center gap-3"><span class="grid size-9 place-items-center rounded-xl bg-[#0069ff] font-black">ب</span><span class="text-lg font-black">بلوکام</span></div>
                    <p class="mb-2 px-2 text-[10px] font-bold text-blue-100/55">فضای کاری</p>
                    <nav class="space-y-1" aria-label="فهرست موبایل">
                        @foreach ($navigation as $item)
                            @if ($panelUser->hasPermission($item['permission']))
                                <a href="{{ route($item['route']) }}" @class(['flex items-center gap-3 rounded-xl px-3 py-3 text-sm font-semibold', 'bg-white/12 text-white' => request()->routeIs($item['route']), 'text-blue-100/75 hover:bg-white/8 hover:text-white' => !request()->routeIs($item['route'])])><svg aria-hidden="true" class="size-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="{{ $item['icon'] }}"/></svg>{{ $item['label'] }}</a>
                            @endif
                        @endforeach
                    </nav>
                    @if ($panelUser->isAdmin())
                        <p class="mb-2 mt-7 px-2 text-[10px] font-bold text-blue-100/55">مدیریت</p>
                        <nav class="space-y-1" aria-label="مدیریت سیستم در موبایل">
                            @foreach ($adminNavigation as $item)
                                <a href="{{ route($item['route']) }}" @class(['flex items-center gap-3 rounded-xl px-3 py-3 text-sm font-semibold', 'bg-white/12 text-white' => request()->routeIs($item['route']), 'text-blue-100/75 hover:bg-white/8 hover:text-white' => !request()->routeIs($item['route'])])><svg aria-hidden="true" class="size-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="{{ $item['icon'] }}"/></svg>{{ $item['label'] }}</a>
                            @endforeach
                        </nav>
                    @endif
                    <div class="mt-7 flex items-center justify-between gap-3 border-t border-white/10 pt-5"><div class="min-w-0"><p class="truncate text-sm font-bold">{{ $panelUser->name }}</p><p class="mt-1 truncate text-xs text-blue-100/55">{{ $panelUser->mobile ?: $panelUser->email }}</p></div><form method="POST" action="{{ route('logout') }}">@csrf<button class="rounded-xl border border-white/15 px-3 py-2 text-xs font-bold text-white hover:bg-white/10">خروج</button></form></div>
                </div>
            </details>
            <nav class="flex min-w-0 items-center gap-2 overflow-x-auto py-2" aria-label="مسیر فعلی">
                @foreach ($breadcrumbs as $index => $crumb)
                    @if ($index > 0)<svg aria-hidden="true" class="size-3.5 shrink-0 text-slate-300" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m7 4 6 6-6 6"/></svg>@endif
                    @if ($crumb['route'] && $index < count($breadcrumbs) - 1)
                        <a href="{{ route($crumb['route']) }}" class="shrink-0 rounded-full bg-[#f1f5f9] px-3 py-1.5 text-[11px] font-semibold text-[#475569] transition hover:bg-[#eef5ff] hover:text-[#0069ff]">{{ $crumb['label'] }}</a>
                    @else
                        <span aria-current="page" class="shrink-0 rounded-full bg-[#eef5ff] px-3 py-1.5 text-[11px] font-bold text-[#0050d0]">{{ $crumb['label'] }}</span>
                    @endif
                @endforeach
            </nav>
            <div class="flex shrink-0 items-center gap-3">
                @if ($quickCreate)
                    <a href="{{ route($quickCreate['route']) }}" class="inline-flex h-9 items-center gap-2 rounded-xl bg-[#0069ff] px-3 text-[11px] font-bold text-white shadow-[0_4px_12px_rgba(0,105,255,0.16)] transition hover:bg-[#0050d0] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#0069ff] sm:px-4"><svg aria-hidden="true" class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path stroke-linecap="round" d="M12 5v14M5 12h14"/></svg><span class="hidden sm:inline">{{ $quickCreate['label'] }}</span><span class="sm:hidden">ایجاد</span></a>
                @endif
                <details class="group relative">
                    <summary aria-label="اعلان‌ها، {{ $unreadNotificationCount }} خوانده‌نشده" class="menu-summary relative grid size-9 cursor-pointer place-items-center rounded-xl border border-[#e2e8f0] bg-white text-[#475569] transition hover:border-[#bfd9ff] hover:bg-[#eef5ff] hover:text-[#0069ff] group-open:border-[#bfd9ff] group-open:bg-[#eef5ff] group-open:text-[#0069ff]">
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
        <main class="mx-auto max-w-[1280px] p-4 sm:p-6 xl:p-10">
            @if (session('status'))<div class="mb-5 rounded-xl border border-emerald-200 bg-emerald-50 px-5 py-3 text-sm font-semibold text-emerald-800" role="status">{{ session('status') }}</div>@endif
            @if ($errors->any())<div class="mb-5 rounded-xl border border-red-200 bg-red-50 px-5 py-3 text-sm text-red-800" role="alert"><p class="font-bold">لطفاً موارد زیر را بررسی کنید:</p><ul class="mt-2 list-disc space-y-1 pr-5">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
            @yield('before-content')
            @yield('content')
        </main>
    </div>
</div>
</body>
</html>
