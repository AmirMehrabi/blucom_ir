<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'tenant_id', 'sip_number_id', 'sip_extension_id', 'freeswitch_uuid',
    'direction', 'source_number', 'destination_number', 'status', 'hangup_cause',
    'started_at', 'answered_at', 'ended_at', 'duration_seconds', 'billable_seconds',
])]
class CallRecord extends Model
{
    public const INBOUND = 'inbound';

    public const OUTBOUND = 'outbound';

    public const ANSWERED = 'answered';

    public const MISSED = 'missed';

    public const FAILED = 'failed';

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'answered_at' => 'datetime',
            'ended_at' => 'datetime',
            'duration_seconds' => 'integer',
            'billable_seconds' => 'integer',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function sipNumber(): BelongsTo
    {
        return $this->belongsTo(SipNumber::class);
    }

    public function sipExtension(): BelongsTo
    {
        return $this->belongsTo(SipExtension::class);
    }
}
