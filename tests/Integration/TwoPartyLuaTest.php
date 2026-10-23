<?php

namespace Tests\Integration;

use Symfony\Component\Process\Process;
use Tests\TestCase;

class TwoPartyLuaTest extends TestCase
{
    public function test_native_lua_workflow_never_calls_before_agent_readiness(): void
    {
        $process = new Process(['python3', 'tests/Telephony/run_lua.py'], base_path());
        $process->setTimeout(15);
        $process->run();
        if ($process->getExitCode() === 77) {
            $this->markTestSkipped('System liblua required for isolated Lua fixtures.');
        }
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $this->assertStringContainsString('Lua workflow scenarios passed: 17', $process->getOutput());
    }
}
