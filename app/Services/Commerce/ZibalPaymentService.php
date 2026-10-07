<?php

namespace App\Services\Commerce;

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
use App\Services\Commerce\NumberReadinessService;
use App\Support\Permissions;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ZibalPaymentService
{
    public function __construct(private CommerceAudit $audit) {}

    public function initiate(Customer $actor, string $invoiceId, string $key): PaymentAttempt
    {
        abort_unless(config('commerce.checkout_enabled'), 404);
        validator(['key' => $key], ['key' => ['required', 'uuid']])->validate();
        $key = strtolower($key);
        [$attempt, $token] = DB::transaction(function () use ($actor, $invoiceId, $key): array {
            $tenant = Tenant::query()->lockForUpdate()->find($actor->tenant_id);
            $actor = Customer::query()->lockForUpdate()->findOrFail($actor->id);
            abort_unless($tenant !== null && $actor->canAccessTenant($tenant)
                && $actor->permissions()->where('permission', Permissions::NUMBERS_PURCHASE)->exists()
                && $actor->permissions()->where('permission', Permissions::BILLING_MANAGE)->exists(), 403);
            $initial = CommerceInvoice::query()->where('public_id', $invoiceId)->where('tenant_id', $tenant->id)->firstOrFail();
            $gateway = PaymentGateway::query()->where('provider', 'zibal')->lockForUpdate()->firstOrFail();
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
                    return [$current, null];
                }
            }
            $account = $gateway->currentVersion;
            if (! $gateway->enabled || ! $gateway->active || $account === null
                || ! $account->amount_unit_confirmed || $account->gateway_unit !== 'IRR'
                || ! is_string($account->credentials['merchant'] ?? null) || $account->credentials['merchant'] === '') {
                throw ValidationException::withMessages(['payment' => 'درگاه پرداخت فعال و آماده نیست.']);
            }
            $token = (string) Str::uuid();
            $attempt = PaymentAttempt::query()->create([
                'public_id' => (string) Str::uuid(), 'commerce_invoice_id' => $invoice->id, 'idempotency_key' => $key,
                'provider' => 'zibal', 'account_key' => $account->account_key, 'payment_gateway_version_id' => $account->id,
                'business_amount' => $invoice->total_amount, 'business_currency' => 'IRT',
                'gateway_amount' => $invoice->total_amount * 10, 'gateway_unit' => 'IRR', 'status' => 'initiating',
                'operation_token' => $token, 'operation_expires_at' => now()->addMinutes(2),
            ]);
            $invoice->update(['current_payment_attempt_id' => $attempt->id]);
            $this->event($attempt, 'initiation.started');

            return [$attempt, $token];
        }, 3);
        if ($token === null) {
            return app(PaidOrderAllocationService::class)->afterSettlement($attempt);
        }
        try {
            $account = $this->account($attempt);
            $response = Http::acceptJson()->asJson()->timeout(12)->post('https://gateway.zibal.ir/v1/request', [
                'merchant' => $account->credentials['merchant'], 'amount' => $attempt->gateway_amount,
                'callbackUrl' => $this->callbackUrl($attempt), 'description' => 'پرداخت سفارش '.(string) $attempt->id,
                'orderId' => $attempt->id,
            ]);
            if (! $response->successful()) {
                throw new PaymentTransportException('Zibal request failed.');
            }
            $body = $response->json();
            $code = $this->code($body['result'] ?? null);
            if ($code === '100' && preg_match('/^[1-9][0-9]{0,18}$/D', (string) ($body['trackId'] ?? ''))) {
                return $this->finish($attempt, $token, 'redirect_ready', $code, ['ref_id' => (string) $body['trackId']]);
            }

            return $this->finish($attempt, $token, in_array($code, ['102', '103', '104', '105', '106', '113'], true) ? 'initiation_failed' : 'unknown', $code);
        } catch (\Throwable $exception) {
            report($exception);

            return $this->finish($attempt, $token, 'unknown');
        }
    }

    public function callback(string $publicId, array $payload): PaymentAttempt
    {
        $attempt = PaymentAttempt::query()->where('public_id', $publicId)->where('provider', 'zibal')->firstOrFail();
        validator($payload, ['trackId' => ['required', 'string', 'regex:/^[1-9][0-9]{0,18}$/D']])->validate();
        if ($attempt->ref_id === null || ! hash_equals($attempt->ref_id, $payload['trackId'])) {
            throw ValidationException::withMessages(['payment' => 'اطلاعات بازگشت درگاه معتبر نیست.']);
        }
        if ($attempt->status === 'settled' || $attempt->status === 'duplicate_payment') {
            return app(PaidOrderAllocationService::class)->afterSettlement($attempt);
        }
        if (($payload['success'] ?? null) !== '1' && ($payload['success'] ?? null) !== 1 && ($payload['success'] ?? null) !== true) {
            $this->event($attempt, 'callback.reported_failure', $this->code($payload['status'] ?? 0));

            return $attempt;
        }
        [$attempt, $token] = DB::transaction(function () use ($attempt): array {
            $this->context($attempt->commerce_invoice_id);
            $locked = PaymentAttempt::query()->lockForUpdate()->findOrFail($attempt->id);
            if (in_array($locked->status, ['settled', 'duplicate_payment'], true)
                || ($locked->operation_token !== null && $locked->operation_expires_at?->isFuture())) {
                return [$locked, null];
            }
            $token = (string) Str::uuid();
            $locked->update(['status' => 'verifying', 'candidate_sale_reference' => $locked->ref_id,
                'operation_token' => $token, 'operation_expires_at' => now()->addMinutes(2)]);
            $this->event($locked, 'verification.started');

            return [$locked, $token];
        }, 3);
        if ($token === null) {
            return app(PaidOrderAllocationService::class)->afterSettlement($attempt);
        }
        try {
            $account = $this->account($attempt);
            $response = Http::acceptJson()->asJson()->timeout(12)->post('https://gateway.zibal.ir/v1/verify', [
                'merchant' => $account->credentials['merchant'], 'trackId' => (int) $attempt->ref_id,
            ]);
            if (! $response->successful()) {
                throw new PaymentTransportException('Zibal verification failed.');
            }
            $body = $response->json();
            $code = $this->code($body['result'] ?? null);
            if (! in_array($code, ['100', '201'], true) || (int) ($body['amount'] ?? 0) !== $attempt->gateway_amount
                || (isset($body['orderId']) && (string) $body['orderId'] !== (string) $attempt->id)) {
                return $this->finish($attempt, $token, in_array($code, ['202', '203'], true) ? 'initiation_failed' : 'unknown', $code);
            }
            $reference = isset($body['refNumber']) && preg_match('/^[1-9][0-9]{0,18}$/D', (string) $body['refNumber'])
                ? (string) $body['refNumber'] : $attempt->ref_id;

            return $this->settled($attempt, $token, $reference);
        } catch (\Throwable $exception) {
            report($exception);

            return $this->finish($attempt, $token, 'unknown');
        }
    }

    public function callbackUrl(PaymentAttempt $attempt): string
    {
        return 'https://'.config('portal.customer_domain').'/payments/zibal/callback/'.$attempt->public_id;
    }

    public function reconcile(User $actor, int $id, string $reason): PaymentAttempt
    {
        abort_unless($actor->isAdmin() && ! $actor->isDisabled(), 403);
        validator(['reason' => $reason], ['reason' => ['required', 'string', 'max:1000']])->validate();
        $attempt = PaymentAttempt::query()->where('provider', 'zibal')->findOrFail($id);
        $this->audit->record($actor, 'payment.reconciliation_requested', 'payment_attempt', $id, $reason);
        if ($attempt->ref_id === null) {
            throw ValidationException::withMessages(['payment' => 'شناسه درگاه موجود نیست؛ بررسی پذیرنده لازم است.']);
        }

        return $this->verifyExisting($attempt);
    }

    private function verifyExisting(PaymentAttempt $attempt): PaymentAttempt
    {
        return $this->callback($attempt->public_id, ['trackId' => $attempt->ref_id, 'success' => '1']);
    }

    private function account(PaymentAttempt $attempt): PaymentGatewayVersion
    {
        $account = PaymentGatewayVersion::query()->findOrFail($attempt->payment_gateway_version_id);
        $invoice = CommerceInvoice::query()->findOrFail($attempt->commerce_invoice_id);
        $order = CommerceOrder::query()->findOrFail($invoice->commerce_order_id);
        if (PaymentGateway::query()->findOrFail($account->payment_gateway_id)->provider !== 'zibal'
            || $attempt->provider !== 'zibal' || $account->account_key !== $attempt->account_key || ! $account->amount_unit_confirmed
            || $account->gateway_unit !== 'IRR' || $attempt->gateway_unit !== 'IRR' || $attempt->business_currency !== 'IRT'
            || $attempt->business_amount < 1 || $attempt->business_amount > intdiv(PHP_INT_MAX, 10)
            || $attempt->gateway_amount !== $attempt->business_amount * 10
            || $invoice->currency !== 'IRT' || $order->currency !== 'IRT' || $order->total_amount !== $invoice->total_amount
            || $order->item->amount !== $invoice->total_amount || $attempt->business_amount !== $invoice->total_amount
            || ! is_string($account->credentials['merchant'] ?? null) || $account->credentials['merchant'] === '') {
            throw new PaymentTransportException('Payment account/amount correlation failed.');
        }

        return $account;
    }

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

    private function settled(PaymentAttempt $initial, string $token, string $reference): PaymentAttempt
    {
        $attempt = DB::transaction(function () use ($initial, $token, $reference): PaymentAttempt {
            [$number, $reservation, $order, $invoice] = $this->context($initial->commerce_invoice_id);
            $attempt = PaymentAttempt::query()->lockForUpdate()->findOrFail($initial->id);
            if ($attempt->operation_token !== $token) {
                return $attempt;
            }
            $this->account($attempt);
            $duplicate = $invoice->paid_payment_attempt_id !== null && $invoice->paid_payment_attempt_id !== $attempt->id;
            $attempt->update(['sale_reference' => $reference, 'verified_at' => $attempt->verified_at ?? now(),
                'settled_at' => $attempt->settled_at ?? now(), 'status' => $duplicate ? 'duplicate_payment' : 'settled',
                'last_code' => '100', 'operation_token' => null, 'operation_expires_at' => null]);
            if (! $duplicate && $invoice->paid_payment_attempt_id === null) {
                $invoice->update(['status' => 'paid', 'paid_payment_attempt_id' => $attempt->id, 'paid_at' => now()]);
                $order->update(['status' => $this->liveHold($number, $reservation, $order) ? 'paid_pending_allocation' : 'paid_unfulfilled']);
            }
            $this->event($attempt, $duplicate ? 'settlement.duplicate' : 'settlement.confirmed', '100');
            $this->audit->system('payment.settled', 'payment_attempt', $attempt->id,
                ['invoice_id' => $invoice->id, 'outcome' => $order->status], 'Zibal server verification');

            return $attempt;
        }, 3);

        return app(PaidOrderAllocationService::class)->afterSettlement($attempt);
    }

    private function finish(PaymentAttempt $initial, string $token, string $status, ?string $code = null, array $extra = []): PaymentAttempt
    {
        return DB::transaction(function () use ($initial, $token, $status, $code, $extra): PaymentAttempt {
            $this->context($initial->commerce_invoice_id);
            $attempt = PaymentAttempt::query()->lockForUpdate()->findOrFail($initial->id);
            if ($attempt->operation_token !== $token) {
                return $attempt;
            }
            $attempt->update([...$extra, 'status' => $status, 'last_code' => $code,
                'operation_token' => null, 'operation_expires_at' => null]);
            $this->event($attempt, 'operation.'.$status, $code);

            return $attempt;
        }, 3);
    }

    private function liveHold(SipNumber $number, NumberReservation $reservation, CommerceOrder $order): bool
    {
        return $reservation->status === 'held' && $reservation->expires_at->isFuture() && $order->expires_at->isFuture()
            && $number->current_reservation_id === $reservation->id && $number->inventory_state === 'reserved'
            && $number->tenant_id === null && $number->current_assignment_id === null;
    }

    private function code(mixed $value): string
    {
        if (! is_string($value) && ! is_int($value)) {
            return '9999';
        }
        $code = (string) $value;

        return preg_match('/^[0-9]{1,4}$/D', $code) ? $code : '9999';
    }

    private function event(PaymentAttempt $attempt, string $type, ?string $code = null): void
    {
        PaymentEvent::query()->create(['payment_attempt_id' => $attempt->id, 'event_key' => (string) Str::uuid(),
            'type' => $type, 'evidence' => $code === null ? [] : ['code' => $code], 'created_at' => now()]);
    }
}
