<?php

namespace Tests\Feature;

use App\Enums\UserType;
use App\Models\InboundRoute;
use App\Models\SipExtension;
use App\Models\SipNumber;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BlucomOwner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SipNumberCatalogTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['user_type' => UserType::Admin]);
    }

    public function test_admin_creates_normalized_did_and_can_disable_it(): void
    {
        $this->actingAs($this->admin())->post('/admin/sip-numbers', [
            'number' => '00982191093464',
            'label' => 'Main Tehran',
            'enabled' => 1,
            'inbound_enabled' => 1,
            'outbound_enabled' => 1,
        ])->assertRedirect();

        $number = SipNumber::query()->firstOrFail();
        $this->assertSame('+982191093464', $number->normalized_number);
        $this->assertSame('Main Tehran', $number->label);
        $this->assertSame(SipNumber::STATUS_ASSIGNED, $number->status);

        $this->actingAs($this->admin())->put('/admin/sip-numbers/'.$number->id, [
            'enabled' => 0,
            'inbound_enabled' => 0,
            'outbound_enabled' => 1,
        ])->assertRedirect();

        $this->assertFalse($number->fresh()->enabled);
        $this->assertFalse($number->fresh()->inbound_enabled);
    }

    public function test_duplicate_normalized_did_is_rejected(): void
    {
        $admin = $this->admin();
        $base = ['enabled' => 1, 'inbound_enabled' => 1, 'outbound_enabled' => 1];

        $this->actingAs($admin)->post('/admin/sip-numbers', $base + ['number' => '982191093464'])->assertRedirect();
        $this->actingAs($admin)->post('/admin/sip-numbers', $base + ['number' => '+982191093464'])
            ->assertSessionHasErrors('normalized_number');

        $this->assertDatabaseCount('sip_numbers', 1);
    }

    public function test_customer_cannot_create_did(): void
    {
        $customer = User::factory()->create(['user_type' => UserType::Operator]);

        $this->actingAs($customer)->post('/admin/sip-numbers', [
            'number' => '982191093464',
            'enabled' => 1,
            'inbound_enabled' => 1,
            'outbound_enabled' => 1,
        ])->assertForbidden();
        $this->assertDatabaseCount('sip_numbers', 0);
    }

    public function test_admin_can_reach_the_correct_routing_form_from_each_did(): void
    {
        $owner = app(BlucomOwner::class)->get();
        $admin = $this->admin();
        $unrouted = SipNumber::factory()->for($owner)->create(['enabled' => true]);
        $routed = SipNumber::factory()->for($owner)->create(['enabled' => true]);
        $extension = SipExtension::factory()->for($owner)->create();
        $route = InboundRoute::query()->create([
            'tenant_id' => $owner->id,
            'sip_number_id' => $routed->id,
            'destination_type' => InboundRoute::DESTINATION_EXTENSION,
            'destination_id' => $extension->id,
            'enabled' => true,
        ]);

        $this->actingAs($admin)->get('/admin/sip-numbers')
            ->assertOk()
            ->assertSee('href="'.route('admin.sip-numbers.setup', ['sip_number' => $unrouted->id, 'tab' => 'inbound']).'"', false)
            ->assertSee('href="'.route('admin.sip-numbers.setup', ['sip_number' => $routed->id, 'tab' => 'inbound']).'"', false)
            ->assertSee($route->destinationLabel());

        $this->actingAs($admin)->get('/inbound-routes?sip_number_id='.$unrouted->id)
            ->assertOk()
            ->assertSee(route('admin.sip-numbers.inbound', $unrouted), false);

        $foreignNumber = SipNumber::factory()->for(Tenant::factory())->create(['enabled' => true]);
        $this->actingAs($admin)->get('/inbound-routes?sip_number_id='.$foreignNumber->id)
            ->assertOk()
            ->assertSee(route('admin.sip-numbers.inbound', $foreignNumber), false);
    }
}
