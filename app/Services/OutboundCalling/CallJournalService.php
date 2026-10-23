<?php

namespace App\Services\OutboundCalling;

use App\Models\OutboundCallSession;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Small native completion journal; survives missed events and backend outages. */
class CallJournalService
{
    public function path(OutboundCallSession $call): string
    {
        $root = rtrim((string) config('voip.two_party.spool'), '/');
        if (! preg_match('#^/[a-zA-Z0-9_./-]+$#D', $root) || ! is_dir($root) || is_link($root)) {
            throw new RuntimeException('Native call journal storage is unavailable.');
        }

        return realpath($root).'/'.$call->id.'.json';
    }

    public function recover(OutboundCallSession $call): bool
    {
        $path = $this->path($call);
        clearstatcache(true, $path);
        if (! is_file($path) || is_link($path) || filesize($path) > 8192) {
            return false;
        }
        $data = json_decode(file_get_contents($path, false, null, 0, 8193), true);
        if (! is_array($data) || ($data['final'] ?? null) !== true || ($data['session_id'] ?? '') !== $call->id
            || ($data['agent_uuid'] ?? '') !== $call->agent_uuid || ($data['customer_uuid'] ?? '') !== $call->customer_uuid) {
            return false;
        }
        if (! isset($data['ended']) || ! $this->validTime($call, $data['ended'])) {
            return false;
        }
        $state = app(CallStateService::class);
        foreach (OutboundCallSession::STAGES as $stage) {
            if (isset($data[$stage]) && $this->validTime($call, $data[$stage])) {
                $state->advance($call, $stage, CarbonImmutable::createFromTimestampUTC($data[$stage]));
            }
        }
        if (isset($data['customer_dialing']) && $this->validTime($call, $data['customer_dialing'])) {
            $call->agent_ready_at ??= CarbonImmutable::createFromTimestampUTC($data['customer_dialing']);
        }

        $reason = $data['failure_reason'] ?? null;
        $state->finish($call, $call->cancel_requested_at ? 'cancelled' : ($call->bridged_at ? 'completed' : 'failed'),
            $call->cancel_requested_at ? 'call_cancelled' : ($call->bridged_at ? null : (isset(OutboundCallSession::FAILURES[$reason]) ? $reason : 'control_unavailable')),
            CarbonImmutable::createFromTimestampUTC($data['ended']));

        return true;
    }

    public function forget(OutboundCallSession $call): void
    {
        $path = $this->path($call);
        DB::afterCommit(function () use ($path) {
            foreach ([$path, $path.'.tmp'] as $file) {
                if (is_file($file) && ! is_link($file)) {
                    unlink($file);
                }
            }
        });
    }

    private function validTime(OutboundCallSession $call, mixed $value): bool
    {
        return is_int($value) && $value >= $call->created_at->timestamp - 1 && $value <= now()->timestamp + 5;
    }
}
