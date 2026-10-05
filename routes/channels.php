<?php

use App\Models\Customer;
use App\Models\Tenant;
use App\Models\User;
use App\Services\LiveOverviewService;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('live-overview.{tenantId}', function (User|Customer $user, string $tenantId): bool {
    $overview = app(LiveOverviewService::class);

    return $overview->canView($user)
        && Tenant::query()->whereKey($tenantId)->where('status', 'active')->exists()
        && ($user->isAdmin() || (string) $user->tenant_id === $tenantId);
});
