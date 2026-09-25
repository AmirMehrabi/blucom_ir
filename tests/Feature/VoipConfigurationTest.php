<?php

namespace Tests\Feature;

use App\Enums\UserType;
use App\Models\SipExtension;
use App\Models\SipGateway;
use App\Models\SipNumber;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BlucomOwner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class VoipConfigurationTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['user_type' => UserType::Admin]);
    }

    private function owner(): Tenant
    {
        return app(BlucomOwner::class)->get();
    }

    public function test_extension_password_is_encrypted_and_shown_only_after_creation(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/sip-extensions', [
            'extension' => '1001',
            'display_name' => 'Desk',
        ])->assertRedirect();

        $extension = SipExtension::query()->firstOrFail();
        $this->assertNotEmpty($extension->password_encrypted);
        $this->assertNotSame($extension->password_encrypted, DB::table('sip_extensions')->value('password_encrypted'));

        $this->actingAs($admin)->get('/sip-extensions')->assertOk()->assertSee($extension->password_encrypted, false);
        $this->actingAs($admin)->get('/sip-extensions')->assertOk()->assertDontSee($extension->password_encrypted, false);

        $oldPassword = $extension->password_encrypted;
        $this->actingAs($admin)->put('/sip-extensions/'.$extension->id, ['generate_password' => 1])->assertRedirect();
        $this->assertNotSame($oldPassword, $extension->fresh()->password_encrypted);
        $this->actingAs($admin)->get('/sip-extensions')->assertOk()->assertSee($extension->fresh()->password_encrypted, false);
        $this->actingAs($admin)->get('/sip-extensions')->assertOk()->assertDontSee($extension->fresh()->password_encrypted, false);
    }

    public function test_admin_can_create_inbound_route_and_cannot_delete_its_extension(): void
    {
        $owner = $this->owner();
        $number = SipNumber::factory()->for($owner)->create();
        $extension = SipExtension::factory()->for($owner)->create();
        $admin = $this->admin();

        $this->actingAs($admin)->post('/inbound-routes', [
            'sip_number_id' => $number->id,
            'destination_id' => $extension->id,
            'enabled' => 1,
        ])->assertRedirect();

        $this->assertDatabaseHas('inbound_routes', [
            'sip_number_id' => $number->id,
            'destination_id' => $extension->id,
        ]);
        $this->actingAs($admin)->delete('/sip-extensions/'.$extension->id)->assertSessionHasErrors('extension');
        $this->assertModelExists($extension);
    }

    public function test_inbound_destination_requires_a_supported_active_extension(): void
    {
        $owner = $this->owner();
        $number = SipNumber::factory()->for($owner)->create();
        $extension = SipExtension::factory()->for($owner)->disabled()->create();
        $admin = $this->admin();

        $this->actingAs($admin)->post('/inbound-routes', [
            'sip_number_id' => $number->id,
            'destination_type' => 'queue',
            'destination_id' => $extension->id,
        ])->assertSessionHasErrors('destination_type');

        $this->actingAs($admin)->post('/inbound-routes', [
            'sip_number_id' => $number->id,
            'destination_type' => 'extension',
            'destination_id' => $extension->id,
        ])->assertSessionHasErrors('destination_id');

        $this->assertDatabaseCount('inbound_routes', 0);
    }

    public function test_outbound_route_is_per_extension_and_rejects_unapproved_gateway(): void
    {
        $owner = $this->owner();
        $number = SipNumber::factory()->for($owner)->create();
        $extensionA = SipExtension::factory()->for($owner)->create();
        $extensionB = SipExtension::factory()->for($owner)->create();
        $allowed = SipGateway::factory()->create(['name' => 'provider-trunk', 'approved_for_outbound' => true]);
        $other = SipGateway::factory()->create(['name' => 'unapproved']);
        $admin = $this->admin();

        $base = ['sip_number_id' => $number->id, 'enabled' => 1];
        $this->actingAs($admin)->post('/outbound-routes', $base + [
            'sip_extension_id' => $extensionA->id,
            'gateway_id' => $other->id,
        ])->assertSessionHasErrors('gateway_id');

        $this->actingAs($admin)->post('/outbound-routes', $base + [
            'sip_extension_id' => $extensionA->id,
            'gateway_id' => $allowed->id,
        ])->assertRedirect();

        $this->actingAs($admin)->post('/outbound-routes', $base + [
            'sip_extension_id' => $extensionB->id,
            'gateway_id' => $allowed->id,
        ])->assertRedirect();

        $this->assertDatabaseCount('outbound_routes', 2);
        $this->actingAs($admin)->post('/outbound-routes', $base + [
            'sip_extension_id' => $extensionA->id,
            'gateway_id' => $allowed->id,
        ])->assertSessionHasErrors('sip_extension_id');
    }

    public function test_admin_cannot_route_to_other_owner_records(): void
    {
        $owner = $this->owner();
        $foreign = Tenant::factory()->create();
        $number = SipNumber::factory()->for($owner)->create();
        $foreignNumber = SipNumber::factory()->for($foreign)->create();
        $extension = SipExtension::factory()->for($owner)->create();
        $foreignExtension = SipExtension::factory()->for($foreign)->create();
        $gateway = SipGateway::factory()->create(['name' => 'provider-trunk']);
        $admin = $this->admin();

        $this->actingAs($admin)->post('/inbound-routes', [
            'sip_number_id' => $number->id,
            'destination_id' => $foreignExtension->id,
        ])->assertSessionHasErrors('destination_id');

        $this->actingAs($admin)->post('/outbound-routes', [
            'sip_extension_id' => $extension->id,
            'sip_number_id' => $foreignNumber->id,
            'gateway_id' => $gateway->id,
        ])->assertSessionHasErrors('sip_number_id');

        $this->assertDatabaseCount('inbound_routes', 0);
        $this->assertDatabaseCount('outbound_routes', 0);
    }
}
