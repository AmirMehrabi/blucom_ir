<div>
    <label class="block text-sm font-semibold">پس از پایان انتظار
        <select name="fallback_action" class="mt-2 w-full rounded-xl border border-slate-200 p-3">
            <option value="">پایان تماس</option>
            @foreach ($extensions as $extension)
                <option value="extension:{{ $extension->id }}" @selected($selected === 'extension:'.$extension->id)>اتصال به {{ $extension->display_name ?: 'داخلی '.$extension->extension }} · {{ $extension->extension }}</option>
            @endforeach
            @include('shared.inbound-audio-options')
        </select>
    </label>
    @include('shared.inbound-audio-help')
</div>
