<?php

namespace Tests\Feature;

use App\Console\Commands\SyncCallQueues;
use App\Models\CallQueue;
use App\Models\SipExtension;
use App\Services\BlucomOwner;
use App\Services\CallQueueConfigService;
use App\Services\ExtensionAvailabilityService;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class ExtensionQueueSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_pause_confirmation_requires_readback_and_never_acknowledges_a_newer_change(): void
    {
        config(['voip.queues_enabled' => true]);
        $tenant = app(BlucomOwner::class)->get();
        $phone = SipExtension::factory()->for($tenant)->create(['paused_at' => now(), 'availability_version' => 1]);
        $queue = CallQueue::query()->create(['tenant_id' => $tenant->id, 'name' => 'Sales', 'strategy' => 'ring-all', 'max_wait_seconds' => 30, 'enabled' => true]);
        $queue->members()->attach($phone);
        $runner = new class extends SyncCallQueues
        {
            public string $agent;

            public string $status = 'Available';

            public bool $ack = false;

            public ?int $changeDuringSync = null;

            protected function fs(string $command): string
            {
                if ($command === 'callcenter_config agent list') {
                    return "name|status|contact\n".$this->agent.'|'.$this->status."|existing\n";
                }
                if (str_contains($command, 'agent set status')) {
                    if ($this->ack) {
                        $this->status = 'On Break';
                    }
                    if ($this->changeDuringSync) {
                        SipExtension::query()->whereKey($this->changeDuringSync)->update(['availability_version' => 2]);
                    }
                }

                return '+OK';
            }
        };
        $runner->agent = $phone->freeSwitchAgentName();
        $runner->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput));
        $this->assertSame(0, $runner->handle(app(CallQueueConfigService::class)));
        $this->assertSame(0, $phone->fresh()->queue_sync_version);
        $runner->ack = true;
        $runner->changeDuringSync = $phone->id;
        $this->assertSame(0, $runner->handle(app(CallQueueConfigService::class)));
        $this->assertSame(0, $phone->fresh()->queue_sync_version);
        $runner->changeDuringSync = null;
        $this->assertSame(0, $runner->handle(app(CallQueueConfigService::class)));
        $this->assertSame(2, $phone->fresh()->queue_sync_version);
        $this->assertNotNull($phone->fresh()->queue_synced_at);
        $this->assertFalse(app(ExtensionAvailabilityService::class)->queuePending($phone->fresh()));
        $this->travel(3)->minutes();
        $this->assertTrue(app(ExtensionAvailabilityService::class)->queuePending($phone->fresh()));
        $queue->update(['enabled' => false]);
        $this->assertFalse(app(ExtensionAvailabilityService::class)->queuePending($phone->fresh()));
    }
}
