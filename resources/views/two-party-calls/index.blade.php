@extends('layouts.portal')
@section('title', 'تماس دوطرفه')
@section('content')
<div data-two-party-page>
    <div class="mb-6"><h1 class="text-2xl font-black">تماس دوطرفه</h1><p class="mt-2 text-sm leading-7 text-slate-500">ابتدا داخلی شما زنگ می‌خورد؛ پس از پاسخ و تأیید، با مشتری تماس می‌گیریم.</p></div>
    @if(session('status'))<p role="status" class="mb-4 rounded-xl bg-blue-50 p-4 text-blue-800">{{ session('status') }}</p>@endif
    @if($errors->any())<div role="alert" class="mb-4 rounded-xl bg-red-50 p-4 text-red-800">{{ $errors->first() }}</div>@endif
    @unless($enabled)<p class="mb-5 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm">این قابلیت در انتظار فعال‌سازی است. تاریخچه تماس‌ها در دسترس است.</p>@endunless
    <div class="grid gap-6 xl:grid-cols-3">
        <form data-call-form action="{{ route('customer.two-party.store') }}" class="rounded-2xl border border-slate-200 bg-white p-5 sm:p-7 xl:col-span-2">
            @csrf
            <h2 class="mb-5 font-bold">تماس جدید</h2>
            <div class="grid gap-5 sm:grid-cols-2">
                <label class="text-sm font-semibold">داخلی پاسخ‌گو<select name="agent_id" required class="mt-2 block w-full rounded-xl border border-slate-200 p-3"><option value="">داخلی را انتخاب کنید</option>@foreach($routes->unique('sip_extension_id') as $route)<option value="{{ $route->sip_extension_id }}">{{ $route->sipExtension->display_name }} · {{ $route->sipExtension->extension }}</option>@endforeach</select></label>
                <label class="text-sm font-semibold">شماره مشتری<input name="customer_number" required type="tel" dir="ltr" autocomplete="tel" placeholder="09123456789" maxlength="40" class="mt-2 block w-full rounded-xl border border-slate-200 p-3"></label>
                <label class="text-sm font-semibold sm:col-span-2">شماره نمایش داده‌شده به مشتری<select name="caller_id" required class="mt-2 block w-full rounded-xl border border-slate-200 p-3"><option value="">ابتدا داخلی را انتخاب کنید</option>@foreach($routes as $route)<option value="{{ $route->sip_number_id }}" data-agent="{{ $route->sip_extension_id }}" hidden disabled>{{ $route->sipNumber->number }}</option>@endforeach</select></label>
            </div>
            <details class="mt-6 rounded-xl bg-slate-50 p-4"><summary class="cursor-pointer text-sm font-bold">تنظیمات تماس</summary><div class="mt-5 grid gap-5 sm:grid-cols-2">@include('two-party-calls.settings-fields', ['values' => $defaults])</div></details>
            <p data-call-error role="alert" class="mt-4 text-sm text-red-700" hidden></p>
            <button type="submit" @disabled(!$enabled || $routes->isEmpty()) class="mt-6 min-h-12 w-full rounded-xl bg-blue-600 px-6 py-3 font-bold text-white disabled:opacity-40 sm:w-auto">شروع تماس</button>
        </form>
        <aside class="rounded-2xl border border-slate-200 bg-white p-5 sm:p-7"><h2 class="font-bold">تماس‌های فعال</h2><p class="mt-2 text-xs leading-6 text-slate-500">وضعیت خودکار به‌روز می‌شود. برای پایان تماس می‌توانید آن را لغو کنید.</p><div data-active-calls class="mt-5 space-y-4">@foreach($active as $call)<div data-call-card data-status-url="{{ route('customer.two-party.show', $call->id) }}" data-cancel-url="{{ route('customer.two-party.cancel', $call->id) }}" class="rounded-xl border border-blue-100 bg-blue-50 p-4"><p class="text-sm font-bold">داخلی {{ $call->agent_label }} ← <bdi>{{ $call->customer_number }}</bdi></p><p data-call-status role="status" class="mt-2 text-sm">{{ \App\Models\OutboundCallSession::LABELS[$call->status] }}</p><button data-call-cancel type="button" class="mt-3 min-h-11 text-sm font-bold text-red-700">لغو تماس</button></div>@endforeach</div><p data-empty-active class="mt-5 text-sm text-slate-400" @if($active->isNotEmpty()) hidden @endif>تماس فعالی ندارید.</p></aside>
    </div>
    @if(auth()->user()->hasPermission('outbound.manage'))
    <details class="mt-6 rounded-2xl border border-slate-200 bg-white p-5"><summary class="cursor-pointer font-bold">پیش‌فرض‌ها و پیام‌های صوتی</summary>
        <form action="{{ route('customer.two-party.settings') }}" method="post" class="mt-5">@csrf @method('PUT')<div class="grid gap-5 sm:grid-cols-2 xl:grid-cols-3">@include('two-party-calls.settings-fields', ['values' => $defaults])</div><button class="mt-5 rounded-xl bg-slate-900 px-5 py-3 text-sm font-bold text-white">ذخیره پیش‌فرض‌ها</button></form>
        <form action="{{ route('customer.two-party.audio') }}" method="post" enctype="multipart/form-data" class="mt-6 grid gap-4 border-t border-slate-100 pt-6 sm:grid-cols-3">@csrf<label class="text-sm">عنوان پیام<input name="name" required maxlength="100" class="mt-2 block w-full rounded-xl border border-slate-200 p-3"></label><label class="text-sm">فایل صوتی، حداکثر ۱۰ مگابایت<input name="audio" type="file" required accept=".wav,.mp3,.m4a" class="mt-2 block w-full text-xs"></label><button class="self-end rounded-xl border border-slate-200 px-5 py-3 text-sm font-bold">افزودن پیام صوتی</button></form>
    </details>
    @endif
    @if(auth()->user()->hasPermission('calls.view'))
    <section class="mt-8"><h2 class="mb-4 text-lg font-bold">تاریخچه تماس دوطرفه</h2><div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white"><table class="w-full whitespace-nowrap text-right text-sm"><thead class="bg-slate-50 text-xs text-slate-500"><tr>@foreach(['زمان', 'داخلی', 'مشتری', 'شناسه تماس‌گیرنده', 'وضعیت', 'پاسخ داخلی / مشتری', 'مدت مکالمه', 'ضبط', 'نتیجه'] as $label)<th class="px-4 py-4">{{ $label }}</th>@endforeach</tr></thead><tbody class="divide-y divide-slate-100">@forelse($sessions as $call)<tr><td class="px-4 py-4"><a class="text-blue-700" href="{{ route('customer.two-party.show', $call->id) }}">{{ $call->created_at->timezone(config('voip.display_timezone'))->format('Y/m/d H:i') }}</a></td><td class="px-4 py-4">{{ $call->agent_label }}</td><td class="px-4 py-4"><bdi>{{ $call->customer_number }}</bdi></td><td class="px-4 py-4"><bdi>{{ $call->caller_id }}</bdi></td><td class="px-4 py-4">{{ \App\Models\OutboundCallSession::LABELS[$call->status] }}</td><td class="px-4 py-4">{{ $call->agent_answered_at ? 'بله' : 'خیر' }} / {{ $call->customer_answered_at ? 'بله' : 'خیر' }}</td><td class="px-4 py-4">{{ $call->conversation_seconds }} ثانیه</td><td class="px-4 py-4">{{ $call->recording?->statusLabel() ?: 'خاموش' }}</td><td class="px-4 py-4">{{ \App\Models\OutboundCallSession::FAILURES[$call->failure_reason] ?? '—' }}</td></tr>@empty<tr><td colspan="9" class="p-8 text-center text-slate-400">هنوز تماسی ثبت نشده است.</td></tr>@endforelse</tbody></table></div><div class="mt-4">{{ $sessions->links() }}</div></section>
    @endif
</div>
@endsection
