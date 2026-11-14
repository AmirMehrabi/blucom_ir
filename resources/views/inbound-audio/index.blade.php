@extends('layouts.portal')
@section('title', 'اعلان‌های صوتی')
@section('content')
<div class="mx-auto max-w-3xl space-y-6">
    <h1 class="text-2xl font-black">اعلان‌های صوتی</h1>
    <form method="POST" action="{{ route('inbound-audio.store') }}" enctype="multipart/form-data" class="panel space-y-4 p-5">
        @csrf
        <label class="block text-sm font-bold">نام اعلان<input required name="name" maxlength="100" value="{{ old('name') }}" class="mt-2 block w-full rounded-xl border border-slate-200 p-3"></label>
        <label class="block text-sm font-bold">فایل اعلان<input required type="file" name="audio" accept=".wav,.mp3" class="mt-2 block w-full"></label>
        <p class="text-xs text-slate-600">WAV یا MP3، حداکثر ۱۰ مگابایت و ۶۰ ثانیه.</p>
        <button class="commerce-button">افزودن اعلان</button>
    </form>
    <section class="panel space-y-4 p-5">
        <h2 class="font-bold">اعلان‌های ذخیره‌شده</h2>
        @forelse($assets as $asset)
            <div><p class="mb-2 text-sm">{{ $asset->name }}</p><audio controls preload="none" src="{{ route('inbound-audio.audio', $asset->id) }}" class="w-full"></audio></div>
        @empty
            <p class="text-sm text-slate-600">هنوز اعلانی ذخیره نشده است.</p>
        @endforelse
    </section>
</div>
@endsection
