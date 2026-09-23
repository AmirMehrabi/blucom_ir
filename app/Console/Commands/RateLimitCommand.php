<?php

namespace App\Console\Commands;

use App\Services\RateLimitService;
use Illuminate\Console\Command;

class RateLimitCommand extends Command
{
    protected $signature = 'rate-limit
        {action : status|disable|enable}
        {--minutes= : Temporary disable duration in minutes (omit to disable until re-enabled)}';

    protected $description = 'Show or temporarily disable application HTTP rate limiting';

    public function handle(RateLimitService $rateLimits): int
    {
        $action = (string) $this->argument('action');

        return match ($action) {
            'status' => $this->status($rateLimits),
            'disable' => $this->disable($rateLimits),
            'enable' => $this->enable($rateLimits),
            default => $this->invalid(),
        };
    }

    private function status(RateLimitService $rateLimits): int
    {
        $status = $rateLimits->status();

        $this->table(
            ['Setting', 'Value'],
            [
                ['env RATE_LIMIT_ENABLED', $status['env'] ? 'true' : 'false'],
                ['temporary override', $status['temporary'] ? 'yes' : 'no'],
                ['rate limiting active', $status['active'] ? 'yes' : 'no'],
                ['temporary until', $status['expires_at'] ?? '—'],
            ],
        );

        if (! $status['env']) {
            $this->warn('Rate limiting is hard-disabled via RATE_LIMIT_ENABLED=false.');
        } elseif ($status['temporary']) {
            $this->warn('Rate limiting is temporarily disabled. Re-enable with: php artisan rate-limit enable');
        } else {
            $this->info('Rate limiting is active.');
        }

        return self::SUCCESS;
    }

    private function disable(RateLimitService $rateLimits): int
    {
        $minutes = $this->option('minutes');
        if ($minutes !== null && $minutes !== '' && (! is_numeric($minutes) || (int) $minutes < 1)) {
            $this->error('--minutes must be a positive integer.');

            return self::FAILURE;
        }

        $minutes = $minutes === null || $minutes === '' ? null : (int) $minutes;
        $rateLimits->disable($minutes);

        if ($minutes !== null) {
            $this->warn("Rate limiting disabled for {$minutes} minute(s).");
        } else {
            $this->warn('Rate limiting disabled until: php artisan rate-limit enable');
        }

        return self::SUCCESS;
    }

    private function enable(RateLimitService $rateLimits): int
    {
        $rateLimits->enable();
        $this->info('Runtime rate-limit override cleared. Limits follow env/config again.');

        return self::SUCCESS;
    }

    private function invalid(): int
    {
        $this->error('Unknown action. Use: status|disable|enable');

        return self::FAILURE;
    }
}
