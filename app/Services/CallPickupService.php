<?php

namespace App\Services;

use App\Models\PickupGroup;
use App\Models\SipExtension;
use App\Services\Commerce\LineEntitlementService;

class CallPickupService
{
    /** Resolve only database-authorized targets; never accept a SIP-supplied tenant or UUID. */
    public function targets(SipExtension $caller, string $code): array
    {
        $tenant = $caller->tenant;
        if (! config('voip.pickup_enabled') || ! $caller->enabled || ! $caller->pickup_allowed
            || ! $tenant?->isActive() || ! $tenant->pickup_enabled || ! $caller->pickup_group_id
            || ! app(LineEntitlementService::class)->tenantAllows($tenant, true)
            || ! PickupGroup::query()->where('tenant_id', $tenant->id)->whereKey($caller->pickup_group_id)->exists()) {
            return [];
        }
        $query = SipExtension::query()->where('tenant_id', $tenant->id)
            ->where('pickup_group_id', $caller->pickup_group_id)->where('enabled', true)->where('id', '!=', $caller->id);
        if ($code === '*8' && $tenant->pickup_group) {
            return $query->orderBy('id')->pluck('id')->all();
        }
        if ($tenant->pickup_directed && preg_match('/^\*\*([1-9][0-9]{2,8})$/D', $code, $matches)) {
            return $query->where('extension', $matches[1])->pluck('id')->all();
        }

        return [];
    }
}
