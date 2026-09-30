<?php

namespace Tests\Feature;

use App\Enums\UserType;
use App\Models\SipGateway;
use App\Models\SipNumber;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminCrudUxTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_indexes_link_to_focused_forms_without_inline_edit_fields(): void
    {
        $admin = User::factory()->create(['user_type' => UserType::Admin]);
        $gateway = SipGateway::factory()->create();

        $this->actingAs($admin)->get(route('sip-gateways.index'))
            ->assertOk()
            ->assertSee(route('sip-gateways.create'), false)
            ->assertSee(route('sip-gateways.edit', $gateway), false)
            ->assertDontSee('name="password"', false);

        $this->actingAs($admin)->get(route('sip-gateways.create'))
            ->assertOk()->assertSee('name="host"', false);
        $this->actingAs($admin)->get(route('sip-gateways.edit', $gateway))
            ->assertOk()->assertSee('خالی بگذارید تا رمز فعلی حفظ شود.');

        $this->actingAs($admin)->get(route('sip-extensions.index'))
            ->assertOk()->assertSee(route('sip-extensions.create'), false)
            ->assertDontSee('name="password"', false);
        $this->actingAs($admin)->get(route('sip-extensions.create'))
            ->assertOk()->assertSee('name="extension"', false);
    }

    public function test_gateway_status_action_changes_only_enabled_flag(): void
    {
        $admin = User::factory()->create(['user_type' => UserType::Admin]);
        $gateway = SipGateway::factory()->create([
            'host' => 'carrier.example.test', 'port' => 5060,
            'enabled' => true, 'approved_for_outbound' => true,
        ]);

        $this->actingAs($admin)->patch(route('sip-gateways.status', $gateway), [
            'enabled' => 0,
            'host' => 'stale.example.test',
            'approved_for_outbound' => 0,
        ])->assertRedirect(route('sip-gateways.index'));

        $gateway->refresh();
        $this->assertFalse($gateway->enabled);
        $this->assertSame('carrier.example.test', $gateway->host);
        $this->assertTrue($gateway->approved_for_outbound);
    }

    public function test_route_indexes_use_dedicated_editors_and_preserve_number_context(): void
    {
        $admin = User::factory()->create(['user_type' => UserType::Admin]);
        $tenant = Tenant::factory()->create();
        $number = SipNumber::factory()->for($tenant)->create([
            'enabled' => true, 'inbound_enabled' => true, 'outbound_enabled' => true,
            'status' => SipNumber::STATUS_ASSIGNED,
        ]);

        $this->actingAs($admin)->get(route('inbound-routes.index', ['sip_number_id' => $number->id]))
            ->assertOk()->assertSee(route('admin.sip-numbers.inbound', $number), false)
            ->assertDontSee('name="destination_choice"', false);

        $this->actingAs($admin)->get(route('outbound-routes.index', ['sip_number_id' => $number->id]))
            ->assertOk()->assertSee(route('outbound-routes.create', ['sip_number_id' => $number->id]), false)
            ->assertDontSee('name="gateway_id"', false);
        $this->actingAs($admin)->get(route('outbound-routes.create', ['sip_number_id' => $number->id]))
            ->assertOk()->assertSee('name="tenant_id" value="'.$tenant->id.'"', false)
            ->assertSee('value="'.$number->id.'" selected', false);
    }
}
