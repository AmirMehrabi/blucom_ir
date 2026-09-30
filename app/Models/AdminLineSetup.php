<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tenant_id', 'created_by_user_id', 'data', 'step', 'completed_at', 'sip_number_id', 'announcement_upload'])]
class AdminLineSetup extends Model
{
    protected function casts(): array
    {
        return ['data' => 'array', 'step' => 'integer', 'completed_at' => 'datetime'];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function sipNumber(): BelongsTo
    {
        return $this->belongsTo(SipNumber::class);
    }
}
