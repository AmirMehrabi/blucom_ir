<?php

namespace Tests\Feature;

use App\Models\SipExtension;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ImportWorkingVoipTest extends TestCase
{
    use RefreshDatabase;

    public function test_import_is_idempotent_and_keeps_sip_password_encrypted(): void
    {
        $this->artisan('voip:import-working')
            ->expectsQuestion('Enter the CURRENT SIP password for extension 1000', 'local-test-password')
            ->assertSuccessful();

        $extension = SipExtension::query()->where('extension', '1000')->firstOrFail();
        $this->assertSame('local-test-password', $extension->password_encrypted);
        $this->assertNotSame('local-test-password', DB::table('sip_extensions')->where('id', $extension->id)->value('password_encrypted'));
        $this->assertDatabaseCount('sip_numbers', 1);
        $this->assertDatabaseCount('inbound_routes', 1);
        $this->assertDatabaseCount('outbound_routes', 1);

        $this->artisan('voip:import-working')->assertSuccessful();
        $this->assertDatabaseCount('sip_extensions', 1);
        $this->assertDatabaseCount('sip_numbers', 1);
        $this->assertDatabaseCount('inbound_routes', 1);
        $this->assertDatabaseCount('outbound_routes', 1);
    }
}
