<?php

namespace App\Models;

class NumberAssignment extends CommerceRecord
{
    protected array $mutableFields = ['released_at', 'cancellation_reason', 'refund_decision', 'returned_to_stock_at'];

    protected function casts(): array
    {
        return ['assigned_at' => 'immutable_datetime', 'released_at' => 'immutable_datetime', 'returned_to_stock_at' => 'immutable_datetime'];
    }

    public function sipNumber(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(SipNumber::class, 'sip_number_id');
    }
}
