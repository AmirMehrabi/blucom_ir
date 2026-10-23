<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tenant_id', 'plan_version_id', 'monthly_amount', 'currency', 'status', 'activated_at', 'period_starts_at', 'period_ends_at', 'grace_ends_at', 'cancel_at', 'pending_plan_version_id', 'pending_change_source', 'pending_effective_at', 'price_notice_sent_at', 'revision', 'anchor_day'])]
class TenantSubscription extends Model
{
    protected function casts(): array
    {
        return ['monthly_amount' => 'integer', 'activated_at' => 'immutable_datetime', 'period_starts_at' => 'immutable_datetime',
            'period_ends_at' => 'immutable_datetime', 'grace_ends_at' => 'immutable_datetime', 'cancel_at' => 'immutable_datetime',
            'pending_effective_at' => 'immutable_datetime', 'price_notice_sent_at' => 'immutable_datetime', 'revision' => 'integer'];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function planVersion(): BelongsTo
    {
        return $this->belongsTo(PlanVersion::class);
    }
}
