<?php

namespace App\Console\Commands;

use App\Services\FreeSwitch\EventSocket;
use App\Services\OutboundCalling\OutboundCallWorker;
use App\Services\OutboundCalling\SwitchCallControl;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class RunOutboundCalls extends Command
{
    protected $signature = 'voip:outbound-worker {--once} {--restart : Ask the current worker to reconnect gracefully}';

    protected $description = 'Dispatch and reconcile persistent agent-first outbound sessions';

    public function handle(OutboundCallWorker $worker, SwitchCallControl $control): int
    {
        $store = Cache::store(config('voip.live.cache_store'));
        if ($this->option('restart')) {
            $store->forever('voip:outbound:restart', (string) Str::uuid());
            $this->info('Outbound worker restart requested.');

            return self::SUCCESS;
        }
        $restart = $store->get('voip:outbound:restart');
        if (! config('voip.two_party.enabled')) {
            $this->warn('Two-party calling is disabled.');

            return self::FAILURE;
        }
        $lock = Cache::store(config('voip.live.cache_store'))->lock('voip:outbound-worker', 60);
        if (! $lock->get()) {
            $this->error('An outbound worker is already running.');

            return self::FAILURE;
        }
        $events = new EventSocket;
        $running = true;
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, function () use (&$running) {
                $running = false;
            });
            pcntl_signal(SIGINT, function () use (&$running) {
                $running = false;
            });
        }
        try {
            $control->connect();
            $events->connect();
            $events->subscribeOutbound();
            $next = 0;
            while ($running) {
                if ($store->get('voip:outbound:restart') !== $restart) {
                    break;
                }
                if (microtime(true) >= $next) {
                    $store->put('voip:outbound:healthy_at', now()->timestamp, 20);
                    $worker->tick(function () use ($lock) {
                        $store = Cache::store(config('voip.live.cache_store'));
                        $store->put('voip:outbound:healthy_at', now()->timestamp, 20);
                        // Renew ownership atomically before each session; a slow
                        // switch cannot let another worker acquire an expired lease.
                        if (! method_exists($lock, 'refresh') || ! $lock->refresh(60)) {
                            throw new \RuntimeException('Worker lease lost.');
                        }
                    });
                    $next = microtime(true) + 2;
                    if ($this->option('once')) {
                        break;
                    }
                }
                $frame = $events->read(1);
                if ($frame) {
                    if (($frame['headers']['Content-Type'] ?? '') === 'text/disconnect-notice') {
                        throw new \RuntimeException;
                    }
                    $worker->frame($frame);
                }
            }

            return self::SUCCESS;
        } catch (\Throwable $error) {
            // Supervisor reconnects; durable sending claims are never re-originated.
            report(new \RuntimeException('Outbound worker lost switch control ('.$error::class.').'));
            $this->error('Outbound control unavailable; supervisor will reconnect.');

            return self::FAILURE;
        } finally {
            try {
                $store->forget('voip:outbound:healthy_at');
            } catch (\Throwable) {
            }
            $control->close();
            $events->close();
            $lock->release();
        }
    }
}
