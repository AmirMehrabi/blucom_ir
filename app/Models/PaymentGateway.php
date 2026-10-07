<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentGateway extends Model
{
    protected $fillable = ['enabled', 'revision', 'current_version_id'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'revision' => 'integer'];
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(PaymentGatewayVersion::class, 'current_version_id');
    }
}
