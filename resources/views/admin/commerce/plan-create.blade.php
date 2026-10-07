@extends('layouts.portal')
@section('title', 'ایجاد پلن')
@section('content')
<a href="{{ route('admin.plans.index') }}" class="text-sm font-bold text-blue-700">بازگشت به پلن‌ها</a>
<h1 class="mt-5 text-2xl font-black">ایجاد پلن</h1><p class="mt-2 text-sm leading-7 text-slate-500">نام و ظرفیت‌های پلن را مشخص کنید. ابتدا پیش‌نویس ذخیره می‌شود؛ انتشار پس از مرور انجام می‌شود.</p>
<form method="POST" action="{{ route('admin.plans.store') }}" class="mt-6 max-w-3xl rounded-2xl border border-slate-200 bg-white p-6 sm:p-8">@csrf
<label for="plan-name" class="block text-sm font-bold">نام پلن</label><input id="plan-name" name="name" value="{{ old('name') }}" required maxlength="255" autofocus placeholder="مثلاً پلن کسب‌وکار" class="mt-2 w-full rounded-xl border border-slate-300 p-3">@error('name')<p class="mt-2 text-sm text-red-700">{{ $message }}</p>@enderror
<div class="mt-7 grid gap-5 sm:grid-cols-3">@include('admin.commerce.plans.limits', ['prefix' => 'create', 'limits' => []])</div>
<p class="mt-6 rounded-xl bg-slate-50 p-4 text-sm leading-7 text-slate-600">ساعت کاری از امکانات پلن است. مبلغ ماهانه هنگام انتشار پیشنهاد فروش شماره تعیین می‌شود.</p>
<div class="mt-7 flex flex-wrap items-center gap-4"><button class="rounded-xl bg-blue-600 px-5 py-3 text-sm font-bold text-white">ذخیره پیش‌نویس</button><a href="{{ route('admin.plans.index') }}" class="text-sm text-slate-600">انصراف</a></div>
</form>
@endsection
