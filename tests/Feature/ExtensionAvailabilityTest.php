<?php

namespace Tests\Feature;

use App\Models\InboundRoute;
use App\Models\IvrMenu;
use App\Models\OutboundRoute;
use App\Models\SipExtension;
use App\Models\SipGateway;
use App\Models\SipNumber;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BlucomOwner;
use App\Services\CustomerAccountService;
use App\Services\ExtensionAvailabilityService;
use App\Services\FreeSwitchDialplanService;
use App\Services\FreeSwitchDirectoryService;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ExtensionAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    private function actor(Tenant $tenant, SipExtension $phone): User
    {
        $actor = User::factory()->create(['tenant_id' => $tenant->id, 'sip_extension_id' => $phone->id]);
        $actor->permissions()->create(['permission' => Permissions::QUEUES_WORK]);

        return $actor;
    }

    public function test_explicit_pause_is_idempotent_resume_preserves_break_and_rejects_stale_requests(): void
    {
        $tenant = Tenant::factory()->create();
        $phone = SipExtension::factory()->for($tenant)->create(['queue_status' => 'On Break']);
        $actor = $this->actor($tenant, $phone);
        $url = '/extensions/'.$phone->id.'/availability';
        $this->actingAs($actor)->putJson($url, ['paused' => true, 'version' => 0])->assertOk()->assertJsonPath('version', 1);
        $this->putJson($url, ['paused' => true, 'version' => 0])->assertOk();
        $this->assertDatabaseCount('extension_availability_events', 1);
        $this->assertTrue($phone->fresh()->enabled);
        $this->putJson($url, ['paused' => false, 'version' => 0])->assertConflict();
        $this->putJson($url, ['paused' => false, 'version' => 1])->assertOk();
        $phone->refresh();
        $this->assertNull($phone->paused_at);
        $this->assertSame('On Break', $phone->queue_status);
        $this->assertSame('On Break', app(ExtensionAvailabilityService::class)->effectiveQueueStatus($phone));
        $this->assertDatabaseCount('extension_availability_events', 2);
        $this->get('/availability')->assertOk()->assertSee('توقف دریافت تماس');
    }

    public function test_actor_cannot_pause_another_extension_or_tenant_and_disabled_extensions_fail(): void
    {
        $tenant = Tenant::factory()->create();
        $phone = SipExtension::factory()->for($tenant)->create();
        $other = SipExtension::factory()->for($tenant)->create();
        $foreign = SipExtension::factory()->create();
        $this->actingAs($this->actor($tenant, $phone));
        $this->putJson('/extensions/'.$other->id.'/availability', ['paused' => true, 'version' => 0])->assertForbidden();
        $this->getJson('/extensions/'.$foreign->id.'/availability')->assertNotFound();
        $this->putJson('/extensions/'.$foreign->id.'/availability', ['paused' => true, 'version' => 0])->assertNotFound();
        $phone->update(['enabled' => false]);
        $this->putJson('/extensions/'.$phone->id.'/availability', ['paused' => true, 'version' => 0])->assertUnprocessable();
    }

    public function test_paused_inbound_target_uses_one_authorized_fallback_and_local_dial_fails_closed(): void
    {
        $tenant = app(BlucomOwner::class)->get();
        $phone = SipExtension::factory()->for($tenant)->create(['extension' => '8701']);
        $fallback = SipExtension::factory()->for($tenant)->create(['extension' => '8702']);
        $number = SipNumber::factory()->for($tenant)->create();
        InboundRoute::query()->create(['tenant_id' => $tenant->id, 'sip_number_id' => $number->id, 'destination_type' => 'extension',
            'destination_id' => $phone->id, 'enabled' => true, 'unanswered_type' => 'extension', 'unanswered_id' => $fallback->id]);
        app(ExtensionAvailabilityService::class)->pause($this->actor($tenant, $phone), $phone->id, true, 0);
        $xml = app(FreeSwitchDialplanService::class)->build('public');
        $this->assertStringNotContainsString('user/'.$phone->sipIdentity().'@', $xml);
        $this->assertStringContainsString('user/'.$fallback->sipIdentity().'@', $xml);
        $this->assertStringContainsString('blucom_extension_paused=true', $xml);
        $local = app(FreeSwitchDialplanService::class)->build('default', ['variable_sip_auth_username' => $fallback->sipIdentity()]);
        $this->assertStringContainsString('application="hangup" data="USER_BUSY"', $local);
        app(ExtensionAvailabilityService::class)->pause($this->actor($tenant, $fallback), $fallback->id, true, 0);
        $xml = app(FreeSwitchDialplanService::class)->build('public');
        $this->assertStringNotContainsString('application="bridge"', $xml);
        $this->assertStringContainsString('application="hangup"', $xml);
        // Directory authentication is deliberately independent of pause.
        $directory = app(FreeSwitchDirectoryService::class)->build($tenant);
        $this->assertStringContainsString('id="'.$phone->sipIdentity().'"', $directory);
    }

    public function test_paused_ivr_choice_uses_menu_fallback_without_republishing(): void
    {
        Storage::fake('ivr');
        $tenant = app(BlucomOwner::class)->get();
        $phone = SipExtension::factory()->for($tenant)->create(['extension' => '8801']);
        $fallback = SipExtension::factory()->for($tenant)->create(['extension' => '8802']);
        $number = SipNumber::factory()->for($tenant)->create();
        $greeting = $tenant->id.'/1/greeting.wav';
        Storage::disk('ivr')->put($greeting, 'RIFF-test-wave');
        $menu = IvrMenu::query()->create(['tenant_id' => $tenant->id, 'name' => 'Main', 'enabled' => true, 'version' => 1,
            'published_config' => ['greeting' => $greeting, 'choices' => ['1' => ['label' => 'Sales', 'destination' => 'extension:'.$phone->id]], 'fallback' => 'extension:'.$fallback->id]]);
        InboundRoute::query()->create(['tenant_id' => $tenant->id, 'sip_number_id' => $number->id, 'destination_type' => 'ivr', 'destination_id' => $menu->id, 'enabled' => true]);
        app(ExtensionAvailabilityService::class)->pause($this->actor($tenant, $phone), $phone->id, true, 0);
        $request = ['variable_blucom_ivr_menu_id' => (string) $menu->id, 'variable_blucom_ivr_number_id' => (string) $number->id, 'variable_blucom_ivr_version' => '1', 'variable_blucom_ivr_digit' => '1'];
        $xml = app(FreeSwitchDialplanService::class)->build('blucom_ivr', $request);
        $this->assertStringNotContainsString('user/'.$phone->sipIdentity().'@', $xml);
        $this->assertStringContainsString('user/'.$fallback->sipIdentity().'@', $xml);
        app(ExtensionAvailabilityService::class)->pause($this->actor($tenant, $fallback), $fallback->id, true, 0);
        $this->assertStringContainsString('application="hangup" data="USER_BUSY"', app(FreeSwitchDialplanService::class)->build('blucom_ivr', $request));
    }

    public function test_customer_supervisor_control_and_audit_journey(): void
    {
        $owner = app(CustomerAccountService::class)->createOwner('Owner', '+989120000088', 'Pause business');
        $phone = SipExtension::factory()->create(['tenant_id' => $owner->tenant_id]);
        $url = 'https://my.blucom.ir/extensions/'.$phone->id.'/availability';
        $this->actingAs($owner, 'customer')->putJson($url, ['paused' => true, 'version' => 0])->assertOk();
        $this->getJson($url)->assertOk()->assertJsonPath('paused', true)->assertJsonPath('pending', false);
        $this->get('https://my.blucom.ir/extensions')->assertOk()->assertSee('ادامه دریافت تماس');
        $this->get('https://my.blucom.ir/extensions/'.$phone->id)->assertOk()->assertSee('آخرین تغییرات دریافت تماس');
        $this->assertDatabaseHas('extension_availability_events', ['tenant_id' => $owner->tenant_id, 'actor_type' => 'customer', 'actor_id' => $owner->id, 'action' => 'paused']);
    }

    public function test_paused_extension_can_still_originate_authorized_outbound_calls(): void
    {
        $tenant = app(BlucomOwner::class)->get();
        $phone = SipExtension::factory()->for($tenant)->create();
        $number = SipNumber::factory()->for($tenant)->create();
        $gateway = SipGateway::factory()->create(['tenant_id' => null, 'approved_for_outbound' => true, 'verification_status' => 'approved', 'enabled' => true]);
        OutboundRoute::query()->create(['tenant_id' => $tenant->id, 'sip_extension_id' => $phone->id, 'sip_number_id' => $number->id, 'gateway_id' => $gateway->id, 'enabled' => true]);
        app(ExtensionAvailabilityService::class)->pause($this->actor($tenant, $phone), $phone->id, true, 0);
        $xml = app(FreeSwitchDialplanService::class)->build('default', ['variable_sip_auth_username' => $phone->sipIdentity(), 'destination_number' => '+989121234567']);
        $this->assertStringContainsString('sofia/gateway/'.$gateway->name.'/', $xml);
    }

    public function test_queue_break_changes_do_not_unpause_extension(): void
    {
        $tenant = Tenant::factory()->create();
        $phone = SipExtension::factory()->for($tenant)->create();
        $actor = $this->actor($tenant, $phone);
        app(ExtensionAvailabilityService::class)->pause($actor, $phone->id, true, 0);
        $this->actingAs($actor)->post('/availability', ['status' => 'On Break'])->assertRedirect();
        $this->post('/availability', ['status' => 'Available'])->assertRedirect();
        $this->assertNotNull($phone->fresh()->paused_at);
        $this->assertSame('On Break', app(ExtensionAvailabilityService::class)->effectiveQueueStatus($phone->fresh()));
        $this->assertSame(3, $phone->fresh()->availability_version);
    }
}
