<?php

namespace Tests\Feature;

use App\Enums\UserType;
use App\Models\CallQueue;
use App\Models\SipExtension;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BlucomOwner;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class LiveOverviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['voip.live.enabled' => true, 'voip.live.cache_store' => 'array']);
        config(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb.key' => 'test-key', 'broadcasting.connections.reverb.secret' => 'test-secret', 'broadcasting.connections.reverb.app_id' => 'test-app']);
        require base_path('routes/channels.php');
    }

    private function operator(Tenant $tenant, bool $details = false): User
    {
        $user = User::factory()->create(['user_type' => UserType::Operator, 'tenant_id' => $tenant->id]);
        $user->permissions()->create(['permission' => Permissions::LIVE_VIEW]);
        if ($details) {
            $user->permissions()->create(['permission' => Permissions::CALLS_VIEW]);
        }

        return $user;
    }

    private function publish(SipExtension $extension, array $overrides = []): void
    {
        Cache::put('voip:live:'.$extension->tenant_id, array_replace([
            'connected' => true, 'updated_at' => now()->timestamp,
            'extensions' => [$extension->id => ['registered' => true, 'devices' => 2, 'calls' => [[
                'id' => 'secret-uuid', 'conversation_id' => 'peer-uuid',
                'state' => 'talking', 'direction' => 'inbound', 'number' => '+989121234567', 'started_at' => now()->timestamp - 50,
            ]]]],
        ], $overrides));
    }

    public function test_live_page_and_state_require_explicit_permission(): void
    {
        $this->get('/live')->assertRedirect('/login');
        $user = User::factory()->create(['user_type' => UserType::Operator]);
        $this->actingAs($user)->get('/live')->assertForbidden();
        $this->actingAs($user)->getJson('/live/state')->assertForbidden();
        $user->permissions()->create(['permission' => Permissions::LIVE_VIEW]);
        $user->update(['disabled_at' => now()]);
        $this->actingAs($user)->getJson('/live/state')->assertForbidden();
    }

    public function test_operator_cannot_select_another_organization_or_see_its_phones_or_teams(): void
    {
        $own = Tenant::factory()->create();
        $other = Tenant::factory()->create();
        $phone = SipExtension::factory()->for($own)->create(['display_name' => 'Own phone']);
        $foreign = SipExtension::factory()->for($other)->create(['display_name' => 'Secret foreign phone']);
        $team = CallQueue::query()->create(['tenant_id' => $other->id, 'name' => 'Foreign team', 'strategy' => 'ring-all', 'max_wait_seconds' => 90, 'enabled' => true]);
        $team->members()->attach($foreign);
        $user = $this->operator($own);
        $this->publish($foreign);
        $this->actingAs($user)->getJson('/live/state?organization='.$other->id)
            ->assertOk()->assertJsonPath('tenant.id', $own->id)
            ->assertJsonCount(1, 'extensions')->assertJsonPath('extensions.0.id', $phone->id)
            ->assertJsonCount(0, 'teams')->assertDontSee('Secret foreign phone');
        $this->actingAs($user)->postJson('/broadcasting/auth', ['socket_id' => '123.456', 'channel_name' => 'private-live-overview.'.$other->id])->assertForbidden();
        $this->actingAs($user)->postJson('/broadcasting/auth', ['socket_id' => '123.456', 'channel_name' => 'private-live-overview.'.$own->id])->assertOk()->assertJsonStructure(['auth']);
    }

    public function test_call_numbers_are_redacted_without_call_permission_and_no_credentials_or_uuids_are_returned(): void
    {
        $tenant = Tenant::factory()->create();
        $phone = SipExtension::factory()->for($tenant)->create();
        $user = $this->operator($tenant);
        $this->publish($phone);
        $this->actingAs($user)->getJson('/live/state')->assertOk()
            ->assertJsonPath('extensions.0.status', 'talking')->assertJsonPath('extensions.0.devices', 2)
            ->assertJsonMissingPath('extensions.0.calls.0.number')->assertDontSee('secret-uuid')->assertDontSee('peer-uuid')
            ->assertDontSee('password')->assertHeader('Cache-Control', 'no-store, private');
        $user->permissions()->create(['permission' => Permissions::CALLS_VIEW]);
        $this->actingAs($user)->getJson('/live/state')->assertJsonPath('extensions.0.calls.0.number', '+989121234567');
        $this->actingAs($user)->get('/live')->assertOk()->assertSee('نمای زنده')->assertSee('data-live-overview', false);
    }

    public function test_stale_missing_and_disconnected_snapshots_are_unknown_and_disabled_phones_remain_disabled(): void
    {
        $tenant = Tenant::factory()->create();
        $phone = SipExtension::factory()->for($tenant)->create();
        SipExtension::factory()->for($tenant)->create(['extension' => '9998', 'enabled' => false]);
        $user = $this->operator($tenant);
        foreach ([[], ['updated_at' => now()->timestamp - 21], ['connected' => false]] as $overrides) {
            if ($overrides !== []) {
                $this->publish($phone, $overrides);
            }
            $response = $this->actingAs($user)->getJson('/live/state')->assertJsonPath('connected', false);
            $phones = collect($response->json('extensions'))->keyBy('id');
            $this->assertSame('unknown', $phones[$phone->id]['status']);
            $this->assertNull($phones[$phone->id]['registered']);
            $this->assertSame([], $phones[$phone->id]['calls']);
            $this->assertTrue($phones->contains(fn ($item) => $item['status'] === 'disabled'));
        }
    }

    public function test_registered_phone_on_break_is_not_ready_but_still_shows_live_calls(): void
    {
        $tenant = Tenant::factory()->create();
        $phone = SipExtension::factory()->for($tenant)->create(['queue_status' => 'On Break']);
        $user = $this->operator($tenant);
        $this->publish($phone, ['extensions' => [$phone->id => ['registered' => true, 'devices' => 1, 'calls' => []]]]);
        $this->actingAs($user)->getJson('/live/state')->assertJsonPath('extensions.0.status', 'break')->assertJsonPath('extensions.0.registered', true);
        $this->publish($phone);
        $this->actingAs($user)->getJson('/live/state')->assertJsonPath('extensions.0.status', 'talking')->assertJsonPath('extensions.0.availability', 'break');
    }

    public function test_admin_can_select_active_organization_and_only_its_live_state(): void
    {
        app(BlucomOwner::class)->get();
        $tenant = Tenant::factory()->create();
        $phone = SipExtension::factory()->for($tenant)->create();
        $admin = User::factory()->create(['user_type' => UserType::Admin]);
        $this->publish($phone);
        $this->actingAs($admin)->getJson('/live/state?organization='.$tenant->id)->assertJsonPath('tenant.id', $tenant->id)->assertJsonCount(1, 'extensions');
        $this->actingAs($admin)->getJson('/live/state?organization=999999')->assertNotFound();
        $tenant->update(['status' => 'suspended']);
        $this->actingAs($admin)->getJson('/live/state?organization='.$tenant->id)->assertForbidden();
    }

    public function test_json_availability_changes_only_the_assigned_owned_phone(): void
    {
        $tenant = Tenant::factory()->create();
        $phone = SipExtension::factory()->for($tenant)->create();
        $other = SipExtension::factory()->for($tenant)->create();
        $user = $this->operator($tenant);
        $user->update(['sip_extension_id' => $phone->id]);
        $user->permissions()->create(['permission' => Permissions::QUEUES_WORK]);
        $this->actingAs($user)->postJson('/availability', ['status' => 'On Break', 'sip_extension_id' => $other->id])->assertOk()->assertJsonPath('status', 'On Break');
        $this->assertSame('On Break', $phone->fresh()->queue_status);
        $this->assertSame('Available', $other->fresh()->queue_status);
    }
}
