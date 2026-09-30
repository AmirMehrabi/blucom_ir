@extends('layouts.portal')
@section('title', 'نمای زنده')
@section('content')
<div class="live-overview" data-live-overview data-state-url="{{ route('live.state', ['organization' => $snapshot['tenant']['id']]) }}" data-availability-url="{{ route('availability.update') }}" data-reverb-key="{{ config('broadcasting.connections.reverb.key') }}">
    <script type="application/json" data-live-initial>@json($snapshot)</script>
    <div class="live-heading">
        <div><div class="live-eyebrow">همین حالا در سازمان شما</div><h1>نمای زنده</h1><p>ببینید چه کسی آماده است و کدام تلفن در حال تماس است.</p></div>
        <div class="live-heading-actions">
            <span class="live-freshness" data-live-health role="status"><i></i><span>در حال دریافت وضعیت…</span></span>
            @if ($organizations->count() > 1)
                <form method="GET" action="{{ route('live.index') }}"><label class="sr-only" for="live-organization">سازمان</label><select id="live-organization" name="organization" aria-label="سازمان" onchange="this.form.submit()">@foreach ($organizations as $organization)<option value="{{ $organization->id }}" @selected($snapshot['tenant']['id'] === $organization->id)>{{ $organization->name }}</option>@endforeach</select></form>
            @else
                <span class="live-organization">{{ $snapshot['tenant']['name'] }}</span>
            @endif
        </div>
    </div>
    <div class="live-warning" data-live-warning hidden role="status"><span class="live-warning-icon">!</span><div><strong data-live-warning-title>وضعیت زنده در دسترس نیست</strong><p data-live-warning-description>تا برقراری اتصال، وضعیت تلفن‌ها نامشخص است.</p></div><button type="button" data-live-refresh>تلاش دوباره</button></div>
    <section class="live-personal" data-live-personal hidden aria-label="وضعیت پاسخ‌گویی شما"><div><span class="live-personal-icon">●</span><strong>تلفن من</strong><span data-live-own-label></span><span class="live-own-state" data-live-own-state></span></div><button type="button" data-live-availability></button><span data-live-availability-error role="alert" hidden></span></section>
    <nav class="live-stats" data-live-stats aria-label="فیلتر وضعیت داخلی‌ها">
        @foreach (['all' => 'همه داخلی‌ها', 'ready' => 'آماده پاسخ', 'active' => 'در حال تماس', 'ringing' => 'در حال زنگ', 'offline' => 'آفلاین'] as $key => $label)
            <button type="button" data-live-filter="{{ $key }}" aria-pressed="{{ $key === 'all' ? 'true' : 'false' }}" class="live-stat {{ $key === 'all' ? 'is-selected' : '' }}"><span class="live-stat-label"><i class="live-dot live-dot-{{ $key }}"></i>{{ $label }}</span><strong data-live-count="{{ $key }}">—</strong><span class="live-stat-foot">{{ $key === 'all' ? 'در این سازمان' : ($key === 'active' ? 'مکالمه یا انتظار اتصال' : ($key === 'ready' ? 'متصل، آزاد و آماده' : 'نمایش داخلی‌ها')) }}</span></button>
        @endforeach
    </nav>
    <section class="live-directory" aria-label="تلفن‌های سازمان">
        <div class="live-directory-heading"><div><h2>تلفن‌های سازمان <span data-live-total></span></h2><p>وضعیت اتصال تلفن، تماس و آمادگی پاسخ‌گویی مستقل هستند.</p></div>@if (auth()->user()->hasPermission('phones.manage') && config('voip.queues_enabled'))<a class="live-link" href="{{ route('teams.index') }}">تنظیم تیم‌ها <span aria-hidden="true">←</span></a>@endif</div>
        <div class="live-toolbar">
            <label class="live-search"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 4 4"/></svg><span class="sr-only">جست‌وجوی نام یا شماره داخلی</span><input type="search" data-live-search placeholder="نام یا شماره داخلی…" autocomplete="off"></label>
            <label><span class="sr-only">تیم</span><select data-live-team aria-label="فیلتر تیم"><option value="all">همه تیم‌ها</option></select></label>
            <label><span class="sr-only">وضعیت</span><select data-live-status aria-label="فیلتر وضعیت"><option value="all">همه وضعیت‌ها</option><option value="ready">آماده پاسخ</option><option value="registered">تلفن متصل</option><option value="active">در حال تماس</option><option value="ringing">در حال زنگ</option><option value="hold">در انتظار</option><option value="break">در استراحت</option><option value="offline">آفلاین</option><option value="disabled">غیرفعال</option><option value="unknown">نامشخص</option></select></label>
            <div class="live-view-switch" role="group" aria-label="شیوه نمایش"><button type="button" data-live-view="grid" aria-pressed="true" aria-label="نمای کارت" class="is-selected"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg></button><button type="button" data-live-view="list" aria-pressed="false" aria-label="نمای فهرست"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M8 6h13M8 12h13M8 18h13M3 6h1M3 12h1M3 18h1"/></svg></button></div>
        </div>
        <div class="live-results-bar"><span data-live-result-count aria-live="polite"></span><button type="button" data-live-clear hidden>پاک کردن فیلترها</button><span class="live-help">برای جزئیات، روی تلفن بزنید.</span></div>
        <div class="live-grid" data-live-grid></div>
        <div class="live-empty" data-live-empty hidden><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="6" y="2" width="12" height="20" rx="3"/><path d="M10 5h4M11 18h2"/></svg><h3 data-live-empty-title>هنوز تلفنی اضافه نشده است</h3><p data-live-empty-description>با افزودن داخلی‌ها، وضعیت آن‌ها اینجا نمایش داده می‌شود.</p></div>
        <noscript><p class="live-empty">برای نمایش وضعیت زنده، جاوااسکریپت مرورگر را فعال کنید.</p></noscript>
    </section>
    <footer class="live-footer"><span><i class="live-dot live-dot-ready"></i>متصل بودن تلفن به معنی آماده بودن شخص نیست.</span><span data-live-updated></span></footer>
    <dialog class="live-drawer" data-live-drawer aria-labelledby="live-detail-title"><div class="live-drawer-top"><span>جزئیات تلفن</span><button type="button" data-live-close aria-label="بستن جزئیات">×</button></div><div data-live-detail></div></dialog>
</div>
@endsection
