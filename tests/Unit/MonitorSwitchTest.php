<?php

namespace Tests\Unit;

use App\Models\SipExtension;
use App\Services\FreeSwitch\EventSocket;
use App\Services\Monitoring\MonitorSwitch;
use Illuminate\Support\Str;
use Mockery;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class MonitorSwitchTest extends TestCase
{
    public function test_authenticated_internal_answered_leg_is_required(): void
    {
        config(['voip.directory_domain' => 'test.local', 'voip.live.profile' => 'internal']);
        $uuid = (string) Str::uuid();
        $extension = (new SipExtension)->forceFill(['sip_username' => 'tenant1_2000']);
        $valid = ['Channel-Name' => 'sofia/internal/tenant1_2000@test.local', 'Caller-Direction' => 'inbound',
            'variable_sip_auth_username' => 'tenant1_2000', 'variable_sip_auth_realm' => 'test.local',
            'Caller-Channel-Answered-Time' => '1000000', 'variable_bridge_uuid' => (string) Str::uuid()];
        $socket = Mockery::mock(EventSocket::class);
        $control = new MonitorSwitch($socket);
        $socket->shouldReceive('api')->with('uuid_dump '.$uuid)->once()->andReturn(collect($valid)->map(fn ($value, $key) => $key.': '.$value)->implode("\n"));
        $control->assertTarget($extension, $uuid);
        foreach ([['variable_sip_auth_username' => 'tenant2_2000'], ['Channel-Name' => 'sofia/external/provider'], ['Caller-Channel-Answered-Time' => '0'], ['variable_bridge_uuid' => ''], ['variable_blucom_monitor_id' => 'another-monitor']] as $override) {
            $socket->shouldReceive('api')->with('uuid_dump '.$uuid)->once()->andReturn(collect(array_replace($valid, $override))->map(fn ($value, $key) => $key.': '.$value)->implode("\n"));
            try {
                $control->assertTarget($extension, $uuid);
                $this->fail('Unsafe call was accepted');
            } catch (HttpException $exception) {
                $this->assertSame(409, $exception->getStatusCode());
            }
        }
    }
}
