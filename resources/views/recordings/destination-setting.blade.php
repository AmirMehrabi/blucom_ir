@if(auth()->user()->hasPermission('recordings.manage'))
    @php
        $recordingSection = 'recording-'.$destination.'-'.$record->id;
        $recordingModes = ['inherit' => 'پیروی از مسیر تماس', 'always' => 'همیشه ضبط شود', 'never' => 'ضبط نشود'];
        if ($destination === 'extension') {
            $recordingModes = [
                'inherit' => $recordingModes['inherit'],
                'always' => 'تماس‌های ورودی و خروجی',
                'inbound' => 'فقط تماس‌های ورودی',
                'outbound' => 'فقط تماس‌های خروجی',
                'never' => $recordingModes['never'],
            ];
        }
        $recordingError = $errors->getBag($recordingSection)->first('recording_mode');
    @endphp
    <section id="{{ $recordingSection }}" aria-labelledby="{{ $recordingSection }}-title" class="mt-5 scroll-mt-6 rounded-2xl border border-slate-200 bg-white p-4 sm:p-5">
        <h2 id="{{ $recordingSection }}-title" class="font-bold">ضبط تماس</h2>
        <p class="mt-2 text-sm leading-6 text-slate-600">این تنظیم هنگام رسیدن تماس به این مقصد اعمال می‌شود. با انتخاب «پیروی از مسیر تماس»، تنظیم ضبط مرحلهٔ قبل یا شمارهٔ خط ادامه پیدا می‌کند.</p>
        @if($destination === 'extension')
            <p class="mt-2 text-sm leading-6 text-slate-600">تماس خروجی با تنظیم داخلیِ تماس‌گیرنده ضبط می‌شود.</p>
        @endif
        <form method="POST" action="{{ route('recordings.destinations.update', [$destination, $record->id]) }}" class="mt-4 flex flex-wrap items-end gap-3">
            @csrf @method('PUT')
            <div class="w-full sm:max-w-xs">
                <label for="{{ $recordingSection }}-mode" class="block text-sm font-bold">روش ضبط</label>
                <select id="{{ $recordingSection }}-mode" name="recording_mode" class="mt-2 block w-full rounded-xl border border-slate-200 bg-white px-4 py-2.5"
                    @if($recordingError) aria-invalid="true" aria-describedby="{{ $recordingSection }}-error" @endif>
                    @foreach($recordingModes as $value => $label)
                        <option value="{{ $value }}" @selected(($record->recording_mode ?? 'inherit') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="min-h-11 rounded-xl bg-blue-600 px-5 py-2.5 text-sm font-bold text-white hover:bg-blue-700">ذخیره تنظیم ضبط</button>
        </form>
        @if($recordingError)
            <p id="{{ $recordingSection }}-error" role="alert" class="mt-3 text-sm font-semibold text-red-700">{{ $recordingError }}</p>
        @endif
        @if(session('recording_saved') === $recordingSection)
            <p role="status" class="mt-3 text-sm font-semibold text-emerald-700">تنظیم ضبط تماس ذخیره شد.</p>
        @endif
    </section>
@endif
