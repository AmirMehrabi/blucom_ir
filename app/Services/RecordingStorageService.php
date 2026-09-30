<?php

namespace App\Services;

use App\Models\CallRecording;
use App\Models\RecordingStorageSetting;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class RecordingStorageService
{
    public function settings(int $tenantId): RecordingStorageSetting
    {
        return RecordingStorageSetting::query()->firstOrCreate(['tenant_id' => $tenantId], [
            'quota_mb' => config('voip.recordings.quota_mb'), 'overflow' => 'stop',
        ]);
    }

    public function usage(int $tenantId): array
    {
        $query = CallRecording::query()->where('tenant_id', $tenantId);

        return [
            'bytes' => (int) (clone $query)->sum('bytes'),
            'reserved_bytes' => (int) (clone $query)->sum('reserved_bytes'),
            'ready' => (clone $query)->where('status', 'ready')->count(),
        ];
    }

    // Admission holds the global capacity lock. File deletion commits its
    // tombstone separately before touching disk, so a later reservation failure
    // cannot roll database state back to an accessible but deleted recording.
    public function makeRoom(int $tenantId, int $reservation): bool
    {
        $setting = $this->settings($tenantId);
        $limit = min($setting->quota_mb, (int) config('voip.recordings.max_quota_mb')) * 1048576;
        $usage = $this->usage($tenantId);
        $used = $usage['bytes'] + $usage['reserved_bytes'];
        if ($reservation > $limit) {
            return false;
        }
        if ($setting->overflow === 'oldest') {
            foreach (CallRecording::query()->where('tenant_id', $tenantId)->where('status', 'ready')->orderBy('created_at')->cursor() as $recording) {
                if ($used + $reservation <= $limit) {
                    break;
                }
                $bytes = $recording->bytes;
                $this->delete($recording, 'expired');
                $used -= $bytes;
            }
        }

        return $this->hasRoom($tenantId, $reservation);
    }

    public function hasRoom(int $tenantId, int $reservation): bool
    {
        $setting = $this->settings($tenantId);
        $limit = min($setting->quota_mb, (int) config('voip.recordings.max_quota_mb')) * 1048576;
        $usage = $this->usage($tenantId);

        return $limit >= $usage['bytes'] + $usage['reserved_bytes'] + $reservation;
    }

    public function delete(CallRecording $recording, string $status = 'deleted'): void
    {
        $recording = DB::transaction(function () use ($recording, $status) {
            Tenant::query()->whereKey($recording->tenant_id)->lockForUpdate()->firstOrFail();
            $recording = CallRecording::query()->lockForUpdate()->findOrFail($recording->id);
            if (in_array($recording->status, ['recording', 'processing'], true)) {
                throw new RuntimeException('Active recordings cannot be deleted.');
            }
            if ($recording->status !== 'deleting') {
                $recording->update(['status' => 'deleting', 'deletion_reason' => $status]);
            }

            return $recording;
        });
        // This runs after the tombstone commits. Failed/partial deletion stays
        // inaccessible and counted until the scheduled processor retries it.
        $this->removeFiles($recording, $recording->deletion_reason ?? $status);
    }

    private function removeFiles(CallRecording $recording, string $status): void
    {
        if ($recording->storage_key !== null) {
            $expected = $recording->tenant_id.'/'.$recording->id.'.wav';
            if ($recording->storage_key !== $expected || ! Storage::disk('recordings')->delete($expected)) {
                throw new RuntimeException('Recording audio could not be deleted.');
            }
        }
        foreach (['wav', 'complete', 'complete.tmp'] as $extension) {
            $path = $this->spoolPath($recording->id, $extension);
            if (file_exists($path) && ! unlink($path)) {
                throw new RuntimeException('Recording spool could not be deleted.');
            }
        }
        $recording->update(['status' => $status, 'bytes' => 0, 'reserved_bytes' => 0,
            'storage_key' => null, 'deleted_at' => now(), 'deletion_reason' => null]);
        Log::info('Recording audio removed', ['recording_id' => $recording->id, 'tenant_id' => $recording->tenant_id, 'reason' => $status]);
    }

    public function spoolPath(string $id, string $extension = 'wav'): string
    {
        if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $id)
            || ! in_array($extension, ['wav', 'complete', 'complete.tmp'], true)) {
            throw new RuntimeException('Invalid recording identifier.');
        }
        $root = rtrim((string) config('voip.recordings.spool'), '/');
        if (! preg_match('#^/[a-zA-Z0-9_/-]+$#D', $root) || str_contains($root, '//')) {
            throw new RuntimeException('Invalid recording spool path.');
        }

        return $root.'/'.$id.'.'.$extension;
    }
}
