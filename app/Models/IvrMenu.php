<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['tenant_id', 'name', 'draft_config', 'published_config', 'previous_config', 'version', 'enabled', 'published_at'])]
class IvrMenu extends Model
{
    protected function casts(): array
    {
        return [
            'draft_config' => 'array',
            'published_config' => 'array',
            'previous_config' => 'array',
            'enabled' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function inboundRoutes(): HasMany
    {
        return $this->hasMany(InboundRoute::class, 'destination_id')
            ->where('destination_type', InboundRoute::DESTINATION_IVR);
    }

    public function isPublished(): bool
    {
        return $this->enabled && $this->published_config !== null;
    }
}
