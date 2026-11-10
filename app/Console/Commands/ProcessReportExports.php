<?php

namespace App\Console\Commands;

use App\Models\ReportExport;
use App\Services\Reporting\ReportExportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class ProcessReportExports extends Command
{
    protected $signature = 'reports:process-exports {--limit=5}';

    protected $description = 'Build pending private Excel reports and expire old downloads';

    public function handle(ReportExportService $service): int
    {
        foreach (ReportExport::query()->where('status', 'processing')->where('updated_at', '<', now()->subMinutes(30))->lazyById(100) as $stale) {
            $claimed = ReportExport::query()->whereKey($stale->id)->where('status', 'processing')
                ->where('updated_at', '<', now()->subMinutes(30))->update(['status' => 'failed', 'path' => null, 'failure_code' => 'timed_out', 'updated_at' => now()]);
            if (! $claimed) {
                continue;
            }
            if ($stale->path) {
                $disk = Storage::disk('report_exports');
                $disk->delete($stale->path);
                foreach ($disk->files(dirname($stale->path)) as $file) {
                    if (str_starts_with(basename($file), basename($stale->path).'.sheet-')) {
                        $disk->delete($file);
                    }
                }
            }
        }
        foreach (ReportExport::query()->where('expires_at', '<=', now())->where('status', '!=', 'expired')->lazyById(100) as $export) {
            if ($export->path) {
                Storage::disk('report_exports')->delete($export->path);
            }
            $export->update(['status' => 'expired', 'path' => null]);
        }
        foreach (ReportExport::query()->where('status', 'pending')->where('expires_at', '>', now())->orderBy('id')->limit(max(1, min(20, (int) $this->option('limit'))))->get() as $export) {
            $service->process($export);
        }

        return self::SUCCESS;
    }
}
