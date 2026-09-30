<?php

namespace App\Services;

use App\Models\CallRecord;
use App\Models\CallRecording;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class RecordingProcessor
{
    public function __construct(private readonly RecordingStorageService $storage, private readonly RecordingWaveInspector $waves) {}

    public function process(CallRecording $recording): void
    {
        DB::transaction(function () use ($recording) {
            Tenant::query()->whereKey($recording->tenant_id)->lockForUpdate()->firstOrFail();
            $recording = CallRecording::query()->lockForUpdate()->findOrFail($recording->id);
            if (! in_array($recording->status, ['recording', 'processing'], true)) {
                return;
            }
            $call = CallRecord::query()->where('freeswitch_uuid', $recording->freeswitch_uuid)->first();
            if ($call === null || $call->ended_at === null) {
                return; // Never publish or clean up a live call's audio.
            }
            if ($call->tenant_id !== $recording->tenant_id) {
                $this->failed($recording, 'ownership_mismatch');

                return;
            }
            $recording->update(['call_record_id' => $call->id, 'status' => 'processing']);
            $path = $this->storage->spoolPath($recording->id);
            $complete = $this->storage->spoolPath($recording->id, 'complete');
            clearstatcache(true, $complete);
            if (! is_file($complete) || is_link($complete)) {
                if ($call->ended_at->lt(now()->subSeconds(config('voip.recordings.completion_grace_seconds')))) {
                    $this->failed($recording, 'completion_missing');
                }

                return;
            }
            $key = $recording->tenant_id.'/'.$recording->id.'.wav';
            $disk = Storage::disk('recordings');
            $target = $disk->path($key);
            // If a previous process crashed between rename and DB commit,
            // validate the already published private file and resume safely.
            $source = is_file($path) ? $path : $target;
            if (! is_file($source)) {
                // FreeSWITCH may discard a recorder that never received audio.
                $recording->update(['status' => $call->status === 'answered' ? 'failed' : 'empty',
                    'failure_reason' => $call->status === 'answered' ? 'audio_missing' : null,
                    'reserved_bytes' => 0, 'expires_at' => $call->ended_at->copy()->addDays($recording->policy['retention_days'])]);
                unlink($complete);

                return;
            }
            try {
                $audio = $this->waves->inspect($source, $recording->reserved_bytes);
                if ($audio['empty']) {
                    unlink($source);
                    unlink($complete);
                    $recording->update(['status' => 'empty', 'reserved_bytes' => 0, 'expires_at' => $call->ended_at->copy()->addDays($recording->policy['retention_days'])]);

                    return;
                }
                $disk->makeDirectory((string) $recording->tenant_id);
                $target = $disk->path($key);
                // Both directories are on the combined host's shared storage.
                // Rename is atomic: recovery can use the target after a crash.
                if ($source !== $target && ! rename($source, $target)) {
                    throw new RuntimeException('storage_unavailable');
                }
                chmod($target, 0640);
                $recording->update(['status' => 'ready', 'storage_key' => $key,
                    'bytes' => $audio['bytes'], 'duration_seconds' => $audio['duration_seconds'],
                    'reserved_bytes' => 0, 'expires_at' => $call->ended_at->copy()->addDays($recording->policy['retention_days'])]);
                unlink($complete);
            } catch (Throwable $exception) {
                $reason = $exception instanceof RuntimeException ? $exception->getMessage() : 'processing_error';
                if (in_array($reason, ['invalid_audio', 'unsupported_audio', 'unreadable_audio'], true)) {
                    $this->failed($recording, $reason);
                } elseif ($call->ended_at->lt(now()->subSeconds(config('voip.recordings.completion_grace_seconds')))
                    && $reason === 'unfinished_audio') {
                    $this->failed($recording, $reason);
                } else {
                    Log::warning('Recording processing will retry', ['recording_id' => $recording->id, 'exception_class' => $exception::class]);
                }
            }
        });
    }

    private function failed(CallRecording $recording, string $reason): void
    {
        // The matching CDR proves the call ended. Quarantine failed audio until
        // retention cleanup, and keep its size accounted for in storage usage.
        $path = $this->storage->spoolPath($recording->id);
        $size = is_file($path) && ! is_link($path) ? filesize($path) : 0;
        $recording->update(['status' => 'failed', 'failure_reason' => $reason,
            'reserved_bytes' => 0, 'bytes' => $size ?: 0,
            'expires_at' => now()->addDays($recording->policy['retention_days'])]);
        Log::warning('Recording failed', ['recording_id' => $recording->id, 'reason' => $reason]);
    }
}
