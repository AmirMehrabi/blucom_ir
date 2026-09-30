<?php

namespace Tests\Feature;

use App\Enums\UserType;
use App\Models\AdminLineSetup;
use App\Models\SipExtension;
use App\Models\SipGateway;
use App\Models\SipNumber;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminLineSetupTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_configure_schedule_and_preview_closed_destination_for_customer_number(): void
    {
        $admin = User::factory()->create(['user_type' => UserType::Admin]);
        $tenant = Tenant::factory()->create();
        $number = SipNumber::factory()->for($tenant)->create();
        $answer = SipExtension::factory()->for($tenant)->create();
        $closed = SipExtension::factory()->for($tenant)->create();
        $foreign = SipExtension::factory()->for(Tenant::factory())->create();

        $this->actingAs($admin)->get(route('admin.sip-numbers.inbound', $number))->assertOk()
            ->assertSee('شرایط زمانی تماس ورودی');

        $this->actingAs($admin)->put(route('admin.sip-numbers.inbound.update', $number), [
            'destination_choice' => 'extension:'.$answer->id, 'schedule_mode' => 'scheduled',
            'timezone' => 'Asia/Tehran', 'weekly' => [0 => [['start' => '09:00', 'end' => '17:00']]],
            'closed_action' => 'extension:'.$foreign->id, 'enabled' => 1,
        ])->assertSessionHasErrors('closed_action');
        $this->assertDatabaseMissing('inbound_routes', ['sip_number_id' => $number->id]);

        $this->actingAs($admin)->put(route('admin.sip-numbers.inbound.update', $number), [
            'destination_choice' => 'extension:'.$answer->id, 'schedule_mode' => 'scheduled',
            'timezone' => 'Asia/Tehran', 'weekly' => [0 => [['start' => '09:00', 'end' => '17:00']]],
            'closed_action' => 'extension:'.$closed->id, 'enabled' => 1,
        ])->assertRedirect(route('admin.sip-numbers.setup', ['sip_number' => $number->id, 'tab' => 'inbound']));
        $route = $number->fresh()->inboundRoute;
        $this->assertSame($closed->id, $route->closed_destination_id);
        $this->actingAs($admin)->post(route('admin.sip-numbers.preview', $number), [
            'at' => '2026-10-03T20:00',
        ])->assertSessionHas('schedule_preview.open', false);
        $this->actingAs($admin)->get(route('admin.time-conditions.index'))->assertOk()
            ->assertSee($number->normalized_number);
    }

    public function test_admin_wizard_applies_reviewed_routing_and_outbound_once(): void
    {
        $admin = User::factory()->create(['user_type' => UserType::Admin]);
        $tenant = Tenant::factory()->create();
        $gateway = SipGateway::factory()->create(['tenant_id' => null, 'enabled' => true, 'approved_for_outbound' => true]);
        $phone = SipExtension::factory()->for($tenant)->create(['enabled' => true]);
        $foreign = SipExtension::factory()->for(Tenant::factory())->create(['enabled' => true]);

        $this->actingAs($admin)->post(route('admin.setup.store'), ['tenant_id' => $tenant->id])->assertRedirect();
        $draft = AdminLineSetup::query()->firstOrFail();
        $this->actingAs($admin)->get(route('admin.setup.show', $draft))->assertOk()->assertSee('اتصال ارائه‌دهنده');
        $this->actingAs($admin)->put(route('admin.setup.update', $draft), ['step' => 2, 'gateway_id' => $gateway->id])->assertRedirect();
        $this->actingAs($admin)->put(route('admin.setup.update', $draft), [
            'step' => 3, 'number_mode' => 'new', 'number' => '982191093465', 'label' => 'Sales',
        ])->assertRedirect();
        $this->actingAs($admin)->get(route('admin.setup.show', $draft))->assertOk()->assertSee('شرایط زمانی تماس ورودی');
        $this->actingAs($admin)->put(route('admin.setup.update', $draft), [
            'step' => 4, 'answerer' => 'existing', 'destination_choice' => 'extension:'.$phone->id,
            'schedule_mode' => 'scheduled', 'timezone' => 'Asia/Tehran',
            'weekly' => [0 => [['start' => '09:00', 'end' => '17:00']]],
            'closed_dates' => ['۱۴۰۵/۰۷/۱۲'], 'closed_action' => 'disconnect',
        ])->assertRedirect();
        $this->actingAs($admin)->put(route('admin.setup.update', $draft), [
            'step' => 5, 'outbound_enabled' => 1, 'outbound_extension_ids' => [$foreign->id],
        ])->assertSessionHasErrors('outbound_extension_ids.0');
        $this->actingAs($admin)->put(route('admin.setup.update', $draft), [
            'step' => 5, 'outbound_enabled' => 1, 'outbound_extension_ids' => [$phone->id],
        ])->assertRedirect();
        $this->assertDatabaseMissing('sip_numbers', ['number' => '982191093465']);
        $this->actingAs($admin)->get(route('admin.setup.show', $draft))->assertOk()->assertSee('Sales');
        $this->actingAs($admin)->post(route('admin.setup.finish', $draft), ['confirm' => 1])->assertRedirect();
        $number = SipNumber::query()->where('number', '982191093465')->firstOrFail();
        $this->assertSame($tenant->id, $number->tenant_id);
        $this->assertSame($gateway->id, $number->provider_gateway_id);
        $this->assertSame('disconnect', $number->inboundRoute->closed_destination_type);
        $this->assertDatabaseHas('outbound_routes', ['sip_extension_id' => $phone->id,
            'sip_number_id' => $number->id, 'gateway_id' => $gateway->id]);
        $this->actingAs($admin)->post(route('admin.setup.finish', $draft), ['confirm' => 1])->assertRedirect();
        $this->assertSame(1, SipNumber::query()->where('number', '982191093465')->count());
    }

    public function test_admin_wizard_refuses_stale_number_and_other_admin_draft(): void
    {
        $admin = User::factory()->create(['user_type' => UserType::Admin]);
        $otherAdmin = User::factory()->create(['user_type' => UserType::Admin]);
        $tenant = Tenant::factory()->create();
        $number = SipNumber::factory()->for($tenant)->create();
        $phone = SipExtension::factory()->for($tenant)->create();
        $gateway = SipGateway::factory()->create(['tenant_id' => null, 'enabled' => true]);
        $this->actingAs($admin)->post(route('admin.setup.store'), ['tenant_id' => $tenant->id]);
        $draft = AdminLineSetup::query()->firstOrFail();
        $this->actingAs($otherAdmin)->get(route('admin.setup.show', $draft))->assertNotFound();
        $this->actingAs($admin)->put(route('admin.setup.update', $draft), ['step' => 2, 'gateway_id' => $gateway->id]);
        $this->actingAs($admin)->put(route('admin.setup.update', $draft), [
            'step' => 3, 'number_mode' => 'existing', 'number_id' => $number->id,
        ])->assertRedirect();
        $this->actingAs($admin)->put(route('admin.setup.update', $draft), [
            'step' => 4, 'answerer' => 'existing', 'destination_choice' => 'extension:'.$phone->id,
            'schedule_mode' => 'anytime',
        ])->assertRedirect();
        $this->actingAs($admin)->put(route('admin.setup.update', $draft), ['step' => 5, 'outbound_enabled' => 0])->assertRedirect();
        $number->update(['label' => 'Changed elsewhere']);
        $this->actingAs($admin)->post(route('admin.setup.finish', $draft), ['confirm' => 1])->assertSessionHasErrors('number_id');
        $this->assertDatabaseMissing('inbound_routes', ['sip_number_id' => $number->id]);
    }
}
