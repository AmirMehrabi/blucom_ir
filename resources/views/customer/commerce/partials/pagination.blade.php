@if($pages->previousPageUrl() || $pages->hasMorePages())
<nav aria-label="صفحه‌های فهرست" class="mt-7 flex flex-wrap items-center justify-between gap-3">
    @if($pages->previousPageUrl())<a class="commerce-secondary" rel="prev" href="{{ $pages->previousPageUrl() }}">صفحهٔ قبل</a>@else<span></span>@endif
    <span class="text-sm text-slate-500">صفحهٔ {{ \App\Services\Commerce\CustomerCommercePresenter::digits($pages->currentPage()) }}</span>
    @if($pages->hasMorePages())<a class="commerce-secondary" rel="next" href="{{ $pages->nextPageUrl() }}">صفحهٔ بعد</a>@else<span></span>@endif
</nav>
@endif
