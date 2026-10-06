@extends('layouts.portal')
@section('title', 'پلن‌های ماهانه')
@section('content')
<h1 class="text-2xl font-black">پلن‌های ماهانه</h1>
<p class="my-4 text-sm text-slate-500">هر نسخه شامل داخلی، ساعت کاری، منوی تماس و تیم است. سقف‌ها برای کل کسب‌وکار هستند. مبلغ ماهانه هر شماره در موجودی فروش، به تومان تعیین می‌شود. اعمال سقف‌ها هنگام خرید و تنظیم سرویس در فازهای بعد انجام خواهد شد.</p>
<form method="POST" action="{{ route('admin.plans.store') }}" class="panel mb-5 flex flex-wrap items-end gap-3 p-5">@csrf<label>نام پلن<input name="name" value="{{ old('name') }}" required maxlength="255" class="mt-1 block rounded-lg border p-3"></label><button class="rounded-xl bg-blue-600 p-3 font-bold text-white">ایجاد پلن</button></form>
@forelse($plans as $plan)
<section class="panel mb-5 p-5"><h2 class="text-lg font-bold">{{ $plan->name }} {{ $plan->archived ? '· آرشیوی' : '' }}</h2>
@foreach($plan->versions as $version)<div class="mt-4 rounded-xl border p-4"><p class="font-bold">نسخه {{ $version->version }} · {{ $version->published_at ? 'منتشرشده / ثابت' : 'پیش‌نویس' }}</p><p class="mt-2 text-sm">داخلی: {{ $version->limits['extensions'] }} · تیم: {{ $version->limits['queues'] }} · منو: {{ $version->limits['ivr_menus'] }} · دامنه سقف: کسب‌وکار</p>@if(!$version->published_at && !$plan->archived)<form method="POST" action="{{ route('admin.plans.edit-draft', $version) }}" class="mt-4 grid gap-3 sm:grid-cols-4">@csrf @method('PUT') @foreach(['extensions' => 'تعداد داخلی', 'queues' => 'تعداد تیم', 'ivr_menus' => 'تعداد منو'] as $key => $label)<label>{{ $label }}<input name="{{ $key }}" type="number" min="1" max="10000" step="1" required value="{{ $version->limits[$key] }}" class="mt-1 w-full rounded-lg border p-3"></label>@endforeach<button class="self-end rounded-xl border p-3">ذخیره پیش‌نویس</button></form><form method="POST" action="{{ route('admin.plans.publish', $version) }}" class="mt-3">@csrf<button class="rounded-lg bg-emerald-100 p-3 font-bold text-emerald-900">انتشار نسخه</button></form>@endif</div>@endforeach
@if(!$plan->archived)
<form method="POST" action="{{ route('admin.plans.version', $plan) }}" class="mt-5 grid gap-3 sm:grid-cols-4">@csrf @foreach(['extensions' => 'تعداد داخلی', 'queues' => 'تعداد تیم', 'ivr_menus' => 'تعداد منو'] as $key => $label)<label>{{ $label }}<input name="{{ $key }}" type="number" min="1" max="10000" step="1" required class="mt-1 w-full rounded-lg border p-3"></label>@endforeach<button class="self-end rounded-xl bg-slate-900 p-3 text-white">نسخه جدید</button></form>
<form method="POST" action="{{ route('admin.plans.archive', $plan) }}" class="mt-5 flex flex-wrap items-end gap-3">@csrf<label>دلیل آرشیو<input name="reason" required maxlength="1000" class="mt-1 block rounded-lg border p-3"></label><button class="rounded-xl border p-3">آرشیو پلن</button></form>
@endif</section>
@empty<p class="panel p-5 text-slate-500">پلنی وجود ندارد؛ ابتدا یک پلن بسازید.</p>@endforelse
@endsection
