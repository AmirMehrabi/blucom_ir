# Next phase: reservation, invoices, and Mellat checkout

Status: Phase B batches 1–3 (schema, reservation/pro forma, Mellat payment/settings and reconciliation) implemented locally on 2026-10-07; not deployed. Phase A and customer registration are deployed. Atomic allocation, complete commerce screens, live merchant testing, customer pilot validation, legacy transfer and real SIP call acceptance remain pending. See [backend contracts](CHECKOUT_BACKEND.md) and [Mellat operations](MELLAT_INTEGRATION.md).

## Outcome

A permitted customer can reserve one published DID, receive an immutable IRT invoice, initiate Mellat payment, and have a verified/settled payment recorded exactly once. Successful allocation creates one assignment and pending technical activation subscription. Paid-but-unfulfilled outcomes are visible to admins and recoverable.

Paid customer launch waits for Phase C call entitlements and Phase D configuration. Do not expose a paid customer DID to the existing permissive legacy routing path while those phases are incomplete.

## Confirmed decisions and prerequisites

- Business amounts are integer toman (`IRT`), as requested. The advertised offer total is the invoice/subscription total, without a second plan charge.
- Payment provider is Mellat / Behpardakht using the customer portal merchant account.
- Preserve separate Customer identities and `numbers.purchase`, `billing.view`, `billing.manage` permissions. Staff do not purchase without a grant.
- Obtain merchant credentials, approved IP and callback hostname privately. Enter credentials through admin payment settings, with encrypted immutable versions; never place real credentials in documentation, test fixtures, prompts or logs, or return stored credentials to clients.
- Confirm merchant gateway amount unit and verify exactly-once conversion. If requests use rial, submit checked integer `IRT × 10`; persist the gateway amount/unit separately from IRT. Never convert both in our adapter and again in a library.
- Finalize reservation duration, invoice identity/tax rules, monthly anniversary anchor, activation-period start, grace and late-payment policy before issuing real invoices. Plan limits are tenant-wide initially; define how multiple subscriptions combine limits before entitlement enforcement.

## Library assessment

