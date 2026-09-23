<?php

namespace Tests\Feature;

use App\Enums\UserType;
use App\Models\InboundRoute;
use App\Models\SipExtension;
use App\Models\SipGateway;
use App\Models\SipNumber;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SipNumberCatalogTest extends TestCase
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

    public function test_admin_creates_available_inventory_number(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post('/admin/sip-numbers', ['number' => '09121112233'])
            ->assertRedirect(route('admin.sip-numbers.index'));

        $number = SipNumber::query()->firstOrFail();

        $number->refresh();

        $this->assertSame(SipNumber::STATUS_AVAILABLE, $number->status);
        $this->assertNull($number->tenant_id);
        $this->assertSame('+989121112233', $number->normalized_number);
    }

    public function test_admin_duplicate_inventory_number_is_rejected(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/admin/sip-numbers', ['number' => '09121112233']);
        $this->actingAs($admin)
            ->post('/admin/sip-numbers', ['number' => '09121112233'])
            ->assertSessionHasErrors('number');

        $this->assertDatabaseCount('sip_numbers', 1);
    }

    public function test_customer_assigns_available_number_to_own_tenant(): void
    {
        $user = $this->customer();
        $number = SipNumber::factory()->available()->create();

        $this->actingAs($user)
            ->post('/sip-numbers/'.$number->id.'/assign')
            ->assertRedirect();

        $number->refresh();
        $tenant = Tenant::query()->findOrFail($user->fresh()->tenant_id);

        $this->assertSame(SipNumber::STATUS_ASSIGNED, $number->status);
        $this->assertSame($tenant->id, $number->tenant_id);
    }

    public function test_customer_cannot_assign_already_assigned_number(): void
    {
        $user = $this->customer();
        $number = SipNumber::factory()->create();

        $this->actingAs($user)
            ->post('/sip-numbers/'.$number->id.'/assign')
            ->assertNotFound();

        $this->assertSame(SipNumber::STATUS_ASSIGNED, $number->fresh()->status);
    }

    public function test_customer_releases_assigned_number_and_routes_are_removed(): void
    {
        $user = $this->customer();
        $tenant = Tenant::factory()->create(['owner_user_id' => $user->id]);
        $user->update(['tenant_id' => $tenant->id]);
        $number = SipNumber::factory()->for($tenant)->create();
        $extension = SipExtension::factory()->for($tenant)->create();

        InboundRoute::factory()->create([
            'tenant_id' => $tenant->id,
            'sip_number_id' => $number->id,
            'destination_id' => $extension->id,
        ]);

        $this->actingAs($user)
            ->post('/sip-numbers/'.$number->id.'/release')
            ->assertRedirect();

        $number->refresh();
        $this->assertSame(SipNumber::STATUS_AVAILABLE, $number->status);
        $this->assertNull($number->tenant_id);
        $this->assertDatabaseCount('inbound_routes', 0);
    }

    public function test_customer_cannot_release_foreign_number(): void
    {
        $intruder = $this->customer();
        $number = SipNumber::factory()->create();
        $intruder->update(['tenant_id' => Tenant::factory()->create()->id]);

        $this->actingAs($intruder)
            ->post('/sip-numbers/'.$number->id.'/release')
            ->assertNotFound();
    }

    public function test_admin_approves_byod_request_to_requester_tenant(): void
    {
        $admin = $this->admin();
        $customer = $this->customer();
        $tenant = Tenant::factory()->create(['owner_user_id' => $customer->id]);
        $customer->update(['tenant_id' => $tenant->id]);

        $number = SipNumber::factory()->pending($customer)->create([
            'number' => '09351112233',
            'normalized_number' => '+989351112233',
        ]);

        $this->actingAs($admin)
            ->post('/admin/sip-numbers/'.$number->id.'/approve', ['disposition' => 'assign'])
            ->assertRedirect();

        $number->refresh();
        $this->assertSame(SipNumber::STATUS_ASSIGNED, $number->status);
        $this->assertSame($tenant->id, $number->tenant_id);
        $this->assertNull($number->requested_by_user_id);
    }

    public function test_admin_approves_byod_request_to_pool(): void
    {
        $admin = $this->admin();
        $customer = $this->customer();
        $number = SipNumber::factory()->pending($customer)->create();

        $this->actingAs($admin)
            ->post('/admin/sip-numbers/'.$number->id.'/approve', ['disposition' => 'available'])
            ->assertRedirect();

        $number->refresh();
        $this->assertSame(SipNumber::STATUS_AVAILABLE, $number->status);
        $this->assertNull($number->tenant_id);
    }

    public function test_admin_rejects_byod_request(): void
    {
        $admin = $this->admin();
        $number = SipNumber::factory()->pending()->create();

        $this->actingAs($admin)
            ->post('/admin/sip-numbers/'.$number->id.'/reject')
            ->assertRedirect();

        $this->assertModelMissing($number);
    }

    public function test_admin_reassigns_number_between_tenants(): void
    {
        $admin = $this->admin();
        $from = Tenant::factory()->create();
        $to = Tenant::factory()->create();
        $number = SipNumber::factory()->for($from)->create();

        $this->actingAs($admin)
            ->put('/admin/sip-numbers/'.$number->id, ['tenant_id' => $to->id])
            ->assertRedirect();

        $number->refresh();
        $this->assertSame($to->id, $number->tenant_id);
        $this->assertSame(SipNumber::STATUS_ASSIGNED, $number->status);
    }

    public function test_admin_sets_trunk_on_number(): void
    {
        $admin = $this->admin();
        $gateway = SipGateway::factory()->create(['enabled' => true]);
        $number = SipNumber::factory()->available()->create();

        $this->actingAs($admin)
            ->put('/admin/sip-numbers/'.$number->id, ['provider_gateway_id' => $gateway->id])
            ->assertRedirect();

        $this->assertSame($gateway->id, $number->fresh()->provider_gateway_id);
    }

    public function test_customer_update_cannot_change_trunk(): void
    {
        $user = $this->customer();
        $tenant = Tenant::factory()->create(['owner_user_id' => $user->id]);
        $user->update(['tenant_id' => $tenant->id]);
        $gateway = SipGateway::factory()->create();
        $number = SipNumber::factory()->for($tenant)->create(['provider_gateway_id' => null]);

        $this->actingAs($user)
            ->put('/sip-numbers/'.$number->id, [
                'status' => 'assigned',
                'provider_gateway_id' => $gateway->id,
            ]);

        $this->assertNull($number->fresh()->provider_gateway_id);
    }

    public function test_disabled_tenant_customer_cannot_open_portal(): void
    {
        $user = $this->customer();
        $tenant = Tenant::factory()->create(['owner_user_id' => $user->id, 'status' => 'disabled']);
        $user->update(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->get('/sip-numbers')->assertForbidden();
        $this->actingAs($user)->get('/portal')->assertForbidden();
    }
}
