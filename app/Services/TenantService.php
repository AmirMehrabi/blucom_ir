<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class TenantService
{
    public function __construct(private readonly BlucomOwner $owner) {}

    /**
     * Resolve (or provision on first use) the tenant that owns VoIP resources for a user.
     *
     * @throws HttpException when the tenant is disabled
     */
    public function forUser(User $user): Tenant
    {
        if ($user->isAdmin()) {
            return $this->owner->get();
        }

        if ($user->tenant_id !== null) {
            $tenant = Tenant::query()->findOrFail($user->tenant_id);
            $tenant->assertActive();

            return $tenant;
        }

        return DB::transaction(function () use ($user): Tenant {
            $user->refresh();

            if ($user->tenant_id !== null) {
                $tenant = Tenant::query()->findOrFail($user->tenant_id);
                $tenant->assertActive();

                return $tenant;
            }

            $tenant = $this->owner->get();

            $user->forceFill(['tenant_id' => $tenant->id])->save();

            return $tenant;
        });
    }
}
