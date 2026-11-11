@extends('layouts.portal')
@section('title', 'کلید API جدید')
@section('content')
<section class="panel mx-auto max-w-2xl space-y-4 p-6">
    <h1 class="text-xl font-bold">کلید API جدید</h1>
    <p>کلید را اکنون در محل امن ذخیره کنید. فقط در این صفحه نمایش داده می‌شود.</p>
    <input readonly dir="ltr" aria-label="کلید API" value="{{ $token }}" class="block w-full rounded-xl border border-slate-300 bg-slate-50 p-3 font-mono">
    <a class="text-sm font-bold text-blue-700" href="{{ route('customer.audio-calls.index') }}#api-keys">بازگشت به کلیدهای API</a>
</section>
@endsection
