@extends('layouts.portal')
@section('title', 'تماس ورودی و شرایط زمانی')
@section('content')
<div class="mx-auto max-w-4xl space-y-5">
    <a href="{{ route('admin.sip-numbers.setup', ['sip_number' => $number->id, 'tab' => 'inbound']) }}" class="text-sm font-bold text-blue-700">← بازگشت به شماره</a>
    <div><h1 class="text-2xl font-black">تماس ورودی و شرایط زمانی</h1><p class="mt-2 text-sm text-slate-600"><span dir="ltr">{{ $number->normalized_number }}</span> · مالک: {{ $tenant->name }}</p></div>
    @if($errors->any())<div role="alert" class="rounded-xl bg-red-50 p-4 text-sm text-red-800">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
    <form method="POST" enctype="multipart/form-data" action="{{ route('admin.sip-numbers.inbound.update', $number) }}" class="panel space-y-6 p-5 sm:p-7">
        @csrf @method('PUT')
        <label class="block text-sm font-bold">پاسخ‌گوی ساعات باز<select name="destination_choice" required class="mt-2 w-full rounded-xl border border-slate-200 bg-white p-3">@include('shared.destination-options', ['selected' => old('destination_choice', $route ? $route->destination_type.':'.$route->destination_id : '')])</select></label>
        @include('shared.schedule-editor', ['scheduleRoute' => $route, 'announcementUrl' => route('admin.sip-numbers.announcement', $number)])
        <label class="block rounded-xl border border-slate-200 p-4 text-sm"><input type="hidden" name="enabled" value="0"><input type="checkbox" name="enabled" value="1" @checked(old('enabled', $route?->enabled ?? true))> <strong>تماس‌های این شماره به مسیر ورودی هدایت شوند</strong><span class="mt-1 block text-xs text-slate-500">اگر خاموش باشد، مقصد و زمان‌بندی ذخیره می‌ماند ولی تماس‌ها به آن هدایت نمی‌شوند.</span></label>
        <div class="flex justify-between border-t border-slate-100 pt-5"><a href="{{ route('admin.sip-numbers.setup', $number) }}" class="p-3 text-sm text-slate-600">انصراف</a><button class="rounded-xl bg-blue-600 px-6 py-3 text-sm font-bold text-white">ذخیره مقصد و زمان‌بندی</button></div>
    </form>
</div>
@endsection
