<?php

namespace App\Console\Commands;

use App\Models\CallRecord;
use App\Models\NumberAssignment;
use App\Models\SipNumber;
use App\Services\Commerce\LineEntitlementService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class CheckLineAcceptance extends Command
{
    protected $signature = 'voip:check-line-acceptance {number : Database DID ID} {--since= : Start of live test, ISO-8601 with timezone} {--caller-id-confirmed : Human confirms the purchased DID appeared on the receiving phone} {--two-way-audio-confirmed : Human confirms two-way audio on both test calls}';

    protected $description = 'Check a purchased line against current registration and recent real inbound/outbound CDRs';

    public function handle(LineEntitlementService $entitlements): int
    {
        if (! ctype_digit((string) $this->argument('number')) || ! is_string($this->option('since'))
            || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/D', $this->option('since'))) {
            $this->error('Provide a numeric DID database ID and --since with an explicit ISO-8601 timezone.');

            return self::INVALID;
        }
        try {
            $since = CarbonImmutable::parse($this->option('since'))->utc();
        } catch (\Throwable) {
            return self::INVALID;
        }
        if ($since->isFuture() || $since->lt(now()->subDay())) {
            $this->error('Live acceptance window must begin within the last 24 hours.');

            return self::INVALID;
        }
        $number = SipNumber::query()->findOrFail((int) $this->argument('number'));
        $assignment = $number->current_assignment_id ? NumberAssignment::query()->find($number->current_assignment_id) : null;
        $calls = CallRecord::query()->where('tenant_id', $number->tenant_id)->where('sip_number_id', $number->id)
            ->where('started_at', '>=', $since)->where('started_at', '>=', $assignment?->assigned_at ?? $since)
            ->where('status', 'answered')->where('billable_seconds', '>', 0)->get();
        $registered = false;
        $phones = $number->tenant?->sipExtensions()->where('enabled', true)->get() ?? collect();
        foreach ($phones as $phone) {
            if (! preg_match('/^[1-9][0-9]{2,8}$/D', $phone->extension)) {
                continue;
            }
            $process = new Process(['fs_cli', '-x', 'sofia_contact internal/'.$phone->extension.'@'.config('voip.directory_domain')]);
            $process->setTimeout(10);
            try {
                $process->run();
                $registered = $registered || ($process->isSuccessful() && str_starts_with(trim($process->getOutput()), 'sofia/internal/'));
            } catch (\Throwable) { /* Missing runtime is an unpassed check. */
            }
        }
        $checks = [
            'paid_assignment' => $assignment !== null && $entitlements->subscription($number) !== null,
            'active_subscription' => $entitlements->allows($number),
            'answering_route' => $number->inboundRoute?->enabled === true,
            'registered_customer_phone' => $registered,
            'recent_answered_inbound' => $calls->contains(fn ($call) => $call->direction === 'inbound'),
            'recent_answered_outbound' => $calls->contains(fn ($call) => $call->direction === 'outbound'),
            'caller_id_human_confirmation' => (bool) $this->option('caller-id-confirmed'),
            'two_way_audio_human_confirmation' => (bool) $this->option('two-way-audio-confirmed'),
        ];
        $this->line(json_encode(['number_id' => $number->id, 'since' => $since->toIso8601String(), 'checks' => $checks,
            'passed' => ! in_array(false, $checks, true)], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return in_array(false, $checks, true) ? self::FAILURE : self::SUCCESS;
    }
}
