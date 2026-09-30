@extends('layouts.portal')
@section('title', 'منوهای تماس')
@section('content')
<div class="mx-auto max-w-5xl space-y-6">
    @if ($setupNumber)<a href="{{ route('admin.sip-numbers.setup', $setupNumber) }}" class="inline-flex rounded-xl border border-blue-200 bg-blue-50 px-4 py-2.5 text-xs font-bold text-blue-700">← بازگشت به راه‌اندازی {{ $setupNumber->normalized_number }}</a>@endif
    @if (request()->boolean('wizard') && ! auth()->user()->isAdmin())<a href="{{ route('customer.setup.wizard') }}" class="inline-flex rounded-xl border border-blue-200 bg-blue-50 px-4 py-2.5 text-xs font-bold text-blue-700">بازگشت به راه‌اندازی کامل خط</a>@endif
    <div>
        <h1 class="text-2xl font-black text-[#071a3b]">منوهای تماس</h1>
        <p class="mt-2 text-sm text-slate-600">پیش از وصل کردن شماره، یک پیام خوش‌آمدگویی و مسیر هر کلید را آماده و منتشر کنید.</p>
    </div>
    <section id="new-menu" class="panel scroll-mt-24 p-5 sm:p-6">
        <h2 class="font-bold">ساخت منوی جدید</h2>
        <form method="POST" action="{{ route('ivr-menus.store') }}" class="mt-4 flex flex-col gap-3 sm:flex-row">
            @csrf
            @if ($setupNumber)<input type="hidden" name="number_id" value="{{ $setupNumber->id }}">@endif
            @if (request()->boolean('wizard') && ! auth()->user()->isAdmin())<input type="hidden" name="wizard" value="1">@endif
            <label class="min-w-0 flex-1 text-sm font-semibold">نام منو
                <input name="name" value="{{ old('name') }}" required maxlength="100" placeholder="مثلاً منوی اصلی" class="mt-2 w-full rounded-xl border border-slate-200 px-4 py-3 font-normal focus:border-blue-500 focus:outline-none">
            </label>
            <button class="self-end rounded-xl bg-blue-600 px-6 py-3 text-sm font-bold text-white hover:bg-blue-700">ساخت منو</button>
        </form>
    </section>
    <section class="panel overflow-hidden">
        <div class="border-b border-slate-100 p-5"><h2 class="font-bold">منوهای آماده</h2></div>
        <div class="divide-y divide-slate-100">
            @forelse ($menus as $menu)
                <div class="flex flex-wrap items-center justify-between gap-3 px-5 py-4">
                    <div>
                        <p class="font-bold">{{ $menu->name }}</p>
                        <p class="mt-1 text-xs text-slate-500">{{ $menu->isPublished() ? 'منتشرشده · نسخه '.$menu->version : 'پیش‌نویس' }} · {{ $menu->inbound_routes_count }} شماره متصل</p>
                    </div>
                    <a href="{{ route('ivr-menus.edit', ['menu' => $menu->id] + ($setupNumber ? ['number_id' => $setupNumber->id] : []) + (request()->boolean('wizard') && ! auth()->user()->isAdmin() ? ['wizard' => 1] : [])) }}" class="rounded-xl border border-blue-200 px-4 py-2 text-xs font-bold text-blue-700 hover:bg-blue-50">ویرایش منو</a>
                </div>
            @empty
                <p class="px-5 py-8 text-sm text-slate-500">هنوز منویی ساخته نشده است.</p>
            @endforelse
        </div>
    </section>
</div>
@endsection
