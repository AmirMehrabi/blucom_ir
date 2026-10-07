<?php

namespace App\Services\Commerce;

use App\Contracts\MellatClient;
use App\Exceptions\PaymentTransportException;
use App\Models\CommerceInvoice;
use App\Models\CommerceOrder;
use App\Models\Customer;
use App\Models\NumberOffer;
use App\Models\NumberReservation;
use App\Models\PaymentAttempt;
use App\Models\PaymentEvent;
use App\Models\PaymentGateway;
use App\Models\PaymentGatewayVersion;
use App\Models\Plan;
use App\Models\PlanVersion;
use App\Models\SipGateway;
use App\Models\SipNumber;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MellatPaymentService
{
    public function __construct(private MellatClient $client, private CommerceAudit $audit) {}

    public function initiate(Customer $actor, string $invoiceId, string $key): PaymentAttempt
    {
        abort_unless(config('commerce.checkout_enabled'), 404);
        validator(['key' => $key], ['key' => ['required', 'uuid']])->validate();
        $key = strtolower($key);
        $work = DB::transaction(function () use ($actor, $invoiceId, $key) {
            $tenant = Tenant::query()->lockForUpdate()->find($actor->tenant_id);
            $actor = Customer::query()->lockForUpdate()->findOrFail($actor->id);
            abort_unless($tenant !== null && $actor->canAccessTenant($tenant)
                && $actor->permissions()->where('permission', Permissions::NUMBERS_PURCHASE)->exists()
                && $actor->permissions()->where('permission', Permissions::BILLING_MANAGE)->exists(), 403);
            $initial = CommerceInvoice::query()->where('public_id', $invoiceId)->where('tenant_id', $tenant->id)->firstOrFail();
            $gateway = PaymentGateway::query()->where('provider', 'mellat')->lockForUpdate()->firstOrFail();
            $item = CommerceOrder::query()->findOrFail($initial->commerce_order_id)->item;
            $versionInitial = PlanVersion::query()->findOrFail($item->plan_version_id);
            $plan = Plan::query()->lockForUpdate()->findOrFail($versionInitial->plan_id);
            $planVersion = PlanVersion::query()->lockForUpdate()->findOrFail($item->plan_version_id);
            $sipGateway = SipGateway::query()->lockForUpdate()->find(SipNumber::query()->findOrFail($item->sip_number_id)->provider_gateway_id);
            [$number, $reservation, $order, $invoice] = $this->context($initial->id);
            $existing = PaymentAttempt::query()->where('commerce_invoice_id', $invoice->id)->where('idempotency_key', $key)->lockForUpdate()->first();
            if ($existing !== null) {
                return [$existing, null];
            }
            if ($invoice->paid_payment_attempt_id !== null) {
                throw ValidationException::withMessages(['payment' => 'این پیش‌فاکتور قبلاً پرداخت شده است.']);
            }
            $offer = NumberOffer::query()->lockForUpdate()->findOrFail($item->number_offer_id);
            $reviewNumber = clone $number;
            $reviewNumber->inventory_state = 'available';
            $reviewNumber->current_reservation_id = null;
            if ($number->current_offer_id !== $offer->id || $offer->withdrawn_at !== null || $offer->published_at === null
                || $offer->plan_version_id !== $planVersion->id || $offer->monthly_amount !== $invoice->total_amount
                || $plan->archived || $planVersion->published_at === null
                || app(NumberReadinessService::class)->issues($reviewNumber, $sipGateway) !== []) {
                throw ValidationException::withMessages(['payment' => 'پیشنهاد یا آمادگی فنی شماره تغییر کرده است.']);
            }
            if (! $this->liveHold($number, $reservation, $order) || $invoice->status !== 'issued'
                || $order->status !== 'reserved' || $order->currency !== 'IRT' || $invoice->currency !== 'IRT'
                || $invoice->total_amount !== $order->total_amount || $invoice->total_amount < 1
                || $invoice->total_amount > intdiv(PHP_INT_MAX, 10)) {
                throw ValidationException::withMessages(['payment' => 'رزرو معتبر نیست؛ وضعیت سفارش را بررسی کنید.']);
            }
            if ($invoice->current_payment_attempt_id !== null) {
                $current = PaymentAttempt::query()->lockForUpdate()->findOrFail($invoice->current_payment_attempt_id);
                if (! in_array($current->status, ['initiation_failed', 'reversed'], true)) {
                    // A different browser key cannot start a second charge for an unresolved attempt.
                    return [$current, null];
                }
            }
            $account = $gateway->currentVersion;
            if (! $gateway->enabled || $account === null || ! $account->amount_unit_confirmed || $account->gateway_unit !== 'IRR') {
                throw ValidationException::withMessages(['payment' => 'درگاه پرداخت فعال و آماده نیست.']);
            }
            $token = (string) Str::uuid();
            $attempt = PaymentAttempt::query()->create([
                'public_id' => (string) Str::uuid(), 'commerce_invoice_id' => $invoice->id, 'idempotency_key' => $key,
                'provider' => 'mellat', 'account_key' => $account->account_key, 'payment_gateway_version_id' => $account->id,
                'business_amount' => $invoice->total_amount, 'business_currency' => 'IRT',
                'gateway_amount' => $invoice->total_amount * 10, 'gateway_unit' => 'IRR', 'status' => 'initiating',
                'operation_token' => $token, 'operation_expires_at' => now()->addMinutes(2),
            ]);
            $invoice->update(['current_payment_attempt_id' => $attempt->id]);
            $this->event($attempt, 'initiation.started');

            return [$attempt, $token];
        }, 3);
        [$attempt, $token] = $work;
        if ($token === null) {
            return $attempt;
        }
        try {
            $account = $this->account($attempt);
            $localTime = now()->timezone('Asia/Tehran');
            $response = $this->client->call($account, 'bpPayRequest', [
                'orderId' => $attempt->id, 'amount' => $attempt->gateway_amount,
                'localDate' => $localTime->format('Ymd'), 'localTime' => $localTime->format('His'),
                'additionalData' => '', 'callBackUrl' => $this->callbackUrl($attempt), 'payerId' => 0,
            ]);
            $parts = explode(',', $response);
            $code = $this->code($parts[0]);
            if ($code === '0' && count($parts) === 2 && preg_match('/^[A-Za-z0-9]{1,100}$/D', $parts[1])) {
                return $this->finish($attempt, $token, 'redirect_ready', $code, ['ref_id' => $parts[1]]);
            }
            $state = in_array($code, ['21', '23', '24', '25', '32', '33', '35', '62', '421'], true) ? 'initiation_failed' : 'unknown';

            return $this->finish($attempt, $token, $state, $code);
        } catch (PaymentTransportException|QueryException) {
            return $this->finish($attempt, $token, 'unknown');
        }
    }

    public function callback(string $publicId, array $payload): PaymentAttempt
    {
        $attempt = PaymentAttempt::query()->where('public_id', $publicId)->where('provider', 'mellat')->firstOrFail();
        $saleOrder = $payload['SaleOrderId'] ?? $payload['saleOrderId'] ?? null;
        validator(['RefId' => $payload['RefId'] ?? null, 'ResCode' => $payload['ResCode'] ?? null, 'SaleOrderId' => $saleOrder], [
            'RefId' => ['required', 'string', 'regex:/^[A-Za-z0-9]{1,100}$/D'],
            'ResCode' => ['required', 'string', 'regex:/^[0-9]{1,4}$/D'],
            'SaleOrderId' => ['required', 'string', 'regex:/^[1-9][0-9]{0,18}$/D'],
        ])->validate();
        if ($attempt->ref_id === null || ! hash_equals($attempt->ref_id, $payload['RefId']) || $saleOrder !== (string) $attempt->id
            || (isset($payload['SaleOrderId'], $payload['saleOrderId']) && $payload['SaleOrderId'] !== $payload['saleOrderId'])) {
            throw ValidationException::withMessages(['payment' => 'اطلاعات بازگشت درگاه معتبر نیست.']);
        }
        if ($payload['ResCode'] !== '0') {
            // Browser cancellation/error is untrusted and cannot invalidate a genuine payment.
            $this->event($attempt, 'callback.reported_failure', $payload['ResCode']);

            return $attempt;
        }
        $reference = $payload['SaleReferenceId'] ?? null;
        validator(['reference' => $reference], ['reference' => ['required', 'string', 'regex:/^[1-9][0-9]{0,18}$/D']])->validate();
        if (strlen($reference) === 19 && strcmp($reference, (string) PHP_INT_MAX) > 0) {
            throw ValidationException::withMessages(['payment' => 'مرجع درگاه معتبر نیست.']);
        }

        return $this->verify($attempt, $reference);
    }

    public function reconcile(User $actor, int $id, string $reason): PaymentAttempt
    {
        abort_unless($actor->isAdmin() && ! $actor->isDisabled(), 403);
        validator(['reason' => $reason], ['reason' => ['required', 'string', 'max:1000']])->validate();
        $attempt = PaymentAttempt::query()->where('provider', 'mellat')->findOrFail($id);
        $this->audit->record($actor, 'payment.reconciliation_requested', 'payment_attempt', $id, $reason);
        $reference = $attempt->sale_reference ?? $attempt->candidate_sale_reference;
        if ($attempt->ref_id === null || $reference === null) {
            throw ValidationException::withMessages(['payment' => 'مرجع بانکی قابل استعلام موجود نیست؛ بررسی پذیرنده لازم است. درخواست پرداخت دوباره ارسال نمی‌شود.']);
        }

        return $this->verify($attempt, $reference);
    }

    public function callbackUrl(PaymentAttempt $attempt): string
    {
        return 'https://'.config('portal.customer_domain').'/payments/mellat/callback/'.$attempt->public_id;
    }

    /** Explicit admin reversal is limited to a provider-confirmed, verified, unsettled attempt. */
    public function reverse(User $actor, int $id, string $reason): PaymentAttempt
    {
        abort_unless($actor->isAdmin() && ! $actor->isDisabled(), 403);
        validator(['reason' => $reason], ['reason' => ['required', 'string', 'max:1000']])->validate();
        $initial = PaymentAttempt::query()->where('provider', 'mellat')->findOrFail($id);
        [$attempt, $token] = DB::transaction(function () use ($initial, $actor, $reason) {
            [, , , $invoice] = $this->context($initial->commerce_invoice_id);
            $attempt = PaymentAttempt::query()->lockForUpdate()->findOrFail($initial->id);
            if ($invoice->paid_payment_attempt_id !== null || $attempt->settled_at !== null || $attempt->verified_at === null
                || $attempt->sale_reference === null || $attempt->status === 'reversed'
                || ($attempt->operation_token !== null && $attempt->operation_expires_at?->isFuture())) {
                throw ValidationException::withMessages(['payment' => 'برگشت فقط برای پرداخت تأییدشده و تسویه‌نشده بدون عملیات هم‌زمان مجاز است.']);
            }
            $token = (string) Str::uuid();
            $attempt->update(['status' => 'reversing', 'reversal_requested' => true, 'operation_token' => $token, 'operation_expires_at' => now()->addMinutes(2)]);
            $this->audit->record($actor, 'payment.reversal_requested', 'payment_attempt', $attempt->id, $reason);
            $this->event($attempt, 'reversal.started');

            return [$attempt, $token];
        }, 3);
        try {
            $account = $this->account($attempt);
            $fields = ['orderId' => $attempt->id, 'saleOrderId' => $attempt->id, 'saleReferenceId' => $attempt->sale_reference];
            $inquiry = $this->code($this->client->call($account, 'bpInquiryRequest', $fields));
            if ($inquiry === '45') {
                return $this->settled($attempt, $token, $attempt->sale_reference);
            }
            if ($inquiry === '48') {
                return $this->finish($attempt, $token, 'reversed', $inquiry);
            }
            if ($inquiry !== '46') {
                // Inquiry success/uncertainty is never permission to reverse a possibly settled payment.
                return $this->finish($attempt, $token, 'pending_settlement', $inquiry);
            }
            if (! $this->renewReversal($attempt, $token)) {
                return $attempt->fresh();
            }
            $code = $this->code($this->client->call($account, 'bpReversalRequest', $fields));

            return $this->finish($attempt, $token, in_array($code, ['0', '48'], true) ? 'reversed' : 'unknown', $code);
        } catch (PaymentTransportException|QueryException) {
            return $this->finish($attempt, $token, 'unknown');
        }
    }

    private function verify(PaymentAttempt $initial, string $reference): PaymentAttempt
    {
        [$attempt, $token] = DB::transaction(function () use ($initial, $reference) {
            $this->context($initial->commerce_invoice_id);
            $attempt = PaymentAttempt::query()->lockForUpdate()->findOrFail($initial->id);
            if ($attempt->sale_reference !== null && $attempt->sale_reference !== $reference) {
                throw ValidationException::withMessages(['payment' => 'مرجع پرداخت با سابقه تأییدشده مطابقت ندارد.']);
            }
            if (in_array($attempt->status, ['settled', 'duplicate_payment', 'reversed'], true)
                || ($attempt->operation_token !== null && $attempt->operation_expires_at?->isFuture())) {
                return [$attempt, null];
            }
            if ($attempt->candidate_sale_reference !== null && $attempt->candidate_sale_reference !== $reference
                && $attempt->status !== 'redirect_ready') {
                throw ValidationException::withMessages(['payment' => 'مرجع قبلی هنوز نیاز به بررسی بانکی دارد.']);
            }
            $token = (string) Str::uuid();
            $attempt->update([
                'status' => 'verifying', 'candidate_sale_reference' => $reference,
                'operation_token' => $token, 'operation_expires_at' => now()->addMinutes(2),
            ]);
            $this->event($attempt, 'verification.started');

            return [$attempt, $token];
        }, 3);
        if ($token === null) {
            return $attempt;
        }
        try {
            $account = $this->account($attempt);
            $fields = ['orderId' => $attempt->id, 'saleOrderId' => $attempt->id, 'saleReferenceId' => $reference];
            if ($attempt->reversal_requested) {
                // A reversal timeout must never turn a reconciliation retry into a settlement request.
                $inquiry = $this->code($this->client->call($account, 'bpInquiryRequest', $fields));
                if ($inquiry === '45') {
                    return $this->settled($attempt, $token, $reference);
                }

                return $this->finish($attempt, $token, $inquiry === '48' ? 'reversed' : 'unknown', $inquiry);
            }
            if ($attempt->verified_at === null) {
                $code = $this->code($this->client->call($account, 'bpVerifyRequest', $fields));
                if ($code === '43' || $code === '45') {
                    $inquiry = $this->code($this->client->call($account, 'bpInquiryRequest', $fields));
                    if ($inquiry === '45') {
                        return $this->settled($attempt, $token, $reference);
                    }
                    if (! in_array($inquiry, ['0', '46'], true)) {
                        return $this->finish($attempt, $token, $inquiry === '48' ? 'reversed' : 'unknown', $inquiry);
                    }
                } elseif ($code !== '0') {
                    // No callback-derived identifier becomes authoritative after a failed verify.
                    return $this->finish($attempt, $token, in_array($code, ['42', '54', '55'], true) ? 'redirect_ready' : 'unknown', $code,
                        in_array($code, ['42', '54', '55'], true) ? ['candidate_sale_reference' => null] : []);
                }
            } else {
                $inquiry = $this->code($this->client->call($account, 'bpInquiryRequest', $fields));
                if ($inquiry === '45') {
                    return $this->settled($attempt, $token, $reference);
                }
                if (! in_array($inquiry, ['0', '46'], true)) {
                    return $this->finish($attempt, $token, $inquiry === '48' ? 'reversed' : 'pending_settlement', $inquiry);
                }
            }
            $progress = $this->progress($attempt, $token, $reference);
            if (! $progress) {
                return $attempt->fresh();
            }
            $settle = $this->code($this->client->call($account, 'bpSettleRequest', $fields));
            if (in_array($settle, ['0', '45'], true)) {
                return $this->settled($attempt, $token, $reference);
            }
            $inquiry = $this->code($this->client->call($account, 'bpInquiryRequest', $fields));
            if ($inquiry === '45') {
                return $this->settled($attempt, $token, $reference);
            }

            return $this->finish($attempt, $token, $inquiry === '48' ? 'reversed' : 'pending_settlement', $inquiry);
        } catch (PaymentTransportException|QueryException) {
            return $this->finish($attempt, $token, $attempt->fresh()->verified_at === null ? 'unknown' : 'pending_settlement');
        }
    }

    private function renewReversal(PaymentAttempt $initial, string $token): bool
    {
        return DB::transaction(function () use ($initial, $token) {
            [, , , $invoice] = $this->context($initial->commerce_invoice_id);
            $attempt = PaymentAttempt::query()->lockForUpdate()->findOrFail($initial->id);
            if ($attempt->operation_token !== $token || $attempt->status !== 'reversing'
                || $invoice->paid_payment_attempt_id !== null || $attempt->settled_at !== null) {
                return false;
            }
            $attempt->update(['operation_expires_at' => now()->addMinutes(2)]);

            return true;
        }, 3);
    }

    private function progress(PaymentAttempt $initial, string $token, string $reference): bool
    {
        return DB::transaction(function () use ($initial, $token, $reference) {
            $this->context($initial->commerce_invoice_id);
            $attempt = PaymentAttempt::query()->lockForUpdate()->findOrFail($initial->id);
            if ($attempt->operation_token !== $token) {
                return false;
            }
            $this->account($attempt);
            $attempt->update(['sale_reference' => $reference, 'verified_at' => $attempt->verified_at ?? now(), 'status' => 'settling', 'operation_expires_at' => now()->addMinutes(2)]);
            $this->event($attempt, 'verification.confirmed');

            return true;
        }, 3);
    }

    private function settled(PaymentAttempt $initial, string $token, string $reference): PaymentAttempt
    {
        return DB::transaction(function () use ($initial, $token, $reference) {
            [$number, $reservation, $order, $invoice] = $this->context($initial->commerce_invoice_id);
            $attempt = PaymentAttempt::query()->lockForUpdate()->findOrFail($initial->id);
            if ($attempt->operation_token !== $token) {
                return $attempt;
            }
            $this->account($attempt);
            $duplicate = $invoice->paid_payment_attempt_id !== null && $invoice->paid_payment_attempt_id !== $attempt->id;
            $attempt->update([
                'sale_reference' => $reference, 'verified_at' => $attempt->verified_at ?? now(), 'settled_at' => $attempt->settled_at ?? now(),
                'status' => $duplicate ? 'duplicate_payment' : 'settled', 'last_code' => '0', 'operation_token' => null, 'operation_expires_at' => null,
            ]);
            if (! $duplicate && $invoice->paid_payment_attempt_id === null) {
                $invoice->update(['status' => 'paid', 'paid_payment_attempt_id' => $attempt->id, 'paid_at' => now()]);
                $order->update(['status' => $this->liveHold($number, $reservation, $order) ? 'paid_pending_allocation' : 'paid_unfulfilled']);
            }
            $this->event($attempt, $duplicate ? 'settlement.duplicate' : 'settlement.confirmed', '0');
            $this->audit->system('payment.settled', 'payment_attempt', $attempt->id, ['invoice_id' => $invoice->id, 'outcome' => $order->status], 'Mellat server verification');

            return $attempt;
        }, 3);
    }

    private function finish(PaymentAttempt $initial, string $token, string $status, ?string $code = null, array $extra = []): PaymentAttempt
    {
        return DB::transaction(function () use ($initial, $token, $status, $code, $extra) {
            $this->context($initial->commerce_invoice_id);
            $attempt = PaymentAttempt::query()->lockForUpdate()->findOrFail($initial->id);
            if ($attempt->operation_token !== $token) {
                return $attempt;
            }
            $attempt->update([...$extra, 'status' => $status, 'last_code' => $code, 'operation_token' => null, 'operation_expires_at' => null]);
            $this->event($attempt, 'operation.'.$status, $code);

            return $attempt;
        }, 3);
    }

    /** No transaction ever spans a provider call. Common lock order excludes mutable gateway settings on callbacks. */
    private function context(int $invoiceId): array
    {
        $initial = CommerceInvoice::query()->findOrFail($invoiceId);
        $numberId = CommerceOrder::query()->findOrFail($initial->commerce_order_id)->item->sip_number_id;
        $number = SipNumber::query()->lockForUpdate()->findOrFail($numberId);
        $reservation = NumberReservation::query()->where('commerce_order_id', $initial->commerce_order_id)->lockForUpdate()->firstOrFail();
        $order = CommerceOrder::query()->lockForUpdate()->findOrFail($initial->commerce_order_id);
        $invoice = CommerceInvoice::query()->lockForUpdate()->findOrFail($invoiceId);
        if ($invoice->tenant_id !== $order->tenant_id || $invoice->customer_id !== $order->customer_id
            || $reservation->tenant_id !== $order->tenant_id || $reservation->sip_number_id !== $number->id) {
            throw ValidationException::withMessages(['payment' => 'سوابق سفارش نیاز به بررسی دارد.']);
        }

        return [$number, $reservation, $order, $invoice];
    }

    private function account(PaymentAttempt $attempt): PaymentGatewayVersion
    {
        $account = PaymentGatewayVersion::query()->findOrFail($attempt->payment_gateway_version_id);
        $invoice = CommerceInvoice::query()->findOrFail($attempt->commerce_invoice_id);
        $order = CommerceOrder::query()->findOrFail($invoice->commerce_order_id);
        if (PaymentGateway::query()->findOrFail($account->payment_gateway_id)->provider !== 'mellat'
            || $attempt->provider !== 'mellat' || $account->account_key !== $attempt->account_key || ! $account->amount_unit_confirmed
            || $account->gateway_unit !== 'IRR' || $attempt->gateway_unit !== 'IRR' || $attempt->business_currency !== 'IRT'
            || $attempt->business_amount < 1 || $attempt->business_amount > intdiv(PHP_INT_MAX, 10)
            || $attempt->gateway_amount !== $attempt->business_amount * 10
            || $invoice->currency !== 'IRT' || $order->currency !== 'IRT'
            || $order->total_amount !== $invoice->total_amount || $order->item->amount !== $invoice->total_amount
            || $attempt->business_amount !== $invoice->total_amount) {
            throw new PaymentTransportException('Payment account/amount correlation failed.');
        }

        return $account;
    }

    private function liveHold(SipNumber $number, NumberReservation $reservation, CommerceOrder $order): bool
    {
        return $reservation->status === 'held' && $reservation->expires_at->isFuture() && $order->expires_at->isFuture()
            && $number->current_reservation_id === $reservation->id && $number->inventory_state === 'reserved'
            && $number->tenant_id === null && $number->current_assignment_id === null;
    }

    private function code(string $response): string
    {
        if (! preg_match('/^[0-9]{1,4}$/D', $response)) {
            throw new PaymentTransportException('Invalid payment provider code.');
        }

        return $response;
    }

    private function event(PaymentAttempt $attempt, string $type, ?string $code = null): void
    {
        PaymentEvent::query()->create([
            'payment_attempt_id' => $attempt->id, 'event_key' => (string) Str::uuid(), 'type' => $type,
            'evidence' => $code === null ? [] : ['code' => $code], 'created_at' => now(),
        ]);
    }
}
