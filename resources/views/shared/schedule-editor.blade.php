@php
    $values = $values ?? [];
    $scheduleRoute = $scheduleRoute ?? null;
    $mode = old('schedule_mode', $values['schedule_mode'] ?? ($scheduleRoute?->schedule ? 'scheduled' : 'anytime'));
    $weekly = old('weekly', $values['weekly'] ?? $scheduleRoute?->schedule['weekly'] ?? array_fill(0, 5, [['start' => '09:00', 'end' => '17:00']]));
    $closedAction = old('closed_action', $values['closed_action'] ?? ($scheduleRoute?->closed_destination_type
        ? (in_array($scheduleRoute->closed_destination_type, ['announcement', 'disconnect']) ? $scheduleRoute->closed_destination_type : $scheduleRoute->closed_destination_type.':'.$scheduleRoute->closed_destination_id) : 'disconnect'));
    $formatter = new \IntlDateFormatter('fa_IR@calendar=persian', 0, 0, 'Asia/Tehran', \IntlDateFormatter::TRADITIONAL, 'yyyy/MM/dd');
    $dates = old('closed_dates', $values['closed_dates'] ?? array_map(fn ($date) => $formatter->format(new \DateTimeImmutable($date.' 12:00:00', new \DateTimeZone('Asia/Tehran'))), $scheduleRoute?->schedule['closed_dates'] ?? []));
    $days = ['شنبه', 'یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنجشنبه', 'جمعه'];
