<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AuditCustomerOwnership extends Command
{
    protected $signature = 'customer:ownership-audit {--json : Output identifiers and counts as JSON}';

    protected $description = 'Read-only ownership inventory for planning an explicit legacy customer migration';

    public function handle(): int
    {
        $tables = ['users', 'sip_gateways', 'sip_numbers', 'sip_extensions', 'inbound_routes', 'outbound_routes',
            'line_setup_wizards', 'admin_line_setups', 'ivr_menus', 'call_queues', 'call_records', 'call_recordings',
            'number_recording_settings', 'recording_storage_settings'];
        $inventory = [];
        foreach ($tables as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'tenant_id')) {
                continue;
            }
            $inventory[$table] = DB::table($table)->select('tenant_id')->selectRaw('COUNT(*) AS records')
                ->groupBy('tenant_id')->orderBy('tenant_id')->get()->toArray();
        }
        $history = Schema::hasTable('workspace_consolidation_log')
            ? DB::table('workspace_consolidation_log')->orderBy('table_name')->orderBy('record_id')->get()->toArray() : [];
        $report = ['read_only' => true, 'inventory' => $inventory, 'previous_ownership' => $history,
            'requires_review' => 'Consolidation history is evidence, not permission to reassign current resources. Classify newer records and media separately.'];
        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } else {
            $rows = [];
            foreach ($inventory as $table => $groups) {
                foreach ($groups as $group) {
                    $rows[] = [$table, $group->tenant_id ?? 'unowned', $group->records];
                }
            }
            $this->table(['Table', 'Current tenant', 'Records'], $rows);
            $this->line('Historical ownership entries: '.count($history));
            $this->warn($report['requires_review']);
        }

        return self::SUCCESS;
    }
}
