<?php

namespace App\Services\OutboundCalling;

use App\Models\CallRecord;
use App\Models\OutboundCallSession;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class CallStateService
{
    public function event(array $event): void
    {
        $uuid = $event['Unique-ID'] ?? $event['Blucom-Agent-UUID'] ?? '';
        $session = OutboundCallSession::query()->where('agent_uuid', $uuid)->orWhere('customer_uuid', $uuid)->first();
        if (! $session) {
            return;
        }
        $stamp = filter_var($event['Event-Date-Timestamp'] ?? null, FILTER_VALIDATE_INT);
        $at = $stamp ? CarbonImmutable::createFromTimestampUTC($stamp / 1000000) : CarbonImmutable::now();
        $at = $at->min(CarbonImmutable::now());
        DB::transaction(function () use ($session, $event, $uuid, $at) {
            $call = OutboundCallSession::query()->lockForUpdate()->findOrFail($session->id);
            if ($call->terminal()) {
                return;
            }
            $agent = $uuid === $call->agent_uuid;
            $name = $event['Event-Name'] ?? '';
            $stage = null;
            if ($name === 'CHANNEL_ANSWER') {
                $stage = $agent ? 'agent_answered' : 'customer_answered';
            } elseif ($name === 'CHANNEL_PROGRESS' && ! $agent && $call->agent_ready_at) {
                $stage = 'customer_ringing';
            } elseif ($name === 'CHANNEL_BRIDGE') {
                $peer = $event['Other-Leg-Unique-ID'] ??
                    (($event['Bridge-A-Unique-ID'] ?? '') === $uuid ? ($event['Bridge-B-Unique-ID'] ?? '') : ($event['Bridge-A-Unique-ID'] ?? ''));
                if ($peer !== ($agent ? $call->customer_uuid : $call->agent_uuid)) {
                    return;
                }
                $stage = 'bridged';
            } elseif ($name === 'CUSTOM' && ($event['Event-Subclass'] ?? '') === 'blucom::two_party' && $agent) {
                $stage = $event['Blucom-Phase'] ?? '';
                if (! in_array($stage, OutboundCallSession::STAGES, true) && $stage !== 'failed') {
                    return;
                }
                $ready = (int) ($event['Blucom-Agent-Ready-At'] ?? 0);
                if ($ready > 0 && ! $call->agent_ready_at) {
                    $call->agent_ready_at = CarbonImmutable::createFromTimestampUTC($ready)->min($at);
                }
                if ($stage === 'failed') {
                    $reason = $event['Blucom-Failure'] ?? 'control_unavailable';
                    $this->finish($call, 'failed', array_key_exists($reason, OutboundCallSession::FAILURES) ? $reason : 'control_unavailable', $at);

                    return;
                }
            } elseif ($name === 'CHANNEL_HANGUP_COMPLETE') {
                $answered = (int) ($event['Caller-Channel-Answered-Time'] ?? 0);
                $answeredColumn = $agent ? 'agent_answered_at' : 'customer_answered_at';
                if ($answered > 0 && ! $call->$answeredColumn) {
                    $call->$answeredColumn = CarbonImmutable::createFromTimestampUTC($answered / 1000000)->min($at);
                }
                // bridge_epoch is switch-owned evidence, including events missed during an outage.
                $bridge = (int) ($event['variable_bridge_epoch'] ?? 0);
                if (! $call->bridged_at && $bridge > 0) {
                    $call->bridged_at = CarbonImmutable::createFromTimestampUTC($bridge)->min($at);
                }
                $call->hangup_cause = substr((string) ($event['Hangup-Cause'] ?? ''), 0, 64);
                $this->finish($call, $call->cancel_requested_at ? 'cancelled' : ($call->bridged_at ? 'completed' : 'failed'),
                    $call->cancel_requested_at ? 'call_cancelled' : ($call->bridged_at ? null : $this->failure($call->hangup_cause, $agent, $call)), $at);

                return;
            }
            if ($stage) {
                $this->advance($call, $stage, $at);
            }
        });
    }

    public function advance(OutboundCallSession $call, string $stage, ?CarbonImmutable $at = null): void
    {
        if ($call->terminal()) {
            return;
        }
        $at ??= CarbonImmutable::now();
        $column = match ($stage) {
            'agent_answered' => 'agent_answered_at', 'customer_dialing' => 'customer_dialed_at',
            'customer_answered' => 'customer_answered_at', 'bridged' => 'bridged_at', default => null,
        };
        if ($column && ! $call->$column) {
            $call->$column = $at;
        }
        $rank = array_search($stage, OutboundCallSession::STAGES, true);
        $current = array_search($call->status, OutboundCallSession::STAGES, true);
        if ($rank !== false && $rank > $current) {
            $call->status = $stage;
        }
        $call->save();
        $call->events()->firstOrCreate(['event_key' => 'stage:'.$stage], ['status' => $stage, 'occurred_at' => $at]);
    }

    public function finish(OutboundCallSession $call, string $status, ?string $reason, ?CarbonImmutable $at = null): void
    {
        if ($call->terminal()) {
            return;
        }
        $at ??= CarbonImmutable::now();
        $call->status = $status;
        $call->failure_reason = $reason;
        $call->ended_at = $at;
        $call->conversation_seconds = $call->bridged_at ? max(0, (int) $call->bridged_at->diffInSeconds($at, false)) : 0;
        $call->save();
        $call->events()->firstOrCreate(['event_key' => 'terminal'], ['status' => $status, 'reason' => $reason, 'occurred_at' => $at]);
        $this->record($call);
        if (! $call->bridged_at && ! $call->customer_answered_at && $recording = $call->recording()->where('status', 'recording')->first()) {
            $recording->update(['status' => 'empty', 'reserved_bytes' => 0,
                'expires_at' => $at->addDays((int) $recording->policy['retention_days'])]);
        }
    }

    public function record(OutboundCallSession $call): void
    {
        CallRecord::query()->updateOrCreate(['freeswitch_uuid' => $call->agent_uuid], [
            'outbound_call_session_id' => $call->id, 'tenant_id' => $call->tenant_id,
            'sip_number_id' => $call->sip_number_id, 'sip_extension_id' => $call->sip_extension_id,
            'direction' => 'outbound', 'source_number' => $call->caller_id, 'destination_number' => $call->customer_number,
            'status' => $call->bridged_at ? 'answered' : 'failed', 'hangup_cause' => $call->hangup_cause,
            'started_at' => $call->created_at, 'answered_at' => $call->bridged_at, 'ended_at' => $call->ended_at,
            'duration_seconds' => max(0, (int) $call->created_at->diffInSeconds($call->ended_at, false)),
            'billable_seconds' => $call->conversation_seconds,
        ]);
    }

    public function importCdr(OutboundCallSession $session, string $uuid, array $fields): void
    {
        // A delayed CSV is finalization evidence only for the authoritative agent leg.
        if ($uuid !== $session->agent_uuid || $session->terminal() || empty($fields[6])) {
            return;
        }
        try {
            $end = CarbonImmutable::parse($fields[6], config('voip.cdr_timezone', 'UTC'))->utc();
        } catch (\Throwable) {
            return;
        }
        DB::transaction(function () use ($session, $end, $fields) {
            $call = OutboundCallSession::query()->lockForUpdate()->findOrFail($session->id);
            if (app(CallJournalService::class)->recover($call)) {
                return;
            }
            $call->hangup_cause = substr((string) ($fields[9] ?? ''), 0, 64);
            $this->finish($call, $call->cancel_requested_at ? 'cancelled' : ($call->bridged_at ? 'completed' : 'failed'),
                $call->cancel_requested_at ? 'call_cancelled' : ($call->bridged_at ? null : 'control_unavailable'), $end);
        });
    }

    private function failure(string $cause, bool $agent, OutboundCallSession $call): string
    {
        if ($agent && $call->status === 'agent_confirmation') {
            return 'agent_declined_confirmation';
        }
        if ($agent && $call->agent_answered_at) {
            return 'agent_hangup';
        }

        return match ($cause) {
            'USER_BUSY' => $agent ? 'agent_busy' : 'customer_busy',
            'NO_ANSWER', 'NO_USER_RESPONSE', 'ALLOTTED_TIMEOUT' => $agent ? 'agent_unanswered' : 'customer_unanswered',
            'NETWORK_OUT_OF_ORDER', 'NORMAL_TEMPORARY_FAILURE', 'GATEWAY_DOWN', 'DESTINATION_OUT_OF_ORDER' => 'trunk_failure',
            default => $agent ? 'agent_unavailable' : 'customer_unreachable',
        };
    }
}
