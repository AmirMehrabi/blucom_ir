<?php

namespace App\Services\AudioCalls;

use App\Models\OutboundRoute;
use App\Models\SipNumber;
use App\Models\Tenant;
use App\Services\Commerce\LineEntitlementService;
use App\Services\OutboundCalling\CallPolicyService;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class AudioCallPolicy
{
    public function lines(Tenant $tenant): Collection
    {
        return SipNumber::query()->with('providerGateway')
            ->where('tenant_id', $tenant->id)
            ->when(! config('audio_calls.all_lines'), fn ($query) => $query->whereIn('id', config('audio_calls.line_ids')))
            ->where('status', 'assigned')->where('enabled', true)->where('outbound_enabled', true)
            ->orderBy('id')->get()->filter(fn (SipNumber $line) => $this->eligible($tenant, $line));
    }

    public function line(Tenant $tenant, ?int $id, string $destination): SipNumber
    {
        $lines = $this->lines($tenant);
        $line = $id === null && $lines->count() === 1 ? $lines->first() : $lines->firstWhere('id', $id);
        if (! $line) {
            throw ValidationException::withMessages(['caller_id_id' => 'Select an enabled caller ID for audio calls.']);
        }
        $route = new OutboundRoute;
        $route->setRelation('sipNumber', $line);
        app(CallPolicyService::class)->destination($route, $destination);

        return $line;
    }

    private function eligible(Tenant $tenant, SipNumber $line): bool
    {
        $gateway = $line->providerGateway;

        return config('audio_calls.enabled') && $tenant->isActive() && $gateway?->enabled
            && $gateway->approved_for_outbound && $gateway->verification_status === 'approved'
            && $gateway->profile === 'external' && $gateway->context === 'public'
            && preg_match('/^[a-zA-Z0-9_-]+$/D', $gateway->name)
            && ($gateway->tenant_id === null || ($gateway->tenant_id === $tenant->id && config('voip.gateway_xml_enabled')))
            && preg_match('/^\+[1-9][0-9]{6,14}$/D', $line->normalized_number)
            && app(LineEntitlementService::class)->allows($line);
    }
}
