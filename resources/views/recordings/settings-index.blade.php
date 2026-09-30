@extends('layouts.portal')
@section('title', 'تنظیم ضبط تماس')
@section('content')
<div class="mb-6 flex flex-wrap items-end justify-between gap-3"><div><h1 class="text-2xl font-black">تنظیم ضبط تماس</h1><p class="mt-2 text-sm text-slate-500">برای هر شماره نوع تماس و مدت نگهداری را انتخاب کنید.</p></div>@if(auth()->user()->hasPermission('recordings.view'))<a href="{{ route('recordings.index') }}" class="text-sm font-bold text-blue-700">صدای تماس‌ها ←</a>@endif</div>
<div class="grid gap-4 md:grid-cols-2">
@forelse($numbers as $number)<article class="panel p-6"><div class="flex items-start justify-between gap-3"><div><h2 dir="ltr" class="text-lg font-black">{{ $number->number }}</h2>@if(auth()->user()->isAdmin())<p class="mt-1 text-xs text-slate-500">{{ $number->tenant?->name }}</p>@endif</div><span class="rounded-full bg-blue-50 px-3 py-1 text-xs font-bold text-blue-700">{{ ['off'=>'خاموش','inbound'=>'ورودی','outbound'=>'خروجی','both'=>'ورودی و خروجی'][$number->recordingSetting?->directions ?? 'off'] ?? 'خاموش' }}</span></div><p class="mt-4 text-sm text-slate-500">نگهداری: {{ $number->recordingSetting?->retention_days ?? 30 }} روز · {{ ($number->recordingSetting?->coverage ?? 'conversation') === 'conversation' ? 'فقط مکالمه' : 'همراه منو و انتظار' }}</p><a href="{{ route('recordings.numbers.edit',$number) }}" class="mt-5 inline-block rounded-xl bg-blue-600 px-5 py-3 text-sm font-bold text-white">تنظیم ضبط و نگهداری</a></article>
@empty<div class="panel p-8 text-center md:col-span-2"><p class="font-bold">ابتدا یک شماره به مجموعه تخصیص دهید.</p></div>@endforelse
</div><div class="mt-5">{{ $numbers->links() }}</div>
@endsection
