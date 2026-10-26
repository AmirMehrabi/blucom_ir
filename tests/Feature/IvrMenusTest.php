<?php

namespace Tests\Feature;

use App\Enums\UserType;
use App\Models\InboundRoute;
use App\Models\IvrMenu;
use App\Models\SipExtension;
use App\Models\SipGateway;
use App\Models\SipNumber;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BlucomOwner;
use App\Services\FreeSwitchDialplanService;
use App\Services\IvrMenuService;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class IvrMenusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('ivr');
        config(['voip.xml_curl.token' => 'test-ivr-token']);
    }

    public function test_admin_drafts_and_publishes_menu_then_routes_only_owned_choices(): void
    {
        $tenant = app(BlucomOwner::class)->get();
        $admin = User::factory()->create(['user_type' => UserType::Admin]);
        $sales = SipExtension::factory()->for($tenant)->create(['extension' => '1000']);
        $fallback = SipExtension::factory()->for($tenant)->create(['extension' => '1001']);
        $foreign = SipExtension::factory()->for(Tenant::factory()->create())->create(['extension' => '2000']);
        $number = SipNumber::factory()->for($tenant)->create();

        $this->actingAs($admin)->post('/menus', ['name' => 'Main'])->assertRedirect();
        $menu = IvrMenu::query()->firstOrFail();
        $this->actingAs($admin)->get('/menus/'.$menu->id.'/edit')->assertOk()->assertSee('Main');
        $greeting = $tenant->id.'/'.$menu->id.'/01KTEST.wav';
        Storage::disk('ivr')->put($greeting, 'RIFF-test-wave');
        $menu->update(['draft_config' => ['greeting' => $greeting, 'choices' => [], 'fallback' => '']]);

        $this->actingAs($admin)->put('/menus/'.$menu->id, [
            'name' => 'Main',
            'choices' => ['1' => ['label' => 'Sales', 'destination' => 'extension:'.$foreign->id]],
            'fallback' => 'extension:'.$fallback->id,
        ])->assertSessionHasErrors('choices');

        $this->actingAs($admin)->put('/menus/'.$menu->id, [
            'name' => 'Main',
            'choices' => ['1' => ['label' => 'Sales', 'destination' => 'extension:'.$sales->id]],
            'fallback' => 'extension:'.$fallback->id,
        ])->assertRedirect();
        $this->assertNull($menu->fresh()->published_config);
        $this->actingAs($admin)->post('/menus/'.$menu->id.'/publish')->assertRedirect();
        $menu->refresh();
        $this->assertSame(1, $menu->version);
        $this->actingAs($admin)->get('/menus/'.$menu->id.'/audio/draft')->assertOk()
            ->assertHeader('Content-Type', 'audio/wav');

        InboundRoute::query()->create([
            'tenant_id' => $tenant->id, 'sip_number_id' => $number->id,
            'destination_type' => 'ivr', 'destination_id' => $menu->id, 'enabled' => true,
        ]);
        $public = app(FreeSwitchDialplanService::class)->build('public');
        $this->assertStringContainsString('application="play_and_get_digits"', $public);
        $this->assertStringContainsString('^['.'1'.']$', $public);
        $this->assertStringContainsString('application="transfer" data="blucom-menu XML blucom_ivr"', $public);
        $this->assertStringNotContainsString('user/2000@', $public);

        $choice = $this->ivrLookup($menu, $number, '1');
        $this->assertStringContainsString('user/1000@', $choice);
        $this->assertStringNotContainsString('user/2000@', $choice);
        $this->assertStringContainsString('user/1001@', $this->ivrLookup($menu, $number, '9'));
        $this->assertStringContainsString('user/1001@', $this->ivrLookup($menu, $number, ''));
        $this->assertStringNotContainsString('user/1000@', app(FreeSwitchDialplanService::class)->build('blucom_ivr'));
    }

    public function test_draft_changes_do_not_affect_published_menu_and_unknown_context_fails_closed(): void
    {
        $tenant = app(BlucomOwner::class)->get();
        $number = SipNumber::factory()->for($tenant)->create();
        $extension = SipExtension::factory()->for($tenant)->create(['extension' => '1000']);
        $other = SipExtension::factory()->for($tenant)->create(['extension' => '1001']);
        $menu = IvrMenu::query()->create([
            'tenant_id' => $tenant->id, 'name' => 'Support', 'enabled' => true,
            'published_config' => [
                'greeting' => $tenant->id.'/1/01KTEST.wav',
                'choices' => ['1' => ['label' => 'Help', 'destination' => 'extension:'.$extension->id]],
                'fallback' => 'extension:'.$extension->id,
            ],
            'draft_config' => [
                'greeting' => $tenant->id.'/1/01KTEST.wav',
                'choices' => ['1' => ['label' => 'Help', 'destination' => 'extension:'.$other->id]],
                'fallback' => 'extension:'.$other->id,
            ],
            'version' => 1,
        ]);
        Storage::disk('ivr')->put($tenant->id.'/'.$menu->id.'/01KTEST.wav', 'RIFF-test-wave');
        InboundRoute::query()->create([
            'tenant_id' => $tenant->id, 'sip_number_id' => $number->id,
            'destination_type' => 'ivr', 'destination_id' => $menu->id, 'enabled' => true,
        ]);

        $this->assertStringContainsString('user/1000@', $this->ivrLookup($menu, $number, '1'));
        $this->assertStringNotContainsString('user/1001@', $this->ivrLookup($menu, $number, '1'));
        $menu->update([
            'previous_config' => $menu->published_config,
            'published_config' => $menu->draft_config,
            'version' => 2,
        ]);
        $oldCall = $this->withHeader('X-FS-Token', 'test-ivr-token')
            ->post('/internal/freeswitch/xml', [
                'section' => 'dialplan', 'Hunt-Context' => 'blucom_ivr',
                'variable_blucom_ivr_menu_id' => $menu->id,
                'variable_blucom_ivr_number_id' => $number->id,
                'variable_blucom_ivr_version' => 1,
                'variable_blucom_ivr_digit' => '1',
            ])->getContent();
        $this->assertStringContainsString('user/1000@', $oldCall);
        $this->assertStringNotContainsString('user/1001@', $oldCall);
        $this->assertStringNotContainsString('user/1000@', $this->withHeader('X-FS-Token', 'test-ivr-token')
            ->post('/internal/freeswitch/xml', [
                'section' => 'dialplan', 'Hunt-Context' => 'blucom_ivr',
                'variable_blucom_ivr_menu_id' => $menu->id,
                'variable_blucom_ivr_number_id' => $number->id + 1,
                'variable_blucom_ivr_version' => $menu->version,
                'variable_blucom_ivr_digit' => '1',
            ])->getContent());
        $this->assertStringContainsString('status="not found"', $this->withHeader('X-FS-Token', 'test-ivr-token')
            ->post('/internal/freeswitch/xml', ['section' => 'dialplan', 'Hunt-Context' => 'other'])->getContent());
    }

    public function test_operator_needs_phone_permission_to_edit_menu(): void
    {
        $tenant = Tenant::factory()->create();
        $operator = User::factory()->create(['user_type' => UserType::Operator, 'tenant_id' => $tenant->id]);
        $this->actingAs($operator)->get('/menus')->assertForbidden();
        $operator->permissions()->create(['permission' => Permissions::PHONES_MANAGE]);
        $this->actingAs($operator)->get('/menus')->assertOk();
        $this->actingAs($operator)->post('/menus', ['name' => 'Own'])->assertRedirect();
        $this->assertDatabaseHas('ivr_menus', ['tenant_id' => $tenant->id, 'name' => 'Own']);
    }

    public function test_number_setup_accepts_only_a_published_menu_owned_by_the_operator(): void
    {
        $tenant = Tenant::factory()->create();
        $other = Tenant::factory()->create();
        $operator = User::factory()->create(['user_type' => UserType::Operator, 'tenant_id' => $tenant->id]);
        $operator->permissions()->create(['permission' => Permissions::PHONES_MANAGE]);
        $gateway = SipGateway::factory()->for($tenant)->create();
        $number = SipNumber::factory()->for($tenant)->create(['provider_gateway_id' => $gateway->id]);
        $own = IvrMenu::query()->create(['tenant_id' => $tenant->id, 'name' => 'Own', 'published_config' => ['greeting' => 'ready.wav']]);
        $foreign = IvrMenu::query()->create(['tenant_id' => $other->id, 'name' => 'Foreign', 'published_config' => ['greeting' => 'ready.wav']]);
        $draft = IvrMenu::query()->create(['tenant_id' => $tenant->id, 'name' => 'Draft']);

        $this->actingAs($operator)->post('/setup/answer/'.$number->id, [
            'answerer' => 'menu', 'menu_id' => $foreign->id,
        ])->assertSessionHasErrors('menu_id');
        $this->actingAs($operator)->post('/setup/answer/'.$number->id, [
            'answerer' => 'menu', 'menu_id' => $draft->id,
        ])->assertSessionHasErrors('menu_id');
        $this->actingAs($operator)->post('/setup/answer/'.$number->id, [
            'answerer' => 'menu', 'menu_id' => $own->id,
        ])->assertRedirect('/setup/lines');
        $this->assertDatabaseHas('inbound_routes', [
            'sip_number_id' => $number->id, 'destination_type' => 'ivr', 'destination_id' => $own->id,
        ]);
    }

    public function test_direct_dial_collects_full_extensions_and_enforces_explicit_tenant_allowlist(): void
    {
        [$menu, $number, $target, $fallback] = $this->directDialFixture();
        $foreign = SipExtension::factory()->for(Tenant::factory()->create())->create(['extension' => '2002']);
        $unlisted = SipExtension::factory()->for($menu->tenant)->create(['extension' => '2003']);
        $xml = app(FreeSwitchDialplanService::class)->build('public');
        $this->assertStringContainsString('data="1 4 2 8000 # ', $xml);
        $this->assertStringContainsString('^(?:[2]|2001)$ 3000', $xml);
        $this->assertStringContainsString('user/'.$target->sipIdentity().'@', $this->ivrLookup($menu, $number, '2001'));
        $this->assertStringContainsString('user/'.$fallback->sipIdentity().'@', $this->ivrLookup($menu, $number, '2'));
        foreach (['', '200', '2002', '2003', '2001#', '2001/sofia/gateway/test', '+2001'] as $input) {
            $result = $this->ivrLookup($menu, $number, $input);
            $this->assertStringContainsString('user/'.$fallback->sipIdentity().'@', $result);
            $this->assertStringNotContainsString('user/'.$target->sipIdentity().'@', $result);
            $this->assertStringNotContainsString('sofia/gateway/', $result);
        }

        // Even a malformed stored allowlist cannot authorize another tenant.
        $config = $menu->published_config;
        $config['direct_dial_extension_ids'][] = $foreign->id;
        $menu->update(['published_config' => $config]);
        $this->assertStringNotContainsString('user/'.$foreign->sipIdentity().'@', $this->ivrLookup($menu, $number, '2002'));
        $this->assertStringNotContainsString('user/'.$unlisted->sipIdentity().'@', $this->ivrLookup($menu, $number, '2003'));
        $target->update(['enabled' => false]);
        $this->assertStringContainsString('user/'.$fallback->sipIdentity().'@', $this->ivrLookup($menu, $number, '2001'));
        $number->update(['inbound_enabled' => false]);
        $this->assertStringNotContainsString('application="bridge"', $this->ivrLookup($menu, $number, '2001'));
    }

    public function test_direct_dial_obeys_published_version_and_uses_scoped_sip_identity(): void
    {
        [$menu, $number, $target, $fallback] = $this->directDialFixture();
        $target->update(['sip_username' => 'opaque-owned-identity']);
        $this->assertStringContainsString('user/opaque-owned-identity@', $this->ivrLookup($menu, $number, '2001'));
        $enabled = $menu->published_config;
        $disabled = $enabled;
        $disabled['direct_dial_enabled'] = false;
        $menu->update(['draft_config' => $disabled]);
        $this->assertStringContainsString('user/opaque-owned-identity@', $this->ivrLookup($menu, $number, '2001'));
        app(IvrMenuService::class)->publish($menu);
        $this->assertStringContainsString('user/'.$fallback->sipIdentity().'@', $this->ivrLookup($menu, $number, '2001'));
        $this->assertStringContainsString('data="1 1 2 8000 # ', app(FreeSwitchDialplanService::class)->build('public'));
        $old = $this->withHeader('X-FS-Token', 'test-ivr-token')->post('/internal/freeswitch/xml', [
            'section' => 'dialplan', 'Hunt-Context' => 'blucom_ivr',
            'variable_blucom_ivr_menu_id' => $menu->id, 'variable_blucom_ivr_number_id' => $number->id,
            'variable_blucom_ivr_version' => 1, 'variable_blucom_ivr_digit' => '2001',
        ])->getContent();
        $this->assertStringContainsString('user/opaque-owned-identity@', $old);
        app(IvrMenuService::class)->restore($menu);
        $this->assertStringContainsString('user/opaque-owned-identity@', $this->ivrLookup($menu, $number, '2001'));
    }

    public function test_managers_can_save_and_publish_direct_dial_without_exposing_other_tenants(): void
    {
        [$menu, $number, $target, $fallback] = $this->directDialFixture();
        $admin = User::factory()->create(['user_type' => UserType::Admin]);
        $foreign = SipExtension::factory()->for(Tenant::factory()->create())->create(['extension' => '9999']);
        $payload = [
            'name' => $menu->name, 'choices' => $menu->published_config['choices'],
            'fallback' => 'extension:'.$fallback->id, 'direct_dial_enabled' => '1',
            'direct_dial_extension_ids' => [$target->id], 'intent' => 'publish',
        ];
        $this->actingAs($admin)->get('/menus/'.$menu->id.'/edit')->assertOk()
            ->assertSee('اجازه شماره‌گیری مستقیم داخلی‌ها')->assertSee('ذخیره و انتشار تغییرات')
            ->assertDontSee('9999');
        foreach ([[], [$foreign->id], [$target->id, $foreign->id]] as $ids) {
            $this->actingAs($admin)->put('/menus/'.$menu->id, array_replace($payload, ['direct_dial_extension_ids' => $ids]))
                ->assertSessionHasErrors('direct_dial_extension_ids');
            $this->assertSame(1, $menu->fresh()->version);
        }
        $this->actingAs($admin)->put('/menus/'.$menu->id, $payload)->assertSessionHasNoErrors()->assertRedirect();
        $menu->refresh();
        $this->assertSame(2, $menu->version);
        $this->assertTrue($menu->published_config['direct_dial_enabled']);
        $this->assertSame([$target->id], $menu->published_config['direct_dial_extension_ids']);
        $this->actingAs($admin)->get('/menus')->assertOk()->assertSee('شماره‌گیری مستقیم داخلی فعال');
        $target->update(['enabled' => false]);
        $this->actingAs($admin)->post('/menus/'.$menu->id.'/publish')->assertSessionHasErrors('direct_dial_extension_ids');
        $target->update(['enabled' => true]);
        $payload['intent'] = 'draft';
        $payload['direct_dial_enabled'] = '0';
        $this->actingAs($admin)->put('/menus/'.$menu->id, $payload)->assertSessionHasNoErrors();
        $this->assertTrue($menu->fresh()->published_config['direct_dial_enabled']);
        $this->assertFalse($menu->fresh()->draft_config['direct_dial_enabled']);
    }

    public function test_customer_phone_manager_can_publish_only_their_own_direct_dial_settings(): void
    {
        [$menu, $number, $target, $fallback] = $this->directDialFixture();
        $operator = User::factory()->create(['user_type' => UserType::Operator, 'tenant_id' => $menu->tenant_id]);
        $payload = [
            'name' => $menu->name, 'choices' => $menu->published_config['choices'],
            'fallback' => 'extension:'.$fallback->id, 'direct_dial_enabled' => '1',
            'direct_dial_extension_ids' => [$target->id], 'intent' => 'publish',
        ];
        $this->actingAs($operator)->put('/menus/'.$menu->id, $payload)->assertForbidden();
        $operator->permissions()->create(['permission' => Permissions::PHONES_MANAGE]);
        $this->actingAs($operator)->get('/menus/'.$menu->id.'/edit')->assertOk()->assertSee('شماره‌گیری مستقیم داخلی');
        $this->actingAs($operator)->put('/menus/'.$menu->id, $payload)->assertSessionHasNoErrors();
        $this->assertTrue($menu->fresh()->published_config['direct_dial_enabled']);
        $foreign = IvrMenu::query()->create(['tenant_id' => Tenant::factory()->create()->id, 'name' => 'Private menu']);
        $this->actingAs($operator)->get('/menus/'.$foreign->id.'/edit')->assertNotFound();
        $this->actingAs($operator)->put('/menus/'.$foreign->id, $payload)->assertNotFound();
        $this->actingAs($operator)->post('/menus/'.$foreign->id.'/publish')->assertNotFound();

        // A failed save-and-publish must also roll back the draft edits.
        $before = $menu->fresh()->draft_config;
        $payload['choices'][2]['label'] = '';
        $this->actingAs($operator)->put('/menus/'.$menu->id, $payload)->assertSessionHasErrors('choices');
        $this->assertSame($before, $menu->fresh()->draft_config);
    }

    private function directDialFixture(): array
    {
        $tenant = app(BlucomOwner::class)->get();
        $number = SipNumber::factory()->for($tenant)->create();
        $target = SipExtension::factory()->for($tenant)->create(['extension' => '2001']);
        $fallback = SipExtension::factory()->for($tenant)->create(['extension' => '2000']);
        $menu = IvrMenu::query()->create(['tenant_id' => $tenant->id, 'name' => 'Direct dialing', 'enabled' => true, 'version' => 1]);
        $greeting = $tenant->id.'/'.$menu->id.'/01KTEST.wav';
        Storage::disk('ivr')->put($greeting, 'RIFF-test-wave');
        $config = [
            'greeting' => $greeting,
            'choices' => ['2' => ['label' => 'Support', 'destination' => 'extension:'.$fallback->id]],
            'fallback' => 'extension:'.$fallback->id,
            'direct_dial_enabled' => true, 'direct_dial_extension_ids' => [$target->id],
        ];
        $menu->update(['published_config' => $config, 'draft_config' => $config]);
        InboundRoute::query()->create([
            'tenant_id' => $tenant->id, 'sip_number_id' => $number->id,
            'destination_type' => 'ivr', 'destination_id' => $menu->id, 'enabled' => true,
        ]);

        return [$menu, $number, $target, $fallback];
    }

    private function ivrLookup(IvrMenu $menu, SipNumber $number, string $digit): string
    {
        return $this->withHeader('X-FS-Token', 'test-ivr-token')
            ->post('/internal/freeswitch/xml', [
                'section' => 'dialplan', 'Hunt-Context' => 'blucom_ivr',
                'variable_blucom_ivr_menu_id' => $menu->id,
                'variable_blucom_ivr_number_id' => $number->id,
                'variable_blucom_ivr_version' => $menu->version,
                'variable_blucom_ivr_digit' => $digit,
            ])->getContent();
    }
}
