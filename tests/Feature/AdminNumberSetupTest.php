<?php

namespace Tests\Feature;

use App\Enums\UserType;
use App\Models\InboundRoute;
use App\Models\IvrMenu;
use App\Models\OutboundRoute;
use App\Models\SipExtension;
use App\Models\SipGateway;
use App\Models\SipNumber;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BlucomOwner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminNumberSetupTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_admin_number_opens_a_setup_workspace(): void
    {
        $admin = User::factory()->create(['user_type' => UserType::Admin]);

        $this->actingAs($admin)->post('/admin/sip-numbers', [
            'number' => '982191093464',
            'enabled' => 1,
            'inbound_enabled' => 1,
            'outbound_enabled' => 1,
        ])->assertRedirect(route('admin.sip-numbers.setup', SipNumber::query()->firstOrFail()));

        $number = SipNumber::query()->firstOrFail();
        $this->actingAs($admin)->get(route('admin.sip-numbers.setup', $number))
            ->assertOk()
            ->assertSee($number->normalized_number)
            ->assertSee(route('ivr-menus.index', ['number_id' => $number->id]), false)
            ->assertSee(route('inbound-routes.index', ['sip_number_id' => $number->id]), false)
            ->assertSee(route('outbound-routes.index', ['sip_number_id' => $number->id]), false)
            ->assertSee('نتیجه تماس واقعی هنوز در این صفحه ثبت یا تأیید نمی‌شود.');
    }

    public function test_gateway_selection_is_scoped_to_number_owner(): void
    {
        $owner = app(BlucomOwner::class)->get();
        $admin = User::factory()->create(['user_type' => UserType::Admin]);
        $number = SipNumber::factory()->for($owner)->create(['enabled' => true]);
        $foreignNumber = SipNumber::factory()->for(Tenant::factory())->create(['enabled' => true]);
        $gateway = SipGateway::factory()->create(['tenant_id' => null]);
        $foreignGateway = SipGateway::factory()->create(['tenant_id' => Tenant::factory()->create()->id]);

        $this->actingAs($admin)->put(route('admin.sip-numbers.gateway', $number), [
            'provider_gateway_id' => $foreignGateway->id,
        ])->assertSessionHasErrors('provider_gateway_id');
        $this->assertNull($number->fresh()->provider_gateway_id);

        $this->actingAs($admin)->put(route('admin.sip-numbers.gateway', $number), [
            'provider_gateway_id' => $gateway->id,
        ])->assertRedirect(route('admin.sip-numbers.setup', $number));
        $this->assertSame($gateway->id, $number->fresh()->provider_gateway_id);

        $this->actingAs($admin)->get(route('admin.sip-numbers.setup', $foreignNumber))->assertOk()
            ->assertSee($foreignNumber->normalized_number);
        $this->actingAs($admin)->put(route('admin.sip-numbers.gateway', $foreignNumber), [
            'provider_gateway_id' => $gateway->id,
        ])->assertRedirect(route('admin.sip-numbers.setup', $foreignNumber));
    }

    public function test_admin_menu_editor_returns_to_the_selected_number(): void
    {
        $owner = app(BlucomOwner::class)->get();
        $admin = User::factory()->create(['user_type' => UserType::Admin]);
        $number = SipNumber::factory()->for($owner)->create(['enabled' => true]);

        $this->actingAs($admin)->get('/menus?number_id='.$number->id)
            ->assertOk()
            ->assertSee(route('admin.sip-numbers.setup', $number), false);
        $this->actingAs($admin)->post('/menus', ['name' => 'Main', 'number_id' => $number->id])
            ->assertRedirect(route('ivr-menus.edit', ['menu' => IvrMenu::query()->firstOrFail()->id, 'number_id' => $number->id]));
        $menu = IvrMenu::query()->firstOrFail();
        $menu->update(['enabled' => true, 'published_config' => ['greeting' => 'example.wav']]);
        $this->actingAs($admin)->get('/menus/'.$menu->id.'/edit?number_id='.$number->id)
            ->assertOk()
            ->assertSee(route('admin.sip-numbers.setup', $number), false)
            ->assertSee(route('admin.sip-numbers.index'), false)
            ->assertDontSee(route('customer.setup.lines'), false);
    }

    public function test_setup_progress_reflects_configured_routes_without_claiming_a_live_call_test(): void
    {
        $owner = app(BlucomOwner::class)->get();
        $admin = User::factory()->create(['user_type' => UserType::Admin]);
        $gateway = SipGateway::factory()->create(['tenant_id' => null, 'approved_for_outbound' => true]);
        $number = SipNumber::factory()->for($owner)->create([
            'enabled' => true, 'inbound_enabled' => true, 'outbound_enabled' => true,
            'provider_gateway_id' => $gateway->id,
        ]);
        $extension = SipExtension::factory()->for($owner)->create(['enabled' => true]);
        InboundRoute::query()->create([
            'tenant_id' => $owner->id, 'sip_number_id' => $number->id,
            'destination_type' => InboundRoute::DESTINATION_EXTENSION,
            'destination_id' => $extension->id, 'enabled' => true,
        ]);
        OutboundRoute::query()->create([
            'tenant_id' => $owner->id, 'sip_number_id' => $number->id,
            'sip_extension_id' => $extension->id, 'gateway_id' => $gateway->id, 'enabled' => true,
        ]);

        $this->actingAs($admin)->get(route('admin.sip-numbers.setup', $number))
            ->assertOk()->assertSee('4 از ۴ مرحله پیکربندی')
            ->assertSee('نتیجه تماس واقعی هنوز در این صفحه ثبت یا تأیید نمی‌شود.');

        $this->actingAs($admin)->get('/outbound-routes?sip_number_id='.$number->id)
            ->assertOk()->assertSee('value="'.$number->id.'" selected', false)
            ->assertSee(route('admin.sip-numbers.setup', $number), false);
    }
}
