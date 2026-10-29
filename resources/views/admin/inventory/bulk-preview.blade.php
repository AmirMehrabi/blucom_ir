@extends('layouts.portal')
@section('title', 'مرور عملیات موجودی')
@section('content')
<a href="{{ route('admin.inventory.index') }}" class="text-sm text-blue-700">← بازگشت به موجودی</a>
<h1 class="my-6 text-2xl font-black">{{ \App\Services\Commerce\InventoryWorkspace::ACTIONS[$data['action']] }} — مرور تغییرات</h1>
@php($eligible = collect($rows)->whereNull('error')->count())
<p class="mb-5 rounded-2xl bg-blue-50 p-4 text-sm text-blue-800">{{ $eligible }} شماره قابل تغییر · {{ count($rows) - $eligible }} شماره مسدود. وضعیت همه شماره‌ها هنگام اعمال دوباره بررسی می‌شود.</p>
@if(isset($data['amount']))<p class="mb-4 text-sm">قیمت جدید: <strong>{{ number_format($data['amount']) }} تومان / {{ config('commerce.separated_billing_enabled') ? 'سال' : 'ماه' }}</strong></p>@endif
@if($data['action'] === 'label')<p class="mb-4 text-sm">برچسب جدید: {{ $data['label'] ?: 'بدون برچسب' }}</p>@endif
@if(!empty($data['reason']))<p class="mb-4 text-sm">دلیل: {{ $data['reason'] }}</p>@endif
<div class="panel overflow-x-auto"><table class="w-full text-right text-sm"><thead class="bg-slate-50"><tr><th class="p-4">شماره</th><th class="p-4">قیمت / پلن</th><th class="p-4">نتیجه</th><th class="p-4">بررسی</th></tr></thead><tbody>@foreach($rows as $row)<tr class="border-t"><td class="p-4"><bdi>{{ $row['number'] }}</bdi></td><td class="p-4">@if(isset($row['sale']['amount'])){{ number_format($row['sale']['amount']) }} تومان / {{ $row['sale']['billing_model'] === 'one_time_number' ? 'خرید یک‌باره' : ($row['sale']['billing_model'] === 'annual_number' ? 'سال' : 'ماه') }}<small class="mt-1 block text-slate-500">{{ $row['plan'] ?? 'پلن تعیین نشده' }}</small>@else—@endif</td><td class="p-4 {{ $row['error'] ? 'text-amber-800' : 'text-emerald-700' }}">{{ $row['error'] ?? 'قابل انجام' }}</td><td class="p-4"><a href="{{ route('admin.inventory.show', $row['id']) }}" class="text-blue-700">مدیریت شماره ←</a></td></tr>@endforeach</tbody></table></div>
<form method="POST" action="{{ route('admin.inventory.bulk') }}" class="mt-6 flex flex-wrap items-center gap-4">@csrf
<input type="hidden" name="preview_token" value="{{ $token }}">
<button class="rounded-xl bg-blue-600 px-6 py-3 font-bold text-white disabled:opacity-50" @disabled(!$eligible)>تأیید و اعمال برای {{ $eligible }} شماره</button><a href="{{ route('admin.inventory.index') }}" class="p-3 text-slate-500">انصراف</a>
</form>
<p class="mt-4 text-xs leading-6 text-slate-500">شماره‌های مسدودشده تغییر نمی‌کنند و در نتیجه عملیات مشخص خواهند شد. برداشتن از فروش یا توقف عرضه، سرویس مشتریان را قطع نمی‌کند.</p>
@endsection
