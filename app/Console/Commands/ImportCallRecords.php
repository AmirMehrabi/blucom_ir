<?php

namespace App\Console\Commands;

use App\Services\CallRecordImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportCallRecords extends Command
{
    protected $signature = 'voip:import-cdr {--file= : FreeSWITCH Master.csv path} {--max-lines=50000 : Maximum rows to scan per run} {--replay : Rescan from the beginning; UUIDs prevent duplicates}';

    protected $description = 'Import tenant-owned FreeSWITCH A-leg CDRs from the existing CSV spool';

    public function handle(CallRecordImporter $importer): int
    {
        $path = (string) ($this->option('file') ?: config('voip.cdr_csv_path'));
        $files = glob(dirname($path).'/'.basename($path).'*') ?: [];
        $files = array_values(array_filter($files, static fn ($file) => is_file($file) && ! str_ends_with($file, '.gz')));
        usort($files, static fn ($a, $b) => filemtime($a) <=> filemtime($b));

        if ($files === []) {
            $this->error('No FreeSWITCH CDR CSV file is readable.');

            return self::FAILURE;
        }

        $lockPath = storage_path('framework/cache/data/cdr-import.lock');
        // flock does not write to the file. Deploy/CLI and service users share
        // storage, so an existing lock may be readable without being writable.
        $lock = @fopen($lockPath, 'rb') ?: @fopen($lockPath, 'c');
        if ($lock === false) {
            $this->error('Cannot create the CDR import lock file.');

            return self::FAILURE;
        }
        if (! flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            $this->warn('A CDR import is already running.');

            return self::SUCCESS;
        }

        try {
            $importer->refreshOwnership();
            $remaining = max(1, (int) $this->option('max-lines'));
            $scanned = 0;
            $inserted = 0;
            $opened = false;

            foreach ($files as $file) {
                if ($remaining <= 0) {
                    break;
                }
                $handle = @fopen($file, 'rb');
                if ($handle === false) {
                    continue;
                }
                $opened = true;
                try {
                    $stat = fstat($handle);
                    $key = $stat['dev'].':'.$stat['ino'];
                    $cursor = DB::table('call_record_import_cursors')->where('source_key', $key)->first();
                    $savedOffset = (int) ($cursor->offset ?? 0);
                    $offset = $this->option('replay') || $savedOffset > (int) $stat['size'] ? 0 : $savedOffset;
                    fseek($handle, $offset);

                    while ($remaining > 0 && ! feof($handle)) {
                        $before = ftell($handle);
                        $row = fgetcsv($handle, 0, ',', '"', '');
                        if ($row === false) {
                            break;
                        }
                        $after = ftell($handle);
                        if ($after === false || $after === $before) {
                            break;
                        }
                        // A writer may still be appending the final record.
                        fseek($handle, $after - 1);
                        $last = fgetc($handle);
                        fseek($handle, $after);
                        if ($last !== "\n") {
                            fseek($handle, $before);
                            break;
                        }

                        $scanned++;
                        $remaining--;
                        if ($importer->import($row)) {
                            $inserted++;
                        }
                    }

                    DB::table('call_record_import_cursors')->updateOrInsert(
                        ['source_key' => $key],
                        ['path' => $file, 'offset' => ftell($handle), 'updated_at' => now()],
                    );
                } finally {
                    fclose($handle);
                }
            }

            if (! $opened) {
                $this->error('The CDR CSV files exist but are not readable by this user.');

                return self::FAILURE;
            }

            $this->info("Scanned {$scanned} CDRs; imported {$inserted} tenant calls.");

            return self::SUCCESS;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
