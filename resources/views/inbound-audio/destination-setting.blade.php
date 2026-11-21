@php
    $announcementSection = 'announcement-'.$destination.'-'.$record->id;
    $announcementErrors = $errors->getBag($announcementSection);
    $settings = $record->inbound_announcement ?? [];
    $retry = old('announcement_form') === $announcementSection && $announcementErrors->any();
    $mode = $retry ? old('mode') : ($settings['mode'] ?? 'normal');
    $selectedAsset = $retry ? old('asset') : ($settings['asset'] ?? null);
    $selectedMailbox = $retry ? old('mailbox') : ($settings['mailbox'] ?? null);
    $selectedDigit = $retry ? old('digit') : ($settings['digit'] ?? null);
@endphp
<section id="{{ $announcementSection }}" aria-labelledby="{{ $announcementSection }}-title" class="mt-5 scroll-mt-6 rounded-2xl border border-slate-200 bg-white p-4 sm:p-5">
    <h2 id="{{ $announcementSection }}-title" class="font-bold">رفتار تماس ورودی</h2>
    <p class="mt-2 text-sm leading-6 text-slate-600">با انتخاب اعلان، تماس ورودی پس از رسیدن به این مقصد پاسخ داده می‌شود و تلفنی زنگ نمی‌خورد.</p>
    <form data-announcement-settings method="POST" action="{{ route('inbound-audio.destinations.update', [$destination, $record->id]) }}" class="mt-4 grid gap-4">
        @csrf @method('PUT')
        <input type="hidden" name="announcement_form" value="{{ $announcementSection }}">
        <label class="block text-sm font-bold">رفتار تماس
            <select name="mode" class="mt-2 block w-full rounded-xl border border-slate-200 bg-white px-4 py-2.5 sm:max-w-sm">
                <option value="normal" @selected($mode === 'normal')>مسیریابی عادی</option>
                <option value="playback" @selected($mode === 'playback')>پخش اعلان و پایان تماس</option>
                @if(config('voip.voicemail_enabled') || in_array($mode, ['message', 'automatic_message'], true))
                    <option value="message" @selected($mode === 'message')>پخش اعلان و ضبط پیام با فشردن کلید</option>
                    <option value="automatic_message" @selected($mode === 'automatic_message')>پخش اعلان و ضبط خودکار پیام صوتی</option>
                @endif
            </select>
        </label>
        <div data-announcement-playback class="space-y-2">
            <label class="block text-sm font-bold">فایل اعلان
                <select name="asset" class="mt-2 block w-full rounded-xl border border-slate-200 bg-white px-4 py-2.5 sm:max-w-sm">
                    <option value="">انتخاب فایل</option>
                    @if(is_string($selectedAsset) && $selectedAsset === ($settings['asset'] ?? null) && !$inboundAudioAssets->contains('id', $selectedAsset))
                        <option value="{{ $selectedAsset }}" selected>فایل فعلی</option>
                    @endif
                    @foreach($inboundAudioAssets as $asset)
                        <option value="{{ $asset->id }}" data-preview="{{ route('inbound-audio.audio', $asset->id) }}" @selected($selectedAsset === $asset->id)>{{ $asset->name }}</option>
                    @endforeach
                </select>
            </label>
            <audio data-announcement-preview controls preload="none" aria-label="پیش‌شنوی فایل اعلان" hidden class="w-full max-w-sm"></audio>
            <a href="{{ route('inbound-audio.index') }}" class="inline-block text-sm font-semibold text-blue-700">کتابخانه اعلان‌ها و افزودن فایل</a>
        </div>
        @if(config('voip.voicemail_enabled') || in_array($mode, ['message', 'automatic_message'], true))
            <div data-announcement-message class="space-y-3">
                <p data-announcement-key-help class="text-sm leading-6 text-slate-600">شمارهٔ کلید را در فایل اعلان بگویید. تماس‌گیرنده پس از پخش اعلان ۵ ثانیه برای فشردن آن فرصت دارد.</p>
                <label data-announcement-key class="block text-sm font-bold">کلید ضبط پیام
                    <select name="digit" class="mt-2 block rounded-xl border border-slate-200 bg-white px-4 py-2.5">
                        <option value="">انتخاب کلید</option>
                        @for($key = 0; $key <= 9; $key++)
                            <option value="{{ $key }}" @selected(is_scalar($selectedDigit) && (string) $selectedDigit === (string) $key)>{{ $key }}</option>
                        @endfor
                    </select>
                </label>
                <p data-announcement-automatic-help class="text-sm leading-6 text-slate-600" hidden>در فایل اعلان از تماس‌گیرنده بخواهید بعد از بوق پیام خود را بگوید.</p>
                @if($destination === 'queue')
                    <label class="block text-sm font-bold">صندوق دریافت پیام
                        <select name="mailbox" class="mt-2 block w-full rounded-xl border border-slate-200 bg-white px-4 py-2.5 sm:max-w-sm">
                            <option value="">انتخاب داخلی</option>
                            @foreach($extensions as $extension)
                                <option value="{{ $extension->id }}" @selected(is_scalar($selectedMailbox) && (string) $selectedMailbox === (string) $extension->id)>{{ $extension->extension }} · {{ $extension->display_name }}</option>
                            @endforeach
                        </select>
                    </label>
                @endif
                <p class="text-sm text-slate-600">پیام در صندوق این داخلی ذخیره می‌شود. برای شنیدن پیام‌ها از همان داخلی <bdi dir="ltr">*98</bdi> را شماره‌گیری کند.</p>
            </div>
        @endif
        @if($announcementErrors->any())
            <ul role="alert" class="list-disc space-y-1 pr-5 text-sm text-red-700">
                @foreach($announcementErrors->all() as $error)<li>{{ $error }}</li>@endforeach
            </ul>
        @endif
        <button class="commerce-button justify-self-start" type="submit">ذخیره رفتار تماس</button>
        @if(session('announcement_saved') === $announcementSection)
            <p role="status" class="text-sm font-semibold text-emerald-700">رفتار تماس ورودی ذخیره شد.</p>
        @endif
    </form>
</section>
