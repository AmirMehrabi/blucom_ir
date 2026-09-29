<?php

namespace App\Console\Commands;

use App\Models\CallQueue;
use App\Services\CallQueueConfigService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Symfony\Component\Process\Process;

class SyncCallQueues extends Command
{
    protected $signature = 'voip:sync-queues {--config-path=/etc/freeswitch/autoload_configs/callcenter.conf.xml}';

    protected $description = 'Reconcile database-backed call teams with the local FreeSWITCH call center module';

    public function handle(CallQueueConfigService $config): int
    {
        if (! config('voip.queues_enabled')) {
            $this->warn('Queue integration is disabled.');

            return self::SUCCESS;
        }

        $lock = Cache::lock('voip-sync-queues', 60);
        if (! $lock->get()) {
            return self::SUCCESS;
        }

        try {
            $queues = CallQueue::query()->where('enabled', true)
                ->whereHas('tenant', fn ($query) => $query->where('status', 'active'))
                ->whereHas('members', fn ($query) => $query->where('enabled', true))
                ->with(['members' => fn ($query) => $query->where('enabled', true)])
                ->orderBy('id')->get();

            $path = (string) $this->option('config-path');
            $xml = $config->build($queues);
            $changed = ! is_file($path) || file_get_contents($path) !== $xml;
            if ($changed) {
                $temp = tempnam(dirname($path), '.blucom-callcenter-');
                if ($temp === false || file_put_contents($temp, $xml) === false) {
                    throw new RuntimeException('Cannot stage call center configuration.');
                }
                chmod($temp, 0644);
                if (! rename($temp, $path)) {
                    throw new RuntimeException('Cannot install call center configuration.');
                }
                $this->fs('reloadxml');
            }

            $loaded = $this->rows($this->fs('callcenter_config queue list'));
            $desiredQueueNames = $queues->mapWithKeys(fn ($queue) => [$queue->freeSwitchName() => $queue])->all();
            foreach ($loaded as $row) {
                $name = $row['name'] ?? '';
                if (str_starts_with($name, 'blucom_q_') && ! isset($desiredQueueNames[$name])) {
                    $this->fs('callcenter_config queue unload '.$name);
                }
            }
            foreach ($queues as $queue) {
                $name = $queue->freeSwitchName();
                $exists = collect($loaded)->contains(fn ($row) => ($row['name'] ?? '') === $name);
                if ($changed || ! $exists) {
                    $this->fs('callcenter_config queue '.($exists ? 'reload ' : 'load ').$name);
                }
            }

            $agents = $this->rows($this->fs('callcenter_config agent list'));
            $agentRows = collect($agents)->keyBy('name');
            $wantedAgents = [];
            $wantedTiers = [];
            foreach ($queues as $queue) {
                foreach ($queue->members as $member) {
                    if (! preg_match('/^[1-9][0-9]{2,8}$/D', $member->extension)) {
                        continue;
                    }
                    $name = $member->freeSwitchAgentName();
                    $wantedAgents[$name] = $member;
                    $wantedTiers[$queue->freeSwitchName().'|'.$name] = [$queue->freeSwitchName(), $name];
                }
            }
            foreach ($wantedAgents as $name => $member) {
                $existing = $agentRows->get($name);
                if ($existing === null) {
                    $this->fs('callcenter_config agent add '.$name.' callback');
                }
                $contact = '[leg_timeout=15]user/'.$member->extension.'@'.config('voip.directory_domain');
                if ($existing === null || ($existing['contact'] ?? '') !== $contact) {
                    $this->fs('callcenter_config agent set contact '.$name.' '.$contact);
                }
                $status = in_array($member->queue_status, ['Available', 'On Break'], true) ? $member->queue_status : 'On Break';
                if ($existing === null || ($existing['status'] ?? '') !== $status) {
                    $this->fs('callcenter_config agent set status '.$name.' '.($status === 'On Break' ? "'On Break'" : 'Available'));
                }
                if ($existing === null) {
                    $this->fs('callcenter_config agent set no_answer_delay_time '.$name.' 30');
                }
            }

            $tiers = $this->rows($this->fs('callcenter_config tier list'));
            foreach ($tiers as $row) {
                $queueName = $row['queue'] ?? '';
                $agentName = $row['agent'] ?? '';
                if (str_starts_with($queueName, 'blucom_q_') && ! isset($wantedTiers[$queueName.'|'.$agentName])) {
                    $this->fs('callcenter_config tier del '.$queueName.' '.$agentName);
                }
            }
            $existingTiers = collect($tiers)->mapWithKeys(fn ($row) => [($row['queue'] ?? '').'|'.($row['agent'] ?? '') => true]);
            foreach ($wantedTiers as $key => [$queueName, $agentName]) {
                if (! $existingTiers->has($key)) {
                    $this->fs('callcenter_config tier add '.$queueName.' '.$agentName);
                }
            }
            foreach ($agents as $row) {
                $name = $row['name'] ?? '';
                if (str_starts_with($name, 'blucom_a_') && ! isset($wantedAgents[$name])) {
                    $this->fs('callcenter_config agent del '.$name);
                }
            }

            foreach ($queues as $queue) {
                $waiting = (int) trim($this->fs('callcenter_config queue count members '.$queue->freeSwitchName()));
                $available = (int) trim($this->fs('callcenter_config queue count agents '.$queue->freeSwitchName().' Available'));
                if ($queue->waiting_count !== $waiting || $queue->available_count !== $available) {
                    $queue->update(['waiting_count' => $waiting, 'available_count' => $available]);
                }
            }

            $this->info('Call teams synchronized: '.$queues->count());

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } finally {
            $lock->release();
        }
    }

    private function fs(string $command): string
    {
        $process = new Process(['fs_cli', '-x', $command]);
        $process->setTimeout(15);
        $process->run();
        $output = trim($process->getOutput());
        if (! $process->isSuccessful() || str_contains($output, '-ERR') || str_contains($output, 'Invalid Command')) {
            throw new RuntimeException('FreeSWITCH command failed: '.$command.' '.substr($output, 0, 200));
        }

        return $output;
    }

    private function rows(string $output): array
    {
        $lines = array_values(array_filter(explode("\n", trim($output)), fn ($line) => str_contains($line, '|')));
        if ($lines === []) {
            return [];
        }
        $headers = explode('|', array_shift($lines));

        return array_map(fn ($line) => array_combine($headers, explode('|', $line)), $lines);
    }
}
