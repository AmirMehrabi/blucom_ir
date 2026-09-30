<?php

namespace App\Support;

use Carbon\CarbonImmutable;

class DashboardPeriod
{
    public const OPTIONS = ['daily' => 'روزانه', 'weekly' => 'هفتگی', 'monthly' => 'ماهانه'];

    public static function window(string $period): array
    {
        $period = array_key_exists($period, self::OPTIONS) ? $period : 'daily';
        $timezone = (string) config('voip.display_timezone', 'Asia/Tehran');
        $end = CarbonImmutable::now($timezone);
        $days = match ($period) {
            'weekly' => 7, 'monthly' => 30, default => 1
        };
        $start = $end->startOfDay()->subDays($days - 1);

        return [
            'key' => $period, 'days' => $days, 'timezone' => $timezone,
            'start' => $start, 'end' => $end,
            'previousStart' => $start->subDays($days), 'previousEnd' => $end->subDays($days),
            'label' => match ($period) {
                'weekly' => '۷ روز اخیر', 'monthly' => '۳۰ روز اخیر', default => 'امروز'
            },
        ];
    }
}
