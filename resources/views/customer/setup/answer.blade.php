@extends('layouts.customer')
@section('title', 'تعیین پاسخ‌گو')
@section('content')
<div class="mx-auto max-w-3xl">
    <p class="text-sm font-bold text-blue-700" dir="ltr">{{ $number->number }}</p>
    <h1 class="mt-2 text-2xl font-extrabold text-[#071a3b]">چه کسی به تماس‌های این شماره پاسخ دهد؟</h1>
    <p class="mt-2 text-slate-600">تماس‌ها را به یک نفر، یک تیم یا منوی تماس بفرستید.</p>
    @if ($number->inboundRoute?->destination)
        <div class="mt-6 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800">پاسخ‌گوی فعلی: <strong>{{ $number->inboundRoute->destinationLabel() }}</strong></div>
    @endif
    <form method="POST" action="{{ route('customer.setup.answer.store', $number) }}" class="panel mt-7 overflow-hidden">
        @csrf
        <div class="space-y-5 p-6">
            <fieldset>
                <legend class="mb-3 text-sm font-bold text-slate-700">انتخاب پاسخ‌گو</legend>
                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="flex items-center gap-2 rounded-xl border border-slate-200 p-4 text-sm font-semibold"><input type="radio" name="answerer" value="new" @checked(old('answerer', 'new') === 'new') /> ساخت تلفن برای یک نفر</label>
                    <label class="flex items-center gap-2 rounded-xl border border-slate-200 p-4 text-sm font-semibold"><input type="radio" name="answerer" value="existing" @checked(old('answerer') === 'existing') @disabled($extensions->isEmpty()) /> انتخاب تلفن موجود</label>
                    <label class="flex items-center gap-2 rounded-xl border border-slate-200 p-4 text-sm font-semibold"><input type="radio" name="answerer" value="team" @checked(old('answerer') === 'team') @disabled($queues->isEmpty()) /> فرستادن تماس به تیم</label>
                    <label class="flex items-center gap-2 rounded-xl border border-slate-200 p-4 text-sm font-semibold"><input type="radio" name="answerer" value="menu" @checked(old('answerer') === 'menu') @disabled($menus->isEmpty()) /> پخش منوی تماس</label>
                </div>
            </fieldset>
            <div id="new-answerer">
                <label class="block text-sm font-bold text-slate-700">نام پاسخ‌گو
                    <input name="display_name" value="{{ old('display_name') }}" maxlength="100" placeholder="مثال: سارا احمدی" class="mt-2 w-full rounded-xl border border-slate-200 px-4 py-3 font-normal focus:border-blue-500 focus:outline-none" />
                </label>
                <p class="mt-2 text-xs text-slate-500">برای این شخص یک شناسه و رمز تلفن ساخته می‌شود.</p>
            </div>
            <div id="menu-answerer" class="hidden">
                <label class="block text-sm font-bold text-slate-700">منوی تماس
                    <select name="menu_id" class="mt-2 w-full rounded-xl border border-slate-200 bg-white px-4 py-3 font-normal focus:border-blue-500 focus:outline-none">
                        <option value="">انتخاب منوی منتشرشده</option>
                        @foreach ($menus as $menu)<option value="{{ $menu->id }}" @selected(old('menu_id') == $menu->id)>{{ $menu->name }}</option>@endforeach
                    </select>
                </label>
                <p class="mt-2 text-xs text-slate-500">تماس‌گیرنده پیام را می‌شنود و با انتخاب کلید به فرد یا تیم مناسب وصل می‌شود. <a class="font-bold text-blue-700 underline" href="{{ route('ivr-menus.index') }}">ساخت یا ویرایش منو</a></p>
            </div>
            <div id="team-answerer" class="hidden">
                <label class="block text-sm font-bold text-slate-700">تیم پاسخ‌گویی
                    <select name="queue_id" class="mt-2 w-full rounded-xl border border-slate-200 bg-white px-4 py-3 font-normal focus:border-blue-500 focus:outline-none">
                        <option value="">انتخاب تیم</option>
                        @foreach ($queues as $queue)<option value="{{ $queue->id }}" @selected(old('queue_id') == $queue->id)>{{ $queue->name }}</option>@endforeach
                    </select>
                </label>
                <p class="mt-2 text-xs text-slate-500">تماس به پاسخ‌گوهای آماده تیم پیشنهاد می‌شود. تلفن‌ها و تنظیم تماس خروجی اعضا تغییر نمی‌کند.</p>
            </div>
            <div id="existing-answerer" class="hidden">
                <label class="block text-sm font-bold text-slate-700">تلفن موجود
                    <select name="extension_id" class="mt-2 w-full rounded-xl border border-slate-200 bg-white px-4 py-3 font-normal focus:border-blue-500 focus:outline-none">
                        <option value="">انتخاب شخص</option>
                        @foreach ($extensions as $extension)
                            <option value="{{ $extension->id }}" @selected(old('extension_id') == $extension->id)>{{ $extension->display_name ?: 'تلفن '.$extension->extension }} · {{ $extension->extension }}</option>
                        @endforeach
                    </select>
                </label>
            </div>
            <p class="rounded-xl bg-slate-50 p-4 text-xs leading-6 text-slate-600">برای تلفن جدید، این شماره به‌عنوان شماره تماس خروجی آماده می‌شود. اگر تلفن موجود از قبل شماره خروجی دارد، آن تنظیم حفظ می‌شود. تماس خروجی تنها پس از تأیید شماره و اتصال ارائه‌دهنده فعال خواهد شد.</p>
        </div>
        <div class="flex flex-wrap justify-between gap-3 border-t border-slate-100 bg-slate-50 p-5">
            @if (auth()->user()->hasPermission('lines.view'))<a href="{{ route('customer.setup.lines') }}" class="rounded-xl px-4 py-3 text-sm font-bold text-slate-600">بازگشت</a>@endif
            <button class="rounded-xl bg-blue-600 px-6 py-3 text-sm font-bold text-white hover:bg-blue-700">ذخیره و اتصال تلفن</button>
        </div>
    </form>
</div>
<script>
    function showAnswerer() {
        const choice = document.querySelector('input[name="answerer"]:checked')?.value;
        const create = choice === 'new';
        const existing = choice === 'existing';
        const team = choice === 'team';
        const menu = choice === 'menu';
        document.getElementById('new-answerer').classList.toggle('hidden', !create);
        document.getElementById('existing-answerer').classList.toggle('hidden', !existing);
        document.getElementById('team-answerer').classList.toggle('hidden', !team);
        document.getElementById('menu-answerer').classList.toggle('hidden', !menu);
        document.querySelector('input[name="display_name"]').required = create;
        document.querySelector('select[name="extension_id"]').required = existing;
        document.querySelector('select[name="queue_id"]').required = team;
        document.querySelector('select[name="menu_id"]').required = menu;
    }
    document.querySelectorAll('input[name="answerer"]').forEach(input => input.addEventListener('change', showAnswerer));
    showAnswerer();
</script>
@endsection
