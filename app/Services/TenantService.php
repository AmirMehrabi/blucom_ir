<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Tenant;
use App\Models\User;
use Symfony\Component\HttpKernel\Exception\HttpException;

class TenantService
{
    public function __construct(private readonly BlucomOwner $owner) {}

    /**
     * Resolve explicit membership. Access must never provision a shared membership.
     *
     * @throws HttpException when the tenant is disabled
     */
    public function forUser(User|Customer $user): Tenant
    {
        if ($user->isAdmin()) {
            return $this->owner->get();
        }

        $tenant = $user->tenant()->first();
        abort_unless($tenant !== null && $user->canAccessTenant($tenant), 403, 'عضویت سازمانی معتبر لازم است.');

        return $tenant;
    }
}
