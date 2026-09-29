@extends('layouts.customer')
@section('title', 'تعیین پاسخ‌گو')
@section('content')
<div class="mx-auto max-w-3xl">
    <p class="text-sm font-bold text-blue-700" dir="ltr">{{ $number->number }}</p>
    <h1 class="mt-2 text-2xl font-extrabold text-[#071a3b]">تماس‌های این شماره چه زمانی و به کجا بروند؟</h1>
    <p class="mt-2 text-slate-600">زمان‌بندی اختیاری است. ساعت‌های باز و بسته را مشخص کنید و سپس پاسخ‌گو را انتخاب کنید.</p>
    @if ($number->inboundRoute?->destination)
        <div class="mt-6 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800">پاسخ‌گوی فعلی: <strong>{{ $number->inboundRoute->destinationLabel() }}</strong></div>
    @endif
    @if($errors->any())<div role="alert" class="mt-5 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800"><strong>لطفاً این موارد را اصلاح کنید:</strong><ul class="mt-2 list-inside list-disc">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    <form method="POST" enctype="multipart/form-data" action="{{ route('customer.setup.answer.store', $number) }}" class="panel mt-7 overflow-hidden">
        @csrf
        <div class="space-y-5 p-6">
            @php
                $route = $number->inboundRoute;
                $scheduleMode = old('schedule_mode', $route?->schedule ? 'scheduled' : 'anytime');
                $weekly = old('weekly', $route?->schedule['weekly'] ?? [0 => [['start'=>'09:00','end'=>'17:00']], 1 => [['start'=>'09:00','end'=>'17:00']], 2 => [['start'=>'09:00','end'=>'17:00']], 3 => [['start'=>'09:00','end'=>'17:00']], 4 => [['start'=>'09:00','end'=>'13:00']]]);
                $closedAction = old('closed_action', $route?->closed_destination_type ? (in_array($route->closed_destination_type, ['announcement','disconnect']) ? $route->closed_destination_type : $route->closed_destination_type.':'.$route->closed_destination_id) : 'announcement');
                $jalaliFormatter = new \IntlDateFormatter('fa_IR@calendar=persian', \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, 'Asia/Tehran', \IntlDateFormatter::TRADITIONAL, 'yyyy/MM/dd');
                $closedDates = old('closed_dates', array_map(fn ($date) => $jalaliFormatter->format(new \DateTimeImmutable($date.' 12:00:00', new \DateTimeZone('Asia/Tehran'))), $route?->schedule['closed_dates'] ?? []));
            @endphp
            <fieldset>
                <legend class="mb-3 text-sm font-bold text-slate-700">۱. چه زمانی تماس‌ها پاسخ داده شوند؟</legend>
                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="flex cursor-pointer items-start gap-2 rounded-xl border border-slate-200 p-4 text-sm has-[:checked]:border-blue-500 has-[:checked]:bg-blue-50"><input type="radio" name="schedule_mode" value="anytime" @checked($scheduleMode === 'anytime')><span><strong class="block">همیشه</strong><small class="text-slate-500">تمام تماس‌ها به یک پاسخ‌گو می‌روند.</small></span></label>
                    <label class="flex cursor-pointer items-start gap-2 rounded-xl border border-slate-200 p-4 text-sm has-[:checked]:border-blue-500 has-[:checked]:bg-blue-50"><input type="radio" name="schedule_mode" value="scheduled" @checked($scheduleMode === 'scheduled')><span><strong class="block">طبق زمان‌بندی</strong><small class="text-slate-500">برای ساعت‌های باز و بسته، اقدام جداگانه دارید.</small></span></label>
                </div>
            </fieldset>
            <div id="schedule-fields" class="space-y-4 rounded-2xl border border-blue-100 bg-blue-50/40 p-4" @if($scheduleMode !== 'scheduled') hidden @endif>
                <div class="flex flex-wrap items-center justify-between gap-2"><div><h2 class="text-sm font-bold">ساعت‌های باز بودن</h2><p class="mt-1 text-xs text-slate-500">روزها و بازه‌های زمانی را انتخاب کنید.</p></div><span class="rounded-lg bg-white px-3 py-2 text-xs font-bold text-slate-600">به وقت ایران · تهران</span><input type="hidden" name="timezone" value="Asia/Tehran"></div>
                <div class="divide-y divide-slate-100 overflow-hidden rounded-xl border border-slate-200 bg-white">
                    @foreach(['شنبه','یکشنبه','دوشنبه','سه‌شنبه','چهارشنبه','پنجشنبه','جمعه'] as $day => $dayName)
                        @php($intervals = $weekly[$day] ?? [])
                        <div class="day-row flex flex-wrap items-center gap-3 p-3" data-day="{{ $day }}"><label class="flex w-24 items-center gap-2 text-sm font-bold"><input type="checkbox" class="day-enabled" @checked(count($intervals) > 0)> {{ $dayName }}</label><div class="day-times flex flex-1 flex-wrap gap-2">@foreach($intervals ?: [['start'=>'09:00','end'=>'17:00']] as $index => $interval)<span class="time-pair inline-flex items-center gap-1"><input type="time" lang="fa-IR" name="weekly[{{ $day }}][{{ $index }}][start]" value="{{ $interval['start'] ?? '09:00' }}" aria-label="شروع {{ $dayName }}" dir="ltr" class="w-27 rounded-lg border border-slate-200 p-2 text-xs"><span>تا</span><input type="time" lang="fa-IR" name="weekly[{{ $day }}][{{ $index }}][end]" value="{{ $interval['end'] ?? '17:00' }}" aria-label="پایان {{ $dayName }}" dir="ltr" class="w-27 rounded-lg border border-slate-200 p-2 text-xs"><button type="button" class="remove-interval px-1 text-slate-400" aria-label="حذف بازه">×</button></span>@endforeach</div><button type="button" class="add-interval text-xs font-bold text-blue-700">+ بازه دیگر</button></div>
                    @endforeach
                </div>
                <div class="border-t border-blue-100 pt-4"><div class="flex flex-wrap items-center justify-between gap-2"><div><h2 class="text-sm font-bold">تعطیلی‌های ویژه <span class="font-normal text-slate-400">· اختیاری</span></h2><p class="mt-1 text-xs text-slate-500">در این تاریخ‌های جلالی، اقدام ساعات بسته اجرا می‌شود.</p></div><button type="button" id="add-date" class="rounded-lg border border-blue-200 bg-white px-3 py-2 text-xs font-bold text-blue-700">+ افزودن تاریخ</button></div><div id="closed-dates" class="mt-3 flex flex-wrap gap-2">@foreach($closedDates as $date)<span class="date-entry inline-flex items-center gap-1"><input name="closed_dates[]" value="{{ $date }}" inputmode="numeric" placeholder="۱۴۰۵/۰۷/۰۷" aria-label="تاریخ تعطیلی جلالی" dir="ltr" class="jalali-input w-34 rounded-lg border border-slate-200 p-2 text-sm"><button type="button" class="open-calendar text-blue-700" aria-label="انتخاب از تقویم">▦</button><button type="button" class="remove-date text-slate-500" aria-label="حذف تاریخ">×</button></span>@endforeach</div></div>
            </div>
            <fieldset>
                <legend id="answer-legend" class="mb-3 text-sm font-bold text-slate-700">۲. انتخاب پاسخ‌گو</legend>
                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="flex items-center gap-2 rounded-xl border border-slate-200 p-4 text-sm font-semibold"><input type="radio" name="answerer" value="new" @checked(old('answerer', $route?->destination_type === 'extension' ? 'existing' : ($route?->destination_type === 'queue' ? 'team' : ($route?->destination_type === 'ivr' ? 'menu' : 'new'))) === 'new') /> ساخت تلفن برای یک نفر</label>
                    <label class="flex items-center gap-2 rounded-xl border border-slate-200 p-4 text-sm font-semibold"><input type="radio" name="answerer" value="existing" @checked(old('answerer', $route?->destination_type) === 'existing' || (old('answerer') === null && $route?->destination_type === 'extension')) @disabled($extensions->isEmpty()) /> انتخاب تلفن موجود</label>
                    <label class="flex items-center gap-2 rounded-xl border border-slate-200 p-4 text-sm font-semibold"><input type="radio" name="answerer" value="team" @checked(old('answerer', $route?->destination_type === 'queue' ? 'team' : null) === 'team') @disabled($queues->isEmpty()) /> فرستادن تماس به تیم</label>
                    <label class="flex items-center gap-2 rounded-xl border border-slate-200 p-4 text-sm font-semibold"><input type="radio" name="answerer" value="menu" @checked(old('answerer', $route?->destination_type === 'ivr' ? 'menu' : null) === 'menu') @disabled($menus->isEmpty()) /> پخش منوی تماس</label>
                </div>
            </fieldset>
            <div id="new-answerer">
                <label class="block text-sm font-bold text-slate-700">نام پاسخ‌گو
                    <input name="display_name" value="{{ old('display_name') }}" maxlength="100" placeholder="مثال: سارا احمدی" class="mt-2 w-full rounded-xl border border-slate-200 px-4 py-3 font-normal focus:border-blue-500 focus:outline-none" />
                </label>
                <p class="mt-2 text-xs text-slate-500">برای این شخص یک شناسه و رمز تلفن ساخته می‌شود.</p>
            </div>
            <div id="menu-answerer" hidden>
                <label class="block text-sm font-bold text-slate-700">منوی تماس
                    <select name="menu_id" class="mt-2 w-full rounded-xl border border-slate-200 bg-white px-4 py-3 font-normal focus:border-blue-500 focus:outline-none">
                        <option value="">انتخاب منوی منتشرشده</option>
                        @foreach ($menus as $menu)<option value="{{ $menu->id }}" @selected(old('menu_id', $route?->destination_type === 'ivr' ? $route->destination_id : null) == $menu->id)>{{ $menu->name }}</option>@endforeach
                    </select>
                </label>
                <p class="mt-2 text-xs text-slate-500">تماس‌گیرنده پیام را می‌شنود و با انتخاب کلید به فرد یا تیم مناسب وصل می‌شود. <a class="font-bold text-blue-700 underline" href="{{ route('ivr-menus.index') }}">ساخت یا ویرایش منو</a></p>
            </div>
            <div id="team-answerer" hidden>
                <label class="block text-sm font-bold text-slate-700">تیم پاسخ‌گویی
                    <select name="queue_id" class="mt-2 w-full rounded-xl border border-slate-200 bg-white px-4 py-3 font-normal focus:border-blue-500 focus:outline-none">
                        <option value="">انتخاب تیم</option>
                        @foreach ($queues as $queue)<option value="{{ $queue->id }}" @selected(old('queue_id', $route?->destination_type === 'queue' ? $route->destination_id : null) == $queue->id)>{{ $queue->name }}</option>@endforeach
                    </select>
                </label>
                <p class="mt-2 text-xs text-slate-500">تماس به پاسخ‌گوهای آماده تیم پیشنهاد می‌شود. تلفن‌ها و تنظیم تماس خروجی اعضا تغییر نمی‌کند.</p>
            </div>
            <div id="existing-answerer" hidden>
                <label class="block text-sm font-bold text-slate-700">تلفن موجود
                    <select name="extension_id" class="mt-2 w-full rounded-xl border border-slate-200 bg-white px-4 py-3 font-normal focus:border-blue-500 focus:outline-none">
                        <option value="">انتخاب شخص</option>
                        @foreach ($extensions as $extension)
                            <option value="{{ $extension->id }}" @selected(old('extension_id', $route?->destination_type === 'extension' ? $route->destination_id : null) == $extension->id)>{{ $extension->display_name ?: 'تلفن '.$extension->extension }} · {{ $extension->extension }}</option>
                        @endforeach
                    </select>
                </label>
            </div>
            <div id="closed-section" class="space-y-4 rounded-xl border border-blue-100 p-4" @if($scheduleMode !== 'scheduled') hidden @endif><div><h2 class="text-sm font-bold">۳. خارج از ساعت کاری چه اتفاقی بیفتد؟</h2><p class="mt-1 text-xs text-slate-500">این اقدام برای جمعه‌ها و تعطیلی‌های ویژه هم اجرا می‌شود.</p></div><label class="block text-sm font-bold">اقدام تماس<select id="closed-action" name="closed_action" class="mt-2 w-full rounded-xl border border-slate-200 bg-white px-4 py-3 font-normal"><option value="announcement" @selected($closedAction === 'announcement')>پخش پیام، سپس پایان تماس</option><option value="disconnect" @selected($closedAction === 'disconnect')>پایان تماس بدون پیام</option>@foreach($extensions as $extension)<option value="extension:{{ $extension->id }}" @selected($closedAction === 'extension:'.$extension->id)>وصل به {{ $extension->display_name ?: 'داخلی '.$extension->extension }}</option>@endforeach @foreach($queues as $queue)<option value="queue:{{ $queue->id }}" @selected($closedAction === 'queue:'.$queue->id)>وصل به تیم {{ $queue->name }}</option>@endforeach @foreach($menus as $menu)<option value="ivr:{{ $menu->id }}" @selected($closedAction === 'ivr:'.$menu->id)>پخش منوی {{ $menu->name }}</option>@endforeach</select></label><div id="announcement-fields" class="rounded-xl bg-blue-50 p-4"><label class="block text-sm font-bold">پیام پایان تماس <span class="font-normal text-slate-600">· WAV، MP3 یا M4A تا ۱۰ مگابایت</span><input type="file" name="announcement" accept="audio/wav,audio/mpeg,audio/mp4,audio/x-m4a,audio/webm" class="mt-2 block w-full text-sm"></label>@if($route?->closed_announcement_path)<p class="mt-2 text-xs text-blue-800">پیام فعلی ذخیره شده است.</p><audio controls preload="none" src="{{ route('customer.setup.answer.announcement', $number) }}" class="mt-2 w-full"></audio>@endif<p class="mt-2 text-xs text-slate-600">پیام پخش می‌شود و سپس تماس پایان می‌یابد.</p></div><p id="disconnect-hint" class="text-xs text-amber-800" hidden>تماس‌گیرنده هیچ پیامی نمی‌شنود و تماس قطع می‌شود.</p></div>
            <div class="rounded-xl bg-slate-50 p-4 text-xs leading-6"><strong>خلاصه مسیر تماس</strong><p id="route-summary" class="mt-1 text-slate-600" aria-live="polite"></p></div>
            <p class="rounded-xl bg-slate-50 p-4 text-xs leading-6 text-slate-600">برای تلفن جدید، این شماره به‌عنوان شماره تماس خروجی آماده می‌شود. اگر تلفن موجود از قبل شماره خروجی دارد، آن تنظیم حفظ می‌شود. تماس خروجی تنها پس از تأیید شماره و اتصال ارائه‌دهنده فعال خواهد شد.</p>
        </div>
        <div class="flex flex-wrap justify-between gap-3 border-t border-slate-100 bg-slate-50 p-5">
            @if (auth()->user()->hasPermission('lines.view'))<a href="{{ route('customer.setup.lines') }}" class="rounded-xl px-4 py-3 text-sm font-bold text-slate-600">بازگشت</a>@endif
            <button class="rounded-xl bg-blue-600 px-6 py-3 text-sm font-bold text-white hover:bg-blue-700">ذخیره مسیر تماس</button>
        </div>
    </form>
    <div id="jalali-calendar" hidden role="dialog" aria-label="تقویم جلالی" class="fixed z-50 w-72 rounded-xl border border-slate-200 bg-white p-3 shadow-xl"><div class="mb-3 flex items-center justify-between"><button type="button" id="calendar-prev" aria-label="ماه قبل" class="px-2">→</button><strong id="calendar-title" class="text-sm"></strong><button type="button" id="calendar-next" aria-label="ماه بعد" class="px-2">←</button></div><div class="grid grid-cols-7 text-center text-xs text-slate-500"><span>ش</span><span>ی</span><span>د</span><span>س</span><span>چ</span><span>پ</span><span>ج</span></div><div id="calendar-days" class="mt-2 grid grid-cols-7 gap-1"></div></div>
