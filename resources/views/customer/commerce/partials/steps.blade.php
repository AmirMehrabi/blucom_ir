<nav aria-label="مراحل خرید شماره" class="mb-7">
    <ol class="grid grid-cols-3 gap-2 rounded-2xl border bg-white p-3 sm:gap-5 sm:p-5">
        @foreach (['انتخاب شماره', 'بررسی سفارش', 'پرداخت'] as $index => $label)
        <li @if($step === $index + 1) aria-current="step" @endif class="flex min-w-0 flex-col items-center gap-2 text-center text-xs sm:flex-row sm:text-sm {{ $step >= $index + 1 ? 'text-blue-700' : 'text-slate-500' }}">
            <span aria-hidden="true" class="grid size-8 shrink-0 place-items-center rounded-full font-bold {{ $step >= $index + 1 ? 'bg-blue-600 text-white' : 'bg-slate-100 text-slate-500' }}">{{ \App\Services\Commerce\CustomerCommercePresenter::digits($index + 1) }}</span>
            <span class="font-bold">{{ $label }}</span>
        </li>
        @endforeach
    </ol>
</nav>
