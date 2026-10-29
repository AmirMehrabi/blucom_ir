<div class="mt-5 space-y-4" data-commerce-page>
    <dl class="space-y-2 rounded-xl bg-slate-50 p-4 text-sm">
        <div class="flex justify-between gap-3"><dt>موجودی کیف پول</dt><dd class="font-bold">{{ \App\Services\Commerce\CustomerCommercePresenter::money($wallet['balance']) }} تومان</dd></div>
        @if($wallet['shortfall'] > 0)<div class="flex justify-between gap-3"><dt>{{ $wallet['fundingAmount'] !== null ? 'مبلغ شارژ ثبت‌شده' : 'مبلغ موردنیاز برای شارژ' }}</dt><dd class="font-bold">{{ \App\Services\Commerce\CustomerCommercePresenter::money($wallet['fundingAmount'] ?? $wallet['shortfall']) }} تومان</dd></div>@endif
    </dl>
    @if($wallet['pending'])
        <p role="status" class="rounded-xl bg-amber-50 p-4 text-sm leading-7 text-amber-900">نتیجه شارژ در حال بررسی است. پس از تأیید بانک، همین خرید خودکار ادامه پیدا می‌کند. پرداخت دوباره لازم نیست.</p>
        <a class="commerce-secondary" href="{{ url()->current() }}">به‌روزرسانی وضعیت</a>
        <a class="commerce-link block text-sm" href="{{ route('contact') }}">پیگیری با پشتیبانی</a>
    @elseif(!$wallet['canManage'])
        <p class="text-sm leading-7 text-slate-600">برای پرداخت از مالک یا مسئول صورتحساب کمک بگیرید.</p>
    @elseif($wallet['continue'])
        <a class="commerce-button w-full" href="{{ $wallet['continue'] }}">ادامه پرداخت قبلی</a>
        <p class="text-sm leading-7 text-slate-600">همان شارژ قبلی را ادامه می‌دهید؛ درخواست پرداخت تازه‌ای ایجاد نمی‌شود.</p>
    @elseif($wallet['ready'])
        <form method="POST" action="{{ route('customer.wallet.pay', $walletInvoice->public_id) }}" data-commerce-submit>
            @csrf
            <input type="hidden" name="idempotency_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
            <button class="commerce-button w-full" data-expiry-action data-busy-label="در حال ادامه پرداخت…">{{ ($wallet['fundingAmount'] ?? $wallet['shortfall']) > 0 ? 'شارژ '.\App\Services\Commerce\CustomerCommercePresenter::money($wallet['fundingAmount'] ?? $wallet['shortfall']).' تومان و تکمیل پرداخت' : 'پرداخت از کیف پول' }}</button>
            <p hidden data-submit-message role="status" class="mt-3 text-sm text-slate-600">لطفاً منتظر بمانید؛ پرداخت شما در حال ثبت است.</p>
        </form>
        <p class="text-sm leading-7 text-slate-600">@if(($wallet['fundingAmount'] ?? $wallet['shortfall']) > 0)پس از شارژ، همین خرید خودکار از کیف پول پرداخت می‌شود. اگر خرید دیگر ممکن نباشد، مبلغ در کیف پول شما می‌ماند.@elseپس از تأیید، {{ \App\Services\Commerce\CustomerCommercePresenter::money($walletInvoice->total_amount) }} تومان از موجودی کسر می‌شود.@endif</p>
    @else
        <p class="rounded-xl bg-amber-50 p-4 text-sm leading-7 text-amber-900">شارژ آنلاین فعلاً در دسترس نیست. موجودی و خرید شما محفوظ‌اند. برای پیگیری با پشتیبانی تماس بگیرید.</p>
        <a class="commerce-secondary" href="{{ route('contact') }}">تماس با پشتیبانی</a>
    @endif
</div>
