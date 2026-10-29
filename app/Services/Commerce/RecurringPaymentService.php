<?php

namespace App\Services\Commerce;

use App\Contracts\MellatClient;
use App\Exceptions\PaymentTransportException;
use App\Models\CommerceInvoice;
use App\Models\Customer;
use App\Models\NumberTerm;
use App\Models\PaymentAttempt;
use App\Models\PaymentEvent;
use App\Models\PaymentGateway;
use App\Models\PaymentGatewayVersion;
use App\Models\PlanVersion;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Payment transport for invoices that have no number reservation. */
class RecurringPaymentService
{
    public function __construct(private MellatClient $mellat, private SubscriptionBillingService $billing, private CommerceAudit $audit) {}

    public function reconcile(User $actor, int $attemptId, string $reason): PaymentAttempt
    {
        $actor = User::query()->findOrFail($actor->id);
        abort_unless($actor->isAdmin() && ! $actor->isDisabled(), 403);
        validator(['reason' => $reason], ['reason' => ['required', 'string', 'min:10', 'max:1000']])->validate();
        $attempt = PaymentAttempt::query()->findOrFail($attemptId);
        $invoice = CommerceInvoice::query()->findOrFail($attempt->commerce_invoice_id);
        abort_unless($invoice->commerce_order_id === null, 404);
        $this->audit->record($actor, 'billing.payment_reconciliation', 'payment_attempt', $attempt->id,
            $reason, ['invoice_id' => $invoice->id]);
        if ($attempt->status === 'settled') {
            $this->applyIfPaid($attempt);

            return $attempt->fresh();
        }
        if ($attempt->provider === 'zibal' && $attempt->ref_id) {
            return $this->callback($attempt->public_id, ['trackId' => $attempt->ref_id, 'success' => '1']);
        }
        $reference = $attempt->candidate_sale_reference ?? $attempt->sale_reference;
        if ($attempt->provider === 'mellat' && $attempt->ref_id && $reference) {
            return $this->callback($attempt->public_id, [
                'RefId' => $attempt->ref_id, 'ResCode' => '0', 'SaleOrderId' => (string) $attempt->id,
                'SaleReferenceId' => $reference,
            ]);
        }
        throw ValidationException::withMessages(['payment' => 'مرجع بانکی کافی برای استعلام این تلاش موجود نیست.']);
    }

