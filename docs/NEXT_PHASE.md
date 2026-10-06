# Next phase: reservation, invoices, and Mellat checkout

Status: Phase B planned. Phase A admin inventory/plans/IRT offers are implemented in the checkout; production activation is still pending.

## Outcome

A permitted customer can reserve one published DID, receive an immutable IRT invoice, initiate Mellat payment, and have a verified/settled payment recorded exactly once. Successful allocation creates one assignment and pending technical activation subscription. Paid-but-unfulfilled outcomes are visible to admins and recoverable.

Paid customer launch waits for Phase C call entitlements and Phase D configuration. Do not expose a paid customer DID to the existing permissive legacy routing path while those phases are incomplete.

## Confirmed decisions and prerequisites

- Business amounts are integer toman (`IRT`), as requested. The advertised offer total is the invoice/subscription total, without a second plan charge.
- Payment provider is Mellat / Behpardakht using the customer portal merchant account.
- Preserve separate Customer identities and `numbers.purchase`, `billing.view`, `billing.manage` permissions. Staff do not purchase without a grant.
- Obtain terminal ID, merchant username/password, allowed origin/IP and callback hostname through private deployment configuration. Do not place them in documentation, tests, prompts, logs, or the frontend.
- Confirm merchant gateway amount unit and verify exactly-once conversion. If requests use rial, submit checked integer `IRT × 10`; persist the gateway amount/unit separately from IRT. Never convert both in our adapter and again in a library.
- Finalize reservation duration, invoice identity/tax rules, monthly anniversary anchor, activation-period start, grace and late-payment policy before issuing real invoices. Plan limits are tenant-wide initially; define how multiple subscriptions combine limits before entitlement enforcement.

## Library assessment

