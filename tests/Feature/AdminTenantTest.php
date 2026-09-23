<?php

namespace Tests\Feature;

use App\Enums\UserType;
use App\Models\SipGateway;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminTenantTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['user_type' => UserType::Admin]);
    }

    private function customer(): User
    {
        return User::factory()->create(['user_type' => UserType::Customer]);
    }

    public function test_admin_lists_tenants(): void
    {
        Tenant::factory()->create(['name' => 'Acme VoIP']);

        $this->actingAs($this->admin())
            ->get('/admin/tenants')
            ->assertOk()
            ->assertSee('Acme VoIP');
    }

    public function test_customer_cannot_access_admin_tenants(): void
    {
        $this->actingAs($this->customer())
            ->get('/admin/tenants')
            ->assertForbidden();
    }

    public function test_admin_shows_tenant_detail(): void
    {
        $tenant = Tenant::factory()->create(['name' => 'Northstar Labs']);

        $this->actingAs($this->admin())
            ->get('/admin/tenants/'.$tenant->id)
            ->assertOk()
            ->assertSee('Northstar Labs');
    }

    public function test_admin_disables_tenant(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);

        $this->actingAs($this->admin())
            ->put('/admin/tenants/'.$tenant->id, ['status' => 'disabled'])
            ->assertRedirect();

        $this->assertSame('disabled', $tenant->fresh()->status);
    }

    public function test_gateway_store_forces_external_public_context(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/sip-gateways', [
            'name' => 'evil-trunk',
            'host' => '10.0.0.1',
            'port' => 5060,
            'transport' => 'udp',
            'profile' => 'internal',
            'context' => 'default',
        ])->assertRedirect();

        $gateway = SipGateway::query()->where('name', 'evil-trunk')->firstOrFail();

        $this->assertSame('external', $gateway->profile);
        $this->assertSame('public', $gateway->context);
    }

    public function test_gateway_update_forces_external_public_context(): void
    {
        $admin = $this->admin();
        $gateway = SipGateway::factory()->create([
            'profile' => 'external',
            'context' => 'public',
        ]);

        $this->actingAs($admin)->put('/sip-gateways/'.$gateway->id, [
            'host' => '10.0.0.2',
            'port' => 5060,
            'transport' => 'udp',
            'profile' => 'internal',
            'context' => 'default',
        ])->assertRedirect();

        $gateway->refresh();
        $this->assertSame('external', $gateway->profile);
        $this->assertSame('public', $gateway->context);
        $this->assertSame('10.0.0.2', $gateway->host);
    }
}
