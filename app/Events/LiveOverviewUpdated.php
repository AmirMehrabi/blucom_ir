<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/** Send an invalidation only; each browser fetches data with current permissions. */
class LiveOverviewUpdated implements ShouldBroadcastNow
{
    public function __construct(private readonly int $tenantId) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('live-overview.'.$this->tenantId)];
    }

    public function broadcastAs(): string
    {
        return 'overview.updated';
    }

    public function broadcastWith(): array
    {
        return ['refresh' => true];
    }
}
