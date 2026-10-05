<?php

namespace App\Models;

use App\Enums\CustomerRole;
use App\Support\Permissions;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['tenant_id', 'name', 'mobile', 'role', 'sip_extension_id', 'mobile_verified_at', 'disabled_at'])]
#[Hidden(['remember_token'])]
class Customer extends Authenticatable
{
    use Notifiable;

    protected function casts(): array
    {
        return ['role' => CustomerRole::class, 'mobile_verified_at' => 'datetime', 'disabled_at' => 'datetime'];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function sipExtension(): BelongsTo
    {
        return $this->belongsTo(SipExtension::class);
    }

    public function permissions(): HasMany
    {
        return $this->hasMany(CustomerPermission::class);
    }

    public function isAdmin(): bool
    {
        return false;
    }

    public function isDisabled(): bool
    {
        return $this->disabled_at !== null;
    }

    public function canAccessTenant(Tenant $tenant): bool
    {
        return ! $this->isDisabled() && $tenant->isActive()
            && $this->tenant_id === $tenant->id && $tenant->system_key === null
            && $tenant->owner_customer_id !== null
            && ($this->role !== CustomerRole::Owner || $tenant->owner_customer_id === $this->id)
            && $tenant->customerOwner()->where('tenant_id', $tenant->id)->where('role', CustomerRole::Owner)->exists();
    }

    public function hasPermission(string $permission): bool
    {
        if (! in_array($permission, Permissions::CUSTOMER_ASSIGNABLE, true)) {
            return false;
        }
        $tenant = $this->tenant()->first();

        return $tenant !== null && $this->canAccessTenant($tenant)
            && $this->permissions()->where('permission', $permission)->exists();
    }

    public function homePath(): string
    {
        if ($this->hasPermission(Permissions::DASHBOARD_VIEW)) {
            return '/dashboard';
        }

        return $this->hasPermission(Permissions::LINES_VIEW) ? '/setup/lines' : '/access-denied';
    }
}
