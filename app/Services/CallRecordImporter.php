<?php

namespace App\Services;

use App\Models\CallRecord;
use App\Models\InboundRoute;
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

        $context = trim((string) $fields[3]);
        $authUser = trim((string) ($fields[15] ?? ''));
        $profile = trim((string) ($fields[16] ?? ''));
        $marker = trim((string) ($fields[17] ?? ''));
        $extensionMarker = trim((string) ($fields[18] ?? ''));
        $source = trim((string) $fields[1]);
        $destination = trim((string) $fields[2]);
        $accountCode = trim((string) $fields[12]);

        $inbound = $marker === CallRecord::INBOUND
            || ($marker === '' && $context === 'public' && ($profile === '' || $profile === 'external'));
        $outbound = $marker === CallRecord::OUTBOUND;

        if ($inbound) {
            $normalized = $this->normalizer->normalize($destination);
            $number = $normalized ? ($this->numbers[$normalized] ?? null) : null;
            if ($number === null) {
                return false;
            }
            $tenantId = $number->tenant_id;
            $numberId = $number->id;
            $extensionId = $this->inboundDestinations[$numberId] ?? null;
            $direction = CallRecord::INBOUND;
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
            $direction = CallRecord::OUTBOUND;
        } else {
            return false;
        }

        if ($accountCode !== '' && str_starts_with($accountCode, 'btenant_')
            && $accountCode !== 'btenant_'.$tenantId) {
            return false;
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

        $status = $answeredAt !== null
            ? CallRecord::ANSWERED
            : ($direction === CallRecord::INBOUND && in_array($cause, [
                'NO_ANSWER', 'NO_USER_RESPONSE', 'ORIGINATOR_CANCEL', 'NORMAL_CLEARING',
            ], true) ? CallRecord::MISSED : CallRecord::FAILED);

        return DB::table('call_records')->insertOrIgnore([
            'tenant_id' => $tenantId,
            'sip_number_id' => $numberId,
            'sip_extension_id' => $extensionId,
            'freeswitch_uuid' => $uuid,
            'direction' => $direction,
            'source_number' => substr($source, 0, 32),
            'destination_number' => substr($destination, 0, 32),
            'status' => $status,
            'hangup_cause' => $cause !== '' ? $cause : null,
            'started_at' => $startedAt,
            'answered_at' => $answeredAt,
            'ended_at' => $endedAt,
            'duration_seconds' => $duration,
            'billable_seconds' => $billable,
            'created_at' => now(),
            'updated_at' => now(),
        ]) === 1;
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
