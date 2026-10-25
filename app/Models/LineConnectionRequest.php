<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['tenant_id', 'customer_id', 'normalized_number', 'provider_name', 'notes', 'status', 'resolution', 'resolved_by_user_id', 'resolved_at'])]
class LineConnectionRequest extends Model
{
    protected function casts(): array
    {
        return ['resolved_at' => 'immutable_datetime'];
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
}
