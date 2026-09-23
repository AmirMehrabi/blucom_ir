<?php

namespace App\Models;

use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'owner_user_id', 'status'])]
class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [];
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function assertActive(): void
    {
        abort_unless($this->isActive(), 403, 'حساب سازمانی شما غیرفعال است.');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /** @return HasMany<SipNumber, $this> */
    public function sipNumbers(): HasMany
    {
        return $this->hasMany(SipNumber::class);
    }

    /** @return HasMany<SipExtension, $this> */
    public function sipExtensions(): HasMany
    {
        return $this->hasMany(SipExtension::class);
    }

    /** @return HasMany<InboundRoute, $this> */
    public function inboundRoutes(): HasMany
    {
        return $this->hasMany(InboundRoute::class);
    }

    /** @return HasMany<OutboundRoute, $this> */
    public function outboundRoutes(): HasMany
    {
        return $this->hasMany(OutboundRoute::class);
    }
}
