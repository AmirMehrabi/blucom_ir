<?php

namespace Tests\Integration;

use Symfony\Component\Process\Process;
use Tests\TestCase;

class CallPickupLuaTest extends TestCase
{
    public function test_pickup_runtime_filters_calls_and_serializes_competing_requests(): void
    {
        $process = new Process(['python3', 'tests/Telephony/run_lua.py', 'tests/Telephony/pickup.lua'], base_path());
        $process->setTimeout(15);
        $process->run();
        if ($process->getExitCode() === 77) {
            $this->markTestSkipped('System liblua required for isolated Lua fixtures.');
        }
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $this->assertStringContainsString('Lua pickup scenarios passed: 11', $process->getOutput());
    }
}
