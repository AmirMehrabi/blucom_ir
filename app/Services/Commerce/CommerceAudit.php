<?php

namespace App\Services\Commerce;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CommerceAudit
{
    public function customer(Customer $actor, string $event, string $type, int $id, array $metadata = []): void
    {
        abort_unless($actor->tenant !== null && $actor->canAccessTenant($actor->tenant), 403);
        DB::table('commerce_audit_events')->insert([
            'actor_customer_id' => $actor->id, 'event' => $event, 'resource_type' => $type,
            'resource_id' => $id, 'reason' => 'Customer reservation checkout',
            'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR), 'created_at' => now(),
        ]);
    }

    public function system(string $event, string $type, int $id, array $metadata = [], string $reason = 'Reservation expiry'): void
    {
        DB::table('commerce_audit_events')->insert([
            'event' => $event, 'resource_type' => $type, 'resource_id' => $id,
            'reason' => $reason, 'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR), 'created_at' => now(),
        ]);
    }

    public function record(User $actor, string $event, string $type, int $id, string $reason, array $metadata = []): void
    {
        abort_unless($actor->isAdmin() && ! $actor->isDisabled(), 403);
        DB::table('commerce_audit_events')->insert([
            'actor_user_id' => $actor->id, 'event' => $event, 'resource_type' => $type,
            'resource_id' => $id, 'reason' => $reason, 'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
    }
}
