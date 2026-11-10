@if ($snapshot['can_monitor'] ?? false)
<dialog data-monitor-dialog class="rounded-2xl border border-slate-200 p-6 shadow-xl w-full max-w-md" aria-labelledby="monitor-title">
    <h2 id="monitor-title" class="text-lg font-bold mb-3">شنیدن تماس زنده</h2>
    <p class="text-sm text-slate-600 mb-4">صدای هر دو طرف را می‌شنوید. صدای شما به تماس منتقل نمی‌شود. سابقه شنود ثبت خواهد شد.</p>
    <form data-monitor-form class="space-y-4">
        <label class="block">روش شنیدن<select data-monitor-mode class="block w-full rounded-lg border p-2 mt-1"><option value="web">در همین مرورگر</option><option value="extension">از طریق داخلی</option></select></label>
        <label data-monitor-supervisor-label class="block" hidden>داخلی ناظر<select data-monitor-supervisor class="block w-full rounded-lg border p-2 mt-1">@foreach ($snapshot['extensions'] as $phone)@if ($phone['enabled'])<option value="{{ $phone['id'] }}">{{ $phone['name'] }} · {{ $phone['extension'] }}</option>@endif @endforeach</select></label>
        <p data-monitor-status class="text-sm" role="status" aria-live="polite"></p>
        <audio data-monitor-audio autoplay controls class="w-full" hidden></audio>
        <button type="submit" data-monitor-start class="rounded-lg bg-blue-600 text-white px-4 py-2">شروع شنود</button>
        <button type="button" data-monitor-stop class="rounded-lg bg-red-600 text-white px-4 py-2" hidden>پایان شنود</button>
        <button type="button" data-monitor-close class="rounded-lg border px-4 py-2">بستن</button>
    </form>
</dialog>
<button type="button" data-monitor-resume class="live-link" hidden>شنود در حال اجرا · مدیریت</button>
@endif
