<?php

namespace App\Services;

use App\Models\InboundRoute;
use App\Models\OutboundRoute;
use App\Models\SipExtension;
use App\Services\OutboundCalling\CallPolicyService;
use Illuminate\Validation\ValidationException;

class LineUnansweredService
{
    /** Recheck policy when generating XML, so revoked routes never keep forwarding. */
    public function forwardingRoute(InboundRoute $route, string $phone): OutboundRoute
    {
        if ($route->destination_type !== 'extension' || ! $route->sipNumber || ! $route->tenant) {
            throw ValidationException::withMessages(['unanswered' => 'برای انتقال، ابتدا یک داخلی پاسخ‌گو تعیین کنید.']);
        }
        $policy = app(CallPolicyService::class);
        $outbound = $policy->route($route->tenant, $route->destination_id, $route->sip_number_id);
        $policy->destination($outbound, $phone);
        if ($phone === $route->sipNumber->normalized_number || $route->tenant->sipNumbers()->where('normalized_number', $phone)->exists()) {
            throw ValidationException::withMessages(['forward_phone' => 'انتقال به خط‌های همین سازمان باعث تکرار تماس می‌شود؛ یک شماره بیرونی انتخاب کنید.']);
        }

        return $outbound;
    }

    public function mailbox(InboundRoute $route): ?SipExtension
    {
        if (! config('voip.voicemail_enabled') || $route->destination_type !== 'extension') {
            return null;
        }

        return SipExtension::query()->where('tenant_id', $route->tenant_id)->where('enabled', true)
            ->whereNotNull('voicemail_secret_encrypted')->find($route->destination_id);
    }
}
