<?php

namespace Tests\Feature;

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ExtensionAvailabilityMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_preexisting_availability_schema_is_preserved_and_missing_index_is_completed(): void
    {
        $tenant = Tenant::factory()->create();
        DB::table('extension_availability_events')->insert([
            'tenant_id' => $tenant->id, 'actor_type' => 'user', 'actor_id' => 1, 'action' => 'pause', 'version' => 1, 'occurred_at' => now(),
        ]);
        Schema::table('extension_availability_events', fn ($table) => $table->dropIndex('extension_availability_tenant_extension_time_idx'));
        $migration = require database_path('migrations/2026_11_10_000004_extension_availability.php');
        $migration->up();
        $migration->up();
        $this->assertDatabaseCount('extension_availability_events', 1);
        $this->assertTrue(collect(Schema::getIndexes('extension_availability_events'))->contains(fn ($index) => $index['columns'] === ['tenant_id', 'sip_extension_id', 'occurred_at']));
    }
}
