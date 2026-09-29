@extends('layouts.customer')
@section('title', 'راه‌اندازی کامل خط')
@section('content')
@php
    $hasProvider = $gateway !== null;
    $hasNumber = $number !== null;
    $configured = $hasProvider && $hasNumber && $answerReady && $outboundReady;
    $needsCorrection = $gateway?->verification_status === 'rejected' || $number?->status === 'disabled';
    $awaitingApproval = $configured && ! $needsCorrection && (! $providerApproved || ! $numberApproved);
    $canTest = $configured && ! $needsCorrection && $providerApproved && $numberApproved
        && $gateway?->approved_for_outbound && config('voip.gateway_xml_enabled');
    $answerLabels = ['person' => 'یک نفر', 'team' => 'یک تیم', 'menu' => 'منوی تماس'];
    $canChooseAnswer = $wizard->answer_type === 'person'
        || ($wizard->answer_type === 'team' && $queues->contains(fn ($queue) => $queue->enabled && $queue->members_count > 0))
        || ($wizard->answer_type === 'menu' && $menus->contains(fn ($menu) => $menu->isPublished()));
@endphp
<div id="start" class="mx-auto max-w-4xl scroll-mt-24">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div><span class="mb-3 inline-block rounded-full bg-blue-50 px-3 py-1 text-xs font-bold text-blue-700">شروع آسان</span><h1 class="text-2xl font-black text-[#071a3b]">راه‌اندازی کامل خط</h1><p class="mt-2 max-w-2xl text-sm leading-7 text-slate-600">اتصال، شماره و پاسخ‌گو را مرحله‌به‌مرحله تنظیم کنید. می‌توانید هر زمان برگردید و از همین‌جا ادامه دهید.</p></div>
        <a href="{{ route('customer.setup.lines') }}" class="rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-xs font-bold text-slate-600">خط‌های من</a>
    </div>

    @if ($needsCorrection)
        <div class="mt-6 rounded-2xl border border-red-200 bg-red-50 p-5 text-sm text-red-900"><strong class="block text-base">اطلاعات خط نیازمند اصلاح است</strong><p class="mt-1 leading-6">اتصال یا شماره رد شده است. مورد مشخص‌شده در مراحل زیر را اصلاح و دوباره ارسال کنید.</p></div>
    @elseif ($canTest)
        <div class="mt-6 rounded-2xl border border-emerald-200 bg-emerald-50 p-5 text-sm text-emerald-900"><strong class="block text-base">تنظیمات آماده آزمایش است</strong><p class="mt-1 leading-6">یک تماس ورودی و خروجی آزمایشی انجام دهید. این صفحه وضعیت ثبت تلفن یا برقراری تماس را زنده تأیید نمی‌کند.</p></div>
    @elseif ($awaitingApproval)
        <div class="mt-6 rounded-2xl border border-amber-200 bg-amber-50 p-5 text-sm text-amber-900"><strong class="block text-base">تنظیمات شما کامل است؛ در انتظار تأیید</strong><p class="mt-1 leading-6">اتصال ارائه‌دهنده و شماره پس از بررسی فعال می‌شوند. تا آن زمان تماس را آماده استفاده در نظر نگیرید.</p></div>
    @elseif ($configured)
        <div class="mt-6 rounded-2xl border border-amber-200 bg-amber-50 p-5 text-sm text-amber-900"><strong class="block text-base">فعال‌سازی اتصال هنوز تأیید نشده است</strong><p class="mt-1 leading-6">پیکربندی در پایگاه داده ذخیره شده است، اما اتصال FreeSWITCH باید جداگانه بررسی و فعال شود.</p></div>
    @elseif ($answerReady && ! $outboundReady)
        <div class="mt-6 rounded-2xl border border-blue-200 bg-blue-50 p-5 text-sm text-blue-900"><strong class="block text-base">مسیر ورودی تنظیم شده است</strong><p class="mt-1 leading-6">شماره تماس خروجی تلفن‌های پاسخ‌گو را در مرحله آخر بررسی کنید.</p></div>
    @endif

    <div class="mt-7 space-y-4">
        <section class="panel p-5 sm:p-6" aria-labelledby="wizard-answer-type">
            <div class="flex items-start gap-3"><span class="grid size-8 shrink-0 place-items-center rounded-full bg-blue-600 text-sm font-black text-white">۱</span><div class="flex-1"><h2 id="wizard-answer-type" class="font-black">چه کسی تماس‌ها را پاسخ می‌دهد؟</h2><p class="mt-1 text-xs leading-6 text-slate-500">انتخاب شما مراحل بعدی را مشخص می‌کند.</p></div>@if ($wizard->answer_type)<span class="rounded-full bg-emerald-50 px-3 py-1 text-xs font-bold text-emerald-700">{{ $answerLabels[$wizard->answer_type] }}</span>@endif</div>
            <form method="POST" action="{{ route('customer.setup.wizard.answer') }}" class="mt-5 grid gap-3 sm:grid-cols-3">
                @csrf
                <label class="cursor-pointer rounded-xl border border-slate-200 p-4 text-sm has-[:checked]:border-blue-500 has-[:checked]:bg-blue-50"><input type="radio" name="answer_type" value="person" @checked($wizard->answer_type === 'person') class="ml-2"> <strong>یک نفر</strong><small class="mt-2 block leading-5 text-slate-500">یک تلفن یا داخلی پاسخ می‌دهد.</small></label>
                <label class="cursor-pointer rounded-xl border border-slate-200 p-4 text-sm has-[:checked]:border-blue-500 has-[:checked]:bg-blue-50 {{ config('voip.queues_enabled') ? '' : 'opacity-50' }}"><input type="radio" name="answer_type" value="team" @checked($wizard->answer_type === 'team') @disabled(! config('voip.queues_enabled')) class="ml-2"> <strong>یک تیم</strong><small class="mt-2 block leading-5 text-slate-500">{{ config('voip.queues_enabled') ? 'تماس بین اعضای تیم توزیع می‌شود.' : 'این قابلیت هنوز فعال نیست.' }}</small></label>
                <label class="cursor-pointer rounded-xl border border-slate-200 p-4 text-sm has-[:checked]:border-blue-500 has-[:checked]:bg-blue-50"><input type="radio" name="answer_type" value="menu" @checked($wizard->answer_type === 'menu') class="ml-2"> <strong>منوی تماس</strong><small class="mt-2 block leading-5 text-slate-500">تماس‌گیرنده با کلید مقصد را انتخاب می‌کند.</small></label>
                <div class="sm:col-span-3"><button class="rounded-xl bg-blue-600 px-5 py-2.5 text-xs font-bold text-white">ذخیره انتخاب</button></div>
            </form>
        </section>

        <section class="panel p-5 sm:p-6" aria-labelledby="wizard-provider">
            <div class="flex items-start gap-3"><span class="grid size-8 shrink-0 place-items-center rounded-full {{ $hasProvider ? 'bg-emerald-100 text-emerald-700' : 'bg-blue-600 text-white' }} text-sm font-black">۲</span><div class="flex-1"><h2 id="wizard-provider" class="font-black">اتصال ارائه‌دهنده</h2><p class="mt-1 text-xs leading-6 text-slate-500">اطلاعات اتصال را از شرکت ارائه‌دهنده خط بگیرید.</p></div>@if ($gateway)<span class="rounded-full px-3 py-1 text-xs font-bold {{ $providerApproved ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-800' }}">{{ $providerApproved ? 'تأیید شده' : ($gateway->verification_status === 'rejected' ? 'نیازمند اصلاح' : 'در انتظار بررسی') }}</span>@endif</div>
            @if ($gateways->isNotEmpty())
                <form method="POST" action="{{ route('customer.setup.wizard.gateway') }}" class="mt-5 flex flex-wrap items-end gap-3">@csrf<label class="min-w-[220px] flex-1 text-xs font-bold text-slate-700">اتصال موجود<select name="gateway_id" required class="mt-2 w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm"><option value="">انتخاب اتصال</option>@foreach($gateways as $item)<option value="{{ $item->id }}" @selected($gateway?->id === $item->id)>{{ $item->display_name ?: $item->provider_name ?: $item->name }} · {{ $item->verification_status === 'approved' ? 'تأیید شده' : ($item->verification_status === 'rejected' ? 'نیازمند اصلاح' : 'در انتظار بررسی') }}</option>@endforeach</select></label><button class="rounded-xl border border-blue-200 px-4 py-2.5 text-xs font-bold text-blue-700">انتخاب اتصال</button></form>
            @endif
            <a href="{{ route('customer.setup.provider', ['wizard' => 1]).'#new-provider' }}" class="mt-4 inline-flex rounded-xl bg-blue-600 px-4 py-2.5 text-xs font-bold text-white">{{ $gateway?->verification_status === 'rejected' ? 'اصلاح یا افزودن اتصال' : 'افزودن اتصال جدید' }}</a>
            @if ($gateway?->verification_status === 'rejected')<a href="{{ route('customer.setup.provider', ['edit' => $gateway->id, 'wizard' => 1]) }}" class="mr-3 text-xs font-bold text-blue-700">اصلاح اتصال انتخاب‌شده</a>@endif
        </section>

        <section class="panel p-5 sm:p-6" aria-labelledby="wizard-number">
            <div class="flex items-start gap-3"><span class="grid size-8 shrink-0 place-items-center rounded-full {{ $hasNumber ? 'bg-emerald-100 text-emerald-700' : 'bg-blue-600 text-white' }} text-sm font-black">۳</span><div class="flex-1"><h2 id="wizard-number" class="font-black">شماره خط</h2><p class="mt-1 text-xs leading-6 text-slate-500">شماره را به اتصال انتخاب‌شده پیوند دهید.</p></div>@if ($number)<span class="rounded-full px-3 py-1 text-xs font-bold {{ $numberApproved ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-800' }}">{{ $numberApproved ? 'تأیید شده' : $number->statusLabel() }}</span>@endif</div>
            @if (! $gateway)<p class="mt-5 text-sm text-slate-500">ابتدا یک اتصال ارائه‌دهنده انتخاب کنید.</p>@else
                @if ($numbers->isNotEmpty())<form method="POST" action="{{ route('customer.setup.wizard.number') }}" class="mt-5 flex flex-wrap items-end gap-3">@csrf<label class="min-w-[220px] flex-1 text-xs font-bold text-slate-700">شماره موجود<select name="number_id" required class="mt-2 w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm"><option value="">انتخاب شماره</option>@foreach($numbers as $item)<option value="{{ $item->id }}" @selected($number?->id === $item->id)>{{ $item->number }} · {{ $item->statusLabel() }}</option>@endforeach</select></label><button class="rounded-xl border border-blue-200 px-4 py-2.5 text-xs font-bold text-blue-700">انتخاب شماره</button></form>@endif
                <a href="{{ route('customer.setup.number', ['wizard' => 1]).'#new-number' }}" class="mt-4 inline-flex rounded-xl bg-blue-600 px-4 py-2.5 text-xs font-bold text-white">افزودن شماره جدید</a>
                @if ($number?->status === 'disabled')<a href="{{ route('customer.setup.numbers.edit', ['number' => $number->id, 'wizard' => 1]) }}" class="mr-3 text-xs font-bold text-blue-700">اصلاح شماره انتخاب‌شده</a>@endif
            @endif
        </section>

        <section class="panel p-5 sm:p-6" aria-labelledby="wizard-destination">
            <div class="flex items-start gap-3"><span class="grid size-8 shrink-0 place-items-center rounded-full {{ $answerReady ? 'bg-emerald-100 text-emerald-700' : 'bg-blue-600 text-white' }} text-sm font-black">۴</span><div class="flex-1"><h2 id="wizard-destination" class="font-black">تنظیم پاسخ‌گو</h2><p class="mt-1 text-xs leading-6 text-slate-500">{{ $wizard->answer_type === 'team' ? 'اعضای تیم باید تلفن فعال داشته باشند.' : ($wizard->answer_type === 'menu' ? 'منو باید تنظیم و منتشر شود.' : 'می‌توانید تلفن جدید بسازید یا تلفن موجود را انتخاب کنید.') }}</p></div>@if ($answerReady)<span class="rounded-full bg-emerald-50 px-3 py-1 text-xs font-bold text-emerald-700">تنظیم شده</span>@endif</div>
            @if (! $wizard->answer_type || ! $number)<p class="mt-5 text-sm text-slate-500">نوع پاسخ‌گو و شماره خط را انتخاب کنید تا این مرحله باز شود.</p>@else
                @if (in_array($wizard->answer_type, ['team', 'menu']))
                    <div class="mt-5 rounded-xl border border-slate-200 p-4"><p class="text-sm font-bold">تلفن‌های پاسخ‌گو</p><p class="mt-1 text-xs leading-6 text-slate-500">{{ $extensions->isEmpty() ? 'برای عضو تیم یا مقصد منو، ابتدا یک تلفن بسازید.' : $extensions->count().' تلفن فعال در دسترس است. در صورت نیاز، تلفن دیگری اضافه کنید.' }}</p><form method="POST" action="{{ route('customer.setup.wizard.phone') }}" class="mt-3 flex flex-wrap items-end gap-2">@csrf<label class="min-w-[180px] flex-1 text-xs font-bold">نام پاسخ‌گو<input name="display_name" value="{{ old('display_name') }}" required maxlength="100" placeholder="مثلاً سارا احمدی" class="mt-2 w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm"></label><button class="rounded-xl border border-blue-200 px-4 py-2.5 text-xs font-bold text-blue-700">ساخت تلفن</button></form></div>
                @endif
                @if ($wizard->answer_type === 'team')
                    <p class="mt-5 text-sm leading-6 text-slate-600">{{ $queues->isEmpty() ? 'ابتدا داخلی‌های اعضا و یک تیم پاسخ‌گویی بسازید.' : 'تیم آماده را به این شماره وصل کنید.' }}</p>
                    <a href="{{ route('teams.index', ['wizard' => 1]) }}" class="mt-3 inline-flex rounded-xl border border-blue-200 px-4 py-2.5 text-xs font-bold text-blue-700">مدیریت تیم‌ها</a>
                @elseif ($wizard->answer_type === 'menu')
                    <p class="mt-5 text-sm leading-6 text-slate-600">{{ $menus->whereNotNull('published_config')->isEmpty() ? 'منوی تماس را بسازید، مقصد کلیدها را تعیین کنید و منتشر کنید.' : 'منوی منتشرشده را به این شماره وصل کنید.' }}</p>
                    <a href="{{ route('ivr-menus.index', ['wizard' => 1]) }}" class="mt-3 inline-flex rounded-xl border border-blue-200 px-4 py-2.5 text-xs font-bold text-blue-700">مدیریت منوهای تماس</a>
                @endif
                @if ($canChooseAnswer)<a href="{{ route('customer.setup.answer', ['number' => $number->id, 'wizard' => 1]) }}" class="mt-3 inline-flex rounded-xl bg-blue-600 px-4 py-2.5 text-xs font-bold text-white">{{ $answerReady ? 'تغییر پاسخ‌گو' : 'انتخاب پاسخ‌گو برای شماره' }}</a>@else<p class="mt-3 text-xs font-bold text-amber-800">برای ادامه، {{ $wizard->answer_type === 'team' ? 'یک تیم فعال با عضو تلفنی' : 'یک منوی منتشرشده' }} آماده کنید.</p>@endif
                @if ($route?->destination && ! $answerReady)<p class="mt-3 text-xs text-amber-800">مقصد فعلی «{{ $route->destinationLabel() }}» با نوع انتخاب‌شده در مرحله ۱ آماده نیست.</p>@endif
            @endif
        </section>

        <section class="panel p-5 sm:p-6" aria-labelledby="wizard-review"><div class="flex items-start gap-3"><span class="grid size-8 shrink-0 place-items-center rounded-full bg-slate-100 text-sm font-black text-slate-600">۵</span><div class="flex-1"><h2 id="wizard-review" class="font-black">بررسی و آزمایش</h2><p class="mt-1 text-xs leading-6 text-slate-500">خلاصه مسیر تماس را پیش از آزمایش بررسی کنید.</p></div></div>
            <dl class="mt-5 divide-y divide-slate-100 rounded-xl border border-slate-100 px-4 text-sm"><div class="flex justify-between gap-4 py-3"><dt class="text-slate-500">ارائه‌دهنده</dt><dd class="font-bold">{{ $gateway?->display_name ?: ($gateway?->provider_name ?: ($gateway?->name ?: 'انتخاب نشده')) }}</dd></div><div class="flex justify-between gap-4 py-3"><dt class="text-slate-500">شماره خط</dt><dd class="font-bold" dir="ltr">{{ $number?->number ?: 'انتخاب نشده' }}</dd></div><div class="flex justify-between gap-4 py-3"><dt class="text-slate-500">مسیر تماس ورودی</dt><dd class="font-bold">{{ $answerReady ? $route->destinationLabel() : 'هنوز آماده نیست' }}</dd></div><div class="flex justify-between gap-4 py-3"><dt class="text-slate-500">تماس خروجی</dt><dd class="font-bold">{{ $outboundReady ? 'از شماره همین خط' : 'نیازمند بررسی' }}</dd></div></dl>
            @if ($answerReady && $number?->outbound_enabled)
                <div class="mt-4 rounded-xl border border-slate-200 p-4"><h3 class="text-sm font-bold">شماره نمایش‌داده‌شده در تماس خروجی</h3><p class="mt-1 text-xs leading-6 text-slate-500">برای هر تلفن پاسخ‌گو، این شماره را به‌عنوان شماره تماس خروجی انتخاب کنید. تغییر تلفنی که از قبل شماره دیگری دارد، با انتخاب شما انجام می‌شود.</p><div class="mt-3 space-y-2">@foreach ($participants as $extension)<div class="flex flex-wrap items-center justify-between gap-3 rounded-lg bg-slate-50 p-3"><span class="text-xs font-bold">{{ $extension->display_name ?: 'تلفن '.$extension->extension }} · <span dir="ltr">{{ $extension->extension }}</span></span>@if ($outboundRoutes->has($extension->id))<span class="text-xs font-bold text-emerald-700">از این خط</span>@else<form method="POST" action="{{ route('customer.setup.wizard.outbound', $extension->id) }}">@csrf<button class="text-xs font-bold text-blue-700">استفاده از این شماره</button></form>@endif</div>@endforeach</div></div>
            @elseif ($answerReady && ! $number?->outbound_enabled)<p class="mt-4 rounded-xl bg-amber-50 p-4 text-xs text-amber-900">تماس خروجی برای این شماره غیرفعال است.</p>@endif
            @if ($answerReady && $route->destination_type === 'extension')<a href="{{ route('customer.setup.phone', ['extension' => $route->destination->id, 'wizard' => 1]) }}" class="mt-4 inline-flex rounded-xl border border-blue-200 px-4 py-2.5 text-xs font-bold text-blue-700">راهنمای اتصال تلفن</a>@endif
            @if ($canTest)<p class="mt-4 rounded-xl bg-emerald-50 p-4 text-xs leading-6 text-emerald-900">۱. تلفن را ثبت کنید. ۲. با شماره خط تماس ورودی بگیرید. ۳. از تلفن تماس خروجی بگیرید و شماره نمایش‌داده‌شده را بررسی کنید. نتیجه تماس را در «تماس‌ها» ببینید.</p>@elseif ($configured)<p class="mt-4 rounded-xl bg-amber-50 p-4 text-xs leading-6 text-amber-900">برای آزمایش واقعی، تأیید اتصال و شماره و فعال بودن پیکربندی در FreeSWITCH لازم است.</p>@endif
        </section>
    </div>
</div>
@endsection
