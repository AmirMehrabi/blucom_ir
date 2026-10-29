<?php

namespace App\Services\Commerce;

use App\Models\CommerceInvoice;
use App\Models\CommerceInvoiceItem;
use App\Models\CommerceOrder;
use App\Models\Customer;
use App\Models\PaymentAttempt;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Models\WalletEntry;
use App\Support\Permissions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class WalletService
{
    public function balance(int $tenantId): int
    {
        return (int) WalletEntry::query()->where('tenant_id', $tenantId)->sum('amount');
    }

    public function funding(CommerceInvoice $invoice): ?CommerceInvoice
    {
        return CommerceInvoice::query()->where('wallet_target_invoice_id', $invoice->id)->where('tenant_id', $invoice->tenant_id)
            ->where(fn ($q) => $q->where('status', 'issued')->orWhere(fn ($paid) => $paid->where('status', 'paid')->whereNull('applied_at')))
            ->latest('id')->first();
    }

    public function summary(CommerceInvoice $invoice, Customer $customer): array
    {
        abort_unless($invoice->tenant_id === $customer->tenant_id && $customer->hasPermission(Permissions::BILLING_VIEW), 404);
        $balance = $this->balance($customer->tenant_id);
        $funding = $this->funding($invoice);
        $attempt = $funding?->current_payment_attempt_id ? PaymentAttempt::query()->find($funding->current_payment_attempt_id) : null;
        $pending = ($funding && $funding->status === 'paid' && $funding->applied_at === null)
            || ($attempt && (($funding->expires_at?->isPast() && $attempt->status === 'redirect_ready') || (! in_array($attempt->status, ['initiation_failed', 'reversed', 'redirect_ready'], true)
            && ! $attempt->hasUndeliveredZibalInitiation())));

        return ['balance' => $balance, 'shortfall' => max(0, $invoice->total_amount - $balance),
            'fundingAmount' => $funding?->total_amount,
            'pending' => (bool) $pending,
            'continue' => ! $pending && $attempt?->status === 'redirect_ready' ? route('customer.payments.show', $attempt->public_id) : null,
            'canManage' => $customer->hasPermission(Permissions::BILLING_MANAGE),
            'ready' => (config('commerce.checkout_enabled') && $balance >= $invoice->total_amount && ! $funding)
                || (app(CustomerCommercePresenter::class)->checkoutReady() && ! app(CustomerCommercePresenter::class)->paymentMode()['isTest']),
        ];
    }

    public function topup(Customer $actor, int $amount, string $key): CommerceInvoice
    {
        abort_unless(config('commerce.wallet_enabled') && config('commerce.checkout_enabled'), 404);
        validator(['amount' => $amount, 'key' => $key], ['amount' => ['required', 'integer', 'min:1000', 'max:1000000000000'], 'key' => ['required', 'uuid']])->validate();

        return DB::transaction(function () use ($actor, $amount, $key) {
            $tenant = Tenant::query()->lockForUpdate()->findOrFail($actor->tenant_id);
            $actor = Customer::query()->findOrFail($actor->id);
            abort_unless($actor->canAccessTenant($tenant) && $actor->hasPermission(Permissions::BILLING_MANAGE)
                && $actor->hasPermission(Permissions::BILLING_VIEW), 403);
            if ($existing = CommerceInvoice::query()->where('wallet_request_key', strtolower($key))->first()) {
                abort_unless($existing->tenant_id === $tenant->id && $existing->wallet_target_invoice_id === null && $existing->total_amount === $amount, 409);

                return $existing;
            }
            // Resume an unfinished standalone funding attempt instead of starting another bank payment.
            $pending = CommerceInvoice::query()->where('tenant_id', $tenant->id)->where('kind', 'wallet_topup')
                ->whereNull('wallet_target_invoice_id')->where('status', 'issued')->latest('id')->first();
            if ($pending && ! $this->retireExpiredUnstarted($pending)) {
                throw ValidationException::withMessages(['wallet' => 'یک شارژ ناتمام دارید؛ ابتدا وضعیت آن را در تراکنش‌ها بررسی کنید.']);
            }
            if (app(CustomerCommercePresenter::class)->paymentMode()['isTest']) {
                throw ValidationException::withMessages(['wallet' => 'شارژ کیف پول با درگاه آزمایشی انجام نمی‌شود.']);
            }
            $id = (string) Str::uuid();
            $invoice = CommerceInvoice::query()->create(['public_id' => $id, 'invoice_number' => 'TOP-'.strtoupper($id),
                'customer_id' => $actor->id, 'tenant_id' => $tenant->id, 'kind' => 'wallet_topup', 'status' => 'issued',
                'total_amount' => $amount, 'currency' => 'IRT', 'buyer_snapshot' => ['customer_name' => $actor->name, 'business_name' => $tenant->name],
                'issued_at' => now(), 'expires_at' => now()->addDay(), 'wallet_request_key' => strtolower($key)]);
            CommerceInvoiceItem::query()->create(['commerce_invoice_id' => $invoice->id, 'kind' => 'wallet_topup',
                'snapshot' => [], 'amount' => $amount, 'currency' => 'IRT']);

            return $invoice;
        }, 3);
    }

    /** Return a top-up to pay, or null when the purchase was paid from existing funds. */
    public function prepare(Customer $actor, string $publicId, string $key): ?CommerceInvoice
    {
        abort_unless(config('commerce.wallet_enabled') && config('commerce.checkout_enabled'), 404);
        validator(['key' => $key], ['key' => ['required', 'uuid']])->validate();

        return DB::transaction(function () use ($actor, $publicId, $key) {
            $tenant = Tenant::query()->lockForUpdate()->findOrFail($actor->tenant_id);
            $actor = Customer::query()->lockForUpdate()->findOrFail($actor->id);
            abort_unless($actor->canAccessTenant($tenant) && $actor->hasPermission(Permissions::BILLING_MANAGE)
                && $actor->hasPermission(Permissions::BILLING_VIEW), 403);
            $invoice = CommerceInvoice::query()->where('tenant_id', $tenant->id)->where('public_id', $publicId)->lockForUpdate()->firstOrFail();
            if ($invoice->hasValidSettlement()) {
                return null;
            }
            abort_if($invoice->commerce_order_id && ! $actor->hasPermission(Permissions::NUMBERS_PURCHASE), 403);
            $this->assertPayable($invoice);
            if (($funding = $this->funding($invoice)) && ! $this->retireExpiredUnstarted($funding)) {
                return $funding;
            }
            $shortfall = max(0, $invoice->total_amount - $this->balance($tenant->id));
            if ($shortfall === 0) {
                $this->settle($invoice);

                return null;
            }
            // A sandbox bank credit can never enter the real wallet.
            if (app(CustomerCommercePresenter::class)->paymentMode()['isTest']) {
                throw ValidationException::withMessages(['wallet' => 'شارژ کیف پول فقط با درگاه واقعی امکان‌پذیر است؛ درگاه اکنون آزمایشی است.']);
            }
            $existing = CommerceInvoice::query()->where('wallet_request_key', strtolower($key))->first();
            if ($existing) {
                abort_unless($existing->tenant_id === $tenant->id && $existing->wallet_target_invoice_id === $invoice->id, 409);

                return $existing;
            }
            $id = (string) Str::uuid();
            $funding = CommerceInvoice::query()->create([
                'public_id' => $id, 'invoice_number' => 'TOP-'.strtoupper($id), 'customer_id' => $actor->id,
                'tenant_id' => $tenant->id, 'kind' => 'wallet_topup', 'status' => 'issued',
                'total_amount' => $shortfall, 'currency' => 'IRT', 'buyer_snapshot' => $invoice->buyer_snapshot,
                'issued_at' => now(), 'expires_at' => now()->addDay(),
                'wallet_target_invoice_id' => $invoice->id, 'wallet_request_key' => strtolower($key),
            ]);
            CommerceInvoiceItem::query()->create(['commerce_invoice_id' => $funding->id, 'kind' => 'wallet_topup',
                'snapshot' => ['target_invoice' => $invoice->public_id], 'amount' => $shortfall, 'currency' => 'IRT']);

            return $funding;
        }, 3);
    }

    /** Bank verification is already committed. Credit survives even if the reserved line is lost. */
    public function applyTopup(int $invoiceId): void
    {
        $initial = CommerceInvoice::query()->findOrFail($invoiceId);
        DB::transaction(function () use ($initial) {
            Tenant::query()->lockForUpdate()->findOrFail($initial->tenant_id);
            $invoice = CommerceInvoice::query()->lockForUpdate()->findOrFail($initial->id);
            $payment = PaymentAttempt::query()->find($invoice->paid_payment_attempt_id);
            if ($invoice->kind !== 'wallet_topup' || ! $invoice->hasValidSettlement() || ! $payment || $payment->isTestPayment()
                || $invoice->items()->sum('amount') !== $invoice->total_amount) {
                throw ValidationException::withMessages(['wallet' => 'شارژ تأییدشده واقعی برای کیف پول موجود نیست.']);
            }
            if (! WalletEntry::query()->where('commerce_invoice_id', $invoice->id)->exists()) {
                $this->entry($invoice, 'credit', $invoice->total_amount, 'شارژ کیف پول');
                app(CommerceAudit::class)->system('wallet.credited', 'commerce_invoice', $invoice->id,
                    ['tenant_id' => $invoice->tenant_id, 'amount' => $invoice->total_amount], 'Verified wallet top-up');
            }
            if ($invoice->applied_at === null) {
                $invoice->update(['applied_at' => now()]);
            }
        }, 3);
        $target = CommerceInvoice::query()->where('tenant_id', $initial->tenant_id)->find($initial->wallet_target_invoice_id);
        if ($target && $target->status === 'issued') {
            try {
                $this->payAuthorizedTarget($target);
            } catch (ValidationException) {
                // Changed/expired purchases keep their money in the wallet, with an explicit recovery message.
            } catch (\Throwable $exception) {
                Log::error('Wallet purchase completion deferred', ['invoice_id' => $target->id, 'exception_class' => $exception::class]);
            }
        } elseif (! $target) {
            $this->recoverDue();
        }
    }

    public function payAuthorizedTarget(CommerceInvoice $initial): void
    {
        DB::transaction(function () use ($initial) {
            $tenant = Tenant::query()->lockForUpdate()->findOrFail($initial->tenant_id);
            $invoice = CommerceInvoice::query()->lockForUpdate()->findOrFail($initial->id);
            $actor = Customer::query()->find($invoice->customer_id);
            if ($invoice->hasValidSettlement()) {
                return;
            }
            if (! $actor || ! $actor->canAccessTenant($tenant) || ! $actor->hasPermission(Permissions::BILLING_MANAGE)
                || ($invoice->commerce_order_id && ! $actor->hasPermission(Permissions::NUMBERS_PURCHASE))) {
                throw ValidationException::withMessages(['wallet' => 'مجوز پرداخت این خرید تغییر کرده است.']);
            }
            $authorizedTopup = CommerceInvoice::query()->where('wallet_target_invoice_id', $invoice->id)
                ->where('tenant_id', $tenant->id)->where('kind', 'wallet_topup')->where('status', 'paid')->exists();
            $automaticRenewal = $invoice->kind === 'plan_renewal' && TenantSubscription::query()
                ->whereKey($invoice->tenant_subscription_id)->where('tenant_id', $tenant->id)->where('wallet_auto_renew', true)
                ->whereNull('cancel_at')->where('period_ends_at', '<=', now())->exists();
            if (! $authorizedTopup && ! $automaticRenewal) {
                throw ValidationException::withMessages(['wallet' => 'اجازه پرداخت خودکار برای این صورتحساب ثبت نشده است.']);
            }
            $this->assertPayable($invoice);
            $this->settle($invoice);
        }, 3);
    }

    /** Used by the scheduler; only subscriptions that opted into wallet renewal are charged. */
    public function recoverDue(): int
    {
        if (! config('commerce.wallet_enabled') || ! config('commerce.checkout_enabled')) {
            return 0;
        }
        $count = 0;
        $ids = TenantSubscription::query()->where('wallet_auto_renew', true)->where('status', 'active')
            ->whereNull('cancel_at')->where('period_ends_at', '<=', now())->pluck('id');
        foreach ($ids as $id) {
            try {
                $paid = DB::transaction(function () use ($id) {
                    $initial = TenantSubscription::query()->findOrFail($id);
                    Tenant::query()->lockForUpdate()->findOrFail($initial->tenant_id);
                    $subscription = TenantSubscription::query()->lockForUpdate()->findOrFail($id);
                    if (! $subscription->wallet_auto_renew || $subscription->status !== 'active'
                        || $subscription->cancel_at !== null || ! $subscription->period_ends_at?->lte(now())) {
                        return false;
                    }
                    $invoice = app(SubscriptionBillingService::class)->issuePlan($id);
                    if (! $invoice || $invoice->status !== 'issued' || $this->funding($invoice)) {
                        return false;
                    }
                    $this->payAuthorizedTarget($invoice);

                    return true;
                }, 3);
                $count += (int) $paid;
            } catch (ValidationException) {
                // Insufficient balance remains an unpaid invoice and follows the normal reminder/grace policy.
            } catch (\Throwable $exception) {
                Log::error('Wallet renewal needs review', ['subscription_id' => $id, 'exception_class' => $exception::class]);
            }
        }

        return $count;
    }

    /** Only undelivered/definitively failed bank sessions can be replaced after expiry. */
    private function retireExpiredUnstarted(CommerceInvoice $invoice): bool
    {
        if ($invoice->status !== 'issued' || ! $invoice->expires_at?->isPast()) {
            return false;
        }
        $unresolved = PaymentAttempt::query()->where('commerce_invoice_id', $invoice->id)->get()
            ->contains(fn ($attempt) => ! in_array($attempt->status, ['initiation_failed', 'reversed'], true)
                && ! $attempt->hasUndeliveredZibalInitiation());
        if ($unresolved) {
            return false;
        }
        $invoice->update(['status' => 'void']);

        return true;
    }

    private function assertPayable(CommerceInvoice $invoice): void
    {
        if (! in_array($invoice->kind, ['proforma', 'plan_renewal', 'plan_upgrade'], true)
            || $invoice->status !== 'issued' || ($invoice->kind !== 'plan_renewal' && $invoice->expires_at?->isPast()) || $invoice->paid_payment_attempt_id !== null
            || $invoice->total_amount < 1 || $invoice->total_amount > 1000000000000 || $invoice->currency !== 'IRT'
            || $invoice->items()->sum('amount') !== $invoice->total_amount) {
            throw ValidationException::withMessages(['wallet' => 'این خرید دیگر قابل پرداخت نیست؛ موجودی کیف پول شما محفوظ است.']);
        }
        $pending = PaymentAttempt::query()->where('commerce_invoice_id', $invoice->id)
            ->whereNotIn('status', ['initiation_failed', 'reversed'])->get()
            ->contains(fn ($attempt) => ! $attempt->hasUndeliveredZibalInitiation());
        if ($pending) {
            throw ValidationException::withMessages(['wallet' => 'نتیجه پرداخت قبلی در حال بررسی است؛ پرداخت دوباره لازم نیست.']);
        }
        if ($invoice->commerce_order_id) {
            $order = CommerceOrder::query()->findOrFail($invoice->commerce_order_id);
            if ($order->tenant_id !== $invoice->tenant_id || $order->status !== 'reserved'
                || $order->item->billing_model !== 'one_time_number') {
                throw ValidationException::withMessages(['wallet' => 'این سفارش برای پرداخت از کیف پول معتبر نیست.']);
            }
        } elseif ($invoice->tenant_subscription_id) {
            $subscription = TenantSubscription::query()->findOrFail($invoice->tenant_subscription_id);
            if ($subscription->tenant_id !== $invoice->tenant_id || ! $subscription->acceptsRenewal() || $subscription->status !== 'active') {
                throw ValidationException::withMessages(['wallet' => 'وضعیت اشتراک تغییر کرده است؛ مبلغی کسر نشد.']);
            }
        }
    }

    private function settle(CommerceInvoice $invoice): void
    {
        if ($this->balance($invoice->tenant_id) < $invoice->total_amount) {
            throw ValidationException::withMessages(['wallet' => 'موجودی کیف پول کافی نیست؛ مبلغ موردنیاز را دوباره بررسی کنید.']);
        }
        $description = match ($invoice->kind) {
            'plan_renewal' => 'تمدید پلن ماهانه', 'plan_upgrade' => 'ارتقای پلن', default => 'خرید شماره و پلن',
        };
        $this->entry($invoice, 'debit', -$invoice->total_amount, $description);
        $invoice->update(['status' => 'paid', 'paid_at' => now()]);
        if ($invoice->commerce_order_id) {
            $order = CommerceOrder::query()->findOrFail($invoice->commerce_order_id);
            $order->update(['status' => 'paid_pending_allocation']);
            if (! app(PaidOrderAllocationService::class)->allocate($order->id)) {
                throw ValidationException::withMessages(['wallet' => 'شماره دیگر برای این خرید آماده نیست؛ موجودی کیف پول شما محفوظ است.']);
            }
        } else {
            app(SubscriptionBillingService::class)->applyPaid($invoice->id);
        }
        app(CommerceAudit::class)->system('wallet.debited', 'commerce_invoice', $invoice->id,
            ['tenant_id' => $invoice->tenant_id, 'amount' => $invoice->total_amount], 'Wallet invoice settlement');
    }

    private function entry(CommerceInvoice $invoice, string $kind, int $amount, string $description): WalletEntry
    {
        return WalletEntry::query()->create(['public_id' => (string) Str::uuid(), 'tenant_id' => $invoice->tenant_id,
            'commerce_invoice_id' => $invoice->id, 'kind' => $kind, 'amount' => $amount,
            'balance_after' => $this->balance($invoice->tenant_id) + $amount, 'description' => $description]);
    }
}
