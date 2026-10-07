<?php

namespace App\Services\Commerce;

use App\Models\SipGateway;
use App\Models\SipNumber;
use App\Services\NumberNormalizer;

class NumberReadinessService
{
    public function fingerprint(SipNumber $number, ?SipGateway $gateway): string
    {
        // Hash operational configuration, never authentication material.
        return hash('sha256', json_encode([
            $number->normalized_number, $number->provider_gateway_id, $number->enabled,
            $number->inbound_enabled, $number->outbound_enabled, $number->destination_prefixes,
            $gateway?->only(['name', 'tenant_id', 'host', 'port', 'transport', 'profile', 'context', 'enabled', 'verification_status', 'approved_for_outbound', 'commerce_revision']),
        ], JSON_THROW_ON_ERROR));
    }

    public function issues(SipNumber $number, ?SipGateway $gateway, bool $requireReview = true): array
    {
        $issues = [];
        if ($number->tenant_id !== null || $number->requested_by_user_id !== null || $number->current_reservation_id !== null
            || $number->current_assignment_id !== null || ! in_array($number->inventory_state, ['draft', 'available'], true)
            || $number->status !== SipNumber::STATUS_AVAILABLE) {
            $issues[] = 'شماره باید موجودی آزاد و بدون مالک یا درخواست قبلی باشد.';
        }
        if (! $number->enabled || ! $number->inbound_enabled || ! $number->outbound_enabled) {
            $issues[] = 'پلن اولیه به قابلیت ورودی و خروجی فعال نیاز دارد.';
        }
        if (! preg_match('/^\+[1-9][0-9]{3,14}$/D', $number->normalized_number) || app(NumberNormalizer::class)->normalize($number->normalized_number) !== $number->normalized_number) {
            $issues[] = 'شماره استاندارد معتبر نیست.';
        }
        if ($gateway === null || $gateway->id !== $number->provider_gateway_id || $gateway->tenant_id !== null || ! $gateway->enabled
            || $gateway->verification_status !== SipGateway::STATUS_APPROVED || ! $gateway->approved_for_outbound
            || $gateway->profile !== 'external' || $gateway->context !== 'public'
            || ! preg_match('/^[a-zA-Z0-9_-]+$/D', $gateway->name)
            || ! preg_match('/^[a-zA-Z0-9.\-]+$/D', $gateway->host)
            || ! in_array($gateway->transport, ['udp', 'tcp', 'tls'], true) || $gateway->port < 1 || $gateway->port > 65535) {
            $issues[] = 'اتصال زیرساخت باید فعال، تأییدشده و مجاز با پروفایل external و زمینه public باشد.';
        }
        $prefixes = $number->destination_prefixes;
        if (! is_array($prefixes) || $prefixes === [] || count(array_filter($prefixes, fn ($prefix) => is_string($prefix) && preg_match('/^\+[1-9][0-9]{0,14}$/D', $prefix))) !== count($prefixes)) {
            $issues[] = 'پیش‌شماره‌های مجاز مقصد باید صریح و استاندارد باشند.';
        }
        if ($number->inboundRoute()->exists() || $number->outboundRoutes()->exists()) {
            $issues[] = 'موجودی فروشی نباید مسیر تماس مشتری داشته باشد.';
        }
        if ($requireReview && (! $number->reviewed_at || ! $number->reviewed_by_user_id || ! $number->readiness_evidence
            || $number->readiness_fingerprint !== $this->fingerprint($number, $gateway))) {
            $issues[] = 'بررسی فنی معتبر برای تنظیمات فعلی لازم است.';
        }

        return $issues;
    }
}
