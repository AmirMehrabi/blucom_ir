@php
    $targetKey = $followMe->targetKey($target);
    $seconds = is_array($target) && is_scalar($target['seconds'] ?? null) ? $target['seconds'] : 15;
@endphp
<div data-follow-me-row class="grid gap-2 rounded-xl bg-slate-50 p-3 sm:grid-cols-[minmax(0,1fr)_7rem_auto] sm:items-end">
    <label class="min-w-0 text-xs font-semibold">
        مقصد <span data-follow-me-position>{{ $index + 1 }}</span>
        <select name="follow_me_targets[{{ $index }}][destination]" class="{{ $fieldClass }}">
            <option value="">انتخاب مقصد</option>
            @if ($targetKey && !$available->has($targetKey))
                <option value="{{ $targetKey }}" selected>مقصد در دسترس نیست</option>
            @endif
            @foreach (['extension' => 'داخلی‌ها', 'queue' => 'تیم‌های پاسخ‌گویی', 'ivr' => 'منوهای صوتی'] as $type => $label)
                @if ($groups->has($type))
                    <optgroup label="{{ $label }}">
                        @foreach ($groups[$type] as $key => $option)
                            <option value="{{ $key }}" @selected($targetKey === $key)>{{ $followMe->label($option) }}{{ !$followMe->available($option) ? ' (غیرفعال)' : '' }}</option>
                        @endforeach
                    </optgroup>
                @endif
            @endforeach
        </select>
    </label>
    <label data-follow-me-duration class="text-xs font-semibold" @if($targetKey && !str_starts_with($targetKey, 'extension:')) hidden @endif>
        زنگ داخلی (ثانیه)
        <input type="number" min="5" max="30" inputmode="numeric" name="follow_me_targets[{{ $index }}][seconds]" value="{{ $seconds }}" class="{{ $fieldClass }}">
    </label>
    <div class="flex flex-wrap gap-1">
        <button type="button" data-follow-me-move="-1" aria-label="انتقال مقصد به مرحله قبل" class="min-h-11 rounded-lg px-2 text-blue-800 disabled:opacity-30">↑</button>
        <button type="button" data-follow-me-move="1" aria-label="انتقال مقصد به مرحله بعد" class="min-h-11 rounded-lg px-2 text-blue-800 disabled:opacity-30">↓</button>
        <button type="button" data-follow-me-remove class="min-h-11 rounded-lg px-2 text-xs font-bold text-red-700">حذف</button>
    </div>
</div>
