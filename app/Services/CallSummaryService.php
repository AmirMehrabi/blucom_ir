<?php

namespace App\Services;

use App\Models\CallRecord;
use Carbon\CarbonImmutable;

class CallSummaryService
{
    /** @return array<string, mixed> */
    public function summarize(?int $tenantId): array
    {
        $timezone = (string) config('voip.display_timezone', 'Asia/Tehran');
        $todayLocal = CarbonImmutable::now($timezone)->startOfDay();
        $todayUtc = $todayLocal->utc();
        $weekUtc = $todayLocal->subDays(6)->utc();

        $scope = CallRecord::query();
        if ($tenantId !== null) {
            $scope->where('tenant_id', $tenantId);
        }

        $today = (clone $scope)->where('started_at', '>=', $todayUtc);
        $totalToday = (clone $today)->count();
        $answeredToday = (clone $today)->where('status', CallRecord::ANSWERED)->count();
        $missedToday = (clone $today)->where('status', CallRecord::MISSED)->count();
        $failedToday = (clone $today)->where('status', CallRecord::FAILED)->count();
        $billableToday = (int) (clone $today)->sum('billable_seconds');
        $answerRate = $totalToday > 0 ? (int) round($answeredToday * 100 / $totalToday) : 0;

        $week = [];
        for ($i = 6; $i >= 0; $i--) {
            $day = $todayLocal->subDays($i);
            $week[$day->toDateString()] = [
                'day' => $day->locale('fa')->isoFormat('dddd'),
                'in' => 0,
                'out' => 0,
            ];
        }
        (clone $scope)->where('started_at', '>=', $weekUtc)
            ->get(['started_at', 'direction'])
            ->each(function (CallRecord $call) use (&$week, $timezone): void {
                $day = $call->started_at->setTimezone($timezone)->toDateString();
                if (isset($week[$day])) {
                    $key = $call->direction === CallRecord::INBOUND ? 'in' : 'out';
                    $week[$day][$key]++;
                }
            });

        $recentCalls = (clone $scope)->latest('started_at')->limit(5)->get();
        $chartMax = max(1, ...array_map(static fn ($day) => max($day['in'], $day['out']), array_values($week)));

        return compact(
            'totalToday', 'answeredToday', 'missedToday', 'failedToday',
            'billableToday', 'answerRate', 'recentCalls', 'chartMax',
        ) + ['week' => array_values($week), 'timezone' => $timezone];
    }
}
