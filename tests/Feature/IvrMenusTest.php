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
