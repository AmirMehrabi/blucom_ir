@extends('layouts.portal')
@section('title', 'داشبورد')
@section('content')
<div class="mb-8 flex flex-wrap items-center justify-between gap-4 border-b border-slate-200 pb-6">
    <div><p class="mb-2 text-xs font-bold text-[#0069ff]">نمای کلی</p><h1 class="text-2xl font-black tracking-tight text-[#0f172a] sm:text-3xl">داشبورد</h1><p class="mt-2 text-sm text-[#475569]">وضعیت پیکربندی تلفن بلوکام</p></div>
    @if (auth()->user()->isAdmin())<a href="{{ route('admin.sip-numbers.index') }}" class="rounded-xl border border-[#e2e8f0] bg-white px-4 py-2.5 text-xs font-bold text-[#0f172a] shadow-sm transition hover:border-[#0069ff] hover:text-[#0069ff]">افزودن شماره</a>@elseif (auth()->user()->hasPermission('numbers.manage'))<a href="{{ route('customer.setup.number') }}" class="rounded-xl border border-[#e2e8f0] bg-white px-4 py-2.5 text-xs font-bold text-[#0f172a] shadow-sm transition hover:border-[#0069ff] hover:text-[#0069ff]">افزودن شماره</a>@endif
</div>
<div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
    @foreach ($stats as $stat)
        <div class="panel p-6"><span class="text-xs font-semibold text-[#64748b]">{{ $stat['label'] }}</span><div class="mt-5 text-3xl font-black tracking-tight text-[#0f172a]">{{ $stat['value'] }}</div><div class="mt-5 h-1 w-8 rounded-full bg-[#0069ff]"></div></div>
    @endforeach
</div>
@if (auth()->user()->hasPermission('lines.view'))<section class="panel mt-6 p-6">
    <h2 class="mb-4 text-base font-black text-[#0f172a]">آخرین شماره‌ها</h2>
    @forelse ($numbers as $number)
        <div class="flex justify-between border-t border-slate-100 py-3 text-sm"><span dir="ltr">{{ $number->normalized_number }}</span><span>{{ $number->statusLabel() }}</span></div>
    @empty
        <p class="text-sm text-slate-500">هنوز شماره‌ای ثبت نشده است.</p>
    @endforelse
</section>@endif
@endsection