    public function initiate(Customer $actor, string $invoiceId, string $key, ?bool $expectedTest = null): PaymentAttempt
    {
        abort_unless(config('commerce.checkout_enabled'), 404);
        validator(['key' => $key], ['key' => ['required', 'uuid']])->validate();
        [$attempt, $token] = DB::transaction(function () use ($actor, $invoiceId, $key, $expectedTest) {
            $tenant = Tenant::query()->lockForUpdate()->find($actor->tenant_id);
            $actor = Customer::query()->lockForUpdate()->findOrFail($actor->id);
            abort_unless($tenant && $actor->canAccessTenant($tenant)
                && $actor->permissions()->where('permission', Permissions::BILLING_MANAGE)->exists(), 403);
            $invoice = CommerceInvoice::query()->where('public_id', $invoiceId)->where('tenant_id', $tenant->id)->lockForUpdate()->firstOrFail();
            abort_unless($invoice->commerce_order_id === null && in_array($invoice->kind, ['plan_renewal', 'number_renewal', 'plan_upgrade', 'wallet_topup'], true), 404);
            $existing = PaymentAttempt::query()->where('commerce_invoice_id', $invoice->id)->where('idempotency_key', strtolower($key))->lockForUpdate()->first();
            if ($existing) {
                return [$existing, null];
            }
            if ($invoice->status !== 'issued' || $invoice->paid_payment_attempt_id !== null || ($invoice->kind !== 'plan_renewal' && $invoice->expires_at->isPast())
                || $invoice->items()->sum('amount') !== $invoice->total_amount || $invoice->total_amount < 1
                || $invoice->total_amount > intdiv(PHP_INT_MAX, 10)) {
                throw ValidationException::withMessages(['billing' => 'صورتحساب برای پرداخت معتبر نیست.']);
            }
            if ($invoice->kind === 'plan_renewal' && $invoice->tenant_subscription_id) {
                $subscription = TenantSubscription::query()->findOrFail($invoice->tenant_subscription_id);
                if (! $subscription->period_ends_at?->equalTo($invoice->period_starts_at)
                    || ! $subscription->acceptsRenewal()) {
                    throw ValidationException::withMessages(['billing' => 'دوره پلن تغییر کرده است؛ صورتحساب را تازه کنید.']);
                }
                $target = PlanVersion::query()->findOrFail($invoice->plan_version_id);
                if ($target->id !== $subscription->plan_version_id && ! $this->billing->fits($tenant, $target)) {
                    throw ValidationException::withMessages(['billing' => 'ظرفیت پلن جدید برای منابع فعلی کافی نیست.']);
                }
            }
            if ($invoice->kind === 'plan_upgrade') {
                $subscription = TenantSubscription::query()->findOrFail($invoice->tenant_subscription_id);
                if ($subscription->plan_version_id !== ($invoice->item?->snapshot['from_version_id'] ?? null)
                    || ! $subscription->period_ends_at?->isFuture() || $subscription->cancel_at !== null) {
                    throw ValidationException::withMessages(['billing' => 'فرصت این ارتقا پایان یافته است؛ دوباره پلن را انتخاب کنید.']);
                }
            }
            if ($invoice->kind === 'number_renewal') {
                $term = NumberTerm::query()->with('assignment')->findOrFail($invoice->number_term_id);
                if (! $term->period_ends_at?->equalTo($invoice->period_starts_at)
                    || ! $term->assignment || $term->assignment->released_at !== null || $term->status !== 'active') {
                    throw ValidationException::withMessages(['billing' => 'مالکیت یا دوره شماره تغییر کرده است.']);
                }
            }
            if ($invoice->current_payment_attempt_id) {
                $current = PaymentAttempt::query()->lockForUpdate()->findOrFail($invoice->current_payment_attempt_id);
                if (! in_array($current->status, ['initiation_failed', 'reversed'], true) && ! $current->hasUndeliveredZibalInitiation()) {
                    return [$current, null];
                }
            }
            $gateway = PaymentGateway::query()->where('active', true)->with('currentVersion')->lockForUpdate()->firstOrFail();
            $account = $gateway->currentVersion;
            if (! $gateway->enabled || ! $account || ! $account->amount_unit_confirmed || $account->gateway_unit !== 'IRR'
                || ! in_array($gateway->provider, ['mellat', 'zibal'], true)
                || ($gateway->provider === 'zibal' && $account->zibalMerchant() === '')
                || ($invoice->kind === 'wallet_topup' && $account->isTest())
                || ($expectedTest !== null && $expectedTest !== $account->isTest())) {
                throw ValidationException::withMessages(['billing' => 'درگاه پرداخت فعال و آماده نیست.']);
            }
            $token = (string) Str::uuid();
            $attempt = PaymentAttempt::query()->create([
                'public_id' => (string) Str::uuid(), 'commerce_invoice_id' => $invoice->id, 'idempotency_key' => strtolower($key),
                'provider' => $gateway->provider, 'account_key' => $account->account_key,
                'payment_gateway_version_id' => $account->id, 'business_amount' => $invoice->total_amount,
                'business_currency' => 'IRT', 'gateway_amount' => $invoice->total_amount * 10, 'gateway_unit' => 'IRR',
                'status' => 'initiating', 'is_test' => $account->isTest(), 'operation_token' => $token,
                'operation_expires_at' => now()->addMinutes(2),
            ]);
            $invoice->update(['current_payment_attempt_id' => $attempt->id]);
            $this->event($attempt, 'initiation.started');

            return [$attempt, $token];
        }, 3);
        if ($token === null) {
            $this->applyIfPaid($attempt);

            return $attempt;
        }
        try {
            $account = $this->account($attempt);
            if ($attempt->provider === 'zibal') {
                $response = Http::acceptJson()->asJson()->connectTimeout(5)->timeout(20)->post('https://gateway.zibal.ir/v1/request', [
                    'merchant' => $account->zibalMerchant(), 'amount' => $attempt->gateway_amount,
                    'callbackUrl' => $this->callbackUrl($attempt), 'description' => 'صورتحساب بلوکام '.(string) $attempt->id,
                    'orderId' => $attempt->id,
                ]);
                $body = $response->successful() ? $response->json() : [];
                if (($body['result'] ?? null) === 100 && preg_match('/^[1-9][0-9]{0,18}$/D', (string) ($body['trackId'] ?? ''))) {
                    return $this->finish($attempt, $token, 'redirect_ready', ['ref_id' => (string) $body['trackId']]);
                }

                return $this->finish($attempt, $token, 'initiation_failed');
            }
            $local = now()->timezone('Asia/Tehran');
            $response = $this->mellat->call($account, 'bpPayRequest', [
                'orderId' => $attempt->id, 'amount' => $attempt->gateway_amount,
                'localDate' => $local->format('Ymd'), 'localTime' => $local->format('His'),
                'additionalData' => '', 'callBackUrl' => $this->callbackUrl($attempt), 'payerId' => 0,
            ]);
            $parts = explode(',', $response);

            return count($parts) === 2 && $parts[0] === '0' && preg_match('/^[A-Za-z0-9]{1,100}$/D', $parts[1])
                ? $this->finish($attempt, $token, 'redirect_ready', ['ref_id' => $parts[1]])
                : $this->finish($attempt, $token, 'unknown');
        } catch (\Throwable $exception) {
            Log::warning('Recurring payment initiation needs review', ['attempt_id' => $attempt->id, 'exception_class' => $exception::class]);

            return $this->finish($attempt, $token, 'unknown');
        }
    }

