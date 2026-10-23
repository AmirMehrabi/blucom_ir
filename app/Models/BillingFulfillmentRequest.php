<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['commerce_order_id', 'tenant_id', 'customer_id', 'choice', 'status', 'resolution', 'resolved_by_user_id', 'resolved_at'])]
class BillingFulfillmentRequest extends Model
{
    protected function casts(): array
    {
        return ['resolved_at' => 'immutable_datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(CommerceOrder::class, 'commerce_order_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
