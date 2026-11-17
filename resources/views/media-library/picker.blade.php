<dialog data-media-picker data-list="{{ route('customer.media.index') }}" data-store="{{ route('customer.media.store') }}" aria-labelledby="media-picker-title" class="fixed inset-0 m-auto h-full max-h-none w-full max-w-none bg-white text-slate-900 shadow-xl backdrop:bg-slate-900/50 sm:h-auto sm:max-h-[85dvh] sm:max-w-xl sm:rounded-2xl">
    <div class="flex h-full max-h-[inherit] flex-col">
        <header class="flex items-center justify-between gap-4 border-b border-slate-200 p-4 sm:p-5">
            <h2 id="media-picker-title" class="text-lg font-black">انتخاب فایل صوتی</h2>
            <button type="button" data-media-close class="rounded-lg px-3 py-2 text-sm font-bold text-slate-700">بستن</button>
        </header>
        <div class="min-h-0 flex-1 space-y-4 overflow-y-auto p-4 sm:p-5">
            <div data-picker-browse>
                <label class="block text-sm font-semibold">جست‌وجوی فایل صوتی
                    <input type="search" data-picker-search placeholder="نام فایل" class="mt-1 block w-full rounded-xl border border-slate-300 px-3 py-2">
                </label>
                <div data-picker-list class="mt-3 space-y-2"></div>
            </div>
            @if($panelUser->hasPermission('phones.manage') || $panelUser->hasPermission('outbound.manage'))
                <details data-picker-upload class="rounded-xl border border-slate-200 p-3">
                    <summary class="cursor-pointer text-sm font-bold text-blue-700">بارگذاری فایل جدید</summary>
                    <form method="post" enctype="multipart/form-data" action="{{ route('customer.media.store') }}" class="mt-3 space-y-3">
                        @csrf
                        <label data-media-drop class="block rounded-lg border border-dashed border-slate-300 p-3 text-sm">فایل را انتخاب کنید یا اینجا بکشید
                            <input type="file" name="audio" accept=".wav,.mp3" required class="mt-2 block w-full text-sm">
                        </label>
                        <p class="text-xs text-slate-600">WAV یا MP3، حداکثر ۱۰ مگابایت و ۶۰ ثانیه</p>
                        <label class="block text-sm font-semibold">نام فایل
                            <input name="name" maxlength="100" required class="mt-1 block w-full rounded-xl border border-slate-300 px-3 py-2">
                        </label>
                        <button class="commerce-secondary">بارگذاری</button>
                    </form>
                </details>
            @endif
            <p data-picker-status role="status" aria-live="polite" class="text-sm text-slate-700"></p>
        </div>
        <footer data-picker-footer class="shrink-0 space-y-2 border-t border-slate-200 bg-white p-4 sm:p-5">
            <audio data-picker-preview controls preload="none" hidden class="h-10 w-full" aria-label="پیش‌شنوی فایل انتخاب‌شده"></audio>
            <button data-picker-confirm type="button" class="commerce-button w-full disabled:opacity-50" disabled>تأیید انتخاب</button>
        </footer>
    </div>
</dialog>
