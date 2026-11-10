@php
$canPause = auth()->user()->hasPermission('phones.manage') || (auth()->user()->hasPermission('queues.work') && auth()->user()->sip_extension_id === $extension->id);
$pendingQueue = app(\App\Services\ExtensionAvailabilityService::class)->queuePending($extension);
@endphp
<div class="space-y-2" data-extension-availability data-version="{{ $extension->availability_version }}" @if($pendingQueue && $canPause) data-pause-poll="{{ route('extensions.availability.state', $extension->id) }}" @endif>
    <p class="text-sm font-bold {{ $extension->paused_at ? 'text-amber-700' : 'text-emerald-700' }}">{{ !$extension->enabled ? 'داخلی غیرفعال' : ($extension->paused_at ? 'دریافت تماس متوقف است' : 'دریافت تماس فعال است') }}</p>
    @if($extension->paused_at)<p class="text-xs text-slate-500">از <bdi>{{ $extension->paused_at->setTimezone(config('voip.display_timezone','Asia/Tehran'))->format('Y/m/d H:i') }}</bdi> تهران</p>@endif
    @if($pendingQueue)<p data-queue-pause-status class="text-xs leading-6 text-amber-700" role="status">در انتظار تأیید تیم‌ها؛ ممکن است تا همگام‌سازی هنوز پیشنهاد تماس دریافت شود. وضعیت به‌صورت خودکار بررسی می‌شود.</p><a class="text-xs font-bold text-blue-700" href="{{ request()->fullUrl() }}">بررسی دوباره وضعیت</a>@endif
    @if($extension->queue_status === 'On Break')<p class="text-xs text-slate-500">استراحت تیمی فعال است؛ ادامه دریافت تماس آن را لغو نمی‌کند.</p>@endif
    @if($canPause && $extension->enabled)
    <form method="POST" action="{{ route('extensions.availability', $extension->id) }}" data-extension-pause>@csrf @method('PUT')
        <input type="hidden" name="version" value="{{ $extension->availability_version }}">
        <input type="hidden" name="paused" value="{{ $extension->paused_at ? 0 : 1 }}">
        <button class="min-h-11 rounded-xl border border-slate-300 bg-white px-4 text-xs font-bold hover:bg-slate-50">{{ $extension->paused_at ? 'ادامه دریافت تماس' : 'توقف دریافت تماس' }}</button>
    </form>
    @endif
</div>
