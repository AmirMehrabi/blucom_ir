<?php

namespace App\Services;

use App\Models\CallRecording;
use App\Models\NumberRecordingSetting;
use App\Models\SipNumber;
use App\Models\Tenant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class RecordingPolicyService
{
    public function __construct(private readonly RecordingStorageService $storage, private readonly RecordingAnnouncementService $announcements) {}

    public function reserve(SipNumber $number, string $direction, array $request): ?CallRecording
    {
        if (! config('voip.recordings.enabled')) {
            return null;
        }
        $uuid = strtolower((string) ($request['Caller-Unique-ID'] ?? $request['Unique-ID'] ?? ''));
        if (! Str::isUuid($uuid) || ! in_array($direction, ['inbound', 'outbound'], true)) {
            return null; // Non-call lookups such as xml_locate have no UUID.
        }
        try {
            // The shared cache lock serializes physical capacity admissions
            // across tenants; tenant locks serialize quotas and setting changes.
            return Cache::lock('recording-spool-capacity', 30)->block(2, function () use ($number, $direction, $uuid, $request) {
                if ($existing = CallRecording::query()->where('freeswitch_uuid', $uuid)->first()) {
                    return $this->existing($existing, $number, $direction);
                }
                $initial = $this->setting($number, $direction);
                if ($initial === null || ($direction === 'inbound' && $initial->announcement_enabled
                    && ! $this->announcements->validPath($number, $initial->announcement_path))) {
                    return null;
                }
                // Eviction commits before deleting audio, independently of the
                // subsequent reservation transaction. A DB failure cannot undo it.
                $this->storage->makeRoom($number->tenant_id, $initial->max_minutes * 60 * 32000 + 4096);

                return DB::transaction(function () use ($number, $direction, $uuid, $request) {
                    $tenant = Tenant::query()->whereKey($number->tenant_id)->lockForUpdate()->firstOrFail();
                    $current = SipNumber::query()->whereKey($number->id)->lockForUpdate()->first();
                    if ($tenant->status !== 'active' || $current?->tenant_id !== $number->tenant_id
                        || ! $current->enabled || ! $current->isRoutable()) {
                        return null;
                    }
                    if ($existing = CallRecording::query()->where('freeswitch_uuid', $uuid)->first()) {
                        return $this->existing($existing, $number, $direction);
                    }
                    $setting = $this->setting($current, $direction);
                    if ($setting === null) {
                        return null;
                    }
                    $announcement = $direction === 'inbound' && $setting->announcement_enabled ? $setting->announcement_path : null;
                    if ($direction === 'inbound' && $setting->announcement_enabled
                        && ! $this->announcements->validPath($current, $announcement)) {
                        return null;
                    }
                    $seconds = $setting->max_minutes * 60;
                    $reserved = $seconds * 32000 + 4096;
                    $spool = (string) config('voip.recordings.spool');
                    $this->storage->spoolPath((string) Str::uuid());
                    $free = is_dir($spool) ? @disk_free_space($spool) : false;
                    $pending = (int) CallRecording::query()->sum('reserved_bytes');
                    $fits = $free !== false && $free >= $pending + $reserved + config('voip.recordings.min_free_mb') * 1048576
                        && $this->storage->hasRoom($number->tenant_id, $reserved);
                    if (! $fits) {
                        Log::notice('Recording skipped: storage capacity', ['tenant_id' => $number->tenant_id, 'sip_number_id' => $number->id]);
                    }

                    return CallRecording::query()->create([
                        'id' => (string) Str::uuid(), 'tenant_id' => $number->tenant_id,
                        'sip_number_id' => $number->id, 'freeswitch_uuid' => $uuid,
                        'direction' => $direction, 'status' => $fits ? 'recording' : 'skipped',
                        'failure_reason' => $fits ? null : 'storage_capacity',
                        'reserved_bytes' => $fits ? $reserved : 0, 'bytes' => 0,
                        'policy' => ['version' => $setting->version, 'coverage' => $setting->coverage,
                            'retention_days' => $setting->retention_days, 'max_seconds' => $seconds,
                            'announcement_path' => $announcement, 'number' => $number->normalized_number,
                            'auth_user' => $direction === 'outbound' ? ($request['variable_sip_auth_username'] ?? null) : null],
                    ]);
                });
            });
        } catch (Throwable $exception) {
            Log::error('Recording reservation failed', ['exception_class' => $exception::class, 'sip_number_id' => $number->id]);

            return null; // Preserve authorized calls during a recording failure.
        }
    }

    private function existing(CallRecording $existing, SipNumber $number, string $direction): ?CallRecording
    {
        return $existing->tenant_id === $number->tenant_id && $existing->sip_number_id === $number->id
            && $existing->direction === $direction && $existing->status === 'recording' ? $existing : null;
    }

    private function setting(SipNumber $number, string $direction): ?NumberRecordingSetting
    {
        $setting = NumberRecordingSetting::query()->where('tenant_id', $number->tenant_id)->where('sip_number_id', $number->id)->first();

        return $setting !== null && in_array($setting->directions, [$direction, 'both'], true)
            && in_array($setting->coverage, ['conversation', 'full'], true)
            && $setting->max_minutes >= 1 && $setting->max_minutes <= 120
            && $setting->retention_days >= 1 && $setting->retention_days <= 365 ? $setting : null;
    }
}
