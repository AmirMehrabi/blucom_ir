<?php

namespace App\Services;

use App\Models\CallQueue;
use App\Models\CallRecord;
use App\Models\CallRecording;
use App\Models\InboundRoute;
use App\Models\IvrMenu;
use App\Models\NumberAssignment;
use App\Models\SipExtension;
use App\Models\SipNumber;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

class CallRecordImporter
{
    private array $numbers = [];

    private array $extensions = [];

    private array $extensionsById = [];

    private array $inboundDestinations = [];

    public function __construct(private readonly NumberNormalizer $normalizer) {}

    public function refreshOwnership(): void
    {
        $this->numbers = SipNumber::query()->whereNotNull('tenant_id')
            ->get(['id', 'tenant_id', 'normalized_number'])
            ->keyBy('normalized_number')->all();
        $this->extensions = SipExtension::query()
            ->get(['id', 'tenant_id', 'extension'])
            ->keyBy('extension')->all();
        $this->extensionsById = [];
        foreach ($this->extensions as $extension) {
            $this->extensionsById[$extension->id] = $extension;
        }
        $this->inboundDestinations = InboundRoute::query()
            ->where('destination_type', InboundRoute::DESTINATION_EXTENSION)
            ->pluck('destination_id', 'sip_number_id')->all();
    }

