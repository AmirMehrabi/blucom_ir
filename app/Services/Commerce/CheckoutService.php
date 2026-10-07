<?php

namespace App\Services\Commerce;

use App\Models\CommerceInvoice;
use App\Models\CommerceInvoiceItem;
use App\Models\CommerceOrder;
use App\Models\CommerceOrderItem;
use App\Models\Customer;
use App\Models\NumberOffer;
use App\Models\NumberReservation;
use App\Models\Plan;
use App\Models\PlanVersion;
use App\Models\SipGateway;
use App\Models\SipNumber;
use App\Models\Tenant;
use App\Support\Permissions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CheckoutService
{
    public function __construct(
        private NumberReadinessService $readiness,
        private NumberReservationService $reservations,
        private CommerceAudit $audit,
    ) {}

    /** Server-generated quote: no provider configuration or authentication material. */
    public function quote(Customer $actor, int $offerId): array
    {
        abort_unless(config('commerce.catalog_enabled'), 404);

        return DB::transaction(function () use ($actor, $offerId) {
            $this->authorize($actor, Permissions::NUMBERS_PURCHASE);
            [$offer, $version, $plan, $number] = $this->eligible($offerId);

            return $this->snapshot($offer, $version, $plan, $number);
        }, 3);
    }

    /** Only creates a pro forma invoice and hold. Never initiates payment or enables SIP. */
    public function reserve(Customer $actor, int $offerId, array $quote, string $key): CommerceOrder
    {
        abort_unless(config('commerce.catalog_enabled') && config('commerce.reservation_enabled'), 404);
        validator([...$quote, 'key' => $key], [
            'key' => ['required', 'uuid'], 'offer_id' => ['required', 'integer', 'in:'.$offerId],
            'plan_version_id' => ['required', 'integer', 'min:1'],
            'monthly_amount' => ['required', 'integer', 'min:1', 'max:1000000000000'],
            'currency' => ['required', 'in:IRT'],
        ])->validate();
        $key = strtolower($key);
        $fingerprint = hash('sha256', json_encode([
            $offerId, (int) $quote['plan_version_id'], (int) $quote['monthly_amount'], $quote['currency'],
        ], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $offerId, $quote, $key, $fingerprint) {
            [$actor, $tenant] = $this->authorize($actor, Permissions::NUMBERS_PURCHASE);
            // Customer row serializes same-key requests before any stock locks.
            $existing = CommerceOrder::query()->where('customer_id', $actor->id)->where('idempotency_key', $key)->lockForUpdate()->first();
            if ($existing !== null) {
                abort_unless($existing->tenant_id === $tenant->id, 403);
                if (! hash_equals($existing->request_fingerprint, $fingerprint)) {
                    throw ValidationException::withMessages(['checkout' => 'کلید درخواست قبلاً برای پیشنهاد دیگری استفاده شده است.']);
                }

                return $existing->load(['item', 'invoice.item', 'reservation']);
            }
            [$offer, $version, $plan, $number] = $this->eligible($offerId);
            if ($offer->plan_version_id !== (int) $quote['plan_version_id'] || $offer->monthly_amount !== (int) $quote['monthly_amount']
                || $offer->currency !== $quote['currency']) {
                throw ValidationException::withMessages(['checkout' => 'پیشنهاد تغییر کرده است؛ قیمت و پلن جدید را دوباره تأیید کنید.']);
            }
            $minutes = (int) config('commerce.reservation_minutes');
            if ($minutes < 1 || $minutes > 1440) {
                throw ValidationException::withMessages(['checkout' => 'مدت رزرو به‌درستی پیکربندی نشده است.']);
            }
            $expires = now()->addMinutes($minutes);
            $snapshot = $this->snapshot($offer, $version, $plan, $number);
            $order = CommerceOrder::query()->create([
                'public_id' => (string) Str::uuid(), 'customer_id' => $actor->id, 'tenant_id' => $tenant->id,
                'idempotency_key' => $key, 'request_fingerprint' => $fingerprint, 'status' => 'reserved',
                'total_amount' => $offer->monthly_amount, 'currency' => 'IRT', 'expires_at' => $expires,
            ]);
            $item = CommerceOrderItem::query()->create([
                'commerce_order_id' => $order->id, 'sip_number_id' => $number->id, 'number_offer_id' => $offer->id,
                'plan_version_id' => $version->id, 'snapshot' => $snapshot, 'amount' => $offer->monthly_amount, 'currency' => 'IRT',
            ]);
            $invoiceId = (string) Str::uuid();
            $invoice = CommerceInvoice::query()->create([
                'public_id' => $invoiceId, 'invoice_number' => 'PF-'.strtoupper($invoiceId),
                'commerce_order_id' => $order->id, 'customer_id' => $actor->id, 'tenant_id' => $tenant->id,
                'kind' => 'proforma', 'status' => 'issued', 'total_amount' => $offer->monthly_amount, 'currency' => 'IRT',
                'buyer_snapshot' => ['customer_name' => $actor->name, 'business_name' => $tenant->name],
                'issued_at' => now(), 'expires_at' => $expires,
            ]);
            CommerceInvoiceItem::query()->create([
                'commerce_invoice_id' => $invoice->id, 'commerce_order_item_id' => $item->id,
                'snapshot' => $snapshot, 'amount' => $offer->monthly_amount, 'currency' => 'IRT',
            ]);
            $reservation = NumberReservation::query()->create([
                'sip_number_id' => $number->id, 'commerce_order_id' => $order->id, 'tenant_id' => $tenant->id,
                'status' => 'held', 'expires_at' => $expires,
            ]);
            $number->update([
                'current_reservation_id' => $reservation->id, 'inventory_state' => 'reserved',
                'inventory_revision' => $number->inventory_revision + 1,
            ]);
            $this->audit->customer($actor, 'reservation.created', 'number_reservation', $reservation->id, ['order_id' => $order->id, 'invoice_id' => $invoice->id]);

            return $order->load(['item', 'invoice.item', 'reservation']);
        }, 3);
    }

    public function order(Customer $actor, string $publicId): CommerceOrder
    {
        return DB::transaction(function () use ($actor, $publicId) {
            [, $tenant] = $this->authorize($actor, Permissions::BILLING_VIEW);

            return CommerceOrder::query()->where('public_id', $publicId)->where('tenant_id', $tenant->id)
                ->with(['item', 'invoice.item', 'reservation'])->firstOrFail();
        }, 3);
    }

    /** All membership checks use fresh rows. Tenant -> customer matches account creation. */
    private function authorize(Customer $actor, string $permission): array
    {
        $tenant = Tenant::query()->lockForUpdate()->find($actor->tenant_id);
        $actor = Customer::query()->lockForUpdate()->findOrFail($actor->id);
        abort_unless($tenant !== null && $actor->canAccessTenant($tenant), 403);
        abort_unless($actor->permissions()->where('permission', $permission)->exists(), 403);

        return [$actor, $tenant];
    }

    /** Publication lock order: plan -> version -> gateway -> DID -> offer. */
    private function eligible(int $offerId): array
    {
        $initial = NumberOffer::query()->with(['planVersion', 'number'])->findOrFail($offerId);
        $plan = Plan::query()->lockForUpdate()->findOrFail($initial->planVersion->plan_id);
        $version = PlanVersion::query()->lockForUpdate()->findOrFail($initial->plan_version_id);
        $gateway = SipGateway::query()->lockForUpdate()->find($initial->number->provider_gateway_id);
        $number = SipNumber::query()->lockForUpdate()->findOrFail($initial->sip_number_id);
        $offer = NumberOffer::query()->lockForUpdate()->findOrFail($offerId);
        $this->reservations->expireCurrent($number);
        if ($number->current_offer_id !== $offer->id || $offer->withdrawn_at !== null || $offer->published_at === null
            || $offer->published_at->isFuture() || $number->inventory_state !== 'available' || $plan->archived
            || $version->published_at === null || $version->published_at->isFuture() || $offer->currency !== 'IRT'
            || $version->billing_interval !== 'monthly' || $version->limit_scope !== 'tenant'
            || $offer->monthly_amount < 1 || $offer->monthly_amount > 1000000000000
            || $this->readiness->issues($number, $gateway) !== []) {
            throw ValidationException::withMessages(['checkout' => 'این شماره اکنون قابل رزرو نیست؛ پیشنهادها را تازه کنید.']);
        }

        return [$offer, $version, $plan, $number];
    }

    private function snapshot(NumberOffer $offer, PlanVersion $version, Plan $plan, SipNumber $number): array
    {
        return [
            'offer_id' => $offer->id, 'number' => $number->normalized_number, 'label' => $number->label,
            'plan_version_id' => $version->id, 'plan_name' => $plan->name, 'plan_version' => $version->version,
            'features' => $version->features, 'limits' => $version->limits, 'limit_scope' => $version->limit_scope,
            'billing_interval' => $version->billing_interval, 'monthly_amount' => $offer->monthly_amount, 'currency' => $offer->currency,
        ];
    }
}
