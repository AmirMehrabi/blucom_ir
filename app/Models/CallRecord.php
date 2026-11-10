<?php

namespace App\Models;

use App\Services\CallOutcomeClassifier;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'outbound_call_session_id', 'tenant_id', 'sip_number_id', 'sip_extension_id', 'call_queue_id', 'ivr_menu_id', 'ivr_digit', 'freeswitch_uuid',
    'outcome', 'answer_evidence', 'outcome_version', 'originate_disposition',
    'direction', 'source_number', 'destination_number', 'status', 'hangup_cause',
    'started_at', 'answered_at', 'ended_at', 'duration_seconds', 'billable_seconds', 'queue_wait_seconds', 'queue_outcome',
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
            'queue_wait_seconds' => 'integer',
        ];
    }

    public function reportingOutcome(): string
    {
        return app(CallOutcomeClassifier::class)->forRecord($this);
    }

    public function outcomeLabel(): string
    {
        return CallOutcomeClassifier::LABELS[$this->reportingOutcome()] ?? 'نتیجه نامشخص';
    }

    public function answerEvidenceLabel(): string
    {
        return CallOutcomeClassifier::ANSWER_LABELS[$this->answer_evidence ?? ($this->answered_at ? 'legacy_answer_unverified' : 'none')] ?? 'نامشخص';
    }

    public function recordings(): HasMany
    {
        return $this->hasMany(CallRecording::class);
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

    public function callQueue(): BelongsTo
    {
        return $this->belongsTo(CallQueue::class);
    }

    public function ivrMenu(): BelongsTo
    {
        return $this->belongsTo(IvrMenu::class);
    }
}
