@extends('layouts.portal')
@section('title', 'راه‌اندازی خط')
@section('before-content')
@isset($step)
<nav class="panel mb-7 p-4 sm:p-5" aria-label="مراحل راه‌اندازی">
    <ol class="grid grid-cols-4 gap-2 text-center sm:gap-5">
        @foreach ([1 => ['اتصال ارائه‌دهنده', 'customer.setup.provider', 'providers.manage'], 2 => ['افزودن شماره', 'customer.setup.number', 'numbers.manage'], 3 => ['تعیین پاسخ‌گو', null, 'phones.manage'], 4 => ['اتصال تلفن', null, 'phones.manage']] as $index => [$label, $route, $permission])
            <li class="min-w-0">
                @if ($route && auth()->user()->hasPermission($permission))
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
@endsection
