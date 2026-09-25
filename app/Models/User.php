<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\UserType;
use App\Support\Permissions;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'mobile', 'user_type', 'mobile_verified_at', 'disabled_at', 'tenant_id'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'mobile_verified_at' => 'datetime',
            'disabled_at' => 'datetime',
            'user_type' => UserType::class,
            'password' => 'hashed',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function isDisabled(): bool
    {
        return $this->disabled_at !== null;
    }

    public function isAdmin(): bool
    {
        return $this->user_type === UserType::Admin;
    }

    public function permissions(): HasMany
    {
        return $this->hasMany(UserPermission::class);
    }

    public function hasPermission(string $permission): bool
    {
        return ! $this->isDisabled() && ($this->isAdmin() || $this->permissions()->where('permission', $permission)->exists());
    }

    public function homePath(): string
    {
        if ($this->hasPermission(Permissions::DASHBOARD_VIEW)) {
            return '/dashboard';
        }
        if ($this->hasPermission(Permissions::LINES_VIEW)) {
            return '/setup/lines';
        }
        if ($this->hasPermission(Permissions::PROVIDERS_MANAGE)) {
            return '/setup/provider';
        }
        if ($this->hasPermission(Permissions::NUMBERS_MANAGE)) {
            return '/setup/number';
        }

        return '/access-denied';
    }
}
