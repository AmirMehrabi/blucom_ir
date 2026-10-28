<?php

namespace Tests\Feature;

use App\Models\InboundRoute;
use App\Models\IvrMenu;
use App\Models\OutboundRoute;
use App\Models\SipExtension;
use App\Models\SipGateway;
use App\Models\SipNumber;
use App\Services\CustomerAccountService;
use App\Services\FreeSwitchDialplanService;
use App\Services\FreeSwitchDirectoryService;
use App\Services\InboundScheduleService;
use App\Services\IvrMenuService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CustomerLineJourneyTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $owner = app(CustomerAccountService::class)->createOwner('Journey owner', '+989120000001', 'Journey tenant');
        $gateway = SipGateway::factory()->create(['tenant_id' => null, 'verification_status' => 'approved', 'approved_for_outbound' => true]);
        $line = SipNumber::factory()->create(['tenant_id' => $owner->tenant_id, 'provider_gateway_id' => $gateway->id, 'inventory_state' => null, 'status' => 'assigned', 'enabled' => true, 'inbound_enabled' => true, 'outbound_enabled' => true]);
        $phone = SipExtension::factory()->create(['tenant_id' => $owner->tenant_id]);
        $backup = SipExtension::factory()->create(['tenant_id' => $owner->tenant_id]);
        $route = InboundRoute::query()->create(['tenant_id' => $owner->tenant_id, 'sip_number_id' => $line->id, 'destination_type' => 'extension', 'destination_id' => $phone->id, 'enabled' => true]);
        $this->actingAs($owner, 'customer');

        return [$owner, $line, $phone, $backup, $route, $gateway];
    }

    private function url(SipNumber $line, string $action): string
    {
        return 'https://my.blucom.ir/lines/'.$line->id.'/'.$action;
    }

    public function test_three_visible_choices_phone_setup_link_and_sidebar_order(): void
    {
        [, $line] = $this->fixture();
        config(['voip.queues_enabled' => true]);
        $response = $this->get($this->url($line, ''));
        $response->assertOk()->assertSee('اتصال مستقیم به داخلی')->assertSee('صف پاسخ‌گویی')->assertSee('منوی صوتی')->assertSee('ساعت کاری')->assertSee('مدیریت و اتصال داخلی‌ها');
        $this->assertLessThan(strpos($response->getContent(), '>داخلی‌ها<'), strpos($response->getContent(), '>خط‌های من<'));
        $response->assertDontSee('data-toggle-password', false);

    }

    public function test_destination_changes_preserve_schedule_and_hours_are_explicitly_editable(): void
    {
        [, $line, , $backup, $route] = $this->fixture();
        $schedule = ['timezone' => 'Asia/Tehran', 'weekly' => ['0' => [['start' => '09:00', 'end' => '17:00']]], 'closed_dates' => []];
        $route->update(['schedule' => $schedule, 'closed_destination_type' => 'disconnect']);
        $this->post($this->url($line, 'answer'), ['answerer' => 'existing', 'extension_id' => $backup->id])->assertSessionHasNoErrors();
        $this->assertSame($schedule, $route->fresh()->schedule);
        $this->post($this->url($line, 'hours'), ['schedule_mode' => 'scheduled', 'timezone' => 'Asia/Tehran', 'weekly' => ['0' => [['start' => '08:00', 'end' => '12:00']]], 'closed_action' => 'disconnect'])->assertSessionHasNoErrors();
        $this->assertSame('08:00', $route->fresh()->schedule['weekly'][0][0]['start']);
        $this->post($this->url($line, 'hours'), ['schedule_mode' => 'scheduled', 'timezone' => 'Asia/Tehran', 'weekly' => [], 'closed_action' => 'disconnect'])->assertSessionHasErrors('weekly');
        $this->post($this->url($line, 'hours'), ['schedule_mode' => 'anytime'])->assertSessionHasNoErrors();
        $this->assertNull($route->fresh()->schedule);
    }

    public function test_temporary_closure_auto_expires_and_preview_has_no_configuration_side_effect(): void
    {
        [, $line, , , $route] = $this->fixture();
        $this->assertNull($route->closed_destination_type);
        $until = now('Asia/Tehran')->addHours(2)->format('Y-m-d\TH:i');
        $this->post($this->url($line, 'closure'), ['closed' => 1, 'until' => $until, 'timezone' => 'Asia/Tehran'])->assertSessionHasNoErrors();
        $this->assertFalse(app(InboundScheduleService::class)->isOpen($route->fresh()));
        $this->post($this->url($line, 'preview'), ['at' => now('Asia/Tehran')->format('Y-m-d\TH:i')])->assertSessionHas('schedule_preview.open', false);
        $this->travel(3)->hours();
        $this->assertTrue(app(InboundScheduleService::class)->isOpen($route->fresh()));
        $this->post($this->url($line, 'closure'), ['closed' => 0])->assertSessionHasNoErrors();
        $this->assertNull($route->fresh()->temporary_closed_until);
    }

    public function test_unanswered_route_is_bounded_tenant_scoped_and_uses_busy_offline_timeout_handling(): void
    {
        [, $line, $phone, $backup, $route] = $this->fixture();
        $this->post($this->url($line, 'unanswered'), ['ring_seconds' => 20, 'unanswered' => 'extension:'.$backup->id])->assertSessionHasNoErrors();
        $xml = app(FreeSwitchDialplanService::class)->build('public');
        $this->assertStringContainsString('call_timeout=20', $xml);
        $this->assertStringContainsString('USER_BUSY,NO_ANSWER', $xml);
        $this->assertStringContainsString('user/'.$backup->sipIdentity().'@', $xml);
        $foreign = SipExtension::factory()->create();
        $this->post($this->url($line, 'unanswered'), ['ring_seconds' => 20, 'unanswered' => 'extension:'.$foreign->id])->assertSessionHasErrors('unanswered');
        $this->post($this->url($line, 'unanswered'), ['ring_seconds' => 20, 'unanswered' => 'extension:'.$phone->id])->assertSessionHasErrors('unanswered');
        $backup->update(['enabled' => false]);
        $this->assertStringNotContainsString('user/'.$backup->sipIdentity().'@', app(FreeSwitchDialplanService::class)->build('public'));
        $route->update(['unanswered_id' => $foreign->id]);
        $this->assertStringNotContainsString('user/'.$foreign->sipIdentity().'@', app(FreeSwitchDialplanService::class)->build('public'));
    }

    public function test_forwarding_requires_existing_authorized_outbound_route_and_rechecks_revocation(): void
    {
        [$owner, $line, $phone, , $route, $gateway] = $this->fixture();
        $data = ['ring_seconds' => 20, 'unanswered' => 'forward', 'forward_phone' => '09121234567'];
        $this->post($this->url($line, 'unanswered'), $data)->assertSessionHasErrors('caller_id');
        $outbound = OutboundRoute::query()->create(['tenant_id' => $owner->tenant_id, 'sip_extension_id' => $phone->id, 'sip_number_id' => $line->id, 'gateway_id' => $gateway->id, 'enabled' => true]);
        $this->post($this->url($line, 'unanswered'), $data)->assertSessionHasNoErrors();
        $this->assertSame('+989121234567', $route->fresh()->unanswered_phone);
        $xml = app(FreeSwitchDialplanService::class)->build('public');
        $this->assertStringContainsString('sofia/gateway/'.$gateway->name.'/989121234567', $xml);
        $this->assertStringContainsString('effective_caller_id_number='.ltrim($line->normalized_number, '+'), $xml);
        $outbound->update(['enabled' => false]);
        $this->assertStringNotContainsString('sofia/gateway/', app(FreeSwitchDialplanService::class)->build('public'));
        $this->post($this->url($line, 'unanswered'), [...$data, 'forward_phone' => 'sofia/gateway/evil'])->assertSessionHasErrors('forward_phone');
    }

    public function test_voicemail_is_gated_and_mailbox_access_is_bound_to_authenticated_sip_identity(): void
    {
        // Keep the gate test deterministic when production has voicemail enabled.
        config(['voip.voicemail_enabled' => false]);
        [$owner, $line, $phone] = $this->fixture();
        $this->post($this->url($line, 'unanswered'), ['ring_seconds' => 20, 'unanswered' => 'voicemail'])->assertNotFound();
        config(['voip.voicemail_enabled' => true]);
        $this->post($this->url($line, 'unanswered'), ['ring_seconds' => 20, 'unanswered' => 'voicemail'])->assertSessionHasNoErrors();
        $phone->refresh();
        $this->assertNotEmpty($phone->voicemail_secret_encrypted);
        $this->assertArrayNotHasKey('voicemail_secret_encrypted', $phone->toArray());
        $this->assertStringContainsString('vm-password', app(FreeSwitchDirectoryService::class)->voicemailDirectory(FreeSwitchDirectoryService::voicemailDomain($owner->tenant_id), $phone->sipIdentity(), 'voicemail-lookup'));
        $this->assertStringContainsString('application="voicemail"', app(FreeSwitchDialplanService::class)->build('public'));
        $own = app(FreeSwitchDialplanService::class)->build('default', ['variable_sip_auth_username' => $phone->sipIdentity()]);
        $this->assertStringContainsString('check auth default '.FreeSwitchDirectoryService::voicemailDomain($owner->tenant_id).' '.$phone->sipIdentity(), $own);
        $this->assertStringNotContainsString('check auth', app(FreeSwitchDialplanService::class)->build('default', ['variable_tenant_id' => $owner->tenant_id]));
    }

    public function test_voicemail_directory_is_tenant_scoped_and_cannot_authenticate_sip_registrations(): void
    {
        config(['voip.voicemail_enabled' => true, 'voip.xml_curl.token' => 'synthetic-voicemail-xml-token']);
        [$owner, , $phone] = $this->fixture();
        $phone->update(['voicemail_secret_encrypted' => (string) random_int(100000000000, 999999999999)]);
        $foreign = SipExtension::factory()->create(['voicemail_secret_encrypted' => (string) random_int(100000000000, 999999999999)]);
        $domain = FreeSwitchDirectoryService::voicemailDomain($owner->tenant_id);
        $payload = ['section' => 'directory', 'domain' => $domain, 'user' => $phone->sipIdentity(), 'action' => 'voicemail-lookup', 'tag_name' => 'domain', 'key_name' => 'name'];
        $response = $this->withHeader('X-FS-Token', 'synthetic-voicemail-xml-token')->post('https://admin.blucom.ir/internal/freeswitch/xml', $payload);
        $response->assertOk()->assertSee('vm-enabled')->assertSee('vm-alternate-greet-id')->assertDontSee('name="password"', false)->assertDontSee('user_context');
        $this->post('https://admin.blucom.ir/internal/freeswitch/xml', [...$payload, 'user' => $foreign->sipIdentity()])->assertDontSee('vm-password');
        $this->post('https://admin.blucom.ir/internal/freeswitch/xml', [...$payload, 'action' => 'sip_auth'])->assertDontSee('vm-password');
        $this->assertStringNotContainsString('vm-password', app(FreeSwitchDirectoryService::class)->build($owner->tenant));
        $alias = app(FreeSwitchDirectoryService::class)->voicemailDirectory($domain, $phone->extension);
        $this->assertStringContainsString('id="'.$phone->sipIdentity().'"', $alias);
        $phone->update(['enabled' => false]);
        $this->assertStringNotContainsString('vm-password', app(FreeSwitchDirectoryService::class)->voicemailDirectory($domain, $phone->extension));
    }

    public function test_all_line_controls_enforce_ownership_and_manage_permission(): void
    {
        [$owner, $line] = $this->fixture();
        $foreignLine = SipNumber::factory()->create();
        foreach (['hours' => ['schedule_mode' => 'anytime'], 'unanswered' => ['ring_seconds' => 20, 'unanswered' => 'disconnect'], 'closure' => ['closed' => 0], 'preview' => ['at' => now()->format('Y-m-d\TH:i')]] as $action => $data) {
            $this->post($this->url($foreignLine, $action), $data)->assertNotFound();
        }
        $owner->permissions()->where('permission', 'phones.manage')->delete();
        $this->post($this->url($line, 'hours'), ['schedule_mode' => 'anytime'])->assertForbidden();
    }

    public function test_nested_menu_routes_only_reachable_owned_menus_and_rejects_cycles(): void
    {
        [$owner, $line, $phone, , $route] = $this->fixture();
        Storage::fake('ivr');
        Storage::disk('ivr')->put('greeting.wav', 'RIFF-test');
        $childConfig = ['greeting' => 'greeting.wav', 'choices' => ['1' => ['label' => 'Reception', 'destination' => 'extension:'.$phone->id]], 'fallback' => 'extension:'.$phone->id];
        $child = IvrMenu::query()->create(['tenant_id' => $owner->tenant_id, 'name' => 'Child', 'enabled' => true, 'published_config' => $childConfig, 'version' => 1]);
        $rootConfig = ['greeting' => 'greeting.wav', 'choices' => ['1' => ['label' => 'Sales', 'destination' => 'ivr:'.$child->id]], 'fallback' => 'extension:'.$phone->id];
        $root = IvrMenu::query()->create(['tenant_id' => $owner->tenant_id, 'name' => 'Root', 'enabled' => true, 'published_config' => $rootConfig, 'version' => 1]);
        $route->update(['destination_type' => 'ivr', 'destination_id' => $root->id]);
        $request = ['variable_blucom_ivr_menu_id' => $root->id, 'variable_blucom_ivr_number_id' => $line->id, 'variable_blucom_ivr_version' => 1, 'variable_blucom_ivr_digit' => '1'];
        $this->assertStringContainsString('blucom_ivr_menu_id='.$child->id, app(FreeSwitchDialplanService::class)->build('blucom_ivr', $request));
        $request['variable_blucom_ivr_menu_id'] = $child->id;
        $this->assertStringContainsString('user/'.$phone->sipIdentity().'@', app(FreeSwitchDialplanService::class)->build('blucom_ivr', $request));
        $orphan = IvrMenu::query()->create(['tenant_id' => $owner->tenant_id, 'name' => 'Orphan', 'enabled' => true, 'published_config' => $childConfig, 'version' => 1]);
        $request['variable_blucom_ivr_menu_id'] = $orphan->id;
        $this->assertStringNotContainsString('application="bridge"', app(FreeSwitchDialplanService::class)->build('blucom_ivr', $request));
        $child->update(['draft_config' => [...$childConfig, 'choices' => ['1' => ['label' => 'Loop', 'destination' => 'ivr:'.$root->id]]]]);
        $this->expectException(ValidationException::class);
        app(IvrMenuService::class)->publish($child);
    }
}
