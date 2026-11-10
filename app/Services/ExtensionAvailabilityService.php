<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\SipExtension;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ExtensionAvailabilityService
{
    public function receivesCalls(?SipExtension $extension): bool
    {
        return $extension !== null && $extension->enabled && $extension->paused_at === null;
    }

    public function effectiveQueueStatus(SipExtension $extension): string
    {
        return $this->receivesCalls($extension) && $extension->queue_status === 'Available' ? 'Available' : 'On Break';
    }

    public function queuePending(SipExtension $extension): bool
    {
        $count = $extension->getAttribute('call_queues_count');
        $hasQueues = $count !== null ? $count > 0 : $extension->callQueues()->where('enabled', true)->exists();

        return config('voip.queues_enabled') && $hasQueues && $extension->availability_version > 0
            && ($extension->queue_sync_version < $extension->availability_version || $extension->queue_synced_at === null
                || $extension->queue_synced_at->lt(now()->subMinutes(2)));
    }

    public function state(User|Customer $actor, int $id): array
    {
        $tenant = app(TenantService::class)->forUser($actor);
        $extension = SipExtension::query()->where('tenant_id', $tenant->id)->findOrFail($id);
        abort_unless($actor->hasPermission(Permissions::PHONES_MANAGE)
            || ($actor->hasPermission(Permissions::QUEUES_WORK) && $actor->sip_extension_id === $extension->id), 403);

        return ['version' => $extension->availability_version, 'paused' => $extension->paused_at !== null,
            'pending' => $this->queuePending($extension)];
    }

    public function pause(User|Customer $actor, int $id, bool $paused, int $version): SipExtension
    {
        $tenant = app(TenantService::class)->forUser($actor);

        return DB::transaction(function () use ($actor, $tenant, $id, $paused, $version) {
            $actor = $actor->newQuery()->lockForUpdate()->findOrFail($actor->id);
            abort_unless(app(TenantService::class)->forUser($actor)->id === $tenant->id, 403);
            $extension = SipExtension::query()->where('tenant_id', $tenant->id)->lockForUpdate()->findOrFail($id);
            abort_unless($actor->hasPermission(Permissions::PHONES_MANAGE)
                || ($actor->hasPermission(Permissions::QUEUES_WORK) && $actor->sip_extension_id === $extension->id), 403);
            abort_unless($extension->enabled, 422, 'داخلی غیرفعال است؛ ابتدا آن را فعال کنید.');
            // Repeating the same explicit request is safe, including double clicks.
            if (($extension->paused_at !== null) === $paused) {
                return $extension;
            }
            abort_unless($extension->availability_version === $version, 409, 'وضعیت داخلی تغییر کرده است؛ صفحه را تازه کنید و دوباره تلاش کنید.');
            $extension->paused_at = $paused ? now() : null;
            $extension->availability_version++;
            $extension->save();
            $this->record($actor, $extension, $paused ? 'paused' : 'resumed');

            return $extension;
        }, 3);
    }

    public function record(User|Customer $actor, SipExtension $extension, string $action): void
    {
        DB::table('extension_availability_events')->insert([
            'tenant_id' => $extension->tenant_id, 'sip_extension_id' => $extension->id,
            'actor_type' => $actor instanceof Customer ? 'customer' : 'user', 'actor_id' => $actor->id,
            'action' => $action, 'version' => $extension->availability_version, 'occurred_at' => now(),
        ]);
        Log::info('Extension availability changed', ['tenant_id' => $extension->tenant_id, 'extension_id' => $extension->id, 'action' => $action]);
    }
}
