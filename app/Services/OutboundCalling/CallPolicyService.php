<?php

namespace App\Services\OutboundCalling;

use App\Models\NumberRecordingSetting;
use App\Models\OutboundRoute;
use App\Models\Tenant;
use App\Services\Commerce\LineEntitlementService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CallPolicyService
{
    public function settings(Tenant $tenant): array
    {
        $stored = DB::table('tenant_calling_settings')->where('tenant_id', $tenant->id)->value('settings');

        return array_replace([
            'agent_timeout' => (int) config('voip.two_party.agent_timeout'), 'customer_timeout' => (int) config('voip.two_party.customer_timeout'), 'confirmation_timeout' => (int) config('voip.two_party.confirmation_timeout'),
            'require_confirmation' => false, 'recording' => false, 'agent_audio_id' => null, 'customer_audio_id' => null,
        ], $stored ? json_decode($stored, true, 512, JSON_THROW_ON_ERROR) : []);
    }

    public function enabled(Tenant $tenant): bool
    {
        $ids = config('voip.two_party.tenant_ids', []);

        return config('voip.two_party.enabled') && ($ids === [] || in_array($tenant->id, $ids, true));
    }

    public function route(Tenant $tenant, int $extensionId, int $numberId): OutboundRoute
    {
        $route = OutboundRoute::query()->with(['sipExtension', 'sipNumber', 'gateway'])
            ->where('tenant_id', $tenant->id)->where('sip_extension_id', $extensionId)
            ->where('sip_number_id', $numberId)->where('enabled', true)->first();
        $extension = $route?->sipExtension;
        $number = $route?->sipNumber;
        $gateway = $route?->gateway;
        if (! $tenant->isActive() || ! $extension?->enabled || $extension->tenant_id !== $tenant->id
            || ! $number?->enabled || $number->tenant_id !== $tenant->id || $number->status !== 'assigned'
            || ! $number->outbound_enabled || ! $gateway?->enabled || ! $gateway->approved_for_outbound
            || $gateway->verification_status !== 'approved' || $gateway->profile !== 'external' || $gateway->context !== 'public'
            || ! preg_match('/^[a-zA-Z0-9_-]+$/D', $gateway->name)
            || ($gateway->tenant_id !== null && ($gateway->tenant_id !== $tenant->id || ! config('voip.gateway_xml_enabled')))
            || ($number->provider_gateway_id !== null && $number->provider_gateway_id !== $gateway->id)
            || ($tenant->owner_customer_id !== null && $number->provider_gateway_id !== $gateway->id)
            || ! app(LineEntitlementService::class)->allows($number)
            || ! preg_match('/^\+[1-9][0-9]{6,14}$/D', $number->normalized_number)) {
            throw ValidationException::withMessages(['caller_id' => 'داخلی، شماره یا مسیر خروجی مجاز و فعال نیست.']);
        }

        return $route;
    }

    public function destination(OutboundRoute $route, string $number): void
    {
        $allowed = config('voip.two_party.allowed_prefixes', []);
        $linePrefixes = $route->sipNumber->destination_prefixes;
        $matches = fn ($prefixes) => is_array($prefixes) && collect($prefixes)->contains(fn ($p) => is_string($p)
            && preg_match('/^\+[1-9][0-9]*$/D', $p) && str_starts_with($number, $p));
        if (! preg_match('/^\+[1-9][0-9]{6,14}$/D', $number) || ! $matches($allowed)
            || ($route->sipNumber->inventory_state !== null && ! $matches($linePrefixes))
            || $matches(config('voip.two_party.denied_prefixes', []))) {
            throw ValidationException::withMessages(['customer_number' => 'تماس با این مقصد مجاز نیست.']);
        }
    }

    public function recordingAllowed(OutboundRoute $route): bool
    {
        return config('voip.recordings.enabled') && NumberRecordingSetting::query()
            ->where('tenant_id', $route->tenant_id)->where('sip_number_id', $route->sip_number_id)
            ->whereIn('directions', ['outbound', 'both'])->exists();
    }
}