    public function callback(string $publicId, array $payload): PaymentAttempt
    {
        $attempt = PaymentAttempt::query()->where('public_id', $publicId)->firstOrFail();
        $invoice = CommerceInvoice::query()->findOrFail($attempt->commerce_invoice_id);
        abort_unless($invoice->commerce_order_id === null, 404);
        if ($attempt->provider === 'zibal') {
            validator($payload, ['trackId' => ['required', 'string', 'regex:/^[1-9][0-9]{0,18}$/D']])->validate();
            if (! $attempt->ref_id || ! hash_equals($attempt->ref_id, $payload['trackId'])) {
                throw ValidationException::withMessages(['billing' => 'شناسه پرداخت نامعتبر است.']);
            }
            if (! in_array($payload['success'] ?? null, ['1', 1, true], true)) {
                return $attempt;
            }
        } else {
            if (! isset($payload['SaleOrderId']) && isset($payload['saleOrderId'])) {
                $payload['SaleOrderId'] = $payload['saleOrderId'];
            }
            validator($payload, ['RefId' => ['required', 'string'], 'ResCode' => ['required', 'string'],
                'SaleOrderId' => ['required', 'string']])->validate();
            if (! $attempt->ref_id || ! hash_equals($attempt->ref_id, $payload['RefId'])
                || $payload['SaleOrderId'] !== (string) $attempt->id) {
                throw ValidationException::withMessages(['billing' => 'شناسه پرداخت نامعتبر است.']);
            }
            if ($payload['ResCode'] !== '0') {
                return $attempt;
            }
            validator($payload, ['SaleReferenceId' => ['required', 'string', 'regex:/^[1-9][0-9]{0,18}$/D']])->validate();
        }
        if ($attempt->status === 'settled') {
            $this->applyIfPaid($attempt);

            return $attempt;
        }
        [$attempt, $token] = DB::transaction(function () use ($attempt, $payload) {
            $invoice = CommerceInvoice::query()->lockForUpdate()->findOrFail($attempt->commerce_invoice_id);
            $attempt = PaymentAttempt::query()->lockForUpdate()->findOrFail($attempt->id);
            if ($invoice->paid_payment_attempt_id !== null || $attempt->status === 'settled'
                || ($attempt->operation_token && $attempt->operation_expires_at?->isFuture())) {
                return [$attempt, null];
            }
            $token = (string) Str::uuid();
            $attempt->update(['status' => 'verifying',
                'candidate_sale_reference' => $attempt->provider === 'mellat' ? $payload['SaleReferenceId'] : $attempt->candidate_sale_reference,
                'operation_token' => $token, 'operation_expires_at' => now()->addMinutes(2)]);

            return [$attempt, $token];
        }, 3);
        if ($token === null) {
            $this->applyIfPaid($attempt);

            return $attempt;
        }
        try {
            $account = $this->account($attempt);
            if ($attempt->provider === 'zibal') {
                $response = Http::acceptJson()->asJson()->timeout(12)->post('https://gateway.zibal.ir/v1/verify', [
                    'merchant' => $account->zibalMerchant(), 'trackId' => (int) $attempt->ref_id,
                ]);
                if (! $response->successful()) {
                    throw new PaymentTransportException('Zibal verification failed.');
                }
                $body = $response->json();
                if (! in_array($body['result'] ?? null, [100, 201], true)
                    || (int) ($body['amount'] ?? 0) !== $attempt->gateway_amount
                    || (isset($body['orderId']) && (string) $body['orderId'] !== (string) $attempt->id)) {
                    return $this->finish($attempt, $token, 'unknown');
                }
                $reference = isset($body['refNumber']) && preg_match('/^[1-9][0-9]{0,18}$/D', (string) $body['refNumber'])
                    ? (string) $body['refNumber'] : $attempt->ref_id;
            } else {
                $reference = $payload['SaleReferenceId'];
                if ($attempt->sale_reference !== null && $attempt->sale_reference !== $reference) {
                    throw new PaymentTransportException('Mellat reference mismatch.');
                }
                $fields = ['orderId' => $attempt->id, 'saleOrderId' => $attempt->id, 'saleReferenceId' => $reference];
                $verified = $this->mellat->call($account, 'bpVerifyRequest', $fields);
                if (! in_array($verified, ['0', '43', '45'], true)) {
                    return $this->finish($attempt, $token, 'unknown');
                }
                $settled = $this->mellat->call($account, 'bpSettleRequest', $fields);
                if (! in_array($settled, ['0', '45'], true)
                    && $this->mellat->call($account, 'bpInquiryRequest', $fields) !== '45') {
                    return $this->finish($attempt, $token, 'pending_settlement');
                }
            }
            $result = DB::transaction(function () use ($attempt, $token, $reference) {
                $invoice = CommerceInvoice::query()->lockForUpdate()->findOrFail($attempt->commerce_invoice_id);
                $locked = PaymentAttempt::query()->lockForUpdate()->findOrFail($attempt->id);
                if ($locked->operation_token !== $token) {
                    return $locked;
                }
                $this->account($locked);
                $duplicate = $invoice->paid_payment_attempt_id !== null;
                $locked->update(['sale_reference' => $reference, 'verified_at' => $locked->verified_at ?? now(),
                    'settled_at' => $locked->settled_at ?? now(), 'status' => $duplicate ? 'duplicate_payment' : 'settled',
                    'operation_token' => null, 'operation_expires_at' => null]);
                if (! $duplicate) {
                    $invoice->update(['status' => 'paid', 'paid_payment_attempt_id' => $locked->id, 'paid_at' => now()]);
                }
                $this->event($locked, $duplicate ? 'settlement.duplicate' : 'settlement.confirmed');

                return $locked;
            }, 3);
            $this->applyIfPaid($result);

            return $result;
        } catch (\Throwable $exception) {
            Log::warning('Recurring payment verification needs review', ['attempt_id' => $attempt->id, 'exception_class' => $exception::class]);

            return $this->finish($attempt, $token, 'unknown');
        }
    }

