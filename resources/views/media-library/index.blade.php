@extends('layouts.portal')
@section('title', 'کتابخانه صوتی')
@section('content')
<div class="mx-auto max-w-4xl space-y-6" data-media-library>
    <header class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-black text-[#071a3b]">کتابخانه صوتی</h1>
            <p class="mt-2 text-sm text-slate-600">پیام‌های منوی تماس، اعلان‌ها و تماس‌های خروجی را اینجا مدیریت کنید.</p>
        </div>
        @if($canManage)
            <button type="button" class="commerce-button" data-media-upload-open>بارگذاری فایل صوتی</button>
        @endif
    </header>

    <section class="panel overflow-hidden" aria-label="فایل‌های صوتی">
        <div class="grid gap-3 border-b border-slate-200 p-4 sm:grid-cols-[1fr_auto_auto] sm:items-end">
            <label class="text-sm font-semibold">جست‌وجو
                <input type="search" placeholder="نام فایل صوتی" data-media-filter class="mt-1 block w-full rounded-xl border border-slate-300 px-3 py-2">
            </label>
            <label class="text-sm font-semibold">ترتیب
                <select data-media-sort class="mt-1 block w-full rounded-xl border border-slate-300 px-3 py-2">
                    <option value="recent">تازه‌ترین</option><option value="name">نام</option>
                </select>
            </label>
            <label class="flex gap-2 py-2 text-sm"><input type="checkbox" data-media-archived> بایگانی‌شده‌ها</label>
        </div>
        <div data-media-rows class="divide-y divide-slate-100">
            @foreach($assets as $asset)
                <article data-media-row data-name="{{ $asset->name }}" data-created="{{ $asset->created_at?->getTimestamp() }}" data-archived="{{ $asset->archived_at ? 'yes' : 'no' }}" @if($asset->archived_at) hidden @endif class="space-y-3 px-4 py-4 sm:px-6">
                    <div class="grid gap-3 sm:grid-cols-[1fr_minmax(15rem,21rem)_auto] sm:items-center">
                        <div class="min-w-0">
                            <p class="break-words font-bold"><bdi>{{ $asset->name }}</bdi></p>
                            <p class="mt-1 text-xs text-slate-600">{{ $asset->duration_seconds }} ثانیه @if($asset->archived_at) · بایگانی‌شده @endif</p>
                        </div>
                        <audio controls preload="none" src="{{ route('customer.media.preview', $asset) }}" class="h-10 w-full" aria-label="پیش‌شنوی {{ $asset->name }}"></audio>
                        @if($canManage)
                            <details>
                                <summary class="cursor-pointer text-sm font-semibold text-blue-700">مدیریت</summary>
                                <form method="post" action="{{ route('customer.media.update', $asset) }}" class="mt-2 flex flex-wrap items-center gap-2">
                                    @csrf @method('PATCH')
                                    <input name="name" value="{{ $asset->name }}" maxlength="100" aria-label="نام فایل" class="w-40 rounded-lg border border-slate-300 p-2 text-sm">
                                    <button name="action" value="rename" class="text-sm font-bold text-blue-700">ذخیره نام</button>
                                    <button name="action" value="{{ $asset->archived_at ? 'restore' : 'archive' }}" class="text-sm font-bold text-slate-700">{{ $asset->archived_at ? 'بازگردانی' : 'بایگانی' }}</button>
                                </form>
                                @unless($asset->archived_at)<p class="mt-2 text-xs text-slate-600">فایل بایگانی‌شده در انتخاب‌های جدید دیده نمی‌شود. پخش در تنظیمات فعلی ادامه دارد.</p>@endunless
                            </details>
                        @endif
                    </div>
                    @if(!empty($usage[$asset->id]))
                        <details class="text-sm">
                            <summary class="cursor-pointer text-slate-700">محل استفاده ({{ count($usage[$asset->id]) }})</summary>
                            <ul class="mt-2 space-y-1">
                                @foreach($usage[$asset->id] as $label => $url)
                                    <li><a class="text-blue-700 underline" href="{{ $url }}"><bdi>{{ $label }}</bdi></a></li>
                                @endforeach
                            </ul>
                        </details>
                    @endif
                </article>
            @endforeach
        </div>
        <p data-media-empty class="p-8 text-center text-sm text-slate-600" @if($assets->whereNull('archived_at')->isNotEmpty()) hidden @endif>فایل صوتی پیدا نشد.</p>
    </section>
</div>
@endsection
