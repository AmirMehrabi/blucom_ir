@php
    $followMe = app(\App\Services\FollowMeService::class);
    $settings = $source->follow_me ?? [];
    $sourceKey = $followMe->key($source);
    $formKey = $source->getTable().':'.$source->id;
    $retry = old('follow_me_form') === $formKey;
    $active = $retry ? (bool) old('follow_me_enabled') : ($settings['enabled'] ?? false);
    $targets = $retry ? old('follow_me_targets', []) : ($settings['targets'] ?? []);
    $targets = is_array($targets) ? array_slice(array_values($targets), 0, $followMe::MAX_TARGETS) : [];
    $fieldClass = 'mt-1 min-h-11 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm';
    $formErrors = $retry ? array_unique(array_merge(...array_values(session('follow_me_errors', [])))) : [];
    $options = $followMeCatalog->except($sourceKey);
    $savedKeys = collect($settings['targets'] ?? [])->map($followMe->targetKey(...))->all();
    $available = $options->filter(fn ($option, $key) => $followMe->available($option) || in_array($key, $savedKeys, true));
    $groups = $available->groupBy(fn ($option, $key) => explode(':', $key)[0], preserveKeys: true);
@endphp
<form method="POST" action="{{ $action }}" class="space-y-4" data-follow-me data-limit="{{ $followMe::MAX_TARGETS }}">
    @csrf @method('PUT')
    <input type="hidden" name="follow_me_form" value="{{ $formKey }}">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h2 class="font-bold">مسیر پشتیبان</h2>
        <label class="inline-flex min-h-11 items-center gap-2 text-sm font-bold">
            <input type="hidden" name="follow_me_enabled" value="0">
            <input type="checkbox" name="follow_me_enabled" value="1" @checked($active) class="size-4 accent-blue-600">
            مسیر پشتیبان فعال باشد
        </label>
    </div>
    <p class="text-sm leading-7 text-slate-600">
        {{ $isQueue ? 'اگر انتظار در صف تمام شود یا پاسخ‌گویی در دسترس نباشد، تماس به مقصدهای زیر می‌رود.' : 'اگر این داخلی در دسترس نباشد یا پس از ۱۵ ثانیه پاسخ ندهد، تماس به مقصدهای زیر می‌رود.' }}
        مسیر هر مقصد پیش از رفتن به مرحله بعدی اجرا می‌شود.
        @if ($isQueue && $source->fallback_extension_id)
            با فعال‌سازی این مسیر، مقصد تکیِ «پس از پایان انتظار» کنار گذاشته می‌شود.
        @endif
    </p>
    @if (session('follow_me_saved') === $source->id)
        <p role="status" class="rounded-xl bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-800">مسیر پشتیبان ذخیره شد.</p>
    @endif
    @if ($formErrors)
        <ul role="alert" class="list-disc rounded-xl bg-red-50 py-3 pl-4 pr-8 text-sm leading-7 text-red-800">
            @foreach ($formErrors as $message)<li>{{ $message }}</li>@endforeach
        </ul>
    @endif
    @if ($available->isEmpty())
        <p class="rounded-xl bg-slate-50 p-3 text-sm text-slate-700">ابتدا یک داخلی، تیم یا منوی صوتی فعال در همین مجموعه آماده کنید.</p>
    @endif
    <fieldset class="space-y-2">
        <legend class="mb-2 text-sm font-bold">ترتیب مقصدها</legend>
        <div class="space-y-2" data-follow-me-rows>
            @foreach ($targets ?: [[]] as $index => $target)
                @include('shared.follow-me-row')
            @endforeach
        </div>
        <template data-follow-me-template>
            @include('shared.follow-me-row', ['target' => [], 'index' => 0])
        </template>
    </fieldset>
    <div class="flex flex-wrap items-center gap-3">
        <button type="button" data-follow-me-add class="commerce-secondary" @disabled($available->isEmpty())>＋ افزودن مقصد</button>
        <p data-follow-me-summary role="status" class="text-xs text-slate-600"></p>
    </div>
    @if ($settings['enabled'] ?? false)
        <details class="rounded-xl border border-slate-200 p-3 text-sm">
            <summary class="cursor-pointer font-bold">نمای مسیر ذخیره‌شده</summary>
            @php
                $remaining = (object) ["nodes" => 100];
            @endphp
            @include('shared.follow-me-tree', ['keys' => $followMe->edges($source), 'path' => [$sourceKey]])
        </details>
    @endif
    <div class="flex flex-wrap items-center justify-between gap-3">
        <p class="text-xs leading-6 text-slate-600">{{ $isQueue && $source->fallback_audio_destination ? 'اعلان یا صندوق صوتی تیم پس از پایان مسیر پشتیبان اجرا می‌شود.' : 'با پاسخ به تماس، مسیر پایان می‌یابد.' }}</p>
        <button class="commerce-button" type="submit">ذخیره مسیر پشتیبان</button>
    </div>
</form>