    /**
     * Import one A-leg record from FreeSWITCH's example/blucom CSV templates.
     * Unknown SIP traffic is ignored rather than assigned to a tenant.
     *
     * @param  list<string|null>  $fields
     */
    public function import(array $fields): bool
    {
        if (count($fields) < 15) {
            return false;
        }

        $uuid = strtolower(trim((string) $fields[10]));
        if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $uuid)) {
            return false;
        }

        $recording = CallRecording::query()->where('freeswitch_uuid', $uuid)->first();

        $context = trim((string) $fields[3]);
        $authUser = trim((string) ($fields[15] ?? ''));
        $profile = trim((string) ($fields[16] ?? ''));
        $marker = trim((string) ($fields[17] ?? ''));
        $extensionMarker = trim((string) ($fields[18] ?? ''));
        $queueMarker = trim((string) ($fields[19] ?? ''));
        $queueCause = trim((string) ($fields[20] ?? ''));
        $fallbackAttempted = trim((string) ($fields[24] ?? '')) === 'true';
        $originateDisposition = strtolower(trim((string) ($fields[25] ?? '')));
        $ivrMarker = trim((string) ($fields[26] ?? ''));
        $ivrDigit = trim((string) ($fields[27] ?? ''));
        $ivrNumberMarker = trim((string) ($fields[28] ?? ''));
        $source = trim((string) $fields[1]);
        $destination = trim((string) $fields[2]);
        $accountCode = trim((string) $fields[12]);

        $startedAt = $this->stamp($fields[4]);
        if ($startedAt === null) {
            return false;
        }

        $inbound = $marker === CallRecord::INBOUND
            || ($marker === '' && $context === 'public' && ($profile === '' || $profile === 'external'));
        $outbound = $marker === CallRecord::OUTBOUND;

        if ($inbound) {
            $ivrMenu = null;
            if ($recording !== null && $recording->direction === CallRecord::INBOUND) {
                $number = new SipNumber;
                $number->id = $recording->sip_number_id;
                $number->tenant_id = $recording->tenant_id;
                $number->normalized_number = $recording->policy['number'] ?? $this->normalizer->normalize($destination);
                $menu = ctype_digit($ivrMarker) ? IvrMenu::query()->find((int) $ivrMarker) : null;
                $ivrMenu = $menu?->tenant_id === $recording->tenant_id ? $menu : null;
            } elseif (ctype_digit($ivrMarker) && ctype_digit($ivrNumberMarker)) {
                $ivrMenu = IvrMenu::query()->find((int) $ivrMarker);
                $number = SipNumber::query()->find((int) $ivrNumberMarker);
                if ($number?->inventory_state === null && $number?->tenant_id !== $ivrMenu?->tenant_id) {
                    return false;
                }
            } else {
                $normalized = $this->normalizer->normalize($destination);
                $number = $normalized ? ($this->numbers[$normalized] ?? null) : null;
            }
            // Assignment history preserves ownership for delayed inbound CDRs after resale.
            if ($recording === null) {
                $candidate = isset($ivrNumberMarker) && ctype_digit($ivrNumberMarker)
                    ? SipNumber::query()->find((int) $ivrNumberMarker)
                    : SipNumber::query()->where('normalized_number', $this->normalizer->normalize($destination))->first();
                if ($candidate?->inventory_state !== null && $candidate !== null) {
                    $historical = NumberAssignment::query()->where('sip_number_id', $candidate->id)
                        ->where('assigned_at', '<=', $startedAt)
                        ->where(fn ($query) => $query->whereNull('released_at')->orWhere('released_at', '>', $startedAt))
                        ->orderByDesc('assigned_at')->first();
                    if (! $historical || $accountCode !== 'btenant_'.$historical->tenant_id) {
                        return false;
                    }
                    $number = clone $candidate;
                    $number->tenant_id = $historical->tenant_id;
                    if ($ivrMenu?->tenant_id !== $number->tenant_id) {
                        $ivrMenu = null;
                    }
                }
            }
            if ($number === null) {
                return false;
            }
            $tenantId = $number->tenant_id;
            $numberId = $number->id;
            $extensionId = $this->inboundDestinations[$numberId] ?? null;
            if (ctype_digit($extensionMarker)) {
                $target = $this->extensionsById[(int) $extensionMarker] ?? null;
                $extensionId = $target?->tenant_id === $tenantId ? $target->id : null;
            }
            $queueId = null;
            if (ctype_digit($queueMarker)) {
                $queue = CallQueue::query()->find((int) $queueMarker);
                if ($queue?->tenant_id === $tenantId) {
                    $queueId = $queue->id;
                }
            }
            $direction = CallRecord::INBOUND;
            $ivrMenuId = $ivrMenu?->id;
        } elseif ($outbound) {
            // Both markers are set by the database-generated route after auth.
            // Caller ID and SIP From can be chosen by the endpoint.
            $extension = ctype_digit($extensionMarker) ? ($this->extensionsById[(int) $extensionMarker] ?? null) : null;
            if ($extension === null || $accountCode !== 'btenant_'.$extension->tenant_id
                || $authUser !== $extension->extension) {
                return false;
            }
            $tenantId = $extension->tenant_id;
            $extensionId = $extension->id;
            $numberId = null;
            // Optional new CDR columns identify the exact paid assignment selected at call setup.
            $didMarker = (string) ($fields[29] ?? '');
            $assignmentMarker = (string) ($fields[30] ?? '');
            if (ctype_digit($didMarker) && ctype_digit($assignmentMarker) && (int) $assignmentMarker > 0) {
                $assigned = NumberAssignment::query()->whereKey((int) $assignmentMarker)
                    ->where('sip_number_id', (int) $didMarker)->where('tenant_id', $tenantId)
                    ->where('assigned_at', '<=', $startedAt)
                    ->where(fn ($query) => $query->whereNull('released_at')->orWhere('released_at', '>', $startedAt))->first();
                if (! $assigned) {
                    return false;
                }
                $numberId = $assigned->sip_number_id;
            }
            $queueId = null;
            $direction = CallRecord::OUTBOUND;
            $ivrMenuId = null;
        } else {
            return false;
        }

        if ($accountCode !== '' && str_starts_with($accountCode, 'btenant_')
            && $accountCode !== 'btenant_'.$tenantId) {
            return false;
        }

        // Recording reservations preserve the owner and DID selected by the
        // authorized dialplan at call setup, even if a DID is reassigned later.
        if ($recording !== null && $recording->direction === $direction) {
            if ($direction === CallRecord::OUTBOUND && $recording->tenant_id !== $tenantId) {
                return false;
            }
            $tenantId = $recording->tenant_id;
            $numberId = $recording->sip_number_id;
        }

        $startedAt = $this->stamp($fields[4]);
        if ($startedAt === null) {
            return false;
        }
        $answeredAt = $this->stamp($fields[5]);
        $endedAt = $this->stamp($fields[6]);
        $cause = substr(trim((string) $fields[9]), 0, 64);
        $duration = $this->seconds($fields[7]);
        $billable = $this->seconds($fields[8]);

        $queueCancelReachedFallback = $queueId !== null && $queueCause === 'cancel'
            && $fallbackAttempted && in_array($originateDisposition, ['success', 'call accepted'], true);
        $ivrConnected = $queueId !== null
            ? $queueCause === 'answered' || $queueCancelReachedFallback
            : in_array($originateDisposition, ['success', 'call accepted'], true);
        $status = $ivrMenuId !== null ? ($ivrConnected ? CallRecord::ANSWERED : CallRecord::MISSED)
            : ($queueId !== null && $queueCause === 'cancel' && ! $queueCancelReachedFallback ? CallRecord::MISSED : ($answeredAt !== null
            ? CallRecord::ANSWERED
            : ($direction === CallRecord::INBOUND && in_array($cause, [
                'NO_ANSWER', 'NO_USER_RESPONSE', 'ORIGINATOR_CANCEL', 'NORMAL_CLEARING',
            ], true) ? CallRecord::MISSED : CallRecord::FAILED)));

        $joinedEpoch = ctype_digit((string) ($fields[21] ?? '')) ? (int) $fields[21] : null;
        $resolvedEpoch = ctype_digit((string) ($fields[22] ?? '')) ? (int) $fields[22]
            : (ctype_digit((string) ($fields[23] ?? '')) ? (int) $fields[23] : null);
        $wait = $queueId !== null && $joinedEpoch !== null && $resolvedEpoch !== null
            ? max(0, min(86400, $resolvedEpoch - $joinedEpoch)) : null;

        $inserted = DB::table('call_records')->insertOrIgnore([
            'tenant_id' => $tenantId,
            'sip_number_id' => $numberId,
            'sip_extension_id' => $extensionId,
            'call_queue_id' => $queueId,
            'ivr_menu_id' => $ivrMenuId,
            'ivr_digit' => $ivrMenuId !== null && preg_match('/^[0-9]$/D', $ivrDigit) ? $ivrDigit : null,
            'freeswitch_uuid' => $uuid,
            'direction' => $direction,
            'source_number' => substr($source, 0, 32),
            'destination_number' => $ivrMenuId !== null ? substr($number->normalized_number, 0, 32) : substr($destination, 0, 32),
            'status' => $status,
            'hangup_cause' => $cause !== '' ? $cause : null,
            'started_at' => $startedAt,
            'answered_at' => $answeredAt,
            'ended_at' => $endedAt,
            'duration_seconds' => $duration,
            'billable_seconds' => $billable,
            'queue_wait_seconds' => $wait,
            'queue_outcome' => $queueId !== null && in_array($queueCause, ['answered', 'cancel'], true) ? $queueCause : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]) === 1;
        if ($recording !== null) {
            $call = CallRecord::query()->where('freeswitch_uuid', $uuid)->where('tenant_id', $recording->tenant_id)->first();
            if ($call !== null) {
                $recording->update(['call_record_id' => $call->id]);
            }
        }

        return $inserted;
    }

    private function stamp(?string $value): ?CarbonImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value, config('voip.cdr_timezone', 'UTC'))->utc();
        } catch (Throwable) {
            return null;
        }
    }

    private function seconds(?string $value): int
    {
        return is_numeric($value) ? max(0, min(31_536_000, (int) $value)) : 0;
    }
}
