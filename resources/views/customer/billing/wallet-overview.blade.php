<section class="panel p-6" aria-labelledby="wallet-heading">
    <div class="grid gap-6 md:grid-cols-2">
        <div><h2 id="wallet-heading" class="text-lg font-bold">کیف پول کسب‌وکار</h2><p class="mt-3 text-3xl font-black">{{ \App\Services\Commerce\CustomerCommercePresenter::money($walletBalance) }} <small class="text-sm font-normal">تومان</small></p><p class="mt-3 text-sm leading-7 text-slate-600">این موجودی بین اعضای همین کسب‌وکار مشترک است. خرید شماره و تمدید پلن در تراکنش‌ها ثبت می‌شوند.</p><a class="commerce-link mt-3 inline-block" href="{{ route('customer.billing.index', ['tab'=>'transactions']) }}">مشاهده تراکنش‌ها</a></div>
        @if(auth('customer')->user()->hasPermission('billing.manage'))
        <details><summary class="commerce-secondary cursor-pointer">افزایش موجودی کیف پول</summary>
        <form method="POST" action="{{ route('customer.wallet.topup') }}" class="mt-4 space-y-3" data-commerce-submit>
            @csrf
            <input type="hidden" name="idempotency_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
            <label for="wallet-amount" class="block text-sm font-bold">افزایش موجودی (تومان)</label>
            <input id="wallet-amount" name="amount" type="number" inputmode="numeric" min="1000" max="1000000000000" step="1" required value="{{ old('amount', max(1000, $walletShortfall ?: ($subscription?->monthly_amount ?? 100000))) }}" class="field w-full" aria-describedby="wallet-help">
            <button class="commerce-button" data-busy-label="در حال اتصال به درگاه…" @disabled($paymentIsTest || !app(\App\Services\Commerce\CustomerCommercePresenter::class)->checkoutReady())>افزایش موجودی</button>
            <p id="wallet-help" class="text-sm leading-7 text-slate-500">مبلغ به تومان است. پس از تأیید بانک به کیف پول اضافه می‌شود. تمدید سررسیدشده با موجودی کافی و اجازه تمدید خودکار انجام می‌شود.</p>
            @if($paymentIsTest)<p class="text-sm leading-7 text-amber-900">شارژ کیف پول با درگاه آزمایشی انجام نمی‌شود.</p>@elseif(!app(\App\Services\Commerce\CustomerCommercePresenter::class)->checkoutReady())<p class="text-sm leading-7 text-amber-900">شارژ آنلاین فعلاً در دسترس نیست. موجودی فعلی شما محفوظ است.</p>@endif
            <p hidden data-submit-message role="status" class="text-sm text-slate-600">لطفاً منتظر بمانید؛ در حال آماده‌کردن پرداخت هستیم.</p>
        </form>
        </details>
        @endif
    </div>
</section>
