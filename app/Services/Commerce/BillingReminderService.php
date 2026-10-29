<?php

namespace App\Services\Commerce;

use App\Models\CommerceInvoice;
use App\Models\Customer;
use App\Models\NumberTerm;
use App\Models\PlanVersion;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Services\Portal\CustomerBillingSummary;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BillingReminderService
{
    public function sendPriceNotices(): int
    {
        $sent = 0;
        TenantSubscription::query()->where('pending_change_source', 'price_notice')
            ->whereNull('price_notice_sent_at')->whereNotNull('pending_plan_version_id')
            ->orderBy('id')->chunkById(100, function ($subscriptions) use (&$sent): void {
                foreach ($subscriptions as $subscription) {
                    $owner = Customer::query()->find($subscription->tenant?->owner_customer_id);
                    $version = PlanVersion::query()->with('plan')->find($subscription->pending_plan_version_id);
                    if (! $owner || $owner->isDisabled() || ! $version) {
                        continue;
                    }
                    $message = 'قیمت پلن '.$version->plan->name.' به '.number_format($version->monthly_price)
                        .' تومان در ماه تغییر می‌کند. قیمت جدید زودتر از ۳۰ روز پس از این اطلاع‌رسانی اعمال نمی‌شود.';
                    $this->inApp($owner, 'price:'.$subscription->id.':'.$version->id, 'اطلاع‌رسانی تغییر قیمت', $message);
                    if (! $this->sms($owner, $message)) {
                        continue;
                    }
                    TenantSubscription::query()->whereKey($subscription->id)->whereNull('price_notice_sent_at')
                        ->where('pending_plan_version_id', $version->id)
                        ->update(['price_notice_sent_at' => now(), 'pending_effective_at' => now()->addDays(30)]);
                    $sent++;
                }
            });
        NumberTerm::query()->whereNotNull('pending_annual_amount')->whereNull('price_notice_sent_at')
            ->orderBy('id')->chunkById(100, function ($terms) use (&$sent): void {
                foreach ($terms as $term) {
                    $owner = Customer::query()->find(Tenant::query()->whereKey($term->tenant_id)->value('owner_customer_id'));
                    $number = $term->assignment?->sipNumber?->normalized_number;
                    if (! $owner || $owner->isDisabled() || ! $number) {
                        continue;
                    }
                    $message = 'هزینه تمدید سالانه شماره '.$number.' به '.number_format($term->pending_annual_amount)
                        .' تومان تغییر می‌کند. قیمت جدید زودتر از ۳۰ روز پس از این اطلاع‌رسانی اعمال نمی‌شود.';
                    $this->inApp($owner, 'number-price:'.$term->id.':'.$term->pending_annual_amount,
                        'اطلاع‌رسانی تغییر قیمت شماره', $message);
                    if (! $this->sms($owner, $message)) {
                        continue;
                    }
                    NumberTerm::query()->whereKey($term->id)->whereNull('price_notice_sent_at')
                        ->where('pending_annual_amount', $term->pending_annual_amount)
                        ->update(['price_notice_sent_at' => now(), 'pending_price_effective_at' => now()->addDays(30)]);
                    $sent++;
                }
            });

        return $sent;
    }

    public function sendDue(): int
    {
        $count = 0;
        CommerceInvoice::query()->whereNull('commerce_order_id')->where('status', 'issued')
            ->whereIn('kind', ['plan_renewal', 'number_renewal', 'plan_upgrade'])->orderBy('id')->chunkById(100, function ($invoices) use (&$count): void {
                foreach ($invoices as $invoice) {
                    if ($invoice->grace_ends_at?->isPast()) {
                        continue;
                    }
                    $stage = $invoice->grace_ends_at?->subDay()->lte(now()) && $invoice->final_notice_sent_at === null ? 'final'
                        : ($invoice->due_at?->lte(now()) && $invoice->due_notice_sent_at === null ? 'due'
                            : ($invoice->notice_sent_at === null ? 'issued' : null));
                    if ($stage !== null && $this->send($invoice, $stage)) {
                        $count++;
                    }
                }
            });

        return $count;
    }

    private function send(CommerceInvoice $invoice, string $stage): bool
    {
        $owner = Customer::query()->find($invoice->customer_id);
        if (! $owner || $owner->tenant_id !== $invoice->tenant_id || $owner->isDisabled()) {
            return false;
        }
        $message = match ($stage) {
            'issued' => 'صورتحساب جدید بلوکام صادر شد.',
            'due' => 'صورتحساب بلوکام سررسید شده است. برای ادامه تماس‌ها پرداخت کنید.',
            default => 'مهلت پرداخت صورتحساب بلوکام فردا پایان می‌یابد. برای جلوگیری از توقف تماس‌ها پرداخت کنید.',
        };
        if (config('commerce.wallet_enabled') && $invoice->kind === 'plan_renewal') {
            $action = app(CustomerBillingSummary::class)->action($invoice, $owner);
            if ($action['pending']) {
                return false; // Never ask for another payment while the bank result is unresolved.
            }
            $wallet = app(WalletService::class);
            $subscription = TenantSubscription::query()->find($invoice->tenant_subscription_id);
            $shortfall = max(0, $invoice->total_amount - $wallet->balance($invoice->tenant_id));
            if ($shortfall === 0 && $subscription?->wallet_auto_renew && ! $wallet->funding($invoice) && ! $action['continue']) {
                return false;
            }
            $message = $action['continue']
                ? 'پرداخت قبلی برای تمدید پلن ناتمام است؛ همان پرداخت را از بخش صورتحساب ادامه دهید.'
                : ($shortfall > 0
                    ? 'برای تمدید پلن ماهانه، '.CustomerCommercePresenter::money($shortfall).' تومان به کیف پول اضافه کنید.'
                    : 'موجودی کیف پول برای تمدید پلن کافی است؛ پرداخت را از بخش صورتحساب تأیید کنید.');
            if ($stage === 'final') {
                $message .= ' مهلت پرداخت فردا پایان می‌یابد.';
            }
        }
        $this->inApp($owner, 'invoice:'.$invoice->id.':'.$stage, 'یادآوری صورتحساب', $message, route('customer.billing.invoice', $invoice->public_id));
        if (! $this->sms($owner, $message)) {
            return false;
        }
        $field = match ($stage) {
            'issued' => 'notice_sent_at', 'due' => 'due_notice_sent_at', default => 'final_notice_sent_at'
        };
        CommerceInvoice::query()->whereKey($invoice->id)->whereNull($field)->update([$field => now()]);

        return true;
    }

    private function inApp(Customer $owner, string $key, string $title, string $message, ?string $url = null): void
    {
        $uuid = substr(hash('sha256', 'blucom-billing:'.$key), 0, 32);
        $notificationId = substr($uuid, 0, 8).'-'.substr($uuid, 8, 4).'-5'.substr($uuid, 13, 3).'-a'.substr($uuid, 17, 3).'-'.substr($uuid, 20, 12);
        DB::table('notifications')->insertOrIgnore([
            'id' => $notificationId, 'type' => 'billing_reminder', 'notifiable_type' => Customer::class,
            'notifiable_id' => $owner->id,
            'data' => json_encode(['title' => $title, 'message' => $message,
                'url' => $url ?? route('customer.billing.index')], JSON_THROW_ON_ERROR),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function sms(Customer $owner, string $message): bool
    {
        $key = (string) config('services.kavenegar.api_key');
        $sender = (string) config('services.kavenegar.sms_sender');
        if ($key === '' || $sender === '') {
            return false;
        }
        try {
            $response = Http::acceptJson()->asForm()->connectTimeout(3)->timeout(8)
                ->post('https://api.kavenegar.com/v1/'.$key.'/sms/send.json', [
                    'receptor' => $owner->mobile, 'sender' => $sender,
                    'message' => $message.' my.blucom.ir/billing',
                ]);
            if (! $response->successful() || (int) ($response->json('return.status') ?? 0) !== 200) {
                return false;
            }

            return true;
        } catch (\Throwable $exception) {
            Log::warning('Billing SMS delivery deferred', ['customer_id' => $owner->id, 'exception_class' => $exception::class]);

            return false;
        }
    }
}
