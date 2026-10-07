<?php

namespace App\Console\Commands;

use App\Models\CommerceOrder;
use App\Services\Commerce\PaidOrderAllocationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class AllocatePaidOrders extends Command
{
    protected $signature = 'commerce:allocate-paid-orders';

    protected $description = 'Retry verified paid orders awaiting allocation without reclaiming expired holds';

    public function handle(PaidOrderAllocationService $allocation): int
    {
        $failed = 0;
        CommerceOrder::query()->where('status', 'paid_pending_allocation')->orderBy('id')->chunkById(100, function ($orders) use ($allocation, &$failed) {
            foreach ($orders as $order) {
                try {
                    $allocation->allocate($order->id);
                } catch (\Throwable $exception) {
                    $failed++;
                    Log::error('Paid allocation retry failed', ['order_id' => $order->id, 'exception_class' => $exception::class]);
                }
            }
        });
        $this->info('Pending paid allocation retry completed; failures: '.$failed);

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
