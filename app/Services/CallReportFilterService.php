<?php

namespace App\Services;

use App\Models\CallQueue;
use App\Models\SipNumber;
use Illuminate\Database\Eloquent\Builder;

class CallReportFilterService
{
    public function validateOwnership(?int $tenantId, array $filters): void
    {
        foreach (['number' => SipNumber::class, 'team' => CallQueue::class] as $key => $model) {
            if (! empty($filters[$key])) {
                $query = $model::query();
                if ($tenantId !== null) {
                    $query->where('tenant_id', $tenantId);
                }
                $query->findOrFail($filters[$key]);
            }
        }
    }

    public function apply(Builder $query, ?int $tenantId, array $filters): Builder
    {
        if ($tenantId !== null) {
            $query->where('tenant_id', $tenantId);
        }
        foreach (['number' => 'sip_number_id', 'team' => 'call_queue_id', 'direction' => 'direction', 'status' => 'status'] as $key => $column) {
            if (! empty($filters[$key])) {
                $query->where($column, $filters[$key]);
            }
        }

        return $query;
    }

    public function options(?int $tenantId): array
    {
        return [
            'numbers' => SipNumber::query()->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))->orderBy('number')->get(['id', 'number', 'label']),
            'teams' => CallQueue::query()->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))->orderBy('name')->get(['id', 'name']),
        ];
    }
}
