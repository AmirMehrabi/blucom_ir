<?php

namespace App\Console\Commands;

use App\Models\CommerceInvoice;
use App\Services\Commerce\BillingReminderService;
use App\Services\Commerce\SubscriptionBillingService;
use App\Services\Commerce\WalletService;
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
                        if ($invoice->kind === 'wallet_topup') {
                            app(WalletService::class)->applyTopup($invoice->id);
                        } else {
                            $billing->applyPaid($invoice->id);
                        }
                        $recovered++;
                    } catch (\Throwable $exception) {
                        Log::error('Paid billing invoice still needs review', ['invoice_id' => $invoice->id, 'exception_class' => $exception::class]);
                    }
                }
            });
        $wallet = app(WalletService::class);
        // Recover a credit committed before a process interruption, before automatic renewals spend it.
        CommerceInvoice::query()->where('kind', 'wallet_topup')->where('status', 'paid')
            ->whereHas('walletTarget', fn ($q) => $q->where('status', 'issued')
                ->where(fn ($window) => $window->where('kind', 'plan_renewal')->orWhere('expires_at', '>', now())))
            ->eachById(fn ($invoice) => $wallet->applyTopup($invoice->id));
        $wallet->recoverDue();
        $sent = $reminders->sendDue();
        $this->components->info("Issued {$issued}; recovered {$recovered}; sent {$sent} reminders and {$priceNotices} price notices.");

        return self::SUCCESS;
    }
}
