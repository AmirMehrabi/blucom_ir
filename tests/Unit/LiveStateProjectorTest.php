<?php

namespace Tests\Unit;

use App\Models\SipExtension;
use App\Services\FreeSwitch\LiveStateProjector;
use Illuminate\Support\Collection;
use Tests\TestCase;

class LiveStateProjectorTest extends TestCase
{
    private function phones(): Collection
    {
        return collect([(new SipExtension)->forceFill(['id' => 11, 'tenant_id' => 1, 'extension' => '8888'])]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['voip.directory_domain' => 'test.local', 'voip.live.profile' => 'internal']);
    }

    public function test_registration_requires_internal_profile_matching_realm_and_unexpired_contact(): void
    {
        $valid = ['reg_user' => '8888', 'realm' => 'test.local', 'url' => 'sofia/internal/sip:8888@phone', 'expires' => 200];
        $rows = [$valid, $valid, array_replace($valid, ['expires' => 99]), array_replace($valid, ['realm' => 'other.local']), array_replace($valid, ['url' => 'sofia/external/sip:8888@phone'])];
        $state = app(LiveStateProjector::class)->project($this->phones(), $rows, [], [], 100);
        $this->assertTrue($state[11]['registered']);
        $this->assertSame(2, $state[11]['devices']);
        $this->assertSame([], $state[11]['calls']);
    }

    public function test_spoofed_provider_caller_id_and_unauthenticated_internal_leg_do_not_mark_extension_busy(): void
    {
        $channels = [
            ['uuid' => 'external', 'name' => 'sofia/external/8888@test.local', 'cid_num' => '8888', 'direction' => 'inbound', 'callstate' => 'ACTIVE'],
            ['uuid' => 'internal', 'name' => 'sofia/internal/8888@test.local', 'cid_num' => '8888', 'direction' => 'inbound', 'callstate' => 'RINGING'],
        ];
        $state = app(LiveStateProjector::class)->project($this->phones(), [], $channels, [], 100);
        $this->assertSame([], $state[11]['calls']);
    }

    public function test_bridged_auth_phone_is_on_call_and_call_legs_have_one_conversation_id(): void
    {
        $channels = [
            ['uuid' => 'a', 'name' => 'sofia/internal/8888@test.local', 'direction' => 'inbound', 'callstate' => 'ACTIVE', 'dest' => '09123456789', 'created_epoch' => 80],
            ['uuid' => 'b', 'name' => 'sofia/external/gateway', 'direction' => 'outbound', 'callstate' => 'ACTIVE'],
        ];
        $dumps = ['a' => ['variable_sip_auth_username' => '8888', 'variable_sip_auth_realm' => 'test.local', 'variable_bridge_uuid' => 'b', 'Caller-Channel-Answered-Time' => 90000000]];
        $state = app(LiveStateProjector::class)->project($this->phones(), [], $channels, $dumps, 100);
        $this->assertCount(1, $state[11]['calls']);
        $this->assertSame('talking', $state[11]['calls'][0]['state']);
        $this->assertSame('outbound', $state[11]['calls'][0]['direction']);
        $this->assertSame(90, $state[11]['calls'][0]['started_at']);
        $this->assertSame('a', $state[11]['calls'][0]['conversation_id']);
        $channels[0]['callstate'] = 'HELD';
        $state = app(LiveStateProjector::class)->project($this->phones(), [], $channels, $dumps, 100);
        $this->assertSame('hold', $state[11]['calls'][0]['state']);
        $channels[0]['callstate'] = 'HANGUP';
        $this->assertSame([], app(LiveStateProjector::class)->project($this->phones(), [], $channels, $dumps, 100)[11]['calls']);
    }

    public function test_called_phone_is_ringing_and_answered_ivr_without_bridge_is_not_talking(): void
    {
        $channel = ['uuid' => 'a', 'name' => 'sofia/internal/8888@phone', 'direction' => 'outbound', 'callstate' => 'RINGING', 'cid_num' => '+989123456789', 'created_epoch' => 80];
        $dump = ['a' => ['variable_dialed_user' => '8888', 'variable_dialed_domain' => 'test.local']];
        $state = app(LiveStateProjector::class)->project($this->phones(), [], [$channel], $dump, 100);
        $this->assertSame('ringing', $state[11]['calls'][0]['state']);
        $this->assertSame('inbound', $state[11]['calls'][0]['direction']);
        $channel['callstate'] = 'ACTIVE';
        $state = app(LiveStateProjector::class)->project($this->phones(), [], [$channel], $dump, 100);
        $this->assertSame('connecting', $state[11]['calls'][0]['state']);
    }

    public function test_ambiguous_shared_domain_extension_is_never_assigned_to_either_tenant(): void
    {
        $phones = $this->phones()->push((new SipExtension)->forceFill(['id' => 12, 'tenant_id' => 2, 'extension' => '8888']));
        $rows = [['reg_user' => '8888', 'realm' => 'test.local', 'url' => 'sofia/internal/sip:8888@phone', 'expires' => 200]];
        $state = app(LiveStateProjector::class)->project($phones, $rows, [], [], 100);
        $this->assertFalse($state[11]['registered']);
        $this->assertFalse($state[12]['registered']);
    }
}
