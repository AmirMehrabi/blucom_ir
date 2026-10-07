<?php

namespace App\Console\Commands;

use App\Services\Commerce\NumberReservationService;
use Illuminate\Console\Command;

class ExpireNumberReservations extends Command
{
    protected $signature = 'commerce:expire-reservations {--limit=100 : Maximum holds to inspect}';

    protected $description = 'Expire due number holds without deleting invoice or payment history';

    public function handle(NumberReservationService $reservations): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10000]]);
        if ($limit === false) {
            $this->error('Limit must be an integer between 1 and 10000.');

            return self::INVALID;
        }
        $result = $reservations->expireDue($limit);
        $this->info("Expired: {$result['expired']}; requires review: {$result['review']}.");

        return $result['review'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
