@extends('layouts.portal')
@section('title', 'وضعیت پاسخ‌گویی')
@section('content')
<div class="mb-7"><h1 class="text-2xl font-black">وضعیت پاسخ‌گویی</h1><p class="mt-2 text-sm text-slate-500">وقتی آماده هستید، تیم می‌تواند تماس‌ها را به تلفن شما برساند.</p></div>
@if (session('status'))<div class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-700">{{ session('status') }}</div>@endif
@if ($errors->any())<div class="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ $errors->first() }}</div>@endif
<section class="panel max-w-xl p-6">
    @if ($extension)
        <p class="text-sm text-slate-500">داخلی شما: <strong dir="ltr">{{ $extension->extension }}</strong></p>
        <p class="mt-3 text-sm">وضعیت کنونی: <strong>{{ $extension->queue_status === 'Available' ? 'آماده پاسخ‌گویی' : 'در استراحت' }}</strong></p>
        <div class="mt-6 flex flex-wrap gap-3">
            <form method="POST" action="{{ route('availability.update') }}">@csrf<input type="hidden" name="status" value="Available"><button class="rounded-xl bg-emerald-600 px-5 py-3 text-sm font-bold text-white">آماده پاسخ‌گویی</button></form>
            <form method="POST" action="{{ route('availability.update') }}">@csrf<input type="hidden" name="status" value="On Break"><button class="rounded-xl bg-slate-800 px-5 py-3 text-sm font-bold text-white">رفتن به استراحت</button></form>
        </div>
        <p class="mt-5 text-xs leading-6 text-slate-500">تلفن شما باید در Zoiper هم متصل باشد. تغییر این وضعیت بر تماس‌های مستقیم داخلی اثر ندارد.</p>
    @else
        <p class="text-sm leading-7 text-slate-600">هنوز داخلی‌ای به حساب شما وصل نشده است. از مدیر بخواهید داخلی‌تان را در صفحه کاربران انتخاب کند.</p>
    @endif
</section>
@endsection
