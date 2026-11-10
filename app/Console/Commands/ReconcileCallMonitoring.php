<?php

namespace App\Console\Commands;

use App\Models\CallMonitorSession;
use App\Services\Monitoring\MonitorSwitch;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ReconcileCallMonitoring extends Command
{
    protected $signature = 'voip:reconcile-monitoring';

    protected $description = 'Close abandoned monitoring sessions without extending their media lease';

    public function handle(MonitorSwitch $switch): int
    {
        CallMonitorSession::query()->whereNull('ended_at')->where(function ($query) {
            $query->where('expires_at', '<=', now())->orWhere('last_seen_at', '<', now()->subSeconds(60));
        })->each(function ($session) use ($switch) {
            Cache::store(config('voip.live.cache_store'))->lock('monitor-session:'.$session->id, 15)->get(function () use ($session, $switch) {
                $session->refresh();
                if ($session->ended_at || ($session->expires_at->isFuture() && $session->last_seen_at?->gte(now()->subSeconds(60)))) {
                    return;
                }
                try {
                    $switch->using(fn ($control) => $control->stop($session));
                    $session->update(['status' => 'ended', 'ended_at' => now(), 'password_encrypted' => null]);
                    Log::info('Abandoned call monitoring ended', ['session_id' => $session->id, 'tenant_id' => $session->tenant_id]);
                } catch (\Throwable) {
                    Log::warning('Call monitoring cleanup waiting for switch', ['session_id' => $session->id]);
                }
            });
        });

        return self::SUCCESS;
    }
}
