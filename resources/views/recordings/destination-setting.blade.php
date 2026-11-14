@if(auth()->user()->hasPermission('recordings.manage'))
    <form method="POST" action="{{ route('recordings.destinations.update', [$destination, $record->id]) }}" class="mt-4 flex flex-wrap items-end gap-3">
        @csrf @method('PUT')
        <label class="block text-sm font-bold">ضبط تماس
            <select name="recording_mode" class="mt-2 block rounded-xl border border-slate-200 bg-white px-4 py-2">
                @foreach(['inherit' => 'طبق تنظیم مسیر تماس', 'always' => 'همیشه ضبط شود', 'never' => 'ضبط نشود'] as $value => $label)
                    <option value="{{ $value }}" @selected(($record->recording_mode ?? 'inherit') === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </label>
        @error('recording_mode')<p class="text-xs text-red-700">{{ $message }}</p>@enderror
        <button class="rounded-xl border border-slate-200 px-4 py-2 text-sm font-bold">ذخیره تنظیم ضبط</button>
    </form>
@endif
