<?php

namespace App\Console\Commands;

use App\Events\LiveOverviewUpdated;
use App\Models\SipExtension;
use App\Models\Tenant;
use App\Services\FreeSwitch\EventSocket;
use App\Services\FreeSwitch\LiveStateProjector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;

class MonitorFreeSwitch extends Command
{
    protected $signature = 'voip:monitor {--once : Capture one snapshot and exit} {--restart : Ask the running monitor to restart gracefully}';

    protected $description = 'Maintain a live, tenant-scoped projection of FreeSWITCH registrations and calls';

    private bool $running = true;

    public function handle(LiveStateProjector $projector): int
    {
        if (! config('voip.live.enabled')) {
            $this->warn('Live monitoring is disabled.');

            return self::FAILURE;
        }
        $store = Cache::store(config('voip.live.cache_store'));
        if ($this->option('restart')) {
            $store->forever('voip:monitor:restart', (string) Str::uuid());
            $this->info('Monitor restart requested.');

            return self::SUCCESS;
        }
        $restart = $store->get('voip:monitor:restart');
        $lock = $store->lock('voip:monitor:lock', 30);
        if (! $lock->get()) {
            $this->error('A monitor is already running.');

            return self::FAILURE;
        }
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, fn () => $this->running = false);
            pcntl_signal(SIGINT, fn () => $this->running = false);
        }
        $api = new EventSocket;
        $events = new EventSocket;
        $hashes = [];
        $lastLock = microtime(true);
        try {
            $api->connect();
            $events->connect();
            $events->subscribe();
            $next = 0;
            $dirty = true;
            while ($this->running) {
                if ($store->get('voip:monitor:restart') !== $restart) {
                    break;
                }
                if ($dirty || microtime(true) >= $next) {
                    $extensions = SipExtension::query()->whereHas('tenant', fn ($query) => $query->where('status', 'active'))->get(['id', 'tenant_id', 'extension']);
                    $channels = $this->rows($api->api('show channels as json'));
                    $registrations = $this->rows($api->api('show registrations as json'));
                    $dumps = [];
                    foreach ($channels as $channel) {
                        if (str_starts_with($channel['name'] ?? '', 'sofia/'.config('voip.live.profile').'/')
                            && preg_match('/^[0-9a-f-]{36}$/D', $channel['uuid'] ?? '')) {
                            $dumps[$channel['uuid']] = $projector->dump($api->api('uuid_dump '.$channel['uuid']));
                        }
                    }
                    $state = $projector->project($extensions, $registrations, $channels, $dumps, time());
                    foreach (Tenant::query()->where('status', 'active')->pluck('id') as $tenantId) {
                        $owned = $extensions->where('tenant_id', $tenantId)->pluck('id')->all();
                        $tenantState = array_intersect_key($state, array_flip($owned));
                        $store->put('voip:live:'.$tenantId, ['connected' => true, 'updated_at' => time(), 'extensions' => $tenantState], 120);
                        $hash = hash('sha256', json_encode($tenantState, JSON_THROW_ON_ERROR));
                        if (($hashes[$tenantId] ?? '') !== $hash) {
                            $this->notify((int) $tenantId);
                            $hashes[$tenantId] = $hash;
                        }
                    }
                    $dirty = false;
                    $next = microtime(true) + 5;
                    if ($this->option('once')) {
                        $this->info('Live snapshot captured.');

                        return self::SUCCESS;
                    }
                }
                // Lease renewal is independent of traffic, including an idle switch.
                if (microtime(true) - $lastLock > 10) {
                    $lock->release();
                    $lock = $store->lock('voip:monitor:lock', 30);
                    if (! $lock->get()) {
                        throw new RuntimeException('Monitor lease was lost.');
                    }
                    $lastLock = microtime(true);
                }
                $frame = $events->read(1);
                if ($frame !== null) {
                    if (($frame['headers']['Content-Type'] ?? '') === 'text/disconnect-notice') {
                        throw new RuntimeException('Event Socket disconnected.');
                    }
                    $dirty = $dirty || $this->relevant($frame);
                    // Drain bursts before taking the next authoritative snapshot.
                    for ($i = 0; $i < 100 && ($queued = $events->read(0)) !== null; $i++) {
                        $dirty = $dirty || $this->relevant($queued);
                    }
                    usleep(100000);
                }
            }

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            foreach (Tenant::query()->where('status', 'active')->pluck('id') as $id) {
                $cached = $store->get('voip:live:'.$id, []);
                $store->put('voip:live:'.$id, array_replace($cached, ['connected' => false]), 120);
                $this->notify((int) $id);
            }
            // Never print raw SIP events, API bodies, or credentials.
            $this->error('Live monitoring lost its connection. The service will retry.');

            return self::FAILURE;
        } finally {
            $api->close();
            $events->close();
            $lock->release();
        }
    }

    private function rows(string $body): array
    {
        $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($data) || ! isset($data['row_count']) || ! is_array($data['rows'] ?? [])) {
            throw new RuntimeException('Invalid FreeSWITCH snapshot.');
        }

        return $data['rows'] ?? [];
    }

    private function relevant(array $frame): bool
    {
        if (($frame['headers']['Content-Type'] ?? '') !== 'text/event-json') {
            return false;
        }
        $event = json_decode($frame['body'], true);
        $profile = (string) config('voip.live.profile');

        return is_array($event) && (($event['profile-name'] ?? '') === $profile
            || str_starts_with($event['Channel-Name'] ?? '', 'sofia/'.$profile.'/'));
    }

    private function notify(int $tenantId): void
    {
        try {
            event(new LiveOverviewUpdated($tenantId));
        } catch (\Throwable) {
            // Snapshot polling remains available while the WebSocket server restarts.
        }
    }
}
