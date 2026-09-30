@extends('layouts.portal')
@section('title', 'ویرایش منوی تماس')
@section('content')
@php
    $draft = $menu->draft_config ?? ['greeting' => null, 'choices' => [], 'fallback' => ''];
    $destinations = [];
    foreach ($extensions as $extension) $destinations['extension:'.$extension->id] = ($extension->display_name ?: 'تلفن '.$extension->extension).' · '.$extension->extension;
    foreach ($queues as $queue) $destinations['queue:'.$queue->id] = 'تیم '.$queue->name;
@endphp
<div class="mx-auto max-w-5xl space-y-6">
    @if ($setupNumber)<a href="{{ route('admin.sip-numbers.setup', $setupNumber) }}" class="inline-flex rounded-xl border border-blue-200 bg-blue-50 px-4 py-2.5 text-xs font-bold text-blue-700">← بازگشت به راه‌اندازی {{ $setupNumber->normalized_number }}</a>@endif
    @if (request()->boolean('wizard') && ! auth()->user()->isAdmin())<a href="{{ route('customer.setup.wizard') }}" class="inline-flex rounded-xl border border-blue-200 bg-blue-50 px-4 py-2.5 text-xs font-bold text-blue-700">بازگشت به راه‌اندازی کامل خط</a>@endif
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div><a href="{{ route('ivr-menus.index', $setupNumber ? ['number_id' => $setupNumber->id] : []) }}" class="text-xs font-bold text-blue-700">← بازگشت به منوها</a><h1 class="mt-2 text-2xl font-black text-[#071a3b]">{{ $menu->name }}</h1><p class="mt-2 text-sm text-slate-600">تماس‌گیرنده پیام را می‌شنود، یک کلید مقصد را انتخاب می‌کند و به شخص یا تیم وصل می‌شود.</p></div>
        <span class="rounded-full px-3 py-1.5 text-xs font-bold {{ $menu->isPublished() ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700' }}">{{ $menu->isPublished() ? 'منتشرشده · نسخه '.$menu->version : 'فقط پیش‌نویس' }}</span>
    </div>

    @if ($menu->isPublished())
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900">تغییرات این صفحه تا وقتی «انتشار» را نزنید روی تماس‌های زنده اثر نمی‌گذارند. <a href="{{ auth()->user()->isAdmin() ? route('admin.sip-numbers.index') : route('customer.setup.lines') }}" class="font-bold underline">شماره‌ها</a></div>
    @endif

    <form method="POST" action="{{ route('ivr-menus.update', $menu) }}" enctype="multipart/form-data" class="space-y-6">
        @csrf @method('PUT')
        <section class="panel p-5 sm:p-6">
            <h2 class="text-lg font-bold">۱. پیام خوش‌آمدگویی</h2>
            <p class="mt-2 text-xs leading-6 text-slate-500">مثلاً «برای فروش کلید ۱، برای پشتیبانی کلید ۲ و برای اپراتور کلید ۰ را فشار دهید.» فایل صوتی WAV، MP3 یا M4A تا ۱۰ مگابایت بارگذاری کنید؛ حداکثر ۶۰ ثانیه پخش می‌شود.</p>
            <label class="mt-5 block text-sm font-semibold">نام منو<input name="name" value="{{ old('name', $menu->name) }}" required maxlength="100" class="mt-2 w-full rounded-xl border border-slate-200 px-4 py-3 font-normal"></label>
            <label class="mt-5 block text-sm font-semibold">فایل پیام<input type="file" name="greeting" accept="audio/*" class="mt-2 block w-full rounded-xl border border-slate-200 bg-white p-3 text-sm font-normal"></label>
            @if (!empty($draft['greeting']))
                <div class="mt-4 rounded-xl bg-slate-50 p-4"><p class="mb-2 text-xs font-bold text-slate-600">شنیدن پیام پیش‌نویس</p><audio controls preload="none" src="{{ route('ivr-menus.audio', [$menu, 'draft']) }}" class="w-full"></audio></div>
            @endif
        </section>

        <section class="panel p-5 sm:p-6">
            <h2 class="text-lg font-bold">۲. هر کلید به کجا وصل شود؟</h2>
            <p class="mt-2 text-xs text-slate-500">فقط کلیدهایی را پر کنید که در پیام خوانده‌اید. حداقل یک کلید لازم است.</p>
            <div class="mt-5 grid gap-3 md:grid-cols-2">
                @foreach (range(0, 9) as $digit)
                    @php($choice = old('choices.'.$digit, $draft['choices'][$digit] ?? []))
                    <div class="grid grid-cols-[2.5rem_1fr] gap-3 rounded-xl border border-slate-200 p-3">
                        <span class="grid size-10 place-items-center rounded-lg bg-blue-50 font-black text-blue-700">{{ $digit }}</span>
                        <div class="space-y-2">
                            <input name="choices[{{ $digit }}][label]" value="{{ $choice['label'] ?? '' }}" maxlength="60" placeholder="عنوان، مثلاً فروش" aria-label="عنوان کلید {{ $digit }}" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm">
                            <select name="choices[{{ $digit }}][destination]" aria-label="مقصد کلید {{ $digit }}" class="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm">
                                <option value="">این کلید استفاده نمی‌شود</option>
                                @foreach ($destinations as $value => $label)<option value="{{ $value }}" @selected(($choice['destination'] ?? '') === $value)>{{ $label }}</option>@endforeach
                            </select>
                        </div>
                    </div>
                @endforeach
            </div>
        </section>

        <section class="panel p-5 sm:p-6">
            <h2 class="text-lg font-bold">۳. اگر تماس‌گیرنده کلیدی نزد</h2>
            <p class="mt-2 text-xs text-slate-500">پیام یک بار دیگر پخش می‌شود. اگر باز هم انتخابی نشد یا کلید اشتباه بود، تماس به این پاسخ‌گو می‌رود.</p>
            <select name="fallback" class="mt-4 w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm">
                <option value="">انتخاب پاسخ‌گوی جایگزین</option>
                @foreach ($destinations as $value => $label)<option value="{{ $value }}" @selected(old('fallback', $draft['fallback'] ?? '') === $value)>{{ $label }}</option>@endforeach
            </select>
        </section>
        <div class="flex justify-end"><button class="rounded-xl bg-slate-900 px-6 py-3 text-sm font-bold text-white">ذخیره پیش‌نویس</button></div>
    </form>

    <section class="panel flex flex-wrap items-center justify-between gap-4 p-5 sm:p-6">
        <div><h2 class="font-bold">انتشار</h2><p class="mt-1 text-xs text-slate-500">بعد از ذخیره پیش‌نویس، پیام را گوش کنید و سپس آن را برای تماس‌های جدید منتشر کنید.</p></div>
        <form method="POST" action="{{ route('ivr-menus.publish', $menu) }}">@csrf<button class="rounded-xl bg-blue-600 px-6 py-3 text-sm font-bold text-white hover:bg-blue-700">انتشار منو</button></form>
    </section>
    @if ($menu->previous_config)
        <form method="POST" action="{{ route('ivr-menus.restore', $menu) }}" onsubmit="return confirm('نسخه قبلی دوباره فعال شود؟')">@csrf<button class="text-sm font-bold text-amber-700">بازگرداندن نسخه قبلی</button></form>
    @endif
    @unless ($menu->inboundRoutes()->exists())
        <form method="POST" action="{{ route('ivr-menus.destroy', $menu) }}" onsubmit="return confirm('این منو حذف شود؟')">@csrf @method('DELETE')<button class="text-xs font-bold text-red-600">حذف منوی بدون شماره</button></form>
    @endunless
</div>
@endsection
