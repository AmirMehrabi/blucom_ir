<p class="mt-2 text-xs leading-7 text-slate-600">
    <a class="font-bold text-blue-700 underline" href="{{ route('inbound-audio.index') }}" target="_blank" rel="noopener">بارگذاری و پیش‌شنوی اعلان</a>
    @if(config('voip.voicemail_enabled'))
        <span class="block">برای شنیدن پیام‌ها از تلفن متصل به داخلیِ صندوق، <bdi dir="ltr">*98</bdi> را شماره‌گیری کنید.</span>
    @endif
</p>
