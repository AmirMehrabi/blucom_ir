# Reservation and pro forma backend

Implemented locally on 2026-10-07. This is Phase B batches 1/2, not a deployed purchasing feature. [Mellat payment/settings](MELLAT_INTEGRATION.md) now build on this backend. Catalog/order/invoice screens, paid allocation, entitlement enforcement and purchased-line configuration remain pending.

## What is implemented

`2026_10_07_000002_create_checkout_records` adds:

| Records | Contract |
| --- | --- |
| `commerce_orders` / `commerce_order_items` | Customer and tenant, public UUID, per-customer UUID idempotency key, request fingerprint, one DID/offer/version, frozen monthly IRT amount and terms, deadline. |
| `number_reservations` | Historical holds, one per order, deadline and terminal release time. A unique nullable `sip_numbers.current_reservation_id` points to the current hold. |
| `commerce_invoices` / `commerce_invoice_items` | One pro forma per order, unique public UUID and `PF-` invoice identifier, frozen buyer/business names, price and terms. No tax or billing service period is asserted. |
| `payment_attempts` / `payment_events` | Payment records: durable local attempt/bank-order ID, provider/account reference uniqueness, distinct IRT and gateway amounts/units, verification/settlement timestamps and retained events. Batch 3 Mellat writes these records. |
| `number_assignments` / `number_subscriptions` | Future one-time allocation records linked to order/payment, current assignment pointer, frozen subscription terms and nullable activation/period timestamps. Checkout does not create these records. |

All finance links use restrictive deletion. Model saves prohibit changes to issued snapshots/identities and deletion of historical records. Lifecycle fields have explicit allowlists. Provider references become immutable once recorded. Application services remain responsible for authorized transitions; direct database maintenance can bypass model guards and requires review.

The audit table now distinguishes `actor_user_id` from `actor_customer_id`. Reservation creation records the Customer actor; automatic expiry records a system event with both actor columns null. It stores record IDs/outcomes, not passwords, merchant credentials, OTPs or raw payment payloads.

## Service contracts

`CheckoutService::quote(Customer $actor, int $offerId)` returns only number/label, offer and plan identifiers, plan name/version, included features, scoped limits, monthly interval, integer `monthly_amount` and `currency=IRT`. It requires the catalog flag and current `numbers.purchase` permission.

`CheckoutService::reserve(Customer $actor, int $offerId, array $quote, string $key)` requires both catalog and reservation flags. The quote must include `offer_id`, `plan_version_id`, `monthly_amount`, and `currency`; the key must be a UUID. Values are confirmation of the displayed quote, never authority to set a price. Published offer/version, active plan, current offer, no assignment/hold, technical review, gateway/capabilities and fresh tenant membership/permission are rechecked under locks. Existing staff purchase grants are respected; internal `User` identities cannot call these Customer-only contracts.

One transaction creates order, item, invoice, invoice item and hold, then updates inventory to `reserved` and its current hold/revision. Any failure rolls back all records. Price is the inclusive monthly offer amount with no second plan charge. A reservation leaves `tenant_id=null`, `status=available`, no assignment and no call routes; it cannot authorize SIP calling.

The same customer/key/quote returns the original order and invoice, including after terminal expiry. It never extends the deadline or creates a replacement hold. Reusing a key for different confirmed terms fails. A new checkout requires a new key and a currently available offer. Changed/withdrawn/repriced offers fail rather than silently accepting new terms. Purchase permission is rechecked on replay.

`CheckoutService::order(Customer $actor, string $publicId)` requires fresh `billing.view`, scopes the lookup to the actor's active customer tenant and returns order/item/invoice/reservation records. Foreign orders are not found. This read remains available when commerce exposure flags are off. Batch 3 payment controllers use this owned read and an explicit safe view; a complete order/invoice portal remains pending.

Membership locks are tenant → Customer. Shared stock locks follow plan → version → gateway → DID → offer. Expiry follows DID → reservation → order → invoice → attempts. Permission grants/revocations lock the Customer row. Database transactions retry deadlocks three times. MySQL tests, not SQLite alone, validate the competing-buyer behavior.

