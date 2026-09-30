<?php

namespace App\Services;

use App\Models\CallQueue;
use App\Models\SipExtension;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Support\Facades\Cache;

class LiveOverviewService
{
    public function __construct(private readonly TenantService $tenants) {}

    public function tenant(User $user, mixed $selected = null): Tenant
    {
        if ($user->isAdmin() && $selected !== null && $selected !== '') {
            $tenant = Tenant::query()->findOrFail($selected);
            $tenant->assertActive();

            return $tenant;
        }

        return $this->tenants->forUser($user);
    }

    public function canView(User $user): bool
    {
        return $user->hasPermission(Permissions::LIVE_VIEW);
    }

    public function snapshot(User $user, Tenant $tenant): array
    {
        try {
            $snapshot = Cache::store(config('voip.live.cache_store'))->get('voip:live:'.$tenant->id, []);
        } catch (\Throwable) {
            $snapshot = [];
        }
        $updated = (int) ($snapshot['updated_at'] ?? 0);
        $healthy = config('voip.live.enabled') && ($snapshot['connected'] ?? false)
            && $updated >= now()->timestamp - (int) config('voip.live.stale_after');
        $details = $user->hasPermission(Permissions::CALLS_VIEW);
        $teams = CallQueue::query()->whereBelongsTo($tenant)->with(['members' => fn ($query) => $query->where('sip_extensions.tenant_id', $tenant->id)])
            ->orderBy('name')->get();
        $extensions = SipExtension::query()->whereBelongsTo($tenant)->orderBy('extension')->get()
            ->map(function ($extension) use ($snapshot, $healthy, $teams, $details, $user) {
                $live = $snapshot['extensions'][$extension->id] ?? [];
                $calls = $healthy && $extension->enabled ? ($live['calls'] ?? []) : [];
                $calls = array_map(function ($call) use ($details) {
                    unset($call['id'], $call['conversation_id']);
                    if (! $details) {
                        unset($call['number']);
                    }

                    return $call;
                }, $calls);
                $priority = ['talking' => 0, 'hold' => 1, 'ringing' => 2, 'dialing' => 3, 'connecting' => 4];
                usort($calls, fn ($a, $b) => ($priority[$a['state']] ?? 9) <=> ($priority[$b['state']] ?? 9));
                $registered = $healthy ? (bool) ($live['registered'] ?? false) : null;
                $status = match (true) {
                    ! $extension->enabled => 'disabled',
                    ! $healthy => 'unknown',
                    $calls !== [] => $calls[0]['state'],
                    ! $registered => 'offline',
                    $extension->queue_status !== 'Available' => 'break',
                    default => 'ready',
                };

                return [
                    'id' => $extension->id, 'extension' => $extension->extension,
                    'name' => $extension->display_name ?: 'داخلی '.$extension->extension,
                    'enabled' => $extension->enabled, 'registered' => $registered,
                    'devices' => $healthy ? (int) ($live['devices'] ?? 0) : null,
                    'status' => $status, 'availability' => $extension->queue_status === 'Available' ? 'available' : 'break',
                    'teams' => $teams->filter(fn ($team) => $team->members->contains('id', $extension->id))->map->id->values()->all(),
                    'calls' => $calls, 'own' => $user->sip_extension_id === $extension->id,
                ];
            })->values()->all();

        return [
            'tenant' => ['id' => $tenant->id, 'name' => $tenant->name],
            'connected' => (bool) $healthy, 'updated_at' => $updated ?: null, 'server_time' => now()->timestamp,
            'extensions' => $extensions,
            'teams' => $teams->map(fn ($team) => ['id' => $team->id, 'name' => $team->name, 'enabled' => $team->enabled])->values()->all(),
            'can_view_calls' => $details,
            'can_set_availability' => $user->hasPermission(Permissions::QUEUES_WORK),
        ];
    }
}
