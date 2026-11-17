@extends('layouts.portal')
@section('title', 'ویرایش منوی تماس')
@section('content')
@php
    $draft = $menu->draft_config ?? ['greeting' => null, 'choices' => [], 'fallback' => ''];
    $directDialEnabled = (bool) old('direct_dial_enabled', $draft['direct_dial_enabled'] ?? false);
    $directDialIds = array_map('intval', old('direct_dial_extension_ids', session()->hasOldInput() ? [] : ($draft['direct_dial_extension_ids'] ?? [])));
    $destinations = [];
    foreach ($extensions as $extension) $destinations['extension:'.$extension->id] = ($extension->display_name ?: 'تلفن '.$extension->extension).' · '.$extension->extension;
    foreach ($queues as $queue) $destinations['queue:'.$queue->id] = 'صف '.$queue->name;
    foreach ($menus as $childMenu) $destinations['ivr:'.$childMenu->id] = 'منوی دیگر: '.$childMenu->name;
@endphp
<div class="mx-auto max-w-5xl space-y-6">
    @if ($setupNumber)<a href="{{ (auth()->user()->isAdmin() ? route('admin.sip-numbers.setup', $setupNumber) : route('customer.lines.show', $setupNumber)) }}" class="inline-flex rounded-xl border border-blue-200 bg-blue-50 px-4 py-2.5 text-xs font-bold text-blue-700">← بازگشت به راه‌اندازی {{ $setupNumber->normalized_number }}</a>@endif
    @if (request()->boolean('wizard') && ! auth()->user()->isAdmin())<a href="{{ route('customer.setup.wizard') }}" class="inline-flex rounded-xl border border-blue-200 bg-blue-50 px-4 py-2.5 text-xs font-bold text-blue-700">بازگشت به راه‌اندازی کامل خط</a>@endif
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div><a href="{{ route('ivr-menus.index', $setupNumber ? ['number_id' => $setupNumber->id] : []) }}" class="text-xs font-bold text-blue-700">← بازگشت به منوها</a><h1 class="mt-2 text-2xl font-black text-[#071a3b]">{{ $menu->name }}</h1><p class="mt-2 text-sm text-slate-600">پیام را آماده کنید، مقصد کلیدها و داخلی‌های قابل شماره‌گیری را انتخاب کنید و در پایان تغییرات را منتشر کنید.</p></div>
        <span class="rounded-full px-3 py-1.5 text-xs font-bold {{ $menu->isPublished() ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700' }}">{{ $menu->isPublished() ? 'منتشرشده · نسخه '.$menu->version : 'فقط پیش‌نویس' }}</span>
    </div>

    @if ($menu->isPublished())
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900">تغییرات پیام و گزینه‌های منو تا وقتی «انتشار» را نزنید روی تماس‌های زنده اثر نمی‌گذارند. <a href="{{ auth()->user()->isAdmin() ? route('admin.sip-numbers.index') : route('customer.setup.lines') }}" class="font-bold underline">شماره‌ها</a></div>
    @endif

    @include('recordings.destination-setting', ['destination' => 'ivr', 'record' => $menu])
    <form method="POST" action="{{ route('ivr-menus.update', $menu) }}" enctype="multipart/form-data" class="space-y-6">
        @if($setupNumber)<input type="hidden" name="number_id" value="{{ $setupNumber->id }}">@endif
        @csrf @method('PUT')
        <section class="panel p-5 sm:p-6" data-media-greeting-field>
            <h2 class="text-lg font-bold">۱. پیام خوش‌آمدگویی</h2>
            <label class="mt-4 block text-sm font-semibold">نام منو<input name="name" value="{{ old('name', $menu->name) }}" required maxlength="100" class="mt-2 w-full rounded-xl border border-slate-200 px-4 py-3 font-normal"></label>
            @if(request()->attributes->get('customer_portal'))
                @php($savedAsset = $inboundAudioAssets->firstWhere('storage_key', $draft['greeting'] ?? null))
                <div class="mt-4 space-y-3">
                    <input type="hidden" name="greeting_asset_id" value="{{ old('greeting_asset_id', $savedAsset?->id) }}" data-media-greeting>
                    <p data-media-current class="text-sm font-semibold">{{ $inboundAudioAssets->firstWhere('id', old('greeting_asset_id', $savedAsset?->id))?->name ?? (!empty($draft['greeting']) ? 'پیام ذخیره‌شده' : 'هنوز پیامی انتخاب نشده است.') }}</p>
                    <button type="button" data-media-choose-greeting class="commerce-secondary">{{ !empty($draft['greeting']) ? 'تغییر فایل صوتی' : 'انتخاب فایل صوتی' }}</button>
                    @if(!empty($draft['greeting']) && preg_match('#^'.$menu->tenant_id.'/'.$menu->id.'/[0-9A-Z]+\.wav$#D', $draft['greeting']))
                        <button type="button" data-media-import="{{ route('customer.media.import', $menu) }}" class="text-sm font-bold text-blue-700">ذخیره در کتابخانه</button>
                        <span data-media-import-status role="status" class="text-sm"></span>
                    @endif
                    <details>
                        <summary class="cursor-pointer text-sm text-blue-700">بارگذاری مستقیم فایل M4A</summary>
                        <label class="mt-2 block text-sm">فایل صوتی تا ۱۰ مگابایت<input type="file" name="greeting" accept="audio/*" class="mt-2 block w-full text-sm"></label>
                    </details>
                </div>
            @else
                <label class="mt-4 block text-sm font-semibold">فایل پیام<input type="file" name="greeting" accept="audio/*" class="mt-2 block w-full rounded-xl border border-slate-200 p-3"></label>
            @endif
            <audio controls preload="none" data-media-greeting-preview class="mt-3 h-10 w-full" aria-label="پیش‌شنوی پیام پیش‌نویس"
                @if(!empty($draft['greeting'])) src="{{ route('ivr-menus.audio', [$menu, 'draft']) }}" @else hidden @endif></audio>
            @if(!empty($menu->published_config['greeting']))
                <div class="mt-4 rounded-xl bg-slate-50 p-4">
                    <p class="mb-2 text-xs font-bold text-slate-600">پیام فعال برای تماس‌گیرندگان</p>
                    <audio controls preload="none" src="{{ route('ivr-menus.audio', [$menu, 'published']) }}" class="h-10 w-full" aria-label="پیش‌شنوی پیام منتشرشده"></audio>
                </div>
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
                                @include('shared.inbound-audio-options', ['selected' => $choice['destination'] ?? null])
                            </select>
                        </div>
                    </div>
                @endforeach
            </div>
        </section>

        <section class="panel p-5 sm:p-6" data-ivr-direct-dial>
            <h2 class="text-lg font-bold">۳. شماره‌گیری مستقیم داخلی</h2>
            <p id="direct-dial-help" class="mt-2 text-sm leading-7 text-slate-600">تماس‌گیرنده‌ای که شماره داخلی را می‌داند، می‌تواند هنگام پیام منو آن را وارد کند؛ نیازی به تعریف یک کلید جدا برای هر داخلی نیست.</p>
            <input type="hidden" name="direct_dial_enabled" value="0">
            <label class="mt-4 flex items-center gap-3 rounded-xl border border-blue-200 bg-blue-50 p-4 font-bold text-blue-900">
                <input type="checkbox" name="direct_dial_enabled" value="1" data-ivr-direct-toggle aria-controls="ivr-direct-options" @checked($directDialEnabled) aria-describedby="direct-dial-help" class="size-5 rounded border-slate-300">
                اجازه شماره‌گیری مستقیم داخلی‌ها
            </label>
            <div id="ivr-direct-options" data-ivr-direct-options class="mt-5 space-y-4">
                <fieldset>
                    <legend class="text-sm font-bold">کدام داخلی‌ها مستقیماً قابل تماس باشند؟</legend>
                    <p class="mt-2 text-xs leading-6 text-slate-500">فقط داخلی‌های انتخاب‌شده در این منو قابل شماره‌گیری هستند. داخلی‌های جدید خودکار به این فهرست اضافه نمی‌شوند.</p>
                    <div class="mt-3 grid gap-3 sm:grid-cols-2">
                        @forelse ($extensions as $extension)
                            <label class="flex min-w-0 items-center gap-3 rounded-xl border border-slate-200 p-3 text-sm">
                                <input type="checkbox" name="direct_dial_extension_ids[]" value="{{ $extension->id }}" @checked(in_array($extension->id, $directDialIds, true)) class="size-4 rounded border-slate-300">
                                <span><b dir="ltr">{{ $extension->extension }}</b> · {{ $extension->display_name ?: 'بدون نام' }}</span>
                            </label>
                        @empty
                            <p class="text-sm text-amber-800">ابتدا یک داخلی فعال ایجاد کنید، سپس به این صفحه برگردید.</p>
                        @endforelse
                    </div>
                    @error('direct_dial_extension_ids')<p role="alert" class="mt-3 text-sm text-red-700">{{ $message }}</p>@enderror
                </fieldset>
                <div class="rounded-xl bg-slate-50 p-4 text-sm leading-7 text-slate-700">
                    <p class="font-bold">این جمله را به پیام خوش‌آمدگویی اضافه کنید:</p>
                    <p>«اگر شماره داخلی مورد نظر را می‌دانید، آن را شماره‌گیری کنید.»</p>
                    <p class="mt-2 text-xs leading-6">بین رقم‌ها بیش از ۳ ثانیه مکث نکنید. اگر کلید منو با رقم اول یک داخلی مشترک باشد، سیستم برای ادامه شماره کمی صبر می‌کند. برای انتخاب سریع کلید منو، پس از آن # بزنید. برای تکمیل شماره داخلی هم می‌توانید # بزنید.</p>
                </div>
            </div>
        </section>

        <section class="panel p-5 sm:p-6">
            <h2 class="text-lg font-bold">۴. اگر تماس‌گیرنده کلیدی نزد یا شماره نامعتبر بود</h2>
            <p class="mt-2 text-xs text-slate-500">پیام یک بار دیگر پخش می‌شود. اگر باز هم انتخابی نشد یا کلید اشتباه بود، تماس به این پاسخ‌گو می‌رود.</p>
            <select name="fallback" class="mt-4 w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm">
                <option value="">انتخاب پاسخ‌گوی جایگزین</option>
                @foreach ($destinations as $value => $label)<option value="{{ $value }}" @selected(old('fallback', $draft['fallback'] ?? '') === $value)>{{ $label }}</option>@endforeach
                @include('shared.inbound-audio-options', ['selected' => old('fallback', $draft['fallback'] ?? null)])
            </select>
        </section>
        <section class="panel p-5 sm:p-6">
            <h2 class="font-bold">۵. ذخیره و فعال‌سازی</h2>
            <p class="mt-2 text-sm leading-7 text-slate-600">پیام صوتی باید با کلیدها و شماره‌گیری مستقیم هماهنگ باشد. «ذخیره و انتشار» همین تغییرات را برای تماس‌های جدید فعال می‌کند؛ برای ادامه ویرایش در فرصتی دیگر، پیش‌نویس را ذخیره کنید.</p>
            <div class="mt-4 flex flex-wrap gap-3">
                <button name="intent" value="publish" class="rounded-xl bg-blue-600 px-6 py-3 text-sm font-bold text-white hover:bg-blue-700">ذخیره و انتشار تغییرات</button>
                <button name="intent" value="draft" class="rounded-xl border border-slate-300 px-6 py-3 text-sm font-bold text-slate-700">فقط ذخیره پیش‌نویس</button>
            </div>
        </section>
    </form>

    @if ($menu->previous_config)
        <form method="POST" action="{{ route('ivr-menus.restore', $menu) }}" onsubmit="return confirm('نسخه قبلی دوباره فعال شود؟')">@csrf<button class="text-sm font-bold text-amber-700">بازگرداندن نسخه قبلی</button></form>
    @endif
    @unless ($menu->inboundRoutes()->exists())
        <form method="POST" action="{{ route('ivr-menus.destroy', $menu) }}" onsubmit="return confirm('این منو حذف شود؟')">@csrf @method('DELETE')<button class="text-xs font-bold text-red-600">حذف منوی بدون شماره</button></form>
    @endunless
</div>
@endsection
