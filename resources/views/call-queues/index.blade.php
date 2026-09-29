@extends('layouts.portal')
@section('title', 'تیم‌های پاسخ‌گویی')
@section('content')
<div class="mb-7"><h1 class="text-2xl font-black">تیم‌های پاسخ‌گویی</h1><p class="mt-2 text-sm text-slate-500">چند داخلی را در یک تیم قرار دهید؛ سپس شماره را از بخش مقصد تماس‌های ورودی به آن وصل کنید.</p></div>
@if (session('status'))<div class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-700">{{ session('status') }}</div>@endif
@if ($errors->any())<div class="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"><ul class="list-disc pr-5">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
<section class="panel p-5 sm:p-6">
    <h2 class="font-bold">ساخت تیم</h2>
    <form method="POST" action="{{ route('teams.store') }}" class="mt-5 grid gap-4 md:grid-cols-2">@csrf
        <label class="text-sm font-semibold">نام تیم<input name="name" value="{{ old('name') }}" placeholder="مثلاً فروش" required class="mt-2 w-full rounded-xl border border-slate-200 p-3"></label>
        <label class="text-sm font-semibold">روش تقسیم تماس<select name="strategy" class="mt-2 w-full rounded-xl border border-slate-200 p-3">@foreach (\App\Models\CallQueue::STRATEGIES as $value => $label)<option value="{{ $value }}" @selected(old('strategy', 'longest-idle-agent') === $value)>{{ $label }}</option>@endforeach</select></label>
        <label class="text-sm font-semibold">حداکثر زمان انتظار، ثانیه<input type="number" name="max_wait_seconds" min="15" max="600" value="{{ old('max_wait_seconds', 90) }}" required class="mt-2 w-full rounded-xl border border-slate-200 p-3"></label>
        <label class="text-sm font-semibold">اگر کسی پاسخ نداد<select name="fallback_extension_id" class="mt-2 w-full rounded-xl border border-slate-200 p-3"><option value="">پایان تماس</option>@foreach ($extensions as $extension)<option value="{{ $extension->id }}" @selected(old('fallback_extension_id') == $extension->id)>{{ $extension->display_name ?: 'داخلی '.$extension->extension }} · {{ $extension->extension }}</option>@endforeach</select></label>
        <fieldset class="md:col-span-2"><legend class="mb-2 text-sm font-bold">اعضای تیم</legend><div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">@foreach ($extensions as $extension)<label class="flex items-center gap-2 rounded-xl border border-slate-200 p-3 text-sm"><input type="checkbox" name="member_ids[]" value="{{ $extension->id }}" @checked(in_array($extension->id, old('member_ids', [])))>{{ $extension->display_name ?: 'داخلی '.$extension->extension }} · {{ $extension->extension }}</label>@endforeach</div></fieldset>
        <div class="md:col-span-2"><button class="rounded-xl bg-blue-600 px-5 py-3 text-sm font-bold text-white" @disabled($extensions->isEmpty())>ساخت تیم</button></div>
    </form>
</section>
<section class="mt-7 space-y-4" aria-label="تیم‌های موجود">
@forelse ($queues as $queue)
    <details class="panel p-5"><summary class="flex cursor-pointer list-none items-center justify-between gap-3"><span><strong>{{ $queue->name }}</strong><small class="mr-3 text-slate-500">{{ $queue->members->count() }} پاسخ‌گو</small></span><span class="text-xs font-bold {{ $queue->enabled ? 'text-emerald-700' : 'text-slate-400' }}">{{ $queue->enabled ? 'فعال' : 'غیرفعال' }}</span></summary>
        <div class="mt-4 flex flex-wrap gap-2 text-xs"><span class="rounded-full bg-blue-50 px-3 py-1.5 text-blue-700">{{ $queue->waiting_count }} در انتظار</span><span class="rounded-full bg-emerald-50 px-3 py-1.5 text-emerald-700">{{ $queue->available_count }} آماده</span><span class="rounded-full bg-slate-100 px-3 py-1.5 text-slate-700">امروز {{ $queue->answered_today_count }} پاسخ / {{ $queue->missed_today_count }} بی‌پاسخ در تیم</span></div>
        <form method="POST" action="{{ route('teams.update', $queue) }}" class="mt-5 grid gap-4 border-t border-slate-100 pt-5 md:grid-cols-2">@csrf @method('PUT')
            <label class="text-sm font-semibold">نام تیم<input name="name" value="{{ $queue->name }}" required class="mt-2 w-full rounded-xl border border-slate-200 p-3"></label>
            <label class="text-sm font-semibold">روش تقسیم تماس<select name="strategy" class="mt-2 w-full rounded-xl border border-slate-200 p-3">@foreach (\App\Models\CallQueue::STRATEGIES as $value => $label)<option value="{{ $value }}" @selected($queue->strategy === $value)>{{ $label }}</option>@endforeach</select></label>
            <label class="text-sm font-semibold">حداکثر انتظار، ثانیه<input type="number" name="max_wait_seconds" min="15" max="600" value="{{ $queue->max_wait_seconds }}" required class="mt-2 w-full rounded-xl border border-slate-200 p-3"></label>
            <label class="text-sm font-semibold">اگر کسی پاسخ نداد<select name="fallback_extension_id" class="mt-2 w-full rounded-xl border border-slate-200 p-3"><option value="">پایان تماس</option>@foreach ($extensions as $extension)<option value="{{ $extension->id }}" @selected($queue->fallback_extension_id === $extension->id)>{{ $extension->display_name ?: 'داخلی '.$extension->extension }} · {{ $extension->extension }}</option>@endforeach</select></label>
            <fieldset class="md:col-span-2"><legend class="mb-2 text-sm font-bold">اعضای تیم</legend><div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">@foreach ($extensions as $extension)<label class="flex items-center gap-2 rounded-xl border border-slate-200 p-3 text-sm"><input type="checkbox" name="member_ids[]" value="{{ $extension->id }}" @checked($queue->members->contains('id', $extension->id))>{{ $extension->display_name ?: 'داخلی '.$extension->extension }} · {{ $extension->extension }}</label>@endforeach</div></fieldset>
            <label class="flex items-center gap-2 text-sm"><input type="hidden" name="enabled" value="0"><input type="checkbox" name="enabled" value="1" @checked($queue->enabled)>تیم فعال باشد</label>
            <div class="flex items-center justify-end"><button class="rounded-xl bg-blue-600 px-5 py-3 text-sm font-bold text-white">ذخیره تغییرات</button></div>
        </form>
        <form method="POST" action="{{ route('teams.destroy', $queue) }}" class="mt-4" onsubmit="return confirm('تیم حذف شود؟')">@csrf @method('DELETE')<button class="text-xs font-bold text-red-600">حذف تیم</button></form>
    </details>
@empty <div class="panel p-8 text-center text-sm text-slate-500">هنوز تیمی ساخته نشده است.</div>
@endforelse
</section>
@endsection