</div>
<script>
    const setupRoot = document.querySelector('form');
    const scheduleFields = document.getElementById('schedule-fields');
    const closedSection = document.getElementById('closed-section');
    const closedAction = document.getElementById('closed-action');
    function showAnswerer() {
        const choice = document.querySelector('input[name="answerer"]:checked')?.value;
        const create = choice === 'new';
        const existing = choice === 'existing';
        const team = choice === 'team';
        const menu = choice === 'menu';
        document.getElementById('new-answerer').hidden = !create;
        document.getElementById('existing-answerer').hidden = !existing;
        document.getElementById('team-answerer').hidden = !team;
        document.getElementById('menu-answerer').hidden = !menu;
        document.querySelector('input[name="display_name"]').required = create;
        document.querySelector('select[name="extension_id"]').required = existing;
        document.querySelector('select[name="queue_id"]').required = team;
        document.querySelector('select[name="menu_id"]').required = menu;
    }
    function refreshSchedule() {
        const scheduled = setupRoot.querySelector('input[name="schedule_mode"]:checked')?.value === 'scheduled';
        scheduleFields.hidden = closedSection.hidden = !scheduled;
        document.getElementById('answer-legend').textContent = scheduled ? '۲. در ساعت‌های باز چه کسی پاسخ دهد؟' : '۲. انتخاب پاسخ‌گو';
        document.querySelectorAll('.day-row').forEach(row => {
            const active = scheduled && row.querySelector('.day-enabled').checked;
            row.querySelector('.day-times').classList.toggle('opacity-40', !active);
            row.querySelectorAll('.time-pair input').forEach(input => input.disabled = !active);
            row.querySelector('.add-interval').disabled = !active;
        });
        document.getElementById('announcement-fields').hidden = !scheduled || closedAction.value !== 'announcement';
        document.getElementById('disconnect-hint').hidden = !scheduled || closedAction.value !== 'disconnect';
        const answerer = setupRoot.querySelector('input[name="answerer"]:checked')?.closest('label')?.textContent.trim() || 'پاسخ‌گو';
        const closed = closedAction.selectedOptions[0]?.textContent.trim() || 'اقدام ساعات بسته';
        const enabledRows = [...document.querySelectorAll('.day-row')].filter(row => row.querySelector('.day-enabled').checked);
        const days = enabledRows.map(row => `${row.querySelector('label').textContent.trim()} ${[...row.querySelectorAll('.time-pair')].map(pair => `${pair.querySelector('input[name$="[start]"]').value}–${pair.querySelector('input[name$="[end]"]').value}`).join('، ')}`);
        const now = new Date();
        const weekday = new Intl.DateTimeFormat('en-US', {timeZone:'Asia/Tehran', weekday:'short'}).format(now);
        const dayIndex = ['Sat','Sun','Mon','Tue','Wed','Thu','Fri'].indexOf(weekday);
        const timeParts = new Intl.DateTimeFormat('en-GB', {timeZone:'Asia/Tehran', hour:'2-digit', minute:'2-digit', hourCycle:'h23'}).formatToParts(now);
        const clock = `${timeParts.find(part => part.type === 'hour').value}:${timeParts.find(part => part.type === 'minute').value}`;
        const currentRow = enabledRows.find(row => Number(row.dataset.day) === dayIndex);
        const openNow = currentRow && [...currentRow.querySelectorAll('.time-pair')].some(pair => clock >= pair.querySelector('input[name$="[start]"]').value && clock < pair.querySelector('input[name$="[end]"]').value)
            && ![...document.querySelectorAll('.jalali-input')].some(input => ascii(input.value).replace(/\D/g,'') === pdate(now).replace(/\D/g,''));
        document.getElementById('route-summary').textContent = scheduled ? `${days.join('؛ ') || 'هیچ روزی'} → ${answerer}؛ سایر زمان‌ها → ${closed}. اکنون به وقت ایران: ${openNow ? 'ساعات باز' : 'ساعات بسته'}.` : `در تمام ساعت‌ها: ${answerer}`;
    }
    setupRoot.addEventListener('change', () => { showAnswerer(); refreshSchedule(); });
    setupRoot.addEventListener('click', event => {
        const row = event.target.closest('.day-row');
        if (event.target.closest('.add-interval') && row) {
            const count = row.querySelectorAll('.time-pair').length;
            if (count >= 3) return;
            const day = row.dataset.day;
            const lastEnd = row.querySelector('.time-pair:last-child input[name$="[end]"]').value;
            const startMinutes = Number(lastEnd.slice(0,2)) * 60 + Number(lastEnd.slice(3,5));
            if (startMinutes >= 1439) return;
            const endMinutes = Math.min(1439, startMinutes + 60);
            const end = `${String(Math.floor(endMinutes/60)).padStart(2,'0')}:${String(endMinutes%60).padStart(2,'0')}`;
            const pair = document.createElement('span'); pair.className = 'time-pair inline-flex items-center gap-1';
            pair.innerHTML = `<input type="time" lang="fa-IR" name="weekly[${day}][${count}][start]" value="${lastEnd}" aria-label="شروع بازه" dir="ltr" class="w-27 rounded-lg border border-slate-200 p-2 text-xs"><span>تا</span><input type="time" lang="fa-IR" name="weekly[${day}][${count}][end]" value="${end}" aria-label="پایان بازه" dir="ltr" class="w-27 rounded-lg border border-slate-200 p-2 text-xs"><button type="button" class="remove-interval px-1 text-slate-400" aria-label="حذف بازه">×</button>`;
            row.querySelector('.day-times').append(pair);
        }
        if (event.target.closest('.remove-interval') && row) {
            if (row.querySelectorAll('.time-pair').length === 1) row.querySelector('.day-enabled').checked = false;
            else event.target.closest('.time-pair').remove();
            row.querySelectorAll('.time-pair').forEach((pair, index) => pair.querySelectorAll('input').forEach(input => input.name = input.name.replace(/\]\[\d+\]\[/, `][${index}][`)));
            refreshSchedule();
        }
        if (event.target.closest('#add-date')) {
            const entry = document.createElement('span'); entry.className = 'date-entry inline-flex items-center gap-1';
            entry.innerHTML = '<input name="closed_dates[]" inputmode="numeric" placeholder="۱۴۰۵/۰۷/۰۷" aria-label="تاریخ تعطیلی جلالی" dir="ltr" class="jalali-input w-34 rounded-lg border border-slate-200 p-2 text-sm"><button type="button" class="open-calendar text-blue-700" aria-label="انتخاب از تقویم">▦</button><button type="button" class="remove-date text-slate-500" aria-label="حذف تاریخ">×</button>';
            document.getElementById('closed-dates').append(entry); openCalendar(entry.querySelector('input'));
        }
        if (event.target.closest('.remove-date')) event.target.closest('.date-entry').remove();
        if (event.target.closest('.open-calendar')) openCalendar(event.target.closest('.date-entry').querySelector('input'));
    });
    const calendar = document.getElementById('jalali-calendar');
    const persian = new Intl.DateTimeFormat('fa-IR-u-ca-persian', {year:'numeric', month:'2-digit', day:'2-digit', timeZone:'Asia/Tehran'});
    const monthName = new Intl.DateTimeFormat('fa-IR-u-ca-persian', {year:'numeric', month:'long', timeZone:'Asia/Tehran'});
    const ascii = value => String(value).replace(/[۰-۹]/g, c => '۰۱۲۳۴۵۶۷۸۹'.indexOf(c)).replace(/[٠-٩]/g, c => '٠١٢٣٤٥٦٧٨٩'.indexOf(c));
    const parts = date => Object.fromEntries(persian.formatToParts(date).filter(part => part.type !== 'literal').map(part => [part.type, Number(ascii(part.value))]));
    const pdate = date => { const p = parts(date); return `${p.year}${String(p.month).padStart(2,'0')}${String(p.day).padStart(2,'0')}`; };
    let calendarInput, calendarAnchor = new Date();
    function firstOfMonth(date) {
        const current = new Date(date); current.setUTCHours(12, 0, 0, 0); const target = parts(current);
        for (let i = 0; i < 35; i++) { const previous = new Date(current); previous.setUTCDate(current.getUTCDate() - 1); const p = parts(previous); if (p.year !== target.year || p.month !== target.month) break; current.setUTCDate(current.getUTCDate() - 1); }
        return current;
    }
    function renderCalendar() {
        const first = firstOfMonth(calendarAnchor), month = parts(first).month;
        document.getElementById('calendar-title').textContent = monthName.format(first);
        const grid = document.getElementById('calendar-days'); grid.replaceChildren();
        for (let i = 0; i < (first.getUTCDay() + 1) % 7; i++) grid.append(document.createElement('span'));
        const date = new Date(first);
        while (parts(date).month === month && grid.children.length < 42) {
            const day = new Date(date), p = parts(day), button = document.createElement('button');
            button.type = 'button'; button.className = 'rounded-lg py-1.5 text-sm hover:bg-blue-50 focus:bg-blue-100'; button.textContent = new Intl.NumberFormat('fa-IR').format(p.day);
            button.addEventListener('click', () => { calendarInput.value = `${p.year}/${String(p.month).padStart(2,'0')}/${String(p.day).padStart(2,'0')}`.replace(/[0-9]/g, digit => '۰۱۲۳۴۵۶۷۸۹'[Number(digit)]); calendar.hidden = true; refreshSchedule(); });
            grid.append(button); date.setUTCDate(date.getUTCDate() + 1);
        }
    }
    function openCalendar(input) {
        calendarInput = input; calendarAnchor = new Date();
        const selected = ascii(input.value).match(/^(1[34]\d{2})\/(0?[1-9]|1[0-2])\/(0?[1-9]|[12]\d|3[01])$/);
        if (selected) calendarAnchor = new Date(Date.UTC(Number(selected[1]) + 621, Number(selected[2]) + 1, 28, 12));
        renderCalendar();
        const box = input.getBoundingClientRect(); calendar.style.top = Math.max(8, Math.min(box.bottom + 6, innerHeight - 310)) + 'px'; calendar.style.left = Math.max(8, Math.min(box.left, innerWidth - 296)) + 'px'; calendar.hidden = false;
    }
    document.getElementById('calendar-prev').addEventListener('click', () => { calendarAnchor = firstOfMonth(calendarAnchor); calendarAnchor.setUTCDate(calendarAnchor.getUTCDate() - 7); renderCalendar(); });
    document.getElementById('calendar-next').addEventListener('click', () => { calendarAnchor = firstOfMonth(calendarAnchor); calendarAnchor.setUTCDate(calendarAnchor.getUTCDate() + 36); renderCalendar(); });
    document.addEventListener('click', event => { if (!calendar.contains(event.target) && !event.target.closest('.open-calendar') && !event.target.closest('#add-date')) calendar.hidden = true; });
    document.addEventListener('keydown', event => { if (event.key === 'Escape') calendar.hidden = true; });
    showAnswerer(); refreshSchedule();
</script>
@endsection
