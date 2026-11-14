<?php

namespace App\Services\AudioCalls;

use App\Models\AudioCall;
use App\Models\Customer;
use App\Models\Tenant;
use App\Services\OutboundCalling\AudioAssetService;
use App\Services\OutboundCalling\SwitchCallControl;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class AudioCallWorker
{
    public function __construct(private AudioCallPolicy $policy, private AudioAssetService $audio, private FetchAudio $fetch) {}

    public function tick(SwitchCallControl $switch, callable $renewLease): void
    {
        foreach (AudioCall::query()->whereNull('finished_at')->orderBy('updated_at')->limit(20)->get() as $call) {
            $renewLease();
            $root = rtrim((string) config('voip.two_party.spool'), '/');
            if (! preg_match('#^/[a-zA-Z0-9_./-]+$#D', $root) || ! is_dir($root) || is_link($root)) {
                throw new RuntimeException('Audio call spool unavailable.');
            }
            $base = $root.'/audio-'.$call->id;
            if ($call->dispatched_at) {
                $journal = is_file($base.'.result') ? file_get_contents($base.'.result', false, null, 0, 100) : '';
                if (preg_match('/^(completed|interrupted|playback_failed) ([0-9]{10})$/D', trim($journal ?: ''), $result)) {
                    $this->finish($call, $result[1], $base, (int) $result[2]);

                    continue;
                }
                if ($switch->exists($call->id)) {
                    if ($call->dispatched_at->lt(now()->subSeconds(120))) {
                        $switch->kill($call->id);
                    } else {
                        $dump = $switch->snapshot($call->id);
                        if ($dump['blucom_audio_playing_at'] ?? null) {
                            $time = date('Y-m-d H:i:s', (int) $dump['blucom_audio_playing_at']);
                            $call->update(['status' => 'playing', 'answered_at' => $call->answered_at ?? $time, 'playback_started_at' => $time]);
                        }
                    }
                } elseif ($call->dispatched_at->lt(now()->subSeconds(55))) {
                    $this->finish($call, $call->answered_at ? 'interrupted' : 'control_unavailable', $base);
                }
                $call->touch();

                continue;
            }

            if ($call->created_at->lt(now()->subMinutes(3))) {
                $this->finish($call, 'queue_expired', $base);

                continue;
            }
            try {
                $tenant = Tenant::query()->findOrFail($call->tenant_id);
                $line = $this->policy->line($tenant, $call->caller_id_id, $call->destination);
                $authorized = $call->customer_id
                    ? Customer::query()->where('tenant_id', $tenant->id)->find($call->customer_id)?->hasPermission('outbound.initiate')
                    : DB::table('audio_call_keys')->where('tenant_id', $tenant->id)->where('id', $call->key_id)->exists();
                if (! $authorized || $line->current_assignment_id !== $call->assignment_id || $line->normalized_number !== $call->caller_id) {
                    throw ValidationException::withMessages(['caller_id_id' => 'Authorization changed.']);
                }
            } catch (ValidationException) {
                $this->finish($call, 'authorization_changed', $base);

                continue;
            }

            try {
                if ($call->audio_url) {
                    try {
                        $this->fetch->download($call->audio_url, $base.'.source');
                        $this->audio->convert($base.'.source', $base.'.wav');
                    } finally {
                        if (is_file($base.'.source')) {
                            unlink($base.'.source');
                        }
                    }
                    $audioPath = $base.'.wav';
                } else {
                    $audioPath = $this->audio->path($tenant, $call->audio_id);
                }
            } catch (\Throwable) {
                $this->finish($call, 'audio_unavailable', $base);

                continue;
            }

            $renewLease();
            // A durable claim precedes the command. An ambiguous send is reconciled, never resent.
            $call->update(['status' => 'dialing', 'dispatched_at' => now(), 'audio_url' => null]);
            $switch->originateAudio($call, $line->providerGateway, $audioPath, $base.'.result');
        }
    }

    public function frame(array $event): void
    {
        $type = $event['Event-Name'] ?? '';
        $id = $type === 'BACKGROUND_JOB' ? ($event['Job-UUID'] ?? '') : ($event['Unique-ID'] ?? '');
        if (! in_array($type, ['BACKGROUND_JOB', 'CHANNEL_ANSWER'], true) || ! Str::isUuid($id)) {
            return;
        }
        $call = AudioCall::query()->whereNull('finished_at')->whereNotNull('dispatched_at')->find($id);
        if (! $call) {
            return;
        }
        if ($type === 'CHANNEL_ANSWER') {
            $call->update(['answered_at' => $call->answered_at ?? now()]);

            return;
        }
        $body = trim($event['_body'] ?? $event['Body'] ?? '');
        if (str_starts_with($body, '-ERR')) {
            $outcome = match (trim(substr($body, 4))) {
                'USER_BUSY' => 'busy',
                'NO_ANSWER', 'NO_USER_RESPONSE', 'ALLOTTED_TIMEOUT' => 'no_answer',
                default => 'call_failed',
            };
            $this->finish($call, $outcome, rtrim(config('voip.two_party.spool'), '/').'/audio-'.$call->id);
        }
    }

    private function finish(AudioCall $call, string $outcome, string $base, ?int $started = null): void
    {
        $time = $started ? date('Y-m-d H:i:s', $started) : null;
        $call->update([
            'status' => 'finished', 'outcome' => $outcome, 'finished_at' => now(), 'audio_url' => null,
            'answered_at' => $call->answered_at ?? $time, 'playback_started_at' => $call->playback_started_at ?? $time,
        ]);
        foreach (['.wav', '.result', '.tmp', '.source'] as $suffix) {
            if (is_file($base.$suffix)) {
                unlink($base.$suffix);
            }
        }
    }
}