[Shetabit Multipay](https://github.com/shetabit/multipay) has a Behpardakht driver. Its Laravel wrapper is [Shetabit Payment](https://github.com/shetabit/payment). Neither has been installed in Phase A; unused payment dependencies are unnecessary for admin catalog work.

Review of the [driver source](https://github.com/shetabit/multipay/blob/master/src/Drivers/Behpardakht/Behpardakht.php) on 2026-10-06 found:

- SOAP initiation and server-side verification/settlement, with currency conversion at purchase.
- A CRC32-derived order ID and callback-derived verification IDs: the application must own durable unique order/reference correlation.
- Already-verified/settled responses become exceptions: retries need explicit inquiry/reconciliation, not a second activation or automatic reversal.
- An HTTP/2 branch disables TLS peer verification: do not adopt that branch; require TLS verification in any adapter used here.

Batch 3 chose a focused `MellatSoapClient` using the existing Guzzle dependency and DOM, with TLS verification, bounded timeouts/response parsing and sanitized failures. No SDK or `ext-soap` was installed. The application owns durable IDs, immutable merchant versions, correlation, leases, retry and reversal policy. See [implemented contracts and merchant validation gates](MELLAT_INTEGRATION.md).

The library is transport assistance, not authorization or proof of payment. Merchant documentation and controlled payment verification are still required.

## Implementation batches

### 1. Billing and allocation schema

- [x] Add orders/items with Customer purchaser and tenant, offer/version snapshot, integer IRT total, status, expiry and client idempotency key.
- [x] Add historical reservations and one current reservation reference on locked DID; reject any existing assignment/current hold.
- [x] Add immutable issued invoices/items and unique invoice identifiers.
- [x] Add payment attempts/events with unique local bank order ID, RefId, sale reference, provider/account namespace, IRT and gateway amount/unit snapshots, verification/settlement status and sanitized evidence.
- [x] Add number assignments/current assignment pointer and subscriptions with immutable price/plan snapshot, activation and billing period state.
- [x] Use restrictive financial foreign keys and explicit retention; no cascading deletion of invoices/payments/history.

### 2. Reservation and invoice services

- [x] Implement `NumberReservationService` and `CheckoutService` with explicit locking order consistent with plan/gateway/DID publication.
- [x] Recheck current offer, plan eligibility, technical review, number availability and customer membership/permission inside the transaction.
- [x] Snapshot the displayed quote; changed amount/version requires customer confirmation before initiating payment.
- [x] Commit pro forma invoice/order/hold atomically. Mellat transport now runs after commit in batch 3.
- [x] Make creation/retry idempotent per customer/order and implement terminal expiry while retaining every invoice/attempt. Existing attempts put the order/invoice into `reconciliation_required`; batch 3 now records late successful payment as paid-unfulfilled; allocation remains batch 4.
- [x] Add isolated MySQL races for competing customers, duplicate keys, expiry/new holds, withdrawal and repricing.
- [x] Add MySQL expiry/verification/new-hold races for the implemented payment flow.
- [ ] Add allocation races in batch 4.

Batch 1/2 limits: the 15-minute hold is a configurable pilot default, invoices are pro forma (`PF-` identifiers), and no official tax invoice or service period is issued. Assignments/subscriptions remain schema foundations; payment attempts are implemented in batch 3; reservation creates no payment, assignment, subscription, tenant ownership or call route. Limited customer payment endpoints now exist; catalog/order/invoice screens remain pending. All exposure flags remain false by default.

### 3. Mellat adapter and verification — implemented locally

- [x] Add admin enable/disable and merchant settings with encrypted versioned credentials, blank retention, revision checks and secret-free audit.
- [x] Require HTTPS/TLS verification, bounded SOAP timeouts/parsing and safe failure reporting.
- [x] Persist the bank order ID before initiation and bind returned RefId to that attempt. Reconcile ambiguous initiation timeout rather than blindly charging again.
- [x] Implement a narrowly scoped customer-host callback that does not require an active browser session. Any CSRF exemption is limited to this callback and does not exempt customer writes.
- [x] Validate callback shape and local correlation before provider calls; callback success/ResCode or redirect alone never activates service.
- [x] Verify/settle using durable local IDs and frozen amount/unit/merchant; inquiry and uncertain results retain evidence.
- [ ] Confirm current merchant contract and perform controlled live provider acceptance.
- [x] Handle already-verified, already-settled, duplicate callback, pending settlement, inquiry and reversal without double recording or undoing another successful attempt.
- [x] Add constrained admin reconciliation and explicit verified/unsettled reversal with required audit reasons; preserve sanitized evidence.
- [ ] Implement settled-payment refunds/credits and recovery of initiation without saved bank references.

### 4. Atomic allocation and repair

This is the next implementation batch. Use the confirmed invoice/attempt from batch 3, then consume only its eligible original hold; no payment endpoint currently creates ownership or service.

- [ ] Reacquire locks and recheck hold/offer/ownership before consuming verified payment.
- [ ] Apply one verified payment to one invoice/order exactly once; create assignment/subscription/current tenant projection in one transaction.
- [ ] Replace the temporary commerce stock guard with this dedicated allocation action; keep old free claim/release services denied.
- [ ] A stale/expired/conflicting hold with successful payment enters paid-but-unfulfilled reconciliation. Never seize a number sold to another customer.
- [ ] Separate settlement, assignment and technical activation. Start the paid period according to the finalized activation policy; do not consume paid service time invisibly while unready.
- [ ] Make provisioning/notification work recoverable after commit. Preserve financial correctness when workers or messages fail.

### 5. Customer and admin surfaces

- [ ] Add server-gated Browse Numbers, frozen-quote checkout, reservation expiry and payment retry/progress under the customer guard.
- [ ] Add owned invoices/receipts, order status and clear paid-but-unready messaging; exclude infrastructure details.
- [x] Add admin payment settings and paginated status-filtered attempts/reconciliation, showing related invoice state.
- [ ] Complete admin orders/invoices, tenant/DID filters and fulfillment repair screens.
- [x] Verify implemented payment/settings pages in Persian RTL on desktop/mobile, including blank credentials, toggles, saves and reconciliation layout.
- [ ] Verify the remaining checkout/order screens and complete customer retry journeys.
- [ ] Keep general paid checkout off until entitlements/configuration and real calls pass the later gates.

## Acceptance and handoff

- [x] Two customers cannot reserve one DID concurrently; one pro forma snapshot survives catalog price changes.
- [ ] Enforce the same invariants during verified-payment allocation and subscription creation.
- [x] Forged callbacks, wrong amount/unit/merchant/order/reference and foreign invoice access fail safely in mocked tests.
- [x] Repeated initiation/verification/settlement/callbacks cannot produce a second invoice payment.
- [ ] Extend these guarantees to assignment/subscription allocation.
- [x] Late successful payment and provider/network uncertainty have visible retained reconciliation outcomes.
- [x] Test small/large supported IRT amounts, unit/merchant corruption and overflow guards locally.
- [ ] Validate amount units/conversion against the current merchant contract.
- [x] MySQL payment/reservation races and mocked SOAP contract/error tests pass; see [release evidence](RELEASE_CHECKLIST.md).
- [ ] Record controlled provider payment/inquiry/reversal results and settled refund verification before launch.
- [x] Existing automated customer isolation and assigned-number XML regression tests pass.
- [ ] Reverify real assigned-number registration/inbound/outbound calling before paid launch.

Next: Phase C subscription entitlements must enforce new customer DID eligibility before general paid checkout is enabled. Phase D connects purchased numbers to configuration; Phase E delivers customer-paid recurring renewals.
