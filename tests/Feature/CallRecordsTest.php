<?php

namespace Tests\Feature;

use App\Models\CallQueue;
use App\Models\CallRecord;
use App\Models\SipExtension;
use App\Models\SipNumber;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CallRecordImporter;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CallRecordsTest extends TestCase
{
    use RefreshDatabase;

    public function test_imports_only_owned_inbound_and_authenticated_outbound_calls_once(): void
    {
        $tenant = Tenant::factory()->create();
        SipNumber::factory()->for($tenant)->create([
            'number' => '982191093464', 'normalized_number' => '+982191093464',
        ]);
        $extension = SipExtension::factory()->for($tenant)->create(['extension' => '8888']);
        $importer = app(CallRecordImporter::class);
        $importer->refreshOwnership();

        $inbound = $this->row('public', '982191093464', '00f81e69-4555-4cc2-bdae-0da9125619e9');
        $inbound[1] = '+989336337953';
        $inbound[9] = 'NO_ANSWER';
        $inbound[16] = 'external';
        $this->assertTrue($importer->import($inbound));
        $this->assertFalse($importer->import($inbound));

        $outbound = $this->row('default', '09123456789', '493a9bc3-2013-4855-9e62-f067b22f3019');
        $outbound[1] = 'spoofed-caller-id';
        $outbound[5] = '2026-09-29 08:00:05';
        $outbound[7] = '85';
        $outbound[8] = '80';
        $outbound[15] = '8888';
        $outbound[16] = 'internal';
        $outbound[17] = 'outbound';
        $outbound[18] = (string) $extension->id;
        $outbound[12] = 'btenant_'.$tenant->id;
        $this->assertTrue($importer->import($outbound));

        $unauthenticated = $outbound;
        $unauthenticated[10] = 'ddd19c52-cbbc-4c89-970c-a80aca96277e';
        $unauthenticated[15] = '';
        $this->assertFalse($importer->import($unauthenticated));

        $this->assertDatabaseCount('call_records', 2);
        $this->assertDatabaseHas('call_records', [
            'tenant_id' => $tenant->id, 'direction' => 'inbound', 'status' => 'missed',
        ]);
        $this->assertDatabaseHas('call_records', [
            'tenant_id' => $tenant->id, 'direction' => 'outbound', 'status' => 'answered',
            'billable_seconds' => 80,
        ]);
    }

    public function test_call_history_is_tenant_scoped_and_dashboard_uses_real_totals(): void
    {
        $tenant = Tenant::factory()->create();
        $otherTenant = Tenant::factory()->create();
        $operator = User::factory()->create(['tenant_id' => $tenant->id]);
        $operator->permissions()->create(['permission' => Permissions::CALLS_VIEW]);
        $operator->permissions()->create(['permission' => Permissions::DASHBOARD_VIEW]);

        foreach ([$tenant->id, $otherTenant->id] as $index => $tenantId) {
            CallRecord::query()->create([
                'tenant_id' => $tenantId,
                'freeswitch_uuid' => $index === 0 ? 'c284dbe1-94dc-47e0-9adb-5361dc1476c2' : 'c284dbe1-94dc-47e0-9adb-5361dc1476c3',
                'direction' => 'inbound',
                'source_number' => $index === 0 ? 'OWN_CALLER' : 'OTHER_CALLER',
                'destination_number' => '982191093464',
                'status' => 'answered',
                'started_at' => now(),
                'answered_at' => now(),
                'ended_at' => now(),
                'duration_seconds' => 90,
                'billable_seconds' => 60,
            ]);
        }

        $this->actingAs($operator)->get('/calls?range=all')
            ->assertOk()->assertSee('OWN_CALLER')->assertDontSee('OTHER_CALLER');
        $this->actingAs($operator)->get('/dashboard')
            ->assertOk()->assertSee('01:00')->assertDontSee('OTHER_CALLER');
    }

    public function test_queue_cdr_records_wait_and_canceled_caller_as_missed(): void
    {
        $tenant = Tenant::factory()->create();
        SipNumber::factory()->for($tenant)->create([
            'number' => '982191093464', 'normalized_number' => '+982191093464',
        ]);
        $queue = CallQueue::query()->create([
            'tenant_id' => $tenant->id, 'name' => 'Sales', 'strategy' => 'ring-all',
            'max_wait_seconds' => 90, 'enabled' => true,
        ]);
        $importer = app(CallRecordImporter::class);
        $importer->refreshOwnership();
        $row = $this->row('public', '982191093464', 'c3505e76-4a3e-4de1-a369-9f9f27b192fd');
        $row[5] = '2026-09-29 08:00:01';
        $row[19] = (string) $queue->id;
        $row[20] = 'cancel';
        $row[21] = '1790668801';
        $row[22] = '';
        $row[23] = '1790668831';
        $this->assertTrue($importer->import($row));
        $this->assertDatabaseHas('call_records', [
            'call_queue_id' => $queue->id, 'queue_outcome' => 'cancel',
            'queue_wait_seconds' => 30, 'status' => 'missed',
        ]);
    }

    public function test_csv_command_resumes_from_its_cursor_and_replay_is_idempotent(): void
    {
        $tenant = Tenant::factory()->create();
        SipNumber::factory()->for($tenant)->create([
            'number' => '982191093464', 'normalized_number' => '+982191093464',
        ]);
        $directory = sys_get_temp_dir().'/blucom-cdr-'.bin2hex(random_bytes(6));
        mkdir($directory);
        $path = $directory.'/Master.csv';
        $handle = fopen($path, 'wb');
        fputcsv($handle, $this->row('public', '982191093464', '2cbabbe2-6798-4e0d-9862-1f91a055224c'), ',', '"', '');
        fclose($handle);

        try {
            $this->artisan('voip:import-cdr', ['--file' => $path])->assertSuccessful();
            $this->artisan('voip:import-cdr', ['--file' => $path])->assertSuccessful();
            $this->artisan('voip:import-cdr', ['--file' => $path, '--replay' => true])->assertSuccessful();
            $this->assertDatabaseCount('call_records', 1);
        } finally {
            unlink($path);
            rmdir($directory);
        }
    }

    /** @return list<string> */
    private function row(string $context, string $destination, string $uuid): array
    {
        return [
            'Caller', '1000', $destination, $context,
            '2026-09-29 08:00:00', '', '2026-09-29 08:01:30',
            '90', '0', 'NO_ANSWER', $uuid, '', '', 'PCMA', 'PCMA', '', '', '', '',
        ];
    }
}
