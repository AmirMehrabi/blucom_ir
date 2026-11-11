@extends('layouts.portal')
@section('title', 'تماس صوتی')
@section('content')
<div class="mx-auto max-w-5xl space-y-6">
    <header>
        <h1 class="text-2xl font-black">تماس صوتی</h1>
        <p class="mt-2 text-sm text-slate-600">پس از پاسخ مقصد، پیام پخش می‌شود و تماس پایان می‌یابد.</p>
    </header>
    <nav class="flex flex-wrap gap-4 text-sm font-bold text-blue-700" aria-label="بخش‌های تماس صوتی">
        <a href="#new-call">تماس جدید</a><a href="#audio-library">کتابخانه صوتی</a><a href="#call-history">تاریخچه</a>
        @if($keys !== null && $canManage)<a href="#api-keys">کلیدهای API</a>@endif
        <a href="{{ route('api.docs') }}">مستندات API</a>
    </nav>
    @if($errors->any())
        <ul role="alert" class="rounded-xl bg-red-50 p-4 text-sm text-red-800">
            @foreach($errors->all() as $message)<li>{{ $message }}</li>@endforeach
        </ul>
    @endif
    @if(session('status'))<p role="status" class="rounded-xl bg-blue-50 p-4">{{ session('status') }}</p>@endif

    <section id="new-call" class="panel p-6">
        <h2 class="mb-4 text-lg font-bold">تماس جدید</h2>
        @if($lines->isEmpty())
            <p class="text-sm text-slate-600">خط مجاز برای تماس صوتی پیدا نشد. برای فعال‌سازی خط خروجی با پشتیبانی تماس بگیرید.</p>
        @else
            <form method="post" action="{{ route('customer.audio-calls.store') }}" class="space-y-4">
                @csrf
                @php
                    $form = fn ($name) => is_scalar($value = old($name, $prefill[$name] ?? '')) ? (string) $value : '';
                @endphp
                <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', $requestKey) }}">
                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="text-sm font-bold">شماره مقصد
                        <input name="to" value="{{ $form('to') }}" type="tel" dir="ltr" maxlength="40" class="mt-2 block w-full rounded-xl border border-slate-200 p-3">
                    </label>
                    <label class="text-sm font-bold">شناسه تماس‌گیرنده
                        <select name="caller_id_id" required class="mt-2 block w-full rounded-xl border border-slate-200 p-3">
                            @if($lines->count() > 1)<option value="">انتخاب شماره</option>@endif
                            @foreach($lines as $line)
                                <option value="{{ $line->id }}" @selected($form('caller_id_id') === (string) $line->id)>{{ $line->normalized_number }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="text-sm font-bold">پیام از کتابخانه
                        <select name="audio_id" class="mt-2 block w-full rounded-xl border border-slate-200 p-3">
                            <option value="">انتخاب فایل</option>
                            @foreach($assets as $asset)
                                <option value="{{ $asset->id }}" @selected($form('audio_id') === $asset->id)>{{ $asset->name }} ({{ $asset->duration_seconds }} ثانیه)</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="text-sm font-bold">یا پیوند مستقیم HTTPS
                        <input name="audio_url" type="url" dir="ltr" maxlength="2048" class="mt-2 block w-full rounded-xl border border-slate-200 p-3">
                    </label>
                </div>
                <div class="flex flex-wrap gap-3">
                    <button class="rounded-xl bg-blue-700 px-5 py-3 font-bold text-white">شروع تماس</button>
                    @if(auth()->user()->mobile_verified_at)
                        <button name="test_call" value="1" class="rounded-xl border border-slate-300 px-5 py-3 font-bold">تماس آزمایشی با شمارهٔ من</button>
                    @endif
                </div>
            </form>
        @endif
    </section>

    <section id="audio-library" class="panel space-y-4 p-6">
        <h2 class="text-lg font-bold">کتابخانه صوتی</h2>
        @if($canManage && $lines->isNotEmpty())
            <form method="post" action="{{ route('customer.audio-calls.upload') }}" enctype="multipart/form-data" class="grid items-end gap-3 sm:grid-cols-3">
                @csrf
                <label class="text-sm font-bold">نام پیام<input name="name" required maxlength="80" class="mt-2 block w-full rounded-xl border border-slate-200 p-3"></label>
                <label class="text-sm font-bold">WAV یا MP3، حداکثر ۲ مگابایت و ۶۰ ثانیه<input name="audio" type="file" accept=".wav,.mp3" class="mt-2 block w-full text-xs"></label>
                <label class="text-sm font-bold">یا پیوند مستقیم HTTPS<input name="audio_url" type="url" dir="ltr" maxlength="2048" class="mt-2 block w-full rounded-xl border border-slate-200 p-3"></label>
                <button class="rounded-xl border border-slate-300 px-4 py-3 font-bold">ذخیره در کتابخانه</button>
            </form>
        @endif
        @forelse($assets as $asset)
            <div class="space-y-2 rounded-xl border border-slate-200 p-4">
                <p class="font-bold">{{ $asset->name }} <span class="text-sm font-normal text-slate-600">· {{ $asset->duration_seconds }} ثانیه</span></p>
                <audio controls preload="none" src="{{ route('customer.audio-calls.preview', $asset->id) }}" class="w-full max-w-sm"></audio>
                <code dir="ltr" class="block break-all text-xs">{{ $asset->id }}</code>
                @if($canManage)
                    <form method="post" action="{{ route('customer.audio-calls.asset.update', $asset->id) }}" class="flex flex-wrap gap-2">
                        @csrf @method('PATCH')
                        <input name="name" aria-label="نام پیام" value="{{ $asset->name }}" maxlength="80" class="rounded-lg border border-slate-200 px-3 py-2 text-sm">
                        <button class="px-2 text-sm font-bold text-blue-700">تغییر نام</button>
                        <button name="archive" value="1" class="px-2 text-sm font-bold text-red-700">بایگانی</button>
                    </form>
                @endif
            </div>
        @empty
            <p class="text-sm text-slate-600">کتابخانه خالی است.</p>
        @endforelse
    </section>

    <section id="call-history" class="panel space-y-3 p-6">
        <h2 class="text-lg font-bold">تاریخچه تماس صوتی</h2>
        @forelse($calls as $call)
            <a href="{{ route('customer.audio-calls.show', $call->id) }}" class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 py-3 text-sm">
                <bdi class="font-bold text-blue-700">{{ $call->destination }}</bdi>
                <span>{{ __('audio_calls.'.($call->outcome ?? $call->status), locale: 'fa') }}</span>
                <time dir="ltr">{{ $call->created_at->timezone(config('voip.display_timezone'))->format('Y/m/d H:i') }}</time>
            </a>
        @empty
            <p class="text-sm text-slate-600">هنوز تماسی ثبت نشده است.</p>
        @endforelse
        {{ $calls->links() }}
    </section>

    @if($keys !== null && $canManage)
        <section id="api-keys" class="panel space-y-4 p-6">
            <h2 class="text-lg font-bold">کلیدهای API</h2>
            @if($lines->isNotEmpty())
                <form method="post" action="{{ route('customer.audio-calls.keys.create') }}" class="flex flex-wrap gap-3">
                    @csrf
                    <input name="name" required maxlength="80" placeholder="مثلاً سامانه سفارش" aria-label="نام کلید" class="rounded-xl border border-slate-200 p-3">
                    <button class="rounded-xl bg-blue-700 px-4 py-3 font-bold text-white">ساخت کلید</button>
                </form>
                <p class="text-sm text-slate-600">شناسه خط برای API:
                    @foreach($lines as $line)<bdi class="px-2">{{ $line->normalized_number }} · {{ $line->id }}</bdi>@endforeach
                </p>
            @endif
            @foreach($keys as $key)
                <form method="post" action="{{ route('customer.audio-calls.keys.revoke', $key->id) }}" class="flex items-center justify-between border-t border-slate-100 pt-3">
                    @csrf @method('DELETE')
                    <span>{{ $key->name }}</span><button class="text-sm font-bold text-red-700">لغو کلید</button>
                </form>
            @endforeach
        </section>
    @endif
</div>
@endsection
