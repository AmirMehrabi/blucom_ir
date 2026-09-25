<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'راه‌اندازی خط') · بلوکام</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-[#f7f8fb] text-slate-900">
<header class="border-b border-slate-200 bg-white">
    <div class="mx-auto flex max-w-6xl items-center justify-between gap-4 px-5 py-4">
        <div class="flex items-center gap-3">
            <a href="{{ route('customer.setup.lines') }}" class="text-xl font-extrabold text-[#071a3b]">بلوکام</a>
            <span class="rounded bg-slate-100 px-2 py-1 text-[10px] font-bold text-slate-500">فضای شما</span>
        </div>
        <div class="flex items-center gap-3 text-sm">
            <a href="{{ route('customer.setup.lines') }}" class="font-semibold text-blue-700">خط‌های من</a>
            <span class="hidden text-slate-500 sm:inline">{{ $tenant->name }}</span>
            <form method="POST" action="{{ route('logout') }}">@csrf<button class="rounded-lg border border-slate-200 px-3 py-2 text-xs font-semibold">خروج</button></form>
        </div>
    </div>
</header>

@isset($step)
<nav class="border-b border-slate-200 bg-white" aria-label="مراحل راه‌اندازی">
    <ol class="mx-auto grid max-w-4xl grid-cols-4 gap-2 px-4 py-5 text-center sm:gap-5">
        @foreach ([1 => ['اتصال ارائه‌دهنده', 'customer.setup.provider'], 2 => ['افزودن شماره', 'customer.setup.number'], 3 => ['تعیین پاسخ‌گو', null], 4 => ['اتصال تلفن', null]] as $index => [$label, $route])
            <li class="min-w-0">
                @if ($route)
                    <a href="{{ route($route) }}" class="mx-auto grid size-8 place-items-center rounded-full text-sm font-bold {{ $step === $index ? 'bg-blue-600 text-white' : ($step > $index ? 'bg-emerald-100 text-emerald-700' : 'border-2 border-slate-300 text-slate-500') }}">{{ $index }}</a>
                @else
                    <span class="mx-auto grid size-8 place-items-center rounded-full text-sm font-bold {{ $step === $index ? 'bg-blue-600 text-white' : ($step > $index ? 'bg-emerald-100 text-emerald-700' : 'border-2 border-slate-300 text-slate-500') }}">{{ $index }}</span>
                @endif
                <span class="mt-2 block text-[10px] font-bold leading-4 sm:text-xs {{ $step === $index ? 'text-blue-700' : 'text-slate-500' }}">{{ $label }}</span>
            </li>
        @endforeach
    </ol>
</nav>
@endisset

<main class="mx-auto max-w-6xl px-5 py-8 sm:py-10">
    @if (session('status'))
        <div class="mb-5 rounded-xl border border-emerald-200 bg-emerald-50 px-5 py-3 text-sm font-semibold text-emerald-800" role="status">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="mb-5 rounded-xl border border-red-200 bg-red-50 px-5 py-3 text-sm text-red-800" role="alert">
            <p class="font-bold">لطفاً موارد زیر را بررسی کنید:</p>
            <ul class="mt-2 list-disc space-y-1 pr-5">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif
    @yield('content')
</main>
</body>
</html>