@endphp
<div data-schedule-editor class="space-y-5">
    <fieldset><legend class="mb-3 font-bold">شرایط زمانی تماس ورودی</legend><div class="flex flex-wrap gap-4">
        <label class="rounded-xl border border-slate-200 p-3 text-sm"><input type="radio" name="schedule_mode" value="anytime" @checked($mode === 'anytime')> همیشه به پاسخ‌گو وصل شود</label>
        <label class="rounded-xl border border-slate-200 p-3 text-sm"><input type="radio" name="schedule_mode" value="scheduled" @checked($mode === 'scheduled')> طبق ساعت کاری و تعطیلی‌ها</label>
    </div></fieldset>
    <div data-scheduled-fields @if($mode !== 'scheduled') hidden @endif class="space-y-5 rounded-xl border border-blue-100 bg-blue-50/30 p-4">
        <label class="block text-sm font-bold">منطقه زمانی<input name="timezone" value="{{ old('timezone', $values['timezone'] ?? $scheduleRoute?->schedule['timezone'] ?? 'Asia/Tehran') }}" required dir="ltr" class="mt-2 block w-full rounded-lg border border-slate-200 p-2 sm:w-64"><span class="mt-1 block text-xs font-normal text-slate-500">پیش‌فرض: تهران. ساعت‌ها بر اساس این منطقه ارزیابی می‌شوند.</span></label>
        <div class="divide-y divide-slate-100 rounded-xl bg-white">
            @foreach ($days as $day => $label)
                @php($intervals = $weekly[$day] ?? [])
                <div data-day="{{ $day }}" class="flex flex-wrap items-center gap-3 p-3">
                    <label class="w-24 text-sm font-bold"><input type="checkbox" data-day-enabled @checked(count($intervals) > 0)> {{ $label }}</label>
                    <div class="flex flex-1 flex-wrap gap-2">
                        @foreach (range(0, 2) as $index)
                            <span data-time-pair @if($index >= max(1, count($intervals))) hidden @endif class="inline-flex items-center gap-1">
                                <input type="time" name="weekly[{{ $day }}][{{ $index }}][start]" value="{{ $intervals[$index]['start'] ?? '09:00' }}" aria-label="شروع {{ $label }}" dir="ltr" class="w-28 rounded-lg border border-slate-200 p-2 text-xs">
                                <span>تا</span><input type="time" name="weekly[{{ $day }}][{{ $index }}][end]" value="{{ $intervals[$index]['end'] ?? '17:00' }}" aria-label="پایان {{ $label }}" dir="ltr" class="w-28 rounded-lg border border-slate-200 p-2 text-xs">
                                <button type="button" data-remove-interval aria-label="حذف بازه {{ $label }}" class="px-2 text-slate-500">×</button>
                            </span>
                        @endforeach
                    </div>
                    <button type="button" data-add-interval class="text-xs font-bold text-blue-700">+ بازه</button>
                </div>
            @endforeach
        </div>
        <div class="space-y-3 rounded-xl bg-white p-3 text-sm"><label>کپی ساعت‌های <select data-copy-source class="rounded-lg border border-slate-200 p-2">@foreach($days as $day => $label)<option value="{{ $day }}">{{ $label }}</option>@endforeach</select> به روزهای انتخاب‌شده:</label><div class="flex flex-wrap gap-3">@foreach($days as $day => $label)<label><input type="checkbox" data-copy-target="{{ $day }}"> {{ $label }}</label>@endforeach</div><button type="button" data-copy-hours class="font-bold text-blue-700">کپی ساعت‌ها</button></div>
        <fieldset><legend class="font-bold">تعطیلی‌های ویژه</legend><p class="mt-1 text-xs text-slate-500">تاریخ جلالی؛ در این روزها اقدام ساعات بسته اجرا می‌شود. حداکثر ۳۰ تاریخ.</p>
            <div data-closed-dates class="mt-3 flex flex-wrap gap-2">@foreach($dates as $date)<span data-date-entry class="inline-flex gap-1"><input name="closed_dates[]" value="{{ $date }}" placeholder="۱۴۰۵/۰۷/۰۷" aria-label="تاریخ تعطیلی جلالی" dir="ltr" class="w-36 rounded-lg border border-slate-200 p-2 text-sm"><button type="button" data-calendar aria-label="تقویم جلالی" class="text-blue-700">▦</button><button type="button" data-remove-date aria-label="حذف تعطیلی">×</button></span>@endforeach</div>
            <template data-date-template><span data-date-entry class="inline-flex gap-1"><input name="closed_dates[]" placeholder="۱۴۰۵/۰۷/۰۷" aria-label="تاریخ تعطیلی جلالی" dir="ltr" class="w-36 rounded-lg border border-slate-200 p-2 text-sm"><button type="button" data-calendar aria-label="تقویم جلالی" class="text-blue-700">▦</button><button type="button" data-remove-date aria-label="حذف تعطیلی">×</button></span></template>
            <button type="button" data-add-date class="mt-3 text-sm font-bold text-blue-700">+ تعطیلی</button>
        </fieldset>
        <label class="block text-sm font-bold">خارج از ساعت کاری چه اتفاقی بیفتد؟<select name="closed_action" data-closed-action class="mt-2 w-full rounded-lg border border-slate-200 bg-white p-3">
            <option value="disconnect" @selected($closedAction === 'disconnect')>پایان تماس بدون پیام</option><option value="announcement" @selected($closedAction === 'announcement')>پخش پیام، سپس پایان تماس</option>
            @include('shared.destination-options', ['selected' => $closedAction])
        </select></label>
        <div data-announcement @if($closedAction !== 'announcement') hidden @endif><label class="block text-sm font-bold">پیام ساعات بسته<input type="file" name="announcement" accept="audio/wav,audio/mpeg,audio/mp4,audio/x-m4a,audio/webm" class="mt-2 block w-full font-normal"></label><p class="mt-2 text-xs text-slate-500">WAV، MP3 یا M4A، حداکثر ۱۰ مگابایت؛ تا ۶۰ ثانیه پخش می‌شود.</p>
            @if($scheduleRoute?->closed_announcement_path && isset($announcementUrl))<audio controls preload="none" src="{{ $announcementUrl }}" class="mt-3 w-full"></audio>@endif
            @if($hasDraftAnnouncement ?? false)<p class="mt-2 text-xs text-emerald-700">فایل پیام در پیش‌نویس ذخیره شده است.</p>@endif
        </div>
        <p data-disconnect-hint @if($closedAction !== 'disconnect') hidden @endif class="text-xs text-amber-800">تماس بدون پخش پیام پایان می‌یابد.</p>
    </div>
</div>
@once
    @include('shared.schedule-editor-script')
@endonce
