@php
    $settings = $source->follow_me ?? [];
    $formKey = $source->getTable().':'.$source->id;
    $retry = old('follow_me_form') === $formKey;
    $active = $retry ? (bool) old('follow_me_enabled') : ($settings['enabled'] ?? false);
    $targets = $retry ? old('follow_me_targets', []) : ($settings['targets'] ?? []);
    $targets = is_array($targets) ? $targets : [];
    $fieldClass = 'mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm';
    $formErrors = $retry ? array_merge(...array_values(session('follow_me_errors', []))) : [];
@endphp
<form method="POST" action="{{ $action }}" class="space-y-4" data-follow-me>
    @csrf @method('PUT')
    <input type="hidden" name="follow_me_form" value="{{ $formKey }}">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h2 class="font-bold">{{ $isQueue ? 'مسیر پشتیبان تیم' : 'دنبال‌کننده تماس' }}</h2>
        <label class="inline-flex min-h-11 items-center gap-2 text-sm font-bold">
            <input type="hidden" name="follow_me_enabled" value="0">
            <input type="checkbox" name="follow_me_enabled" value="1" @checked($active) class="h-4 w-4 accent-blue-600">
            {{ $isQueue ? 'مسیر پشتیبان فعال باشد' : 'دنبال‌کننده فعال باشد' }}
        </label>
    </div>
    <p class="text-sm leading-7 text-slate-600">
        @if ($isQueue)
            اگر زمان انتظار تمام شود یا پاسخ‌گویی در دسترس نباشد، داخلی‌های زیر به‌ترتیب زنگ می‌خورند. وقتی این مسیر فعال است، داخلی تکیِ بخش «پس از پایان انتظار» کنار گذاشته می‌شود. اگر اعلان یا صندوق صوتی انتخاب کرده‌اید، پس از بی‌پاسخ ماندن همه مقصدها اجرا می‌شود.
        @else
            ابتدا این داخلی {{ \App\Services\FollowMeService::PRIMARY_SECONDS }} ثانیه زنگ می‌خورد. اگر پاسخ ندهد، اشغال باشد یا دریافت تماس را متوقف کرده باشد، تماس به‌ترتیب به داخلی‌های زیر می‌رود.
        @endif
    </p>
    @if (session('follow_me_saved') === $source->id)
        <p role="status" class="rounded-xl bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-800">مسیر پشتیبان ذخیره شد.</p>
    @endif
    @if ($formErrors)
        <ul role="alert" class="list-disc rounded-xl bg-red-50 py-3 pl-4 pr-8 text-sm leading-7 text-red-800">
            @foreach (array_unique($formErrors) as $message)<li>{{ $message }}</li>@endforeach
        </ul>
    @endif
    @if ($followMeExtensions->where('enabled', true)->isEmpty())
        <p class="rounded-xl bg-slate-50 p-3 text-sm text-slate-700">برای انتخاب مقصد پشتیبان، ابتدا <a class="font-bold text-blue-700 underline" href="{{ route('customer.extensions.index') }}">یک داخلی دیگر</a> فعال کنید.</p>
    @endif
    <fieldset class="space-y-2">
        <legend class="mb-2 text-sm font-bold">ترتیب زنگ مقصدها</legend>
        @for ($index = 0; $index < \App\Services\FollowMeService::MAX_TARGETS; $index++)
            @php
                $target = is_array($targets[$index] ?? null) ? $targets[$index] : [];
                $targetId = is_scalar($target['id'] ?? null) ? $target['id'] : null;
                $seconds = is_scalar($target['seconds'] ?? null) ? $target['seconds'] : 15;
            @endphp
            <div data-follow-me-row class="grid grid-cols-[auto_minmax(0,1fr)_5.5rem] items-end gap-2 rounded-xl bg-slate-50 p-3 sm:gap-3">
                <span class="mb-2.5 rounded-lg bg-white px-2.5 py-2 text-xs font-bold text-slate-700">{{ $index + 1 }}</span>
                <label class="min-w-0 text-xs font-semibold">
                    <span class="sr-only">مرحله {{ $index + 1 }}</span> داخلی
                    <select name="follow_me_targets[{{ $index }}][id]" class="{{ $fieldClass }}">
                        <option value="">بدون مقصد</option>
                        @foreach ($followMeExtensions as $option)
                            <option value="{{ $option->id }}" @selected($targetId == $option->id)>
                                {{ $option->display_name ?: 'داخلی' }} · {{ $option->extension }}{{ !$option->enabled ? ' (غیرفعال)' : ($option->paused_at ? ' (دریافت متوقف)' : '') }}
                            </option>
                        @endforeach
                    </select>
                </label>
                <label class="text-xs font-semibold">
                    <span class="sr-only">مرحله {{ $index + 1 }}</span> زمان (ثانیه)
                    <input type="number" inputmode="numeric" min="5" max="30" name="follow_me_targets[{{ $index }}][seconds]"
                        value="{{ $seconds }}" class="{{ $fieldClass }}">
                </label>
            </div>
        @endfor
    </fieldset>
    <p data-follow-me-summary role="status" class="text-sm font-semibold text-slate-700"></p>
    <div class="flex flex-wrap items-center justify-between gap-3">
        <p class="text-xs leading-6 text-slate-600">داخلی‌های غیرفعال یا متوقف رد می‌شوند. با اولین پاسخ، مسیر پایان می‌یابد.</p>
        <button class="commerce-button" type="submit">ذخیره مسیر پشتیبان</button>
    </div>
</form>
