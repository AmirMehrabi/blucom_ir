<section class="panel p-6">
    <h2 class="text-lg font-bold">{{ $tab === 'overview' ? 'تراکنش‌های اخیر' : 'تراکنش‌های کیف پول' }}</h2>
    <p class="mt-2 text-sm leading-7 text-slate-600">فقط مبالغ تأییدشده در موجودی محاسبه می‌شوند. شارژ و پرداخت خرید، دو تراکنش مرتبط هستند.</p>
    @if($pendingTopups->isNotEmpty())
        <div class="mt-5 space-y-3"><h3 class="font-bold">شارژهای ناتمام</h3>@foreach($pendingTopups as $topup)<div class="flex flex-wrap items-center justify-between gap-3 rounded-xl bg-amber-50 p-4 text-sm"><div><p class="font-bold">{{ \App\Services\Commerce\CustomerCommercePresenter::money($topup->total_amount) }} تومان</p><p class="mt-1 text-amber-900">هنوز به موجودی اضافه نشده است؛ پیش از شارژ دوباره، وضعیت را بررسی کنید.</p></div><a class="commerce-secondary" href="{{ route('customer.billing.invoice', $topup->public_id) }}">بررسی و ادامه شارژ</a></div>@endforeach</div>
    @endif
    <div class="mt-5 space-y-3 sm:hidden">
        @forelse($transactions as $transaction)
            <article class="rounded-xl border border-slate-200 p-4 text-sm">
                <div class="flex flex-wrap items-start justify-between gap-2"><h3 class="font-bold">{{ $transaction->description }}</h3><p class="{{ $transaction->amount > 0 ? 'text-emerald-800' : 'text-slate-900' }}">{{ $transaction->amount > 0 ? 'واریز' : 'برداشت' }} {{ \App\Services\Commerce\CustomerCommercePresenter::money(abs($transaction->amount)) }} تومان</p></div>
                <p class="mt-2 text-xs leading-6 text-slate-500">{{ \App\Services\Commerce\CustomerCommercePresenter::date($transaction->created_at) }}</p>
                <p class="mt-2 text-slate-600">موجودی پس از تراکنش: {{ \App\Services\Commerce\CustomerCommercePresenter::money($transaction->balance_after) }} تومان</p>
                <a class="commerce-link mt-3 inline-flex min-h-11 items-center" href="{{ route('customer.billing.invoice', $transaction->invoice->public_id) }}">مشاهده رسید</a>
            </article>
        @empty<p class="py-8 text-center text-sm leading-7 text-slate-500">هنوز تراکنشی ندارید. اولین شارژ یا خرید شما در همین‌جا ثبت می‌شود.</p>@endforelse
    </div>
    <div class="relative mt-5 hidden overflow-x-auto sm:block"><table class="w-full whitespace-nowrap text-right text-sm"><caption class="sr-only">تاریخچه ورود و خروج پول کسب‌وکار</caption><thead><tr class="border-b text-slate-500"><th class="p-3">زمان</th><th class="p-3">شرح</th><th class="p-3">واریز / برداشت (تومان)</th><th class="p-3">موجودی پس از تراکنش</th><th class="p-3">رسید</th></tr></thead><tbody>
    @forelse($transactions as $transaction)<tr class="border-b border-slate-100"><td class="p-3">{{ \App\Services\Commerce\CustomerCommercePresenter::date($transaction->created_at) }}</td><td class="p-3">{{ $transaction->description }}</td><td class="p-3 {{ $transaction->amount > 0 ? 'text-emerald-800' : 'text-slate-900' }}">{{ $transaction->amount > 0 ? 'واریز' : 'برداشت' }} {{ \App\Services\Commerce\CustomerCommercePresenter::money(abs($transaction->amount)) }}</td><td class="p-3">{{ \App\Services\Commerce\CustomerCommercePresenter::money($transaction->balance_after) }}</td><td class="p-3"><a class="commerce-link" href="{{ route('customer.billing.invoice', $transaction->invoice->public_id) }}">مشاهده رسید<span class="sr-only"> {{ $transaction->invoice->invoice_number }}</span></a></td></tr>
    @empty<tr><td colspan="5" class="py-10 text-center text-slate-500">هنوز تراکنشی ندارید. اولین شارژ یا خرید شما در همین‌جا ثبت می‌شود.</td></tr>@endforelse
    </tbody></table></div>
    <div class="mt-5">@if($tab === 'overview')<a class="commerce-link" href="{{ route('customer.billing.index', ['tab'=>'transactions']) }}">همه تراکنش‌ها</a>@else{{ $transactions->links() }}@endif</div>
</section>
