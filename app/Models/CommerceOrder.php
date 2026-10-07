<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasOne;

class CommerceOrder extends CommerceRecord
{
    protected array $mutableFields = ['status'];

    protected function casts(): array
    {
        return ['total_amount' => 'integer', 'expires_at' => 'immutable_datetime'];
    }

    public function item(): HasOne
    {
        return $this->hasOne(CommerceOrderItem::class);
    }

    public function invoice(): HasOne
    {
        return $this->hasOne(CommerceInvoice::class);
    }

    public function reservation(): HasOne
    {
        return $this->hasOne(NumberReservation::class);
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
