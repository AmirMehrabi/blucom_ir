<?php

namespace Tests\Feature;

use App\Enums\UserType;
use App\Models\CallMonitorSession;
use App\Models\Customer;
use App\Models\SipExtension;
use App\Models\Tenant;
use App\Models\User;
use App\Services\FreeSwitchDirectoryService;
use App\Services\Monitoring\MonitorSwitch;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use Mockery\MockInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class CallMonitoringTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['voip.monitoring.enabled' => true, 'voip.live.enabled' => true, 'voip.live.cache_store' => 'array']);
    }

    private function operator(Tenant $tenant): User
    {
        $user = User::factory()->create(['user_type' => UserType::Operator, 'tenant_id' => $tenant->id]);
        foreach ([Permissions::LIVE_VIEW, Permissions::CALLS_MONITOR] as $permission) {
            $user->permissions()->create(['permission' => $permission]);
        }

        return $user;
    }

    private function switch(): MockInterface
    {
        $mock = Mockery::mock(MonitorSwitch::class);
        $mock->shouldReceive('using')->andReturnUsing(fn ($callback) => $callback($mock));
        $this->app->instance(MonitorSwitch::class, $mock);

        return $mock;
    }

    private function data(SipExtension $extension, string $mode = 'web'): array
    {
        return ['extension_id' => $extension->id, 'call_id' => (string) Str::uuid(), 'mode' => $mode];
    }

    public function test_permission_is_required_and_disabled_feature_fails_closed(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->operator($tenant);
        $extension = SipExtension::factory()->create(['tenant_id' => $tenant->id]);
        $user->permissions()->where('permission', Permissions::CALLS_MONITOR)->delete();
        $this->actingAs($user)->postJson('/live/monitor', $this->data($extension))->assertForbidden();
        config(['voip.monitoring.enabled' => false]);
        $this->postJson('/live/monitor', $this->data($extension))->assertStatus(503);
    }

    public function test_cross_tenant_target_and_supervisor_are_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        $other = SipExtension::factory()->create();
        $target = SipExtension::factory()->create(['tenant_id' => $tenant->id]);
        $user = $this->operator($tenant);
        $this->actingAs($user)->postJson('/live/monitor', $this->data($other) + ['organization' => $other->tenant_id])->assertNotFound();
        $this->postJson('/live/monitor', $this->data($target, 'extension') + ['supervisor_extension_id' => $other->id])->assertNotFound();
    }

    public function test_web_identity_is_temporary_encrypted_receive_only_and_can_be_stopped(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->operator($tenant);
        $target = SipExtension::factory()->create(['tenant_id' => $tenant->id]);
        $mock = $this->switch();
        $mock->shouldReceive('assertTarget')->twice();
        $mock->shouldReceive('heartbeat')->once();
        $mock->shouldReceive('originate')->once();
        $mock->shouldReceive('stop')->once();
        $response = $this->actingAs($user)->postJson('/live/monitor', $this->data($target))->assertOk();
        $session = CallMonitorSession::findOrFail($response->json('id'));
        $password = $response->json('connection.password');
        $this->assertNotSame($password, $session->getRawOriginal('password_encrypted'));
        $this->assertStringNotContainsString($password, $session->toJson());
        $xml = app(FreeSwitchDirectoryService::class)->buildAll($session->sip_identity);
        $this->assertStringContainsString('value="blucom_monitor"', $xml);
        $this->assertStringContainsString($password, $xml);
        $this->getJson('/live/monitor/current')->assertJsonMissing(['password' => $password]);
        $this->postJson('/live/monitor/'.$session->id.'/connect')->assertOk()->assertJsonPath('status', 'ringing');
        $this->postJson('/live/monitor/'.$session->id.'/connect')->assertStatus(409);
        $user->permissions()->where('permission', Permissions::CALLS_MONITOR)->delete();
        $this->postJson('/live/monitor/'.$session->id.'/stop')->assertOk()->assertJsonPath('status', 'ended');
        $this->assertStringNotContainsString($password, app(FreeSwitchDirectoryService::class)->buildAll($session->sip_identity));
    }

    public function test_extension_mode_does_not_return_sip_credentials(): void
    {
        $tenant = Tenant::factory()->create();
        $target = SipExtension::factory()->create(['tenant_id' => $tenant->id]);
        $supervisor = SipExtension::factory()->create(['tenant_id' => $tenant->id]);
        $mock = $this->switch();
        $mock->shouldReceive('assertTarget')->once();
        $this->actingAs($this->operator($tenant))->postJson('/live/monitor', $this->data($target, 'extension') + ['supervisor_extension_id' => $supervisor->id])
            ->assertOk()->assertJsonMissingPath('connection');
    }

    public function test_other_actor_cannot_access_or_stop_a_session(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->operator($tenant);
        $target = SipExtension::factory()->create(['tenant_id' => $tenant->id]);
        $this->switch()->shouldReceive('assertTarget')->once();
        $id = $this->actingAs($owner)->postJson('/live/monitor', $this->data($target))->json('id');
        $this->actingAs($this->operator($tenant))->postJson('/live/monitor/'.$id.'/stop')->assertNotFound();
        $this->postJson('/live/monitor/'.$id.'/heartbeat')->assertNotFound();
    }

    public function test_call_ending_before_dispatch_fails_without_originate(): void
    {
        $tenant = Tenant::factory()->create();
        $target = SipExtension::factory()->create(['tenant_id' => $tenant->id]);
        $mock = $this->switch();
        $mock->shouldReceive('assertTarget')->once();
        $id = $this->actingAs($this->operator($tenant))->postJson('/live/monitor', $this->data($target))->json('id');
        $mock->shouldReceive('assertTarget')->once()->andThrow(new HttpException(409));
        $this->postJson('/live/monitor/'.$id.'/connect')->assertStatus(409);
        $this->assertDatabaseHas('call_monitor_sessions', ['id' => $id, 'status' => 'failed', 'password_encrypted' => null]);
    }

    public function test_revoked_permission_stops_existing_audio_and_invalidates_directory_credentials(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->operator($tenant);
        $target = SipExtension::factory()->create(['tenant_id' => $tenant->id]);
        $mock = $this->switch();
        $mock->shouldReceive('assertTarget')->once();
        $id = $this->actingAs($user)->postJson('/live/monitor', $this->data($target))->json('id');
        $session = CallMonitorSession::findOrFail($id);
        $user->permissions()->where('permission', Permissions::CALLS_MONITOR)->delete();
        $this->assertStringNotContainsString('value="blucom_monitor"', app(FreeSwitchDirectoryService::class)->buildAll($session->sip_identity));
        $mock->shouldReceive('stop')->once();
        $this->postJson('/live/monitor/'.$id.'/heartbeat')->assertOk()->assertJsonPath('status', 'ended');
    }

    public function test_expired_preparation_cannot_be_dispatched(): void
    {
        $tenant = Tenant::factory()->create();
        $target = SipExtension::factory()->create(['tenant_id' => $tenant->id]);
        $this->switch()->shouldReceive('assertTarget')->once();
        $id = $this->actingAs($this->operator($tenant))->postJson('/live/monitor', $this->data($target))->json('id');
        $this->travel(91)->seconds();
        $this->postJson('/live/monitor/'.$id.'/connect')->assertStatus(409);
    }

    public function test_concurrent_preparations_are_limited_to_one_actor_session(): void
    {
        $tenant = Tenant::factory()->create();
        $target = SipExtension::factory()->create(['tenant_id' => $tenant->id]);
        $this->switch()->shouldReceive('assertTarget')->twice();
        $this->actingAs($this->operator($tenant))->postJson('/live/monitor', $this->data($target))->assertOk();
        $this->postJson('/live/monitor', $this->data($target))->assertStatus(409);
        $this->assertDatabaseCount('call_monitor_sessions', 1);
    }

    public function test_customer_owner_can_monitor_only_their_tenant_on_customer_host(): void
    {
        $tenant = Tenant::factory()->create(['system_key' => null]);
        $owner = Customer::create(['tenant_id' => $tenant->id, 'name' => 'Owner', 'mobile' => '09121112233', 'role' => 'owner']);
        $tenant->update(['owner_customer_id' => $owner->id]);
        foreach ([Permissions::LIVE_VIEW, Permissions::CALLS_MONITOR] as $permission) {
            $owner->permissions()->create(['permission' => $permission]);
        }
        $extension = SipExtension::factory()->create(['tenant_id' => $tenant->id]);
        $this->switch()->shouldReceive('assertTarget')->once();
        $this->actingAs($owner, 'customer')->postJson('https://my.blucom.ir/live/monitor', $this->data($extension))->assertOk();
        $this->postJson('https://my.blucom.ir/live/monitor', $this->data(SipExtension::factory()->create()))->assertNotFound();
    }
}
