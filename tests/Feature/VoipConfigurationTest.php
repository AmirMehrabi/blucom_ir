<?php

namespace Tests\Feature;

use App\Enums\UserType;
use App\Models\InboundRoute;
use App\Models\OutboundRoute;
use App\Models\SipExtension;
use App\Models\SipGateway;
use App\Models\SipNumber;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VoipConfigurationTest extends TestCase
{
    use RefreshDatabase;

    private function customer(): User
    {
        return User::factory()->create(['user_type' => UserType::Customer]);
    }

    private function admin(): User
    {
        return User::factory()->create(['user_type' => UserType::Admin]);
    }

    public function test_guest_is_redirected_from_configuration_pages(): void
    {
        $this->get('/sip-numbers')->assertRedirect('/login');
    }

    public function test_customer_byod_request_is_pending_until_approved(): void
    {
        $user = $this->customer();

        $this->actingAs($user)
            ->post('/sip-numbers', ['number' => '09123456789'])
            ->assertRedirect();

        $number = SipNumber::query()->firstOrFail();

        $this->assertSame(SipNumber::STATUS_PENDING, $number->status);
        $this->assertNull($number->tenant_id);
        $this->assertSame($user->id, $number->requested_by_user_id);
        $this->assertSame('+989123456789', $number->normalized_number);
    }

    public function test_customer_duplicate_byod_request_is_rejected(): void
    {
        $user = $this->customer();

        $this->actingAs($user)->post('/sip-numbers', ['number' => '09123456789']);
        $this->actingAs($user)
            ->post('/sip-numbers', ['number' => '09123456789'])
            ->assertSessionHasErrors('number');

        $this->assertDatabaseCount('sip_numbers', 1);
    }

    public function test_customer_cannot_use_another_tenants_sip_number(): void
    {
        $owner = $this->customer();
        $intruder = $this->customer();
        $number = SipNumber::factory()->for(
            Tenant::factory()->create(['owner_user_id' => $owner->id])
        )->create();
        $owner->update(['tenant_id' => $number->tenant_id]);

        $this->actingAs($intruder)
            ->put('/sip-numbers/'.$number->id, ['status' => 'disabled'])
            ->assertNotFound();

        $this->assertSame(SipNumber::STATUS_ASSIGNED, $number->fresh()->status);
    }

    public function test_customer_store_ignores_gateway_selection(): void
    {
        $user = $this->customer();

        $this->actingAs($user)
            ->post('/sip-numbers', [
                'number' => '98211234567',
                'provider_gateway_id' => 999999,
            ])
            ->assertRedirect();

        $number = SipNumber::query()->firstOrFail();
        $this->assertNull($number->provider_gateway_id);
        $this->assertSame(SipNumber::STATUS_PENDING, $number->status);
    }

    public function test_inbound_route_requires_tenant_owned_number_and_extension(): void
    {
        $user = $this->customer();
        $tenant = Tenant::factory()->create(['owner_user_id' => $user->id]);
        $user->update(['tenant_id' => $tenant->id]);

        $foreignTenant = Tenant::factory()->create();
        $foreignNumber = SipNumber::factory()->for($foreignTenant)->create();
        $ownExtension = SipExtension::factory()->for($tenant)->create();

        $this->actingAs($user)
            ->post('/inbound-routes', [
                'sip_number_id' => $foreignNumber->id,
                'destination_id' => $ownExtension->id,
            ])
            ->assertSessionHasErrors('sip_number_id');

        $ownNumber = SipNumber::factory()->for($tenant)->create();
        $foreignExtension = SipExtension::factory()->for($foreignTenant)->create();

        $this->actingAs($user)
            ->post('/inbound-routes', [
                'sip_number_id' => $ownNumber->id,
                'destination_id' => $foreignExtension->id,
            ])
            ->assertSessionHasErrors('destination_id');

        $this->assertDatabaseCount('inbound_routes', 0);
    }

    public function test_outbound_route_rejects_unknown_gateway(): void
    {
        $user = $this->customer();
        $tenant = Tenant::factory()->create(['owner_user_id' => $user->id]);
        $user->update(['tenant_id' => $tenant->id]);
        $number = SipNumber::factory()->for($tenant)->create();

        $this->actingAs($user)
            ->post('/outbound-routes', [
                'sip_number_id' => $number->id,
                'gateway_id' => 424242,
            ])
            ->assertSessionHasErrors('gateway_id');

        $this->assertDatabaseCount('outbound_routes', 0);
    }

    public function test_outbound_route_requires_tenant_owned_number(): void
    {
        $user = $this->customer();
        $tenant = Tenant::factory()->create(['owner_user_id' => $user->id]);
        $user->update(['tenant_id' => $tenant->id]);
        $gateway = SipGateway::factory()->create();
        $foreignNumber = SipNumber::factory()->create();

        $this->actingAs($user)
            ->post('/outbound-routes', [
                'sip_number_id' => $foreignNumber->id,
                'gateway_id' => $gateway->id,
            ])
            ->assertSessionHasErrors('sip_number_id');

        $this->assertDatabaseCount('outbound_routes', 0);
    }

    public function test_customer_cannot_manage_gateways(): void
    {
        $this->actingAs($this->customer())
            ->get('/sip-gateways')
            ->assertForbidden();
    }

    public function test_admin_creates_gateway_and_password_is_hidden_from_html(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/sip-gateways', [
            'name' => 'provider-trunk',
            'host' => '172.28.238.162',
            'port' => 5060,
            'transport' => 'udp',
            'username' => 'trunk-user',
            'password' => 'provider-secret',
            'profile' => 'external',
            'context' => 'public',
        ])->assertRedirect();

        $gateway = SipGateway::query()->where('name', 'provider-trunk')->firstOrFail();
        $this->assertSame('provider-secret', $gateway->password_encrypted);
        $this->assertSame('external', $gateway->profile);
        $this->assertSame('public', $gateway->context);

        $response = $this->actingAs($admin)->get('/sip-gateways');
        $response->assertOk();
        $response->assertSee('provider-trunk');
        $response->assertDontSee('provider-secret');
        $response->assertDontSee('provider-secret', false);
    }

    public function test_extension_credentials_are_shown_once_after_create(): void
    {
        $user = $this->customer();

        $this->actingAs($user)
            ->post('/sip-extensions', [
                'extension' => '1000',
                'display_name' => 'Zoiper',
            ])
            ->assertRedirect();

        $extension = SipExtension::query()->where('extension', '1000')->firstOrFail();
        $this->assertNotEmpty($extension->password_encrypted);

        $response = $this->actingAs($user)->get('/sip-extensions');
        $response->assertOk();
        $response->assertSee($extension->password_encrypted, false);

        $response = $this->actingAs($user)->get('/sip-extensions');
        $response->assertOk();
        $response->assertDontSee($extension->password_encrypted, false);
    }

    public function test_cross_tenant_inbound_route_update_returns_404(): void
    {
        $intruder = $this->customer();
        $route = InboundRoute::factory()->create();
        $intruder->update(['tenant_id' => Tenant::factory()->create()->id]);

        $this->actingAs($intruder)
            ->put('/inbound-routes/'.$route->id, ['enabled' => 0])
            ->assertNotFound();
    }

    public function test_cross_tenant_outbound_route_delete_returns_404(): void
    {
        $intruder = $this->customer();
        $route = OutboundRoute::factory()->create();
        $tenant = Tenant::factory()->create();
        $intruder->update(['tenant_id' => $tenant->id]);

        $this->actingAs($intruder)
            ->delete('/outbound-routes/'.$route->id)
            ->assertNotFound();

        $this->assertModelExists($route);
    }

    public function test_customer_cannot_create_inventory_numbers(): void
    {
        $user = $this->customer();

        $this->actingAs($user)->get('/admin/sip-numbers')->assertForbidden();
        $this->actingAs($user)->post('/admin/sip-numbers', ['number' => '98219999999'])->assertForbidden();

        $this->assertDatabaseCount('sip_numbers', 0);
    }
}