[Shetabit Multipay](https://github.com/shetabit/multipay) has a Behpardakht driver. Its Laravel wrapper is [Shetabit Payment](https://github.com/shetabit/payment). Neither has been installed in Phase A; unused payment dependencies are unnecessary for admin catalog work.

Review of the [driver source](https://github.com/shetabit/multipay/blob/master/src/Drivers/Behpardakht/Behpardakht.php) on 2026-10-06 found:

- SOAP initiation and server-side verification/settlement, with currency conversion at purchase.
- A CRC32-derived order ID and callback-derived verification IDs: the application must own durable unique order/reference correlation.
- Already-verified/settled responses become exceptions: retries need explicit inquiry/reconciliation, not a second activation or automatic reversal.
- An HTTP/2 branch disables TLS peer verification: do not adopt that branch; require TLS verification in any adapter used here.

The library uses `SoapClient`; this checkout's PHP CLI does not currently list `ext-soap`. Add and verify SOAP on application workers before using that driver. Choose a compatible pinned release after Composer checks against the project's PHP/Laravel constraints. Use the driver only after the above behaviors are corrected or wrapped and tested. A small focused Mellat SOAP adapter is an acceptable alternative if safe idempotent integration requires replacing most driver behavior.

The library is transport assistance, not authorization or proof of payment. Merchant documentation and controlled payment verification are still required.

## Implementation batches

### 1. Billing and allocation schema

- [ ] Add orders/items with Customer purchaser and tenant, offer/version snapshot, integer IRT total, status, expiry and client idempotency key.
- [ ] Add historical reservations and one current reservation reference on locked DID; reject any existing assignment/current hold.
- [ ] Add immutable issued invoices/items and unique invoice identifiers.
- [ ] Add payment attempts/events with unique local bank order ID, RefId, sale reference, provider/account namespace, IRT and gateway amount/unit snapshots, verification/settlement status and sanitized evidence.
- [ ] Add number assignments/current assignment pointer and subscriptions with immutable price/plan snapshot, activation and billing period state.
- [ ] Use restrictive financial foreign keys and explicit retention; no cascading deletion of invoices/payments/history.

### 2. Reservation and invoice services

- [ ] Implement `NumberReservationService` and `CheckoutService` with explicit locking order consistent with plan/gateway/DID publication.
- [ ] Recheck current offer, plan eligibility, technical review, number availability and customer membership/permission inside the transaction.
- [ ] Snapshot the displayed quote; changed amount/version requires customer confirmation before initiating payment.
- [ ] Commit invoice/order/hold first; contact Mellat after commit.
- [ ] Make creation/retry idempotent per customer/order and implement terminal expiry without losing late payment outcomes.
- [ ] Add isolated MySQL races for competing customers, expiry/verification, and publication/withdrawal during reservation.

### 3. Mellat adapter and verification

- [ ] Configure merchant secrets only in environment/secret storage. Require HTTPS/TLS verification, bounded SOAP timeouts and safe logging.
- [ ] Persist the bank order ID before initiation and bind returned RefId to that attempt. Reconcile ambiguous initiation timeout rather than blindly charging again.
- [ ] Implement a narrowly scoped customer-host callback that does not require an active browser session. Any CSRF exemption is limited to this callback and does not exempt customer writes.
- [ ] Validate callback shape and local correlation before provider calls; callback success/ResCode or redirect alone never activates service.
- [ ] Verify with durable local expected identifiers, amount/unit and merchant account; settle and confirm final outcomes according to the merchant contract.
- [ ] Handle already-verified, already-settled, duplicate callback, pending settlement, inquiry and reversal without double recording or undoing another successful attempt.
- [ ] Give admin reconciliation a constrained retry/confirm/refund path with an audit reason. Preserve failure evidence without raw sensitive payload/card information.

### 4. Atomic allocation and repair

- [ ] Reacquire locks and recheck hold/offer/ownership before consuming verified payment.
- [ ] Apply one verified payment to one invoice/order exactly once; create assignment/subscription/current tenant projection in one transaction.
- [ ] Replace the temporary commerce stock guard with this dedicated allocation action; keep old free claim/release services denied.
- [ ] A stale/expired/conflicting hold with successful payment enters paid-but-unfulfilled reconciliation. Never seize a number sold to another customer.
- [ ] Separate settlement, assignment and technical activation. Start the paid period according to the finalized activation policy; do not consume paid service time invisibly while unready.
- [ ] Make provisioning/notification work recoverable after commit. Preserve financial correctness when workers or messages fail.

### 5. Customer and admin surfaces

- [ ] Add server-gated Browse Numbers, frozen-quote checkout, reservation expiry and payment retry/progress under the customer guard.
- [ ] Add owned invoices/receipts, order status and clear paid-but-unready messaging; exclude infrastructure details.
- [ ] Add admin orders, invoices, attempts and reconciliation screens with tenant/DID/status filters and audited actions.
- [ ] Verify Persian RTL, accessible controls, IRT labels, loading/error/empty/stale states on desktop/mobile.
- [ ] Keep general paid checkout off until entitlements/configuration and real calls pass the later gates.

## Acceptance and handoff

- [ ] Two customers cannot reserve/own one DID concurrently; one invoice snapshot survives catalog price changes.
- [ ] Forged return/callback, wrong amount/unit/merchant/order/reference and foreign invoice access fail safely.
- [ ] Repeated initiation/verification/settlement/callbacks cannot produce a second financial effect or allocation.
- [ ] Late successful payment and provider/network uncertainty have visible reconciliation outcomes.
- [ ] IRT conversion is tested with representative small/large values and overflow limits against the merchant contract.
- [ ] MySQL concurrency tests, mocked SOAP contract/error tests and provider-controlled testing pass; real payment/refund verification is recorded before launch.
- [ ] Existing customer isolation and assigned-number calling behavior remain unchanged.

Next: Phase C subscription entitlements must enforce new customer DID eligibility before general paid checkout is enabled. Phase D connects purchased numbers to configuration; Phase E delivers customer-paid recurring renewals.
