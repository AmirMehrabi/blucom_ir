<?php

namespace Tests\Feature;

use App\Enums\UserType;
use App\Models\CallQueue;
use App\Models\IvrMenu;
use App\Models\LineSetupWizard;
use App\Models\OutboundRoute;
use App\Models\SipExtension;
use App\Models\SipGateway;
use App\Models\SipNumber;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LineSetupWizardTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_can_resume_setup_and_sees_approval_status(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->operator($tenant);

        $this->actingAs($user)->get('/setup/wizard')->assertOk()->assertSee('راه‌اندازی کامل خط');
        $this->actingAs($user)->post('/setup/wizard/answer', ['answer_type' => 'person'])->assertRedirect('/setup/wizard');
        $this->actingAs($user)->post('/setup/providers', [
            'wizard' => '1', 'display_name' => 'Main', 'provider_name' => 'Carrier',
            'connection_method' => 'ip', 'host' => 'sip.example.test',
        ])->assertRedirect('/setup/wizard');
        $gateway = SipGateway::query()->firstOrFail();
        $this->actingAs($user)->post('/setup/numbers', [
            'wizard' => '1', 'number' => '02191093464', 'gateway_id' => $gateway->id,
        ])->assertRedirect('/setup/wizard');
        $number = SipNumber::query()->firstOrFail();
        $this->assertDatabaseHas('line_setup_wizards', [
            'tenant_id' => $tenant->id, 'answer_type' => 'person',
            'sip_gateway_id' => $gateway->id, 'sip_number_id' => $number->id,
        ]);
        $this->actingAs($user)->post('/setup/answer/'.$number->id, [
            'wizard' => '1', 'answerer' => 'new', 'display_name' => 'Sara',
        ])->assertRedirect();
        $this->actingAs($user)->get('/setup/wizard')->assertOk()
            ->assertSee('مسیر ورودی تنظیم شده است')
            ->assertDontSee('تنظیمات آماده آزمایش است');

        $extension = SipExtension::query()->whereBelongsTo($tenant)->firstOrFail();
        $this->actingAs($user)->post('/setup/wizard/outbound/'.$extension->id)->assertRedirect('/setup/wizard');
        $this->actingAs($user)->get('/setup/wizard')->assertOk()->assertSee('تنظیمات شما کامل است؛ در انتظار تأیید');

        $admin = User::factory()->create(['user_type' => UserType::Admin]);
        $this->actingAs($admin)->post('/admin/customer-connections/gateways/'.$gateway->id.'/approve')->assertRedirect();
        $this->actingAs($admin)->post('/admin/customer-connections/numbers/'.$number->id.'/approve')->assertRedirect();
        config(['voip.gateway_xml_enabled' => true]);
        $this->actingAs($user)->get('/setup/wizard')->assertOk()->assertSee('تنظیمات آماده آزمایش است');
    }

    public function test_wizard_refuses_foreign_records_and_limited_users(): void
    {
        $tenant = Tenant::factory()->create();
        $other = Tenant::factory()->create();
        $user = $this->operator($tenant);
        $foreignGateway = SipGateway::factory()->for($other)->create();
        $foreignNumber = SipNumber::factory()->for($other)->create(['provider_gateway_id' => $foreignGateway->id]);

        $this->actingAs($user)->post('/setup/wizard/gateway', ['gateway_id' => $foreignGateway->id])
            ->assertSessionHasErrors('gateway_id');
        $this->actingAs($user)->post('/setup/wizard/number', ['number_id' => $foreignNumber->id])
            ->assertSessionHasErrors('number_id');
        $this->assertNull(LineSetupWizard::query()->where('tenant_id', $tenant->id)->first()?->sip_number_id);

        $limited = User::factory()->create(['user_type' => UserType::Operator, 'tenant_id' => $tenant->id]);
        $limited->permissions()->create(['permission' => Permissions::LINES_VIEW]);
        $this->actingAs($limited)->get('/setup/wizard')->assertForbidden();
        $this->actingAs($limited)->post('/setup/wizard/phone', ['display_name' => 'No'])->assertForbidden();
        $this->actingAs(User::factory()->create(['user_type' => UserType::Admin]))->get('/setup/wizard')->assertForbidden();
    }

    public function test_operators_in_one_tenant_keep_separate_wizard_selections(): void
    {
        $tenant = Tenant::factory()->create();
        $first = $this->operator($tenant);
        $second = $this->operator($tenant);

        $this->actingAs($first)->post('/setup/wizard/answer', ['answer_type' => 'person'])->assertRedirect();
        $this->actingAs($second)->post('/setup/wizard/answer', ['answer_type' => 'menu'])->assertRedirect();

        $this->assertDatabaseHas('line_setup_wizards', [
            'tenant_id' => $tenant->id, 'created_by_user_id' => $first->id, 'answer_type' => 'person',
        ]);
        $this->assertDatabaseHas('line_setup_wizards', [
            'tenant_id' => $tenant->id, 'created_by_user_id' => $second->id, 'answer_type' => 'menu',
        ]);
    }

    public function test_team_branch_creates_a_tenant_phone_and_team(): void
    {
        config(['voip.queues_enabled' => true]);
        $tenant = Tenant::factory()->create();
        $user = $this->operator($tenant);
        $gateway = SipGateway::factory()->for($tenant)->create([
            'verification_status' => SipGateway::STATUS_PENDING, 'enabled' => false,
        ]);
        $number = SipNumber::factory()->for($tenant)->create([
            'provider_gateway_id' => $gateway->id, 'status' => SipNumber::STATUS_PENDING,
        ]);

        $this->actingAs($user)->post('/setup/wizard/answer', ['answer_type' => 'team'])->assertRedirect();
        $this->actingAs($user)->post('/setup/wizard/gateway', ['gateway_id' => $gateway->id])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($user)->post('/setup/wizard/number', ['number_id' => $number->id])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('line_setup_wizards', ['tenant_id' => $tenant->id, 'sip_gateway_id' => $gateway->id, 'sip_number_id' => $number->id]);
        $this->actingAs($user)->post('/setup/wizard/phone', ['display_name' => 'Support'])->assertRedirect();
        $extension = SipExtension::query()->whereBelongsTo($tenant)->firstOrFail();
        $this->assertDatabaseMissing('outbound_routes', ['sip_extension_id' => $extension->id]);
        $this->actingAs($user)->get('/teams?wizard=1')->assertOk()->assertSee('بازگشت به راه‌اندازی کامل خط');
        $this->actingAs($user)->post('/teams', [
            'name' => 'Support', 'strategy' => 'ring-all', 'max_wait_seconds' => 90,
            'member_ids' => [$extension->id],
        ])->assertRedirect();
        $queue = CallQueue::query()->whereBelongsTo($tenant)->firstOrFail();
        $foreignQueue = CallQueue::query()->create([
            'tenant_id' => Tenant::factory()->create()->id, 'name' => 'Foreign',
            'strategy' => 'ring-all', 'max_wait_seconds' => 90, 'enabled' => true,
        ]);
        $this->actingAs($user)->put('/teams/'.$foreignQueue->id, [
            'name' => 'Changed', 'strategy' => 'ring-all', 'max_wait_seconds' => 90,
            'member_ids' => [$extension->id], 'enabled' => '1',
        ])->assertNotFound();
        $this->actingAs($user)->post('/setup/answer/'.$number->id, [
            'wizard' => '1', 'answerer' => 'team', 'queue_id' => $queue->id,
        ])->assertRedirect('/setup/wizard');
        $this->actingAs($user)->get('/setup/wizard')->assertOk()->assertSee('مسیر ورودی تنظیم شده است');
        $this->actingAs($user)->post('/setup/wizard/outbound/'.$extension->id)->assertRedirect('/setup/wizard');
        $this->actingAs($user)->get('/setup/wizard')->assertOk()->assertSee('تنظیمات شما کامل است؛ در انتظار تأیید');
    }

    public function test_existing_phone_outbound_caller_id_changes_only_after_explicit_action(): void
    {
        $tenant = Tenant::factory()->create();
        $other = Tenant::factory()->create();
        $user = $this->operator($tenant);
        $gateway = SipGateway::factory()->for($tenant)->create();
        $number = SipNumber::factory()->for($tenant)->create(['provider_gateway_id' => $gateway->id]);
        $previous = SipNumber::factory()->for($tenant)->create(['provider_gateway_id' => $gateway->id]);
        $phone = SipExtension::factory()->for($tenant)->create();
        $foreignPhone = SipExtension::factory()->for($other)->create();
        OutboundRoute::query()->create([
            'tenant_id' => $tenant->id, 'sip_extension_id' => $phone->id,
            'sip_number_id' => $previous->id, 'gateway_id' => $gateway->id, 'enabled' => true,
        ]);

        $this->actingAs($user)->post('/setup/wizard/answer', ['answer_type' => 'person'])->assertRedirect();
        $this->actingAs($user)->post('/setup/wizard/gateway', ['gateway_id' => $gateway->id])->assertRedirect();
        $this->actingAs($user)->post('/setup/wizard/number', ['number_id' => $number->id])->assertRedirect();
        $this->actingAs($user)->post('/setup/answer/'.$number->id, [
            'wizard' => '1', 'answerer' => 'existing', 'extension_id' => $phone->id,
        ])->assertRedirect();
        $this->assertSame($previous->id, $phone->fresh()->outboundRoute->sip_number_id);
        $this->actingAs($user)->get('/setup/wizard')->assertOk()->assertSee('نیازمند بررسی');
        $this->actingAs($user)->post('/setup/wizard/outbound/'.$foreignPhone->id)->assertNotFound();
        $this->actingAs($user)->post('/setup/wizard/outbound/'.$phone->id)->assertRedirect('/setup/wizard');
        $this->assertSame($number->id, $phone->fresh()->outboundRoute->sip_number_id);
    }

    public function test_published_menu_branch_resolves_its_phone_destinations(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->operator($tenant);
        $gateway = SipGateway::factory()->for($tenant)->create([
            'verification_status' => SipGateway::STATUS_PENDING, 'enabled' => false,
        ]);
        $number = SipNumber::factory()->for($tenant)->create([
            'provider_gateway_id' => $gateway->id, 'status' => SipNumber::STATUS_PENDING,
        ]);
        $phone = SipExtension::factory()->for($tenant)->create();
        $menu = IvrMenu::query()->create([
            'tenant_id' => $tenant->id, 'name' => 'Main', 'enabled' => true,
            'published_config' => [
                'greeting' => 'sample.wav',
                'choices' => ['1' => ['label' => 'Sales', 'destination' => 'extension:'.$phone->id]],
                'fallback' => 'extension:'.$phone->id,
            ],
        ]);

        $this->actingAs($user)->post('/setup/wizard/answer', ['answer_type' => 'menu'])->assertRedirect();
        $this->actingAs($user)->post('/setup/wizard/gateway', ['gateway_id' => $gateway->id])->assertRedirect();
        $this->actingAs($user)->post('/setup/wizard/number', ['number_id' => $number->id])->assertRedirect();
        $this->actingAs($user)->post('/setup/answer/'.$number->id, [
            'wizard' => '1', 'answerer' => 'menu', 'menu_id' => $menu->id,
        ])->assertRedirect('/setup/wizard');
        $this->actingAs($user)->get('/setup/wizard')->assertOk()->assertSee('مسیر ورودی تنظیم شده است');
        $this->actingAs($user)->post('/setup/wizard/outbound/'.$phone->id)->assertRedirect('/setup/wizard');
        $this->actingAs($user)->get('/setup/wizard')->assertOk()->assertSee('تنظیمات شما کامل است؛ در انتظار تأیید');
    }

    private function operator(Tenant $tenant): User
    {
        $user = User::factory()->create(['user_type' => UserType::Operator, 'tenant_id' => $tenant->id]);
        foreach (Permissions::OPERATOR_DEFAULTS as $permission) {
            $user->permissions()->create(['permission' => $permission]);
        }

        return $user;
    }
}
