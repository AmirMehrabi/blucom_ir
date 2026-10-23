<?php

namespace App\Services\OutboundCalling;

use App\Models\OutboundCallSession;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class OutboundCallWorker
{
    public function __construct(private SwitchCallControl $switch, private CallStateService $state, private CallPolicyService $policy) {}

    public function tick(?callable $renewLease = null): void
    {
        foreach (OutboundCallSession::query()->whereNull('cleanup_completed_at')->orderBy('updated_at')->limit(50)->get() as $session) {
            if ($renewLease) {
                $renewLease();
            }
            DB::transaction(function () use ($session) {
                $call = OutboundCallSession::query()->lockForUpdate()->findOrFail($session->id);
                if (! $call->terminal() && $call->dispatch_status !== 'pending') {
                    app(CallJournalService::class)->recover($call);
                }
                if ($call->terminal() || $call->cancel_requested_at || $call->deadline_at?->isPast()) {
                    if (! $call->terminal()) {
                        $this->state->finish($call, $call->cancel_requested_at ? 'cancelled' : 'failed',
                            $call->cancel_requested_at ? 'call_cancelled' : 'workflow_timeout');
                    }
                    $this->cleanup($call);

                    return;
                }
                if ($call->dispatch_status === 'pending') {
                    $tenant = Tenant::query()->findOrFail($call->tenant_id);
                    try {
                        $route = $this->policy->route($tenant, $call->sip_extension_id, $call->sip_number_id);
                        $this->policy->destination($route, $call->customer_number);
                        $c = $call->configuration;
                        if (! $this->policy->enabled($tenant) || $route->id !== $call->outbound_route_id
                            || $route->gateway->name !== $c['gateway'] || $route->sipExtension->sipIdentity() !== $c['sip_username']
                            || $route->sipNumber->normalized_number !== $call->caller_id
                            || $route->sipNumber->current_assignment_id !== ($c['assignment_id'] ?? null)
                            || ($c['recording'] && ! $this->policy->recordingAllowed($route))) {
                            throw new \RuntimeException('Authorization changed.');
                        }
                    } catch (\Throwable) {
                        $this->state->finish($call, 'failed', 'authorization_failure');
                        $call->update(['cleanup_completed_at' => now()]);

                        return;
                    }
                    // Commit intent before crossing the network. A crash must never
                    // turn an uncertain originate into an automatic second call.
                    $call->update(['dispatch_status' => 'sending', 'dispatch_started_at' => now()]);

                    return;
                }
                if ($call->dispatch_status === 'sending') {
                    $agent = $this->switch->exists($call->agent_uuid);
                    if ($agent) {
                        $call->update(['dispatch_status' => 'accepted']);
                        $this->reconcile($call);
                    } elseif ($call->dispatch_started_at->lt(now()->subSeconds($call->configuration['agent_timeout'] + 15))) {
                        $this->state->finish($call, 'failed', 'control_unavailable');
                        $this->cleanup($call);
                    }

                    return;
                }
                $this->reconcile($call);
            });
            $call = $session->fresh();
            // The durable claim and originate happen in different transactions.
            if ($call->dispatch_status === 'sending' && $session->dispatch_status === 'pending' && ! $call->terminal()) {
                // Serialize cancellation against dispatch; never retry after any exception.
                DB::transaction(function () use ($call) {
                    $call = OutboundCallSession::query()->lockForUpdate()->findOrFail($call->id);
                    if ($call->cancel_requested_at || $call->terminal()) {
                        return;
                    }
                    $this->switch->originate($call);
                    $call->update(['dispatch_status' => 'accepted']);
                    $this->state->advance($call, 'agent_ringing');
                });
            }
        }
    }

    public function frame(array $frame): void
    {
        if (($frame['headers']['Content-Type'] ?? '') !== 'text/event-json') {
            return;
        }
        $event = json_decode($frame['body'], true);
        if (! is_array($event)) {
            return;
        }
        if (($event['Event-Name'] ?? '') === 'BACKGROUND_JOB') {
            $call = OutboundCallSession::query()->where('job_uuid', $event['Job-UUID'] ?? '')->first();
            $body = $event['_body'] ?? $event['Body'] ?? '';
            if ($call && str_starts_with(trim($body), '-ERR')) {
                DB::transaction(function () use ($call, $body) {
                    $call = OutboundCallSession::query()->lockForUpdate()->findOrFail($call->id);
                    $reason = str_contains($body, 'USER_BUSY') ? 'agent_busy' : (str_contains($body, 'NO_ANSWER') || str_contains($body, 'NO_USER_RESPONSE') ? 'agent_unanswered' : 'agent_unavailable');
                    $this->state->finish($call, 'failed', $reason);
                });
            }
        } else {
            $this->state->event($event);
        }
    }

    private function reconcile(OutboundCallSession $call): void
    {
        if (! $this->switch->exists($call->agent_uuid)) {
            // Allow the background originate and late CDR import to settle.
            if ($call->dispatch_started_at?->lt(now()->subSeconds($call->configuration['agent_timeout'] + 15))) {
                $this->state->finish($call, $call->bridged_at ? 'completed' : 'failed', $call->bridged_at ? null : 'control_unavailable');
                $this->cleanup($call);
            }

            return;
        }
        $dump = $this->switch->snapshot($call->agent_uuid);
        if (($dump['blucom_session_id'] ?? '') !== $call->id) {
            $this->state->finish($call, 'failed', 'control_unavailable');
            $this->cleanup($call);

            return;
        }
        foreach (OutboundCallSession::STAGES as $stage) {
            $epoch = (int) ($dump['blucom_phase_'.$stage.'_at'] ?? 0);
            if ($epoch > 0) {
                $this->state->advance($call, $stage, CarbonImmutable::createFromTimestampUTC($epoch));
            }
        }
        if (! $call->agent_ready_at && (int) ($dump['blucom_agent_ready_at'] ?? 0) > 0) {
            $call->update(['agent_ready_at' => CarbonImmutable::createFromTimestampUTC((int) $dump['blucom_agent_ready_at'])]);
        }
        $bridgeEpoch = (int) ($dump['bridge_epoch'] ?? 0);
        $bridgeMicros = (int) ($dump['Caller-Channel-Bridged-Time'] ?? 0);
        if (($dump['bridge_uuid'] ?? '') === $call->customer_uuid && ($bridgeEpoch > 0 || $bridgeMicros > 0)) {
            $this->state->advance($call, 'bridged', CarbonImmutable::createFromTimestampUTC($bridgeMicros > 0 ? $bridgeMicros / 1000000 : $bridgeEpoch));
        }
        // Rotate bounded batches fairly, including calls without a new phase.
        $call->touch();
        if (($dump['blucom_phase'] ?? '') === 'failed') {
            $reason = $dump['blucom_failure'] ?? 'control_unavailable';
            $this->state->finish($call, 'failed', array_key_exists($reason, OutboundCallSession::FAILURES) ? $reason : 'control_unavailable');
        }
    }

    private function cleanup(OutboundCallSession $call): void
    {
        // Kill and recheck both legs on later passes; a ringing leg can materialize
        // after an uncertain background originate was accepted by the switch.
        foreach ([$call->agent_uuid, $call->customer_uuid] as $uuid) {
            if ($this->switch->exists($uuid)) {
                $this->switch->kill($uuid);
            }
        }
        if ($call->dispatch_status === 'pending' || ($call->dispatch_started_at?->lt(now()->subSeconds($call->configuration['agent_timeout'] + $call->configuration['customer_timeout'] + 15))
            && ! $this->switch->exists($call->agent_uuid) && ! $this->switch->exists($call->customer_uuid))) {
            $call->update(['cleanup_completed_at' => now()]);
            app(CallJournalService::class)->forget($call);
        }
    }
}