## Expiry and uncertain payments

The pilot hold defaults to 15 minutes, configurable within 1–1,440 minutes. This is provisional; finalize the policy before accepting real payment. Deadlines are not service activation or billing-period start times.

`NumberReservationService::expire($reservationId)` releases a due held reservation exactly once under the DID lock. It validates the current pointer, DID/order/invoice/tenant/deadline relationships and denies malformed or assigned projections. An old expiry worker cannot clear another reservation. Both quote and reserve can expire a due current hold while checking that DID.

With no attempts, reservation/order/invoice become `expired`, the current hold clears and inventory returns to `available`. History remains. If any payment attempt exists, order/invoice become `reconciliation_required`; the hold expires but attempts/evidence remain untouched. This conservative state does not assert payment success or failure. Mellat now verifies late/uncertain payment independently of expiry and never allocates a number. A paid invoice remains `paid` while expiry sets its unallocated order to `paid_unfulfilled`. A verified late payment cannot claim another buyer’s hold.

Before expiry processing, a stored `reserved` status may have a past deadline. UI/payment code checks both status and deadline; scheduler delay must not extend reservation eligibility. Replaying a key alone does not run expiry.

## Deployment and operation

No production migration or deployment was performed for this batch. Apply the additive migration through the release process with all exposure flags off:

```dotenv
COMMERCE_CATALOG_ENABLED=false
COMMERCE_RESERVATION_ENABLED=false
COMMERCE_RESERVATION_MINUTES=15
COMMERCE_CHECKOUT_ENABLED=false
```

The checkout flag now gates Mellat initiation on an existing owned invoice; the configured merchant must also be enabled and amount-unit confirmed. Catalog/reservation browsing screens and paid allocation remain pending. Keep these flags false for deployment until the later launch gates pass.

The Laravel schedule now runs `commerce:expire-reservations` every minute with overlap prevention and a five-minute lock expiry. Ensure the deployment actually invokes Laravel's scheduler and uses a shared cache for scheduler locks. Manual bounded processing is:

```sh
php artisan commerce:expire-reservations --limit=100
php artisan schedule:list
```

The command validates limits (1–10,000), reports expired/review counts and returns failure if malformed holds need review. Repair those projections with an audited process; repeated anomalies can block the oldest-first bounded batch. A stopped scheduler does not prevent inline expiry when the affected DID is quoted/reserved, but monitoring remains required for timely status updates.

No FreeSWITCH XML/profile/gateway file, reload, restart, external payment or ownership migration is part of this release. For application rollback retain the new schemas/history. The migration's `down()` refuses if orders or customer commerce audit records exist; empty-schema rollback/reapply is tested on SQLite and MariaDB.

## Validation and next work

- 23 reservation feature tests cover immutable IRT snapshots, same-key retries, stale price/offer/readiness, fresh permissions/membership, tenant isolation, rollback, expiry, malformed projections, payment-evidence retention and restrictive foreign keys/reference uniqueness.
- Five opt-in disposable MariaDB races cover competing buyers, duplicate keys, withdrawal, withdrawal/republication, and expiry competing with a new hold.
- Full regression and dated local validation are recorded in [the release checklist](RELEASE_CHECKLIST.md). Existing real SIP call acceptance is a separate gate.

Run the ordinary suite with `vendor/bin/phpunit`. Race tests require `pcntl`, `CHECKOUT_CONCURRENCY_TESTS=1`, Laravel's MySQL driver, and a disposable database whose name starts with `blucom_checkout_test`. They run `migrate:fresh`; never point them at the application database. Set `DB_URL` empty when supplying the disposable connection variables.

Batch 3 [Mellat initiation/verification/settlement](MELLAT_INTEGRATION.md), settings, reconciliation and expiry/payment races are implemented locally. Next build [atomic allocation and repair](NEXT_PHASE.md#4-atomic-allocation-and-repair), then complete customer commerce surfaces. Paid launch still waits for Phase C entitlements and Phase D configuration. Fiscal invoice identity/tax, anniversary/activation-period start, grace and late-payment policies remain decisions before real billing.
