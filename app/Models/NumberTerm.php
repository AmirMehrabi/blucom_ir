<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['number_assignment_id', 'tenant_id', 'annual_amount', 'currency', 'status', 'activated_at', 'period_starts_at', 'period_ends_at', 'grace_ends_at', 'release_review_at', 'anchor_day', 'anchor_month', 'pending_annual_amount', 'pending_price_effective_at', 'price_notice_sent_at'])]
class NumberTerm extends Model
{
    protected function casts(): array
    {
        return ['annual_amount' => 'integer', 'activated_at' => 'immutable_datetime', 'period_starts_at' => 'immutable_datetime',
            'period_ends_at' => 'immutable_datetime', 'grace_ends_at' => 'immutable_datetime', 'release_review_at' => 'immutable_datetime',
            'pending_annual_amount' => 'integer', 'pending_price_effective_at' => 'immutable_datetime', 'price_notice_sent_at' => 'immutable_datetime'];
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(NumberAssignment::class, 'number_assignment_id');
    }
}
