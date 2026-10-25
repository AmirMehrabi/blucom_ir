<?php

namespace App\Services\Portal;

class PortalLabels
{
    public static function order(string $status): string
    {
        return ['reserved' => 'در انتظار پرداخت', 'allocated' => 'شماره تخصیص یافت', 'paid_pending_allocation' => 'پرداخت‌شده؛ در انتظار آماده‌سازی', 'paid_unfulfilled' => 'پرداخت‌شده؛ نیازمند رسیدگی', 'reconciliation_required' => 'نتیجه پرداخت در حال بررسی', 'cancelled' => 'لغوشده', 'expired' => 'مهلت پایان‌یافته', 'service_cancelled' => 'سرویس لغوشده', 'test_completed' => 'آزمایش پایان‌یافته'][$status] ?? 'نیازمند بررسی';
    }

    public static function invoice(string $status): string
    {
        return ['paid' => 'پرداخت‌شده', 'issued' => 'در انتظار پرداخت', 'void' => 'لغوشده'][$status] ?? 'نیازمند بررسی';
    }

    public static function subscription($subscription): string
    {
        if (! $subscription) {
            return 'بدون اشتراک';
        }
        if ($subscription->status === 'pending_activation') {
            return 'در انتظار راه‌اندازی';
        }
        if ($subscription->cancel_at?->isPast()) {
            return 'لغوشده در پایان دوره';
        }
        if ($subscription->status !== 'active') {
            return 'متوقف‌شده';
        }
        if ($subscription->period_ends_at?->isFuture()) {
            return 'فعال';
        }
        if ($subscription->grace_ends_at?->isFuture()) {
            return 'در مهلت پرداخت';
        }

        return 'متوقف‌شده؛ نیازمند تمدید';
    }
}
