<?php

namespace App\Services\Monitoring;

use App\Models\CallMonitorSession;
use App\Models\Customer;
use App\Models\SipExtension;
use App\Models\Tenant;
use App\Models\User;
use App\Services\LiveOverviewService;
use App\Support\Permissions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CallMonitoringService
{
    public function __construct(private MonitorSwitch $switch, private LiveOverviewService $overview) {}

    public function prepare(User|Customer $actor, array $data): array
    {
        $this->authorize($actor);
        $tenant = $this->overview->tenant($actor, $data['organization'] ?? null);
        $target = SipExtension::query()->where('tenant_id', $tenant->id)->where('enabled', true)->findOrFail($data['extension_id']);
        $supervisor = $data['mode'] === 'extension'
            ? SipExtension::query()->where('tenant_id', $tenant->id)->where('enabled', true)->findOrFail($data['supervisor_extension_id'] ?? null) : null;
        abort_if($supervisor?->id === $target->id, 422, 'برای شنود، داخلی دیگری انتخاب کنید.');
        $this->switch->using(fn ($switch) => $switch->assertTarget($target, $data['call_id']));

        return Cache::store(config('voip.live.cache_store'))->lock('monitor-actor:'.$this->actorType($actor).':'.$actor->id, 15)->block(5, function () use ($actor, $tenant, $target, $supervisor, $data) {
            abort_if(CallMonitorSession::query()->where('actor_type', $this->actorType($actor))->where('actor_id', $actor->id)
                ->whereNull('ended_at')->where('expires_at', '>', now())->exists(), 409, 'ابتدا شنود قبلی را پایان دهید.');
            $id = (string) Str::uuid();
            $password = $data['mode'] === 'web' ? bin2hex(random_bytes(24)) : null;
            $session = CallMonitorSession::create([
                'id' => $id, 'tenant_id' => $tenant->id, 'actor_type' => $this->actorType($actor), 'actor_id' => $actor->id,
                'target_extension_id' => $target->id, 'supervisor_extension_id' => $supervisor?->id,
                'target_uuid' => $data['call_id'], 'listener_uuid' => (string) Str::uuid(), 'mode' => $data['mode'],
                'sip_identity' => $password ? 'mon_'.str_replace('-', '', $id) : null,
                'password_encrypted' => $password, 'expires_at' => now()->addSeconds(90),
            ]);
            Log::info('Call monitoring prepared', ['session_id' => $id, 'tenant_id' => $tenant->id, 'actor_type' => $session->actor_type, 'actor_id' => $actor->id, 'mode' => $session->mode]);
            $response = $this->resource($session);
            if ($password) {
                $response['connection'] = $this->connection($session, $password);
            }

            return $response;
        });
    }

    public function connect(User|Customer $actor, CallMonitorSession $session): array
    {
        $this->owned($actor, $session);
        $this->authorize($actor);
        Tenant::findOrFail($session->tenant_id)->assertActive();

        return Cache::store(config('voip.live.cache_store'))->lock('monitor-session:'.$session->id, 15)->block(5, function () use ($session) {
            $session->refresh();
            abort_unless($session->status === 'prepared' && ! $session->ended_at && $session->expires_at->isFuture(), 409);
            $target = SipExtension::query()->where('tenant_id', $session->tenant_id)->where('enabled', true)->findOrFail($session->target_extension_id);
            $supervisor = $session->mode === 'extension' ? SipExtension::query()->where('tenant_id', $session->tenant_id)->where('enabled', true)->findOrFail($session->supervisor_extension_id) : null;
            $session->update(['status' => 'ringing', 'dispatched_at' => now(), 'last_seen_at' => now(), 'expires_at' => now()->addSeconds((int) config('voip.monitoring.max_seconds', 3600))]);
            try {
                $this->switch->using(function ($switch) use ($session, $target, $supervisor) {
                    $switch->assertTarget($target, $session->target_uuid);
                    $switch->heartbeat($session);
                    $switch->originate($session, $target, $supervisor);
                });
            } catch (\Throwable $exception) {
                $this->finish($session, 'failed');
                throw $exception;
            }

            return $this->resource($session);
        });
    }

    public function heartbeat(User|Customer $actor, CallMonitorSession $session): array
    {
        return Cache::store(config('voip.live.cache_store'))->lock('monitor-session:'.$session->id, 15)->block(5, function () use ($actor, $session) {
            return $this->heartbeatLocked($actor, $session->refresh());
        });
    }

    private function heartbeatLocked(User|Customer $actor, CallMonitorSession $session): array
    {
        $this->owned($actor, $session);
        if ($session->ended_at) {
            return $this->resource($session);
        }
        $target = SipExtension::query()->where('tenant_id', $session->tenant_id)->where('enabled', true)->find($session->target_extension_id);
        $supervisorValid = $session->mode === 'web' || SipExtension::query()->where('tenant_id', $session->tenant_id)->where('enabled', true)->whereKey($session->supervisor_extension_id)->exists();
        if (! Tenant::find($session->tenant_id)?->isActive() || ! config('voip.monitoring.enabled') || ! $actor->hasPermission(Permissions::CALLS_MONITOR)
            || ! $actor->hasPermission(Permissions::LIVE_VIEW) || ! $target || ! $supervisorValid || $session->expires_at->isPast()) {
            return $this->stopLocked($actor, $session);
        }
        if ($session->status === 'prepared') {
            return $this->resource($session);
        }
        $this->switch->using(function ($switch) use ($session, $target) {
            $status = $switch->status($session);
            if ($status === 'absent') {
                if ($session->status === 'listening' || $session->dispatched_at?->lt(now()->subSeconds(30))) {
                    $this->finish($session);
                }

                return;
            }
            try {
                $switch->assertTarget($target, $session->target_uuid);
            } catch (HttpException $exception) {
                $switch->stop($session);
                $this->finish($session);

                return;
            }
            $switch->heartbeat($session);
            $session->update(['last_seen_at' => now()]);
            if ($status === 'listening' && $session->status !== 'listening') {
                $session->update(['status' => 'listening', 'connected_at' => now()]);
                Log::info('Call monitoring connected', ['session_id' => $session->id, 'tenant_id' => $session->tenant_id]);
            }
        });

        return $this->resource($session);
    }

    public function stop(User|Customer $actor, CallMonitorSession $session): array
    {
        return Cache::store(config('voip.live.cache_store'))->lock('monitor-session:'.$session->id, 15)->block(5, function () use ($actor, $session) {
            return $this->stopLocked($actor, $session->refresh());
        });
    }

    private function stopLocked(User|Customer $actor, CallMonitorSession $session): array
    {
        $this->owned($actor, $session);
        // Stopping stays available after permission revocation or feature disablement.
        if (! $session->ended_at) {
            try {
                $this->switch->using(fn ($switch) => $switch->stop($session));
            } finally {
                $this->finish($session);
            }
        }

        return $this->resource($session);
    }

    public function current(User|Customer $actor): ?array
    {
        $session = CallMonitorSession::query()->where('actor_type', $this->actorType($actor))->where('actor_id', $actor->id)
            ->whereNull('ended_at')->where('expires_at', '>', now())->latest()->first();

        return $session ? $this->resource($session) : null;
    }

    public function directorySession(string $identity): ?CallMonitorSession
    {
        if (! config('voip.monitoring.enabled')) {
            return null;
        }
        $session = CallMonitorSession::query()->where('sip_identity', $identity)->where('mode', 'web')
            ->whereNull('ended_at')->where('expires_at', '>', now())->first();
        $actor = $session ? ($session->actor_type === 'customer' ? Customer::find($session->actor_id) : User::find($session->actor_id)) : null;
        $tenant = $session ? Tenant::find($session->tenant_id) : null;

        return $session && $actor && $tenant?->isActive() && ($actor->isAdmin() || $actor->canAccessTenant($tenant))
            && $actor->hasPermission(Permissions::CALLS_MONITOR) && $actor->hasPermission(Permissions::LIVE_VIEW) ? $session : null;
    }

    private function authorize(User|Customer $actor): void
    {
        abort_unless(config('voip.monitoring.enabled') && config('voip.live.enabled'), 503, 'شنود زنده فعال نیست.');
        abort_unless($actor->hasPermission(Permissions::CALLS_MONITOR) && $actor->hasPermission(Permissions::LIVE_VIEW), 403);
    }

    private function owned(User|Customer $actor, CallMonitorSession $session): void
    {
        abort_unless($session->actor_type === $this->actorType($actor) && $session->actor_id === $actor->id, 404);
        $tenant = Tenant::findOrFail($session->tenant_id);
        abort_unless($actor->isAdmin() || $actor->canAccessTenant($tenant), 403);
    }

    private function actorType(User|Customer $actor): string
    {
        return $actor instanceof Customer ? 'customer' : 'user';
    }

    private function finish(CallMonitorSession $session, string $status = 'ended'): void
    {
        $session->update(['status' => $status, 'ended_at' => now(), 'password_encrypted' => null]);
        Log::info('Call monitoring ended', ['session_id' => $session->id, 'tenant_id' => $session->tenant_id, 'status' => $status]);
    }

    private function resource(CallMonitorSession $session): array
    {
        return ['id' => $session->id, 'mode' => $session->mode, 'status' => $session->status, 'expires_at' => $session->expires_at->toIso8601String(),
            'connected_at' => $session->connected_at?->toIso8601String(), 'ended_at' => $session->ended_at?->toIso8601String()];
    }

    private function connection(CallMonitorSession $session, string $password): array
    {
        $ice = [];
        $secret = (string) config('voip.web_rtc.turn_secret');
        $url = (string) config('voip.web_rtc.turn_url');
        if ($secret !== '' && $url !== '') {
            $username = now()->addMinutes(10)->timestamp.':'.$session->sip_identity;
            $ice[] = ['urls' => [$url.'?transport=udp', $url.'?transport=tcp'], 'username' => $username,
                'credential' => base64_encode(hash_hmac('sha1', $username, $secret, true))];
        }

        return ['uri' => 'sip:'.$session->sip_identity.'@'.config('voip.directory_domain'), 'username' => $session->sip_identity,
            'password' => $password, 'websocket' => config('voip.web_rtc.websocket_url'), 'iceServers' => $ice];
    }
}
