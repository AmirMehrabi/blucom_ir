<div role="status" class="mb-5 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm leading-7 text-amber-900">
    <p class="font-bold">پرداخت آزمایشی — مبلغی کسر نمی‌شود</p>
    @if(($order['legacyTest'] ?? false) || ($result ?? null) === 'test_legacy_success')
    <p>این آزمایش قدیمی شماره‌ای تخصیص نداده است. برای آزمایش کامل خرید و تنظیم خط، رزرو جدیدی انجام دهید.</p>
    @else
    <p>این پرداخت آزمایشی است؛ مبلغ سفارش، تخصیص شماره و اشتراک مانند خرید واقعی ثبت می‌شوند تا بتوانید خط را تنظیم کنید.</p>
    @endif
</div>
