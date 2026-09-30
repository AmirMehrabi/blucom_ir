<?php

namespace App\Services;

use App\Models\CallRecord;
use App\Support\DashboardPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class CallSummaryService
{
    public function __construct(private readonly CallReportFilterService $filters) {}

    public function summarize(?int $tenantId, string $period = 'daily', array $filters = [], bool $withRecordings = false): array
    {
        $window = DashboardPeriod::window($period);
        $scope = $this->filters->apply(CallRecord::query(), $tenantId, $filters);
        $current = (clone $scope)->where('started_at', '>=', $window['start']->utc())->where('started_at', '<=', $window['end']->utc());
        $previous = (clone $scope)->where('started_at', '>=', $window['previousStart']->utc())
            ->where('started_at', '<=', $window['previousEnd']->utc())->where('started_at', '<', $window['start']->utc());
        $stats = $this->aggregate($current);
        $previousStats = $this->aggregate($previous);
        $series = [];
        $selects = [];
        $bindings = [];
        $buckets = $period === 'daily' ? $window['end']->hour + 1 : $window['days'];
        for ($i = 0; $i < $buckets; $i++) {
            $start = $period === 'daily' ? $window['start']->addHours($i) : $window['start']->addDays($i);
            $end = $period === 'daily' ? $start->addHour() : $start->addDay();
            foreach (['inbound' => 'in', 'outbound' => 'out'] as $direction => $key) {
                $selects[] = "COALESCE(SUM(CASE WHEN started_at >= ? AND started_at < ? AND direction = ? THEN 1 ELSE 0 END), 0) AS {$key}_{$i}";
                array_push($bindings, $start->utc()->toDateTimeString(), $end->utc()->toDateTimeString(), $direction);
            }
            $series[] = ['label' => $period === 'daily' ? $start->format('H:00') : $start->format('m/d'),
                'date' => $start->toDateString(), 'hour' => $period === 'daily' ? $i : null];
        }
        $counts = (clone $current)->selectRaw(implode(', ', $selects), $bindings)->first();
        foreach ($series as $i => &$bucket) {
            $bucket['in'] = (int) $counts->{'in_'.$i};
            $bucket['out'] = (int) $counts->{'out_'.$i};
        }
        unset($bucket);
        $relations = ['sipNumber:id,number,label', 'sipExtension:id,extension,display_name', 'callQueue:id,name'];
        if ($withRecordings) {
            $relations[] = 'recordings';
        }
        $lastImport = DB::table('call_record_import_cursors')->max('updated_at');

        return $stats + [
            'previous' => $previousStats, 'window' => $window, 'series' => $series,
            'chartMax' => max(1, ...array_map(fn ($bucket) => max($bucket['in'], $bucket['out']), $series)),
            'recentCalls' => (clone $current)->with($relations)->orderByDesc('started_at')->orderByDesc('id')->limit(6)->get(),
            'lastImport' => $lastImport ? CarbonImmutable::parse($lastImport, 'UTC')->setTimezone($window['timezone']) : null,
            'generatedAt' => $window['end'],
        ];
    }

    private function aggregate(Builder $query): array
    {
        $result = (clone $query)->selectRaw("COUNT(*) AS total,
            COALESCE(SUM(CASE WHEN direction = 'inbound' THEN 1 ELSE 0 END), 0) AS incoming,
            COALESCE(SUM(CASE WHEN direction = 'outbound' THEN 1 ELSE 0 END), 0) AS outgoing,
            COALESCE(SUM(CASE WHEN direction = 'inbound' AND status = 'answered' THEN 1 ELSE 0 END), 0) AS incoming_answered,
            COALESCE(SUM(CASE WHEN direction = 'outbound' AND status = 'answered' THEN 1 ELSE 0 END), 0) AS outgoing_answered,
            COALESCE(SUM(CASE WHEN direction = 'inbound' AND status = 'missed' THEN 1 ELSE 0 END), 0) AS missed,
            COALESCE(SUM(CASE WHEN direction = 'inbound' AND status = 'failed' THEN 1 ELSE 0 END), 0) AS incoming_failed,
            COALESCE(SUM(CASE WHEN direction = 'outbound' AND status = 'failed' THEN 1 ELSE 0 END), 0) AS outgoing_failed,
            COALESCE(SUM(billable_seconds), 0) AS talk_seconds")->first();
        $stats = array_map('intval', $result->getAttributes());
        $stats['answerRate'] = $stats['incoming'] > 0 ? round($stats['incoming_answered'] * 100 / $stats['incoming'], 1) : null;
        $stats['outgoingRate'] = $stats['outgoing'] > 0 ? round($stats['outgoing_answered'] * 100 / $stats['outgoing'], 1) : null;

        return $stats;
    }
}
