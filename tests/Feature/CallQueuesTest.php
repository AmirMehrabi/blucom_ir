<?php

namespace Tests\Feature;

use App\Enums\UserType;
use App\Models\CallQueue;
use App\Models\InboundRoute;
use App\Models\SipExtension;
use App\Models\SipNumber;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BlucomOwner;
use App\Services\CallQueueConfigService;
use App\Services\FreeSwitchDialplanService;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CallQueuesTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_build_team_and_route_number_while_rejecting_other_tenant_member(): void
    {
        config(['voip.queues_enabled' => true]);
        $tenant = app(BlucomOwner::class)->get();
        $other = Tenant::factory()->create();
        $member = SipExtension::factory()->for($tenant)->create(['extension' => '8888']);
        $outsider = SipExtension::factory()->for($other)->create(['extension' => '8999']);
        $number = SipNumber::factory()->for($tenant)->create();
        $admin = User::factory()->create(['user_type' => UserType::Admin]);

        $this->actingAs($admin)->post('/teams', [
            'name' => 'Sales', 'strategy' => 'longest-idle-agent',
            'max_wait_seconds' => 90, 'member_ids' => [$outsider->id],
        ])->assertSessionHasErrors('member_ids');

        $this->actingAs($admin)->post('/teams', [
            'name' => 'Sales', 'strategy' => 'longest-idle-agent',
            'max_wait_seconds' => 90, 'member_ids' => [$member->id],
        ])->assertRedirect();
        $queue = CallQueue::query()->firstOrFail();

        $this->actingAs($admin)->post('/inbound-routes', [
            'sip_number_id' => $number->id, 'destination_choice' => 'queue:'.$queue->id,
        ])->assertRedirect();
        $this->assertDatabaseHas('inbound_routes', [
            'sip_number_id' => $number->id, 'destination_type' => 'queue', 'destination_id' => $queue->id,
        ]);
        $this->actingAs($admin)->delete('/teams/'.$queue->id)->assertSessionHasErrors('queue');
    }

    public function test_queue_dialplan_uses_only_enabled_owned_queue_and_bounded_wait(): void
    {
        config(['voip.queues_enabled' => true, 'voip.gateway_xml_enabled' => true]);
        $tenant = app(BlucomOwner::class)->get();
        $member = SipExtension::factory()->for($tenant)->create(['extension' => '8888']);
        $queue = CallQueue::query()->create([
            'tenant_id' => $tenant->id, 'name' => 'Sales', 'strategy' => 'ring-all',
            'max_wait_seconds' => 90, 'enabled' => true,
        ]);
        $queue->members()->attach($member);
        $number = SipNumber::factory()->for($tenant)->create();
        InboundRoute::query()->create([
            'tenant_id' => $tenant->id, 'sip_number_id' => $number->id,
            'destination_type' => 'queue', 'destination_id' => $queue->id, 'enabled' => true,
        ]);

        $xml = app(FreeSwitchDialplanService::class)->build('public');
        $this->assertStringContainsString('application="callcenter" data="'.$queue->freeSwitchName().'"', $xml);
        $this->assertStringContainsString('application="answer"', $xml);
        $this->assertStringContainsString('data="hangup_after_bridge=true"', $xml);
        $this->assertStringNotContainsString('sofia/gateway/', $xml);

        $config = app(CallQueueConfigService::class)->build([$queue]);
        $this->assertStringContainsString('name="max-wait-time" value="90"', $config);
        $this->assertStringContainsString('name="max-wait-time-with-no-agent" value="15"', $config);
        $member->update(['enabled' => false]);
        $this->assertStringNotContainsString($queue->freeSwitchName(), app(FreeSwitchDialplanService::class)->build('public'));
    }

    public function test_operator_can_change_only_assigned_extension_availability(): void
    {
        $tenant = app(BlucomOwner::class)->get();
        $own = SipExtension::factory()->for($tenant)->create();
        $other = SipExtension::factory()->for($tenant)->create();
        $operator = User::factory()->create([
            'user_type' => UserType::Operator, 'tenant_id' => $tenant->id, 'sip_extension_id' => $own->id,
        ]);
        $operator->permissions()->create(['permission' => Permissions::QUEUES_WORK]);

        $this->actingAs($operator)->post('/availability', ['status' => 'On Break', 'sip_extension_id' => $other->id])->assertRedirect();
        $this->assertSame('On Break', $own->fresh()->queue_status);
        $this->assertSame('Available', $other->fresh()->queue_status);
    }

    public function test_admin_can_assign_an_owned_extension_to_one_operator_only(): void
    {
        $tenant = app(BlucomOwner::class)->get();
        $own = SipExtension::factory()->for($tenant)->create();
        $foreign = SipExtension::factory()->for(Tenant::factory()->create())->create();
        $admin = User::factory()->create(['user_type' => UserType::Admin]);

        $this->actingAs($admin)->post('/users', [
            'name' => 'Operator A', 'mobile' => '09123456789', 'role' => 'operator',
            'sip_extension_id' => $foreign->id,
        ])->assertSessionHasErrors('sip_extension_id');
        $this->actingAs($admin)->post('/users', [
            'name' => 'Operator A', 'mobile' => '09123456789', 'role' => 'operator',
            'sip_extension_id' => $own->id,
        ])->assertRedirect();
        $this->assertSame($own->id, User::query()->where('mobile', '+989123456789')->firstOrFail()->sip_extension_id);
        $this->actingAs($admin)->post('/users', [
            'name' => 'Operator B', 'mobile' => '09123456788', 'role' => 'operator',
            'sip_extension_id' => $own->id,
        ])->assertSessionHasErrors('sip_extension_id');
    }
}
