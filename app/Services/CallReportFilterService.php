<?php

namespace App\Services;

use App\Models\CallQueue;
use App\Models\CallRecord;
use App\Models\SipExtension;
use App\Models\SipNumber;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CallReportFilterService
{
    public function validated(Request $request, string $defaultRange = '30d'): array
    {
        $filters = $request->validate([
            'direction' => ['nullable', Rule::in([CallRecord::INBOUND, CallRecord::OUTBOUND])],
            'status' => ['nullable', Rule::in([CallRecord::ANSWERED, CallRecord::MISSED, CallRecord::FAILED])],
            'range' => ['nullable', Rule::in(['1d', '7d', '30d', 'all', 'custom'])],
            'from' => ['nullable', 'required_if:range,custom', 'date_format:Y-m-d'],
            'to' => ['nullable', 'required_if:range,custom', 'date_format:Y-m-d', 'after_or_equal:from'],
            'hour' => ['nullable', 'integer', 'between:0,23'],
            'phone' => ['nullable', 'string', 'max:32', 'regex:/^[+0-9]+$/'],
            'extension' => ['nullable', 'integer', 'min:1'],
            'number' => ['nullable', 'integer', 'min:1'], 'team' => ['nullable', 'integer', 'min:1'],
        ]);
        $filters['range'] ??= $defaultRange;

        return $filters;
    }

    public function applyWindow(Builder $query, array $filters): Builder
    {
        $timezone = config('voip.display_timezone', 'Asia/Tehran');
        $now = CarbonImmutable::now($timezone);
        $range = $filters['range'] ?? '30d';
        $query->where('started_at', '<=', $now->utc());
        if ($range === 'custom') {
            $start = CarbonImmutable::createFromFormat('!Y-m-d', $filters['from'], $timezone);
            $end = CarbonImmutable::createFromFormat('!Y-m-d', $filters['to'], $timezone)->addDay();
            if (isset($filters['hour'])) {
                if ($filters['from'] !== $filters['to']) {
                    throw ValidationException::withMessages(['hour' => 'فیلتر ساعت فقط برای یک روز مشخص قابل استفاده است.']);
                }
                $start = $start->addHours((int) $filters['hour']);
                $end = $start->addHour();
            }
            $query->where('started_at', '>=', $start->utc())->where('started_at', '<', $end->utc());
        } elseif (isset($filters['hour'])) {
            throw ValidationException::withMessages(['hour' => 'برای فیلتر ساعت، یک روز مشخص انتخاب کنید.']);
        } elseif ($range !== 'all') {
            $days = match ($range) {
                '1d' => 0, '7d' => 6, default => 29
            };
            $query->where('started_at', '>=', $now->startOfDay()->subDays($days)->utc());
        }

        return $query;
    }

    public function validateOwnership(?int $tenantId, array $filters): void
    {
        foreach (['number' => SipNumber::class, 'team' => CallQueue::class, 'extension' => SipExtension::class] as $key => $model) {
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
        foreach (['number' => 'sip_number_id', 'team' => 'call_queue_id', 'direction' => 'direction', 'status' => 'status', 'extension' => 'sip_extension_id'] as $key => $column) {
            if (! empty($filters[$key])) {
                $query->where($column, $filters[$key]);
            }
        }

        if (! empty($filters['phone'])) {
            $phone = app(NumberNormalizer::class)->normalize($filters['phone']);
            $variants = array_unique(array_filter([$phone, $phone ? ltrim($phone, '+') : null, $phone ? '00'.ltrim($phone, '+') : null, $filters['phone']]));
            $query->where(fn ($q) => $q->whereIn('source_number', $variants)->orWhereIn('destination_number', $variants));
        }

        return $query;
    }

    public function options(?int $tenantId): array
    {
        return [
            'extensions' => SipExtension::query()->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))->orderBy('extension')->get(['id', 'extension', 'display_name']),
            'numbers' => SipNumber::query()->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))->orderBy('number')->get(['id', 'number', 'label']),
            'teams' => CallQueue::query()->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))->orderBy('name')->get(['id', 'name']),
        ];
    }
}
