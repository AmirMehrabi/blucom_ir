<?php

namespace App\Services\Commerce;

use App\Models\CommerceOrder;
use App\Models\Customer;
use App\Models\PaymentAttempt;
use App\Models\PaymentGateway;
use App\Models\SipNumber;
use App\Support\Permissions;
use DateTimeInterface;
use IntlDateFormatter;

/** Explicit customer view data: no merchant, bank correlation or infrastructure models. */
class CustomerCommercePresenter
{
    public static function digits(string|int $value): string
    {
        return strtr((string) $value, ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹']);
    }

    public static function money(int $amount): string
    {
        return self::digits(number_format($amount, 0, '.', '٬'));
    }

    public static function date(DateTimeInterface $date): string
    {
        $format = new IntlDateFormatter('fa_IR@calendar=persian', IntlDateFormatter::NONE, IntlDateFormatter::NONE, 'Asia/Tehran', IntlDateFormatter::TRADITIONAL, 'd MMMM yyyy، HH:mm');

        return (string) $format->format($date);
    }

    public function checkoutReady(): bool
    {
        $gateway = PaymentGateway::query()->where('active', true)->with('currentVersion')->first();

        return config('commerce.checkout_enabled') && $gateway?->enabled && $gateway->currentVersion?->amount_unit_confirmed
            && $gateway->currentVersion?->gateway_unit === 'IRR';
    }

    public function canPurchase(Customer $customer): bool
    {
        return $customer->hasPermission(Permissions::NUMBERS_PURCHASE) && $customer->hasPermission(Permissions::BILLING_MANAGE)
            && $customer->hasPermission(Permissions::BILLING_VIEW);
    }

    public function paymentMode(): array
    {
        $gateway = PaymentGateway::query()->where('active', true)->with('currentVersion')->first();

        return ['isTest' => $gateway?->provider === 'zibal' && ($gateway->currentVersion?->isTest() ?? false),
            'paymentProviderName' => $gateway?->provider === 'zibal' ? 'زیبال' : 'بانک ملت'];
    }

    public function quote(array $quote): array
    {
        $labels = ['extensions' => 'تلفن‌های پاسخ‌گو', 'schedules' => 'زمان‌بندی پاسخ‌گویی', 'ivr' => 'منوی تماس', 'queues' => 'تیم‌های پاسخ‌گویی'];
        $limits = ['extensions' => 'تلفن پاسخ‌گو', 'queues' => 'تیم پاسخ‌گویی', 'ivr_menus' => 'منوی تماس'];

        return [
            'offerId' => $quote['offer_id'], 'number' => self::digits($quote['number']), 'planName' => $quote['plan_name'],
            'amount' => self::money($quote['monthly_amount']),
            'features' => array_values(array_intersect_key($labels, array_flip($quote['features']))),
            'limits' => collect($quote['limits'])->map(fn ($value, $key) => isset($limits[$key]) ? self::digits($value).' '.$limits[$key] : null)->filter()->values()->all(),
        ];
    }

    public function order(CommerceOrder $order, Customer $customer, ?PaymentAttempt $displayAttempt = null): array
    {
        $invoice = $order->invoice;
        $current = $invoice->current_payment_attempt_id === null ? null : PaymentAttempt::query()->where('commerce_invoice_id', $invoice->id)->find($invoice->current_payment_attempt_id);
        $attempt = $displayAttempt ?? $current;
        $provider = $attempt?->provider ?? PaymentGateway::query()->where('active', true)->value('provider');
        $paymentProviderName = $provider === 'zibal' ? 'زیبال' : 'بانک ملت';
        $number = SipNumber::query()->find($order->item->sip_number_id);
        $live = $order->status === 'reserved' && $order->expires_at->isFuture() && $order->reservation->status === 'held'
            && $order->reservation->expires_at->isFuture() && $number?->current_reservation_id === $order->reservation->id
            && $number?->inventory_state === 'reserved' && $number?->tenant_id === null && $number?->current_assignment_id === null;
        $paid = $invoice->paid_payment_attempt_id !== null;
        $authorized = $this->canPurchase($customer);
        $retryable = $current === null || in_array($current->status, ['initiation_failed', 'reversed'], true)
            || $current->hasUndeliveredZibalInitiation();
        $continue = ! $paid && $live && $authorized && $attempt?->status === 'redirect_ready' && $attempt?->id === $current?->id;
        $canStart = ! $paid && $live && $authorized && $retryable && $this->checkoutReady();
        $mode = $this->paymentMode();
        $isTest = $attempt?->isTestPayment() ?? ($live && $mode['isTest']);
        $checkoutIsTest = $canStart ? $mode['isTest'] : $isTest;
        if ($canStart) {
            $paymentProviderName = $mode['paymentProviderName'];
        }
        $canCancel = ! $paid && $order->status === 'reserved' && $order->reservation->status === 'held'
            && $number?->current_reservation_id === $order->reservation->id
            && $customer->hasPermission(Permissions::BILLING_MANAGE)
            && $customer->hasPermission(Permissions::BILLING_VIEW)
            && ! PaymentAttempt::query()->where('commerce_invoice_id', $invoice->id)->where('status', '!=', 'reversed')->where(function ($query) {
                $query->whereNotNull('verified_at')->orWhereNotNull('settled_at')
                    ->orWhere(fn ($operation) => $operation->whereNotNull('operation_token')->where('operation_expires_at', '>', now()));
            })->exists();
        if ($order->status === 'test_completed' || $attempt?->status === 'test_succeeded') {
            $state = ['title' => 'پرداخت آزمایشی با موفقیت تأیید شد', 'description' => 'مبلغی کسر نشده و شماره‌ای به حساب شما اضافه نشده است. رزرو آزمایشی آزاد شد؛ برای خرید واقعی، پرداخت واقعی باید فعال باشد و رزرو جدیدی انجام دهید.', 'badge' => 'آزمایش موفق', 'tone' => 'green'];
        } elseif ($order->status === 'service_cancelled') {
            $state = ['title' => 'سرویس این شماره لغو شد', 'description' => 'شماره دیگر به حساب شما تخصیص ندارد. تاریخچه خرید و پرداخت محفوظ است؛ برای پیگیری بازپرداخت با پشتیبانی تماس بگیرید.', 'badge' => 'سرویس لغوشده', 'tone' => 'slate'];
        } elseif ($paid) {
            $state = $order->status === 'paid_unfulfilled'
                ? ['title' => 'پرداخت تأیید شد؛ سفارش نیاز به بررسی دارد', 'description' => 'پرداخت شما ثبت شده، اما شماره هنوز به حساب شما اضافه نشده است. برای پیگیری با پشتیبانی تماس بگیرید؛ نیازی به پرداخت دوباره نیست.', 'badge' => 'نیازمند پیگیری', 'tone' => 'amber']
                : ['title' => 'پرداخت شما تأیید شد', 'description' => 'سفارش شما برای آماده‌سازی ثبت شده است. خط هنوز آمادهٔ تماس نیست؛ وضعیت آن را از همین صفحه پیگیری کنید.', 'badge' => 'در انتظار آماده‌سازی', 'tone' => 'green'];
        } elseif ($order->reservation->status === 'cancelled' && $order->status === 'reconciliation_required') {
            $state = ['title' => 'رزرو لغو شد؛ نتیجهٔ پرداخت نیاز به بررسی دارد', 'description' => 'شماره آزاد شده است. اگر پرداختی شروع کرده‌اید، آن را ادامه ندهید و نتیجه را با پشتیبانی پیگیری کنید. لغو رزرو، مبلغ بانکی را برگشت نمی‌زند.', 'badge' => 'نیازمند پیگیری', 'tone' => 'amber'];
        } elseif ($live && $attempt?->provider === 'zibal'
            && ($attempt->status === 'initiation_failed' || $attempt->hasUndeliveredZibalInitiation())) {
            $state = ['title' => 'اتصال به درگاه برای شروع پرداخت برقرار نشد', 'description' => 'صفحهٔ پرداخت آماده نشد. تا پایان مهلت رزرو می‌توانید دوباره تلاش کنید؛ اگر مشکل ادامه داشت، با پشتیبانی تماس بگیرید.', 'badge' => 'پرداخت آغاز نشد', 'tone' => 'amber'];
        } elseif ($attempt !== null && in_array($attempt->status, ['initiating', 'verifying', 'settling', 'reversing', 'unknown', 'pending_settlement', 'duplicate_payment'], true)) {
            $state = ['title' => 'نتیجهٔ پرداخت در حال بررسی است', 'description' => 'برای این سفارش پرداخت دیگری انجام ندهید. اگر مبلغی از حساب شما کسر شده، وضعیت سفارش را پیگیری کنید یا با پشتیبانی تماس بگیرید.', 'badge' => 'در حال بررسی', 'tone' => 'amber'];
        } elseif ($order->status === 'cancelled') {
            $state = ['title' => 'رزرو این شماره لغو شد', 'description' => 'شماره آزاد شده است. برای خرید دوباره، از فهرست شماره‌های موجود انتخاب کنید.', 'badge' => 'رزرو لغوشده', 'tone' => 'slate'];
        } elseif (! $live) {
            $state = ['title' => 'مهلت رزرو این شماره پایان یافت', 'description' => 'برای خرید، دوباره از بین شماره‌های موجود انتخاب کنید. اگر مبلغی پرداخت کرده‌اید، پیش از پرداخت مجدد با پشتیبانی تماس بگیرید.', 'badge' => 'رزرو پایان‌یافته', 'tone' => 'slate'];
        } elseif ($attempt?->status === 'initiation_failed') {
            $state = ['title' => 'شروع پرداخت امکان‌پذیر نشد', 'description' => 'ارتباط با درگاه پرداخت برقرار نشد. می‌توانید تا پایان مهلت رزرو دوباره تلاش کنید.', 'badge' => 'پرداخت تکمیل‌نشده', 'tone' => 'amber'];
        } elseif ($attempt?->status === 'reversed') {
            $state = ['title' => 'برگشت این پرداخت در بانک ثبت شد', 'description' => 'این سفارش هنوز پرداخت نشده است. زمان بازگشت مبلغ به حساب را از بانک خود پیگیری کنید.', 'badge' => 'پرداخت برگشت‌خورده', 'tone' => 'slate'];
        } else {
            $state = ['title' => 'شماره برای شما رزرو شده است', 'description' => 'مبلغ و جزئیات را بررسی کنید و تا پایان مهلت رزرو، پرداخت را انجام دهید.', 'badge' => 'در انتظار پرداخت', 'tone' => 'blue'];
        }

        $allocated = $paid && $order->status === 'allocated' && $number?->tenant_id === $customer->tenant_id
            && app(LineEntitlementService::class)->allows($number, false);
        if ($allocated) {
            $state = ['title' => 'خط شما آمادهٔ تنظیم است', 'description' => 'پاسخ‌گوی خط را انتخاب کنید و تلفن خود را وصل کنید. دوره اشتراک از اولین ذخیره پاسخ‌گو شروع می‌شود.', 'badge' => 'خرید تکمیل شد', 'tone' => 'green'];
        }
        if (($canStart ? $checkoutIsTest : $isTest) && $order->status !== 'test_completed' && $attempt?->status !== 'test_succeeded') {
            $state['description'] .= ' این پرداخت آزمایشی است؛ مبلغی کسر نمی‌شود، اما مبلغ سفارش، تخصیص شماره و اشتراک مانند خرید واقعی ثبت می‌شوند.';
        }

        return [...$this->quote($order->item->snapshot), ...$state,
            'publicId' => $order->public_id, 'reference' => self::digits($order->id), 'invoiceNumber' => $invoice->invoice_number,
            'buyer' => $invoice->buyer_snapshot['customer_name'], 'business' => $invoice->buyer_snapshot['business_name'],
            'issued' => self::date($invoice->issued_at), 'expires' => self::date($order->expires_at),
            'expiryIso' => $order->expires_at->toIso8601String(), 'nowIso' => now()->toIso8601String(),
            'remaining' => self::digits(max(0, (int) ceil(now()->diffInSeconds($order->expires_at, false) / 60))),
            'setupUrl' => $allocated ? route('customer.lines.show', $number->id) : null,
            'paid' => $paid, 'paidAt' => $invoice->paid_at === null ? null : self::date($invoice->paid_at),
            'live' => $live, 'canStart' => $canStart, 'canContinue' => $continue, 'canCancel' => $canCancel,
            'continueUrl' => $continue ? route('customer.payments.show', $attempt->public_id) : null,
            'invoiceId' => $invoice->public_id, 'canPurchase' => $authorized, 'paymentProviderName' => $paymentProviderName,
            'isTest' => $isTest, 'checkoutIsTest' => $checkoutIsTest,
            'legacyTest' => $order->status === 'test_completed' || $attempt?->status === 'test_succeeded',
        ];
    }
}
