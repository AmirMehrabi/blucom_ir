<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['tenant_id', 'name', 'strategy', 'max_wait_seconds', 'fallback_extension_id', 'enabled'])]
class CallQueue extends Model
{
    public const STRATEGIES = [
        'longest-idle-agent' => 'آزادترین پاسخ‌گو',
        'ring-all' => 'زنگ هم‌زمان برای همه',
        'round-robin' => 'به نوبت',
    ];

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(SipExtension::class, 'call_queue_members')->withTimestamps();
    }

    public function fallbackExtension(): BelongsTo
    {
        return $this->belongsTo(SipExtension::class, 'fallback_extension_id');
    }

    public function callRecords(): HasMany
    {
        return $this->hasMany(CallRecord::class);
    }

    public function freeSwitchName(): string
    {
        return 'blucom_q_'.$this->id.'@default';
    }
}
