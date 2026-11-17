@if(request()->attributes->get('customer_portal'))
    <option value="__media_picker" disabled hidden>انتخاب یا بارگذاری اعلان…</option>
@endif
@if(str_starts_with($selected ?? '', 'announcement:') && !$inboundAudioAssets->contains(fn ($asset) => 'announcement:'.$asset->id === $selected))
    <option value="{{ $selected }}" selected>اعلان ذخیره‌شده</option>
@endif
@if($inboundAudioAssets->isNotEmpty())
    <optgroup label="پخش اعلان و پایان تماس">
        @foreach($inboundAudioAssets as $asset)
            <option value="announcement:{{ $asset->id }}" @selected(($selected ?? '') === 'announcement:'.$asset->id)>{{ $asset->name }}</option>
        @endforeach
    </optgroup>
@endif
@if(config('voip.voicemail_enabled'))
    <optgroup label="دریافت پیام صوتی">
        @foreach($extensions->where('enabled', true) as $extension)
            <option value="voicemail:{{ $extension->id }}" @selected(($selected ?? '') === 'voicemail:'.$extension->id)>صندوق {{ $extension->display_name ?: 'داخلی '.$extension->extension }} · {{ $extension->extension }}</option>
        @endforeach
    </optgroup>
@endif
