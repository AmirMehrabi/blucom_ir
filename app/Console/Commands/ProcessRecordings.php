<?php

namespace App\Console\Commands;

use App\Models\CallRecording;
use App\Models\NumberRecordingSetting;
use App\Services\RecordingProcessor;
use App\Services\RecordingStorageService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ProcessRecordings extends Command
{
    protected $signature = 'voip:process-recordings {--limit=200}';

    protected $description = 'Finalize completed recordings and delete expired audio safely';

    public function handle(RecordingProcessor $processor, RecordingStorageService $storage): int
    {
        $lock = Cache::lock('recording-processor', 600);
        if (! $lock->get()) {
            $this->info('A recording processor is already running.');

            return self::SUCCESS;
        }
        $failures = 0;
        try {
            $limit = max(1, min(2000, (int) $this->option('limit')));
            // Candidates require a completed CDR. Active calls do not starve
            // completed recordings when more than --limit calls are in flight.
            $candidates = CallRecording::query()->whereIn('status', ['recording', 'processing'])
                ->whereExists(fn ($query) => $query->selectRaw('1')->from('call_records')
                    ->whereColumn('call_records.freeswitch_uuid', 'call_recordings.freeswitch_uuid')->whereNotNull('ended_at'))
                ->orderBy('updated_at')->limit($limit)->get();
            foreach ($candidates as $recording) {
                try {
                    $processor->process($recording);
                } catch (Throwable $exception) {
                    $failures++;
                    Log::error('Recording processing error', ['recording_id' => $recording->id, 'exception_class' => $exception::class]);
                }
            }
            foreach (CallRecording::query()->where('status', 'deleting')->limit($limit)->get() as $recording) {
                try {
                    $storage->delete($recording, $recording->deletion_reason ?? 'expired');
                } catch (Throwable $exception) {
                    $failures++;
                    Log::error('Recording deletion will retry', ['recording_id' => $recording->id, 'exception_class' => $exception::class]);
                }
            }
            foreach (CallRecording::query()->whereIn('status', ['ready', 'failed', 'empty'])
                ->where('expires_at', '<=', now())->orderBy('expires_at')->limit($limit)->get() as $recording) {
                try {
                    $storage->delete($recording, 'expired');
                } catch (Throwable $exception) {
                    $failures++;
                    Log::error('Recording retention will retry', ['recording_id' => $recording->id, 'exception_class' => $exception::class]);
                }
            }
            // Old announcement uploads may still be referenced by calls that
            // started before a settings edit. Keep current and active snapshots.
            $references = NumberRecordingSetting::query()->whereNotNull('announcement_path')->pluck('announcement_path')->all();
            foreach (CallRecording::query()->whereIn('status', ['recording', 'processing'])->cursor() as $recording) {
                if (! empty($recording->policy['announcement_path'])) {
                    $references[] = $recording->policy['announcement_path'];
                }
            }
            $disk = Storage::disk('ivr');
            foreach ($disk->allFiles('recording-announcements') as $file) {
                if (! in_array($file, $references, true) && $disk->lastModified($file) < now()->subHours(3)->timestamp) {
                    $disk->delete($file);
                }
            }
        } finally {
            $lock->release();
        }
        $this->info('Recording processing and retention finished.');

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
