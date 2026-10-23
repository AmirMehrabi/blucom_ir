<?php

namespace App\Services\OutboundCalling;

use App\Models\CallRecording;
use App\Models\NumberRecordingSetting;
use App\Models\OutboundCallSession;
use App\Services\FreeSwitch\EventSocket;
use App\Services\RecordingAnnouncementService;
use App\Services\RecordingPolicyService;
use App\Services\RecordingStorageService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/** Typed, server-only switch operations. No command text accepted from HTTP. */
class SwitchCallControl
{
    public function __construct(private EventSocket $socket) {}

    public function connect(): void
    {
        $this->socket->connect();
    }

    public function close(): void
    {
        $this->socket->close();
    }

    public function originate(OutboundCallSession $call): void
    {
        $c = $call->configuration;
        $domain = (string) config('voip.directory_domain');
        if (! preg_match('/^[a-zA-Z0-9_.-]+$/D', $domain) || ! preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $c['sip_username'])
            || ! preg_match('/^[a-zA-Z0-9_-]+$/D', $c['gateway']) || ! preg_match('/^\+[1-9][0-9]{6,14}$/D', $call->customer_number)) {
            throw new RuntimeException('Invalid call configuration.');
        }
        $vars = [
            'blucom_result_path' => app(CallJournalService::class)->path($call),
            'origination_uuid' => $call->agent_uuid, 'originate_timeout' => (int) $c['agent_timeout'],
            'ignore_early_media' => 'true', 'hangup_after_bridge' => 'true',
            'origination_caller_id_number' => ltrim($call->caller_id, '+'), 'origination_caller_id_name' => 'Blucom',
            'accountcode' => 'btenant_'.$call->tenant_id, 'blucom_session_id' => $call->id,
            'blucom_agent_uuid' => $call->agent_uuid, 'blucom_customer_uuid' => $call->customer_uuid,
            'blucom_customer_number' => $call->customer_number, 'blucom_caller_id' => ltrim($call->caller_id, '+'),
            'blucom_gateway' => $c['gateway'], 'blucom_customer_timeout' => (int) $c['customer_timeout'],
            'blucom_require_confirmation' => $c['require_confirmation'] ? 'true' : 'false',
            'blucom_confirmation_timeout' => (int) $c['confirmation_timeout'], 'blucom_max_seconds' => (int) $c['max_seconds'],
            'blucom_agent_audio' => $c['agent_audio_path'] ?? '', 'blucom_customer_audio' => $c['customer_audio_path'] ?? '',
        ];
        if (! empty($c['recording'])) {
            $setting = NumberRecordingSetting::query()->where('tenant_id', $call->tenant_id)
                ->where('sip_number_id', $call->sip_number_id)->first();
            if ($setting?->announcement_enabled) {
                if (! app(RecordingAnnouncementService::class)->validPath($call->number, $setting->announcement_path)) {
                    throw new RuntimeException('Required recording notice is unavailable.');
                }
                $vars['blucom_recording_notice'] = Storage::disk('ivr')->path($setting->announcement_path);
            }
            $recording = app(RecordingPolicyService::class)->reserve($call->number, 'outbound', ['Caller-Unique-ID' => $call->agent_uuid]);
            if (! $recording) {
                CallRecording::query()->firstOrCreate(['freeswitch_uuid' => $call->agent_uuid], [
                    'id' => (string) Str::uuid(), 'tenant_id' => $call->tenant_id,
                    'sip_number_id' => $call->sip_number_id, 'outbound_call_session_id' => $call->id,
                    'direction' => 'outbound', 'status' => 'failed', 'failure_reason' => 'reservation_failed',
                    'policy' => ['coverage' => 'conversation', 'retention_days' => 30, 'max_seconds' => 0],
                    'reserved_bytes' => 0, 'bytes' => 0,
                ]);
            }
            if ($recording) {
                $recording->update(['outbound_call_session_id' => $call->id]);
                if ($recording->status === 'recording') {
                    $vars['blucom_recording_path'] = app(RecordingStorageService::class)->spoolPath($recording->id);
                    $vars['blucom_recording_complete'] = app(RecordingStorageService::class)->spoolPath($recording->id, 'complete');
                    $vars['blucom_recording_max_seconds'] = $recording->policy['max_seconds'];
                }
            }
        }
        $pairs = [];
        foreach ($vars as $name => $value) {
            $value = (string) $value;
            if (! preg_match('#^[a-zA-Z0-9_./+@:-]*$#D', $value)) {
                throw new RuntimeException('Unsafe call variable.');
            }
            $pairs[] = $name.'='.$value;
        }
        $command = 'originate {'.implode(',', $pairs).'}user/'.$c['sip_username'].'@'.$domain.' &lua(blucom_two_party.lua)';
        $this->socket->background($command, $call->job_uuid);
    }

    public function exists(string $uuid): bool
    {
        $this->uuid($uuid);

        return trim($this->socket->api('uuid_exists '.$uuid)) === 'true';
    }

    public function snapshot(string $uuid): array
    {
        $this->uuid($uuid);
        $body = $this->socket->api('uuid_dump '.$uuid.' json');
        $dump = json_decode($body, true);
        if (! is_array($dump)) {
            throw new RuntimeException('Cannot inspect active call.');
        }
        $result = array_merge($dump, $dump['variables'] ?? []);
        foreach ($dump as $key => $value) {
            if (str_starts_with($key, 'variable_')) {
                $result[substr($key, 9)] = $value;
            }
        }

        return $result;
    }

    public function kill(string $uuid): void
    {
        $this->uuid($uuid);
        $this->socket->api('uuid_kill '.$uuid.' ORIGINATOR_CANCEL');
    }

    private function uuid(string $uuid): void
    {
        if (! Str::isUuid($uuid)) {
            throw new RuntimeException('Invalid call identifier.');
        }
    }
}
