# Shared layouts

`portal.blade.php` is the admin shell: right-to-left sidebar, header, and content slot. It is not a customer-facing layout.

### `resources/views/layouts/portal.blade.php`
```blade
<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'بلوکام' }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-[#f7f8fb] text-slate-900">
@php
    $navigation = [
        ['label' => 'داشبورد', 'route' => 'admin'],
        ['label' => 'داخلی‌های SIP', 'route' => 'sip-extensions.index'],
        ['label' => 'دروازه‌های SIP', 'route' => 'sip-gateways.index'],
        ['label' => 'شماره‌های DID', 'route' => 'admin.sip-numbers.index'],
        ['label' => 'مسیرهای ورودی', 'route' => 'inbound-routes.index'],
        ['label' => 'مسیرهای خروجی', 'route' => 'outbound-routes.index'],
    ];
@endphp
<div class="min-h-screen lg:flex">
    <aside class="bg-[#071a3b] p-5 text-white lg:w-[250px] lg:shrink-0">
        <a href="{{ route('admin') }}" class="mb-6 block text-xl font-extrabold">بلوکام <span class="text-xs font-medium text-blue-200">مدیریت</span></a>
        <nav class="flex gap-2 overflow-x-auto lg:flex-col">
            @foreach ($navigation as $item)
                <a href="{{ route($item['route']) }}" class="whitespace-nowrap rounded-xl px-3 py-2 text-sm font-semibold {{ request()->routeIs($item['route']) ? 'bg-blue-600 text-white' : 'text-slate-200 hover:bg-white/10' }}">{{ $item['label'] }}</a>
            @endforeach
        </nav>
    </aside>
    <main class="min-w-0 flex-1">
        <header class="flex items-center justify-between border-b border-slate-200 bg-white px-5 py-4 sm:px-8">
            <span class="text-sm font-bold">پنل مدیریت بلوکام</span>
            <form method="POST" action="{{ route('logout') }}">@csrf<button class="rounded-lg border border-slate-200 px-3 py-2 text-xs font-semibold">خروج</button></form>
        </header>
        <div class="mx-auto max-w-[1440px] p-5 sm:p-8">@yield('content')</div>
    </main>
</div>
</body>
</html>

```
