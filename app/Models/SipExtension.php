<?php

namespace App\Models;

use Database\Factories\SipExtensionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

#[Fillable([
    'tenant_id',
    'extension',
    'sip_username',
    'password_encrypted',
    'display_name',
    'enabled',
    'queue_status',
    'pickup_group_id',
    'pickup_allowed',
    'max_registrations',
    'voicemail_secret_encrypted',
])]
#[Hidden(['password_encrypted', 'voicemail_secret_encrypted'])]
class SipExtension extends Model
{
    /** @use HasFactory<SipExtensionFactory> */
    use HasFactory;

    protected $attributes = ['max_registrations' => 2];

    protected static function booted(): void
    {
        static::creating(function (self $extension): void {
            // Preserve available legacy usernames; overlapping display numbers
            // get an opaque identity, never another tenant's registration.
            $extension->sip_username ??= self::query()->where('sip_username', $extension->extension)->orWhere(fn ($q) => $q->whereNull('sip_username')->where('extension', $extension->extension))->exists()
                ? 'b'.str_replace('-', '', (string) Str::uuid())
                : $extension->extension;
        });
    }

    public function sipIdentity(): string
    {
        return $this->sip_username ?: $this->extension;
    }

    protected function casts(): array
    {
        return [
            'password_encrypted' => 'encrypted',
            'voicemail_secret_encrypted' => 'encrypted',
            'enabled' => 'boolean',
            'pickup_allowed' => 'boolean',
            'paused_at' => 'immutable_datetime',
            'availability_version' => 'integer',
            'queue_sync_version' => 'integer',
            'queue_synced_at' => 'immutable_datetime',
            'max_registrations' => 'integer',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function callQueues(): BelongsToMany
    {
        return $this->belongsToMany(CallQueue::class, 'call_queue_members');
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
