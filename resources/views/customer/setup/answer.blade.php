@extends('layouts.customer')
@section('title', 'تعیین پاسخ‌گو')
@section('content')
<div class="mx-auto max-w-3xl">
    <p class="text-sm font-bold text-blue-700" dir="ltr">{{ $number->number }}</p>
    <h1 class="mt-2 text-2xl font-extrabold text-[#071a3b]">چه کسی به تماس‌های این شماره پاسخ دهد؟</h1>
    <p class="mt-2 text-slate-600">تلفن یک نفر را انتخاب کنید یا برای او یک تلفن جدید بسازید. وقتی شماره تأیید شود، تماس‌ها به همان تلفن می‌رسند.</p>
    @if ($number->inboundRoute?->destination)
        <div class="mt-6 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800">پاسخ‌گوی فعلی: <strong>{{ $number->inboundRoute->destination->display_name ?: $number->inboundRoute->destination->extension }}</strong></div>
    @endif
    <form method="POST" action="{{ route('customer.setup.answer.store', $number) }}" class="panel mt-7 overflow-hidden">
        @csrf
        <div class="space-y-5 p-6">
            <fieldset>
                <legend class="mb-3 text-sm font-bold text-slate-700">انتخاب پاسخ‌گو</legend>
                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="flex items-center gap-2 rounded-xl border border-slate-200 p-4 text-sm font-semibold"><input type="radio" name="answerer" value="new" @checked(old('answerer', 'new') === 'new') /> ساخت تلفن برای یک نفر</label>
                    <label class="flex items-center gap-2 rounded-xl border border-slate-200 p-4 text-sm font-semibold"><input type="radio" name="answerer" value="existing" @checked(old('answerer') === 'existing') @disabled($extensions->isEmpty()) /> انتخاب تلفن موجود</label>
                </div>
            </fieldset>
            <div id="new-answerer">
                <label class="block text-sm font-bold text-slate-700">نام پاسخ‌گو
                    <input name="display_name" value="{{ old('display_name') }}" maxlength="100" placeholder="مثال: سارا احمدی" class="mt-2 w-full rounded-xl border border-slate-200 px-4 py-3 font-normal focus:border-blue-500 focus:outline-none" />
                </label>
                <p class="mt-2 text-xs text-slate-500">برای این شخص یک شناسه و رمز تلفن ساخته می‌شود.</p>
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
            <a href="{{ route('customer.setup.number') }}" class="rounded-xl px-4 py-3 text-sm font-bold text-slate-600">بازگشت</a>
            <button class="rounded-xl bg-blue-600 px-6 py-3 text-sm font-bold text-white hover:bg-blue-700">ذخیره و اتصال تلفن</button>
        </div>
    </form>
</div>
<script>
    function showAnswerer() {
        const create = document.querySelector('input[name="answerer"]:checked')?.value === 'new';
        document.getElementById('new-answerer').classList.toggle('hidden', !create);
        document.getElementById('existing-answerer').classList.toggle('hidden', create);
        document.querySelector('input[name="display_name"]').required = create;
        document.querySelector('select[name="extension_id"]').required = !create;
    }
    document.querySelectorAll('input[name="answerer"]').forEach(input => input.addEventListener('change', showAnswerer));
    showAnswerer();
</script>
@endsection