    public function callbackUrl(PaymentAttempt $attempt): string
    {
        $provider = $attempt->provider === 'zibal' ? 'zibal' : 'mellat';

        return 'https://'.config('portal.customer_domain').'/payments/'.$provider.'/callback/'.$attempt->public_id;
    }

    private function account(PaymentAttempt $attempt): PaymentGatewayVersion
    {
        $account = PaymentGatewayVersion::query()->findOrFail($attempt->payment_gateway_version_id);
        $invoice = CommerceInvoice::query()->findOrFail($attempt->commerce_invoice_id);
        if (PaymentGateway::query()->findOrFail($account->payment_gateway_id)->provider !== $attempt->provider
            || $account->account_key !== $attempt->account_key || ! $account->amount_unit_confirmed || $account->gateway_unit !== 'IRR'
            || $attempt->business_currency !== 'IRT' || $attempt->gateway_unit !== 'IRR'
            || $attempt->business_amount !== $invoice->total_amount || $attempt->gateway_amount !== $invoice->total_amount * 10
            || $invoice->commerce_order_id !== null || $invoice->currency !== 'IRT') {
            throw new PaymentTransportException('Recurring payment account/amount mismatch.');
        }

        return $account;
    }

    private function finish(PaymentAttempt $attempt, string $token, string $status, array $extra = []): PaymentAttempt
    {
        return DB::transaction(function () use ($attempt, $token, $status, $extra) {
            $invoice = CommerceInvoice::query()->lockForUpdate()->findOrFail($attempt->commerce_invoice_id);
            $locked = PaymentAttempt::query()->lockForUpdate()->findOrFail($attempt->id);
            if ($locked->operation_token !== $token || $invoice->paid_payment_attempt_id !== null) {
                return $locked;
            }
            $locked->update([...$extra, 'status' => $status, 'operation_token' => null, 'operation_expires_at' => null]);
            $this->event($locked, 'operation.'.$status);

            return $locked;
        }, 3);
    }

    private function applyIfPaid(PaymentAttempt $attempt): void
    {
        if ($attempt->status !== 'settled') {
            return;
        }
        try {
            $invoice = CommerceInvoice::query()->findOrFail($attempt->commerce_invoice_id);
            if ($invoice->kind === 'wallet_topup') {
                app(WalletService::class)->applyTopup($invoice->id);
            } else {
                $this->billing->applyPaid($invoice->id);
            }
        } catch (\Throwable $exception) {
            Log::error('Paid recurring invoice application deferred', ['invoice_id' => $attempt->commerce_invoice_id,
                'exception_class' => $exception::class]);
        }
    }

    private function event(PaymentAttempt $attempt, string $type): void
    {
        PaymentEvent::query()->create(['payment_attempt_id' => $attempt->id, 'event_key' => (string) Str::uuid(),
            'type' => $type, 'evidence' => [], 'created_at' => now()]);
    }
}
