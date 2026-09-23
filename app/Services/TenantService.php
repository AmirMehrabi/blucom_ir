<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class TenantService
{
    /**
     * Resolve (or provision on first use) the tenant that owns VoIP resources for a user.
     */
    public function forUser(User $user): Tenant
    {
        if ($user->tenant_id !== null) {
            return Tenant::query()->findOrFail($user->tenant_id);
        }

        return DB::transaction(function () use ($user): Tenant {
            $user->refresh();

            if ($user->tenant_id !== null) {
                return Tenant::query()->findOrFail($user->tenant_id);
            }

            $tenant = Tenant::query()->create([
                'name' => $user->name ?: ('Tenant '.$user->id),
                'owner_user_id' => $user->id,
                'status' => 'active',
            ]);

            $user->forceFill(['tenant_id' => $tenant->id])->save();

            return $tenant;
        });
    }
}
