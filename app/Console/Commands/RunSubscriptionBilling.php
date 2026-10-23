<?php

namespace App\Console\Commands;

use App\Models\CommerceInvoice;
use App\Services\Commerce\BillingReminderService;
use App\Services\Commerce\SubscriptionBillingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RunSubscriptionBilling extends Command
{
    protected $signature = 'commerce:run-billing';

    protected $description = 'Issue due subscription invoices, recover settled terms, and send reminders';

    public function handle(SubscriptionBillingService $billing, BillingReminderService $reminders): int
    {
        $issued = $billing->issueDue();
        $priceNotices = $reminders->sendPriceNotices();
        $recovered = 0;
        CommerceInvoice::query()->whereNull('commerce_order_id')->where('status', 'paid')->whereNull('applied_at')
            ->orderBy('id')->chunkById(100, function ($invoices) use ($billing, &$recovered): void {
                foreach ($invoices as $invoice) {
                    try {
                        $billing->applyPaid($invoice->id);
                        $recovered++;
                    } catch (\Throwable $exception) {
                        Log::error('Paid billing invoice still needs review', ['invoice_id' => $invoice->id, 'exception_class' => $exception::class]);
                    }
                }
            });
        $sent = $reminders->sendDue();
        $this->components->info("Issued {$issued}; recovered {$recovered}; sent {$sent} reminders and {$priceNotices} price notices.");
        return self::SUCCESS;
    }
}
