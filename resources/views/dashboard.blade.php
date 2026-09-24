@extends('layouts.portal')
@section('content')
<div class="mb-7 flex items-center justify-between">
    <div><h1 class="text-xl font-extrabold">داشبورد</h1><p class="mt-1 text-sm text-slate-500">وضعیت پیکربندی تلفن بلوکام</p></div>
    <a href="{{ route('admin.sip-numbers.index') }}" class="rounded-xl bg-blue-600 px-4 py-2 text-sm font-bold text-white">افزودن DID</a>
</div>
<div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
    @foreach ($stats as $stat)
        <div class="panel p-5"><span class="text-sm text-slate-500">{{ $stat['label'] }}</span><div class="mt-3 text-3xl font-extrabold">{{ $stat['value'] }}</div></div>
    @endforeach
</div>
<section class="panel mt-6 p-5">
    <h2 class="mb-4 font-bold">آخرین شماره‌ها</h2>
    @forelse ($numbers as $number)
        <div class="flex justify-between border-t border-slate-100 py-3 text-sm"><span dir="ltr">{{ $number->normalized_number }}</span><span>{{ $number->enabled ? 'فعال' : 'غیرفعال' }}</span></div>
    @empty
        <p class="text-sm text-slate-500">هنوز شماره‌ای ثبت نشده است.</p>
    @endforelse
</section>
@endsection
