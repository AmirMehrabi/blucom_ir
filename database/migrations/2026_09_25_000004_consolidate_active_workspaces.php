<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_consolidation_log', function (Blueprint $table) {
            $table->string('table_name', 32);
            $table->unsignedBigInteger('record_id');
            $table->unsignedBigInteger('previous_tenant_id');
            $table->primary(['table_name', 'record_id']);
        });

        DB::transaction(function (): void {
            $workspaceId = DB::table('tenants')->where('system_key', 'blucom')->value('id');
            if ($workspaceId === null) {
                $workspaceId = DB::table('tenants')->insertGetId([
                    'name' => 'Blucom',
                    'system_key' => 'blucom',
                    'status' => 'active',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $legacyIds = DB::table('tenants')
                ->where('id', '!=', $workspaceId)
                ->where('status', 'active')
                ->pluck('id');

            foreach ($legacyIds as $legacyId) {
                foreach (['sip_gateways', 'sip_numbers', 'sip_extensions', 'inbound_routes', 'outbound_routes', 'users'] as $table) {
                    $records = DB::table($table)->where('tenant_id', $legacyId)->pluck('id');
                    foreach ($records as $recordId) {
                        DB::table('workspace_consolidation_log')->insert([
                            'table_name' => $table,
                            'record_id' => $recordId,
                            'previous_tenant_id' => $legacyId,
                        ]);
                    }
                    DB::table($table)->where('tenant_id', $legacyId)->update(['tenant_id' => $workspaceId]);
                }
            }
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            foreach (DB::table('workspace_consolidation_log')->get() as $entry) {
                DB::table($entry->table_name)->where('id', $entry->record_id)
                    ->update(['tenant_id' => $entry->previous_tenant_id]);
            }
        });

        Schema::dropIfExists('workspace_consolidation_log');
    }
};
