@extends('layouts.portal')
@section('title', 'راه‌اندازی سریع خط')
@section('content')
<div class="panel mb-5 p-5"><h2 class="font-bold">آماده‌سازی شماره برای فروش</h2><p class="my-3 text-sm text-slate-500">برای موجودی فروش، مالک و پاسخ‌گو لازم نیست. شماره و اتصال را ذخیره کنید، سپس بررسی فنی و پیشنهاد ماهانه را تکمیل کنید.</p><a href="{{ route('admin.sip-numbers.index', ['scope' => 'stock']).'#new-stock' }}" class="inline-block rounded-xl bg-blue-600 p-3 font-bold text-white">آماده‌سازی موجودی فروش ←</a></div>

<div id="start" class="mx-auto max-w-4xl space-y-6">
    <div><h1 class="text-2xl font-black">راه‌اندازی سریع خط</h1><p class="mt-2 text-sm leading-7 text-slate-600">مالک، اتصال، شماره، پاسخ‌گو و ساعت کاری را انتخاب کنید؛ قبل از اعمال، همه تغییرات را بررسی خواهید کرد.</p></div>
    @if(session('status'))<div role="status" class="rounded-xl bg-emerald-50 p-4 text-sm text-emerald-800">{{ session('status') }}</div>@endif
    @if($errors->any())<div role="alert" class="rounded-xl bg-red-50 p-4 text-red-800">{{ $errors->first() }}</div>@endif
    <section class="panel p-6"><h2 class="font-bold">۱. این خط برای چه کسی است؟</h2>
        <form method="POST" action="{{ route('admin.setup.store') }}" class="mt-5 flex flex-wrap gap-3">@csrf
            <select name="tenant_id" aria-label="مالک خط" required class="min-w-64 flex-1 rounded-xl border border-slate-200 bg-white p-3">
                @foreach($tenants as $tenant)<option value="{{ $tenant->id }}" @selected(old('tenant_id', $defaultTenantId) == $tenant->id)>{{ $tenant->name }}</option>@endforeach
            </select><button class="rounded-xl bg-blue-600 px-5 py-3 text-sm font-bold text-white">شروع راه‌اندازی</button>
        </form>
    </section>
    <section class="panel p-6"><h2 class="font-bold">پیش‌نویس‌های شما</h2><p class="mt-2 text-xs text-slate-500">می‌توانید چند شماره را هم‌زمان آماده کنید و بعداً ادامه دهید.</p>
        <div class="mt-4 divide-y divide-slate-100">
            @forelse($drafts as $draft)
                <div class="flex flex-wrap items-center justify-between gap-3 py-4 text-sm"><div class="space-y-1"><strong>{{ $draft->tenant->name }}</strong><p class="text-xs text-slate-500">{{ $draft->data['number'] ?? 'شماره موجود / هنوز انتخاب نشده' }} · مرحله {{ $draft->step }} از ۶</p></div>
                    <div class="flex gap-4"><a href="{{ route('admin.setup.show', $draft) }}" class="font-bold text-blue-700">ادامه راه‌اندازی ←</a><form method="POST" action="{{ route('admin.setup.destroy', $draft) }}" onsubmit="return confirm('پیش‌نویس حذف شود؟')">@csrf @method('DELETE')<button class="font-bold text-red-700">حذف پیش‌نویس</button></form></div>
                </div>
            @empty
                <p class="py-4 text-sm text-slate-500">پیش‌نویسی ندارید.</p>
            @endforelse
        </div>
    </section>
</div>
@endsection
