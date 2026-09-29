<?php

namespace App\Services;

use App\Models\InboundRoute;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Validation\ValidationException;
use IntlDateFormatter;

class InboundScheduleService
{
    public function normalizeDigits(string $value): string
    {
        return strtr($value, array_combine(
            preg_split('//u', '۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩', -1, PREG_SPLIT_NO_EMPTY),
            str_split('01234567890123456789'),
        ));
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function fromInput(array $data): array
    {
        $timezone = (string) ($data['timezone'] ?? 'Asia/Tehran');
        if (! in_array($timezone, timezone_identifiers_list(), true)) {
            throw ValidationException::withMessages(['timezone' => 'منطقه زمانی معتبر انتخاب کنید.']);
        }

        $weekly = [];
        foreach (range(0, 6) as $day) {
            $intervals = $data['weekly'][$day] ?? [];
            if (! is_array($intervals) || count($intervals) > 3) {
                throw ValidationException::withMessages(['weekly' => 'برای هر روز حداکثر سه بازه زمانی وارد کنید.']);
            }
            $dayIntervals = [];
            foreach ($intervals as $interval) {
                if (! is_array($interval)) {
                    throw ValidationException::withMessages(['weekly' => 'بازه زمانی معتبر نیست.']);
                }
                $start = $this->normalizeDigits((string) ($interval['start'] ?? ''));
                $end = $this->normalizeDigits((string) ($interval['end'] ?? ''));
                if (! $this->validTime($start) || ! $this->validTime($end) || $start >= $end) {
                    throw ValidationException::withMessages(['weekly' => 'ساعت پایان هر بازه باید بعد از ساعت شروع آن باشد.']);
                }
                $dayIntervals[] = ['start' => $start, 'end' => $end];
            }
            usort($dayIntervals, fn ($a, $b) => strcmp($a['start'], $b['start']));
            for ($i = 1; $i < count($dayIntervals); $i++) {
                if ($dayIntervals[$i]['start'] < $dayIntervals[$i - 1]['end']) {
                    throw ValidationException::withMessages(['weekly' => 'بازه‌های یک روز نباید روی هم بیفتند.']);
                }
            }
            $weekly[(string) $day] = $dayIntervals;
        }
        if (! array_filter($weekly)) {
            throw ValidationException::withMessages(['weekly' => 'دست‌کم یک روز و بازه زمانی باز انتخاب کنید.']);
        }

        $closedDates = [];
        foreach (array_slice($data['closed_dates'] ?? [], 0, 30) as $date) {
            $date = trim($this->normalizeDigits((string) $date));
            if ($date !== '') {
                $closedDates[] = $this->jalaliToGregorian($date);
            }
        }

        return ['timezone' => $timezone, 'weekly' => $weekly, 'closed_dates' => array_values(array_unique($closedDates))];
    }

    public function isOpen(InboundRoute $route, ?CarbonImmutable $at = null): bool
    {
        $schedule = $route->schedule;
        if (! is_array($schedule)) {
            return true;
        }
        if (! is_string($schedule['timezone'] ?? null)
            || ! is_array($schedule['weekly'] ?? null)
            || ! is_array($schedule['closed_dates'] ?? null)) {
            return false;
        }
        try {
            $local = ($at ?? CarbonImmutable::now())->setTimezone($schedule['timezone'] ?? 'Asia/Tehran');
        } catch (\Throwable) {
            return false;
        }
        if (in_array($local->toDateString(), $schedule['closed_dates'], true)) {
            return false;
        }
        $day = (string) (($local->dayOfWeek + 1) % 7); // Saturday = 0
        $clock = $local->format('H:i');
        $intervals = $schedule['weekly'][$day] ?? [];
        if (! is_array($intervals)) {
            return false;
        }
        foreach ($intervals as $interval) {
            if (is_array($interval) && $clock >= ($interval['start'] ?? '') && $clock < ($interval['end'] ?? '')) {
                return true;
            }
        }

        return false;
    }

    private function validTime(string $time): bool
    {
        return (bool) preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D', $time);
    }

    private function jalaliToGregorian(string $date): string
    {
        if (! preg_match('/^(1[34][0-9]{2})[\/\-](0?[1-9]|1[0-2])[\/\-](0?[1-9]|[12][0-9]|3[01])$/D', $date, $parts)) {
            throw ValidationException::withMessages(['closed_dates' => 'تاریخ تعطیلی را به صورت ۱۴۰۵/۰۷/۰۷ وارد کنید.']);
        }
        $canonical = sprintf('%04d/%02d/%02d', $parts[1], $parts[2], $parts[3]);
        $formatter = new IntlDateFormatter('fa_IR@calendar=persian', IntlDateFormatter::NONE, IntlDateFormatter::NONE,
            'Asia/Tehran', IntlDateFormatter::TRADITIONAL, 'yyyy/MM/dd');
        $timestamp = $formatter->parse($canonical);
        if ($timestamp === false || $this->normalizeDigits($formatter->format($timestamp)) !== $canonical) {
            throw ValidationException::withMessages(['closed_dates' => 'تاریخ جلالی معتبر وارد کنید.']);
        }

        return (new DateTimeImmutable('@'.$timestamp))->setTimezone(new \DateTimeZone('Asia/Tehran'))->format('Y-m-d');
    }
}
