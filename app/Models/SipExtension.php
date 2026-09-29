<?php

namespace App\Models;

use Database\Factories\SipExtensionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'tenant_id',
    'extension',
    'password_encrypted',
    'display_name',
    'enabled',
    'queue_status',
])]
#[Hidden(['password_encrypted'])]
class SipExtension extends Model
{
    /** @use HasFactory<SipExtensionFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'password_encrypted' => 'encrypted',
            'enabled' => 'boolean',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function outboundRoute(): HasOne
    {
        return $this->hasOne(OutboundRoute::class);
    }

    public function freeSwitchAgentName(): string
    {
        return 'blucom_a_'.$this->id.'@default';
    }
}
