# Subscription architecture and invariants

Status: inventory/offers and reservation/pro forma foundations implemented; Mellat transport/settings/verification are implemented locally; allocation and subscription enforcement remain pending.

Currency: `IRT` (integer toman). Payment provider: Mellat / Behpardakht.

## Responsibility boundaries

Laravel owns customers, tenants, inventory, offers, billing, assignments, entitlements, and configuration. FreeSWITCH owns SIP registration/signaling, RTP, media, and call execution. Keep the protected internal XML-CURL endpoint, proper XML serialization, and thin controllers. Do not move media into Laravel, hardcode customer test values, or introduce per-customer XML files.

Customers authenticate as `Customer` on the configured customer hostname; admins/internal operators remain `User`. Resolve the tenant server-side. Customer IDs must never be stored in User foreign keys; choose explicit actor namespaces for orders and audit events.

Preserve globally unique SIP usernames in the existing shared directory, E.164 global DID uniqueness, and the current Sofia/gateway behavior. Infrastructure activation is a separate controlled procedure: inspect, back up, make one change, validate, reload only if necessary, test registration/inbound/outbound, inspect logs, document. Never delete working configuration before replacement validation.

## Proposed records

| Record | Responsibility |
| --- | --- |
| Existing SIP number | Canonical DID, trusted gateway/capabilities/policy, inventory state and current ownership projection. |
| Plan/version | Included configuration features and explicit scoped limits; published versions immutable. |
| Number offer | Versioned DID + plan version + monthly total/currency and publication/readiness. |
| Order/item | Customer purchaser and tenant, idempotency key, frozen offer/price/features, one DID initially. |
| Reservation | Exclusive temporary hold under a locked DID/current-reservation reference; expiry and history. |
| Assignment | DID ownership history, tenant, subscription linkage, start/end, release reason. |
| Subscription | Snapshotted terms, anchored billing periods, paid-through/grace, cancellation and activation state. |
| Invoice/item | Immutable issued amount, currency, service period and due date; order/subscription references. |
| Payment gateway/version | Enable switch, revision and immutable encrypted merchant credential versions; each attempt freezes its account/version. |
| Payment attempt/event | Provider identity/reference, exact expected amount/currency, verified outcome and idempotent processing. |
| Refund/adjustment | Linked immutable financial correction, actor, reason, provider outcome. |
| Audit event | Namespaced actor, resource, transition and time; sanitized business evidence. |

Financial records survive account disabling and stock withdrawal. Review cascading foreign keys before linking finance to existing VoIP tables. Store integer IRT amounts, UTC timestamps, and immutable commercial snapshots. At the Mellat boundary, verify the required amount unit; for a rial request use checked integer `IRT × 10`, never floating point. Persist both business and gateway amounts/units to prevent double conversion during verification/retries.

Inventory moves draft → available → reserved → assigned → quarantined → available, with controlled disabled states. Administrative disabling cannot erase an active reservation or assignment. Payment expiry returns stock only after terminal reservation handling; cancellation never releases immediately.

Subscription billing state (pending payment, active, past due, suspended, canceled), technical readiness, and configuration completeness are separate. Paid-but-unready is a reconciliation state, not evidence of a working line. A catalog price edit does not rewrite existing invoices/subscriptions.

## Reservation and payment integrity

1. Lock/recheck the DID, current offer and availability; persist order, quote, invoice, and exclusive reservation.
2. Commit before contacting the provider. Never hold database transactions during external network requests.
3. Initiate payment with a persisted attempt and idempotency reference; retry/reconcile ambiguous timeouts rather than blindly creating another charge.
4. Treat browser return/callback as a verification trigger. Verify success, expected amount/currency, invoice association, provider account/reference, and any provider-specific requirements server-side.
5. Apply verification in a new locked transaction. Persist payment once and atomically create assignment/subscription/current ownership when the hold is still valid.
6. Late success after expiry or conflicting assignment enters paid-but-unfulfilled reconciliation/refund. Never seize another buyer's DID.
7. Retry post-commit provisioning/notifications independently. Notification failure cannot lose a payment or allocate a second assignment.

Define one lock order for DID, reservation, order, invoice, payment, and subscription operations. Enforce unique provider payment/event references and unique invoice service periods. Use MySQL concurrency tests; SQLite results do not prove row-lock correctness.

## Entitlement contract

One `VoipEntitlementService` resolves current assignment, tenant status, verified paid period/grace, gateway readiness, DID capabilities and destination policy. Customer writes and XML generation consume consistent decisions.

- Inbound: normalized actual called number → eligible assignment → owned enabled route/destination. A SIP gateway Request-URI is not the DID.
- Outbound: authenticated extension → tenant → selected eligible owned DID → its trusted gateway → normalized allowed destination. Ignore SIP-provided tenant/caller-ID/gateway authorization claims.
- Directory: return minimal authentication/context metadata and authorized caller-ID data; reauthorize new outbound calls from current database state rather than trusting registration-held variables.
- IVR/queues: bind the original DID/assignment to call context, validate trusted continuation markers and tenant destinations, and define active-call continuation semantics. Never let stale markers authorize another assignment.
- Missing, expired, disabled or malformed relationships fail safely. Inspect actual XML-CURL miss/static fallback behavior so safe denial cannot fall through to unrestricted dialing.
- Evaluate expiry timestamps on requests even when jobs are late. Cache decisions only with documented invalidation and bounded freshness.

Provider traffic remains in public; customer authenticated calling uses default. Never expose unrestricted default dialing to providers. Suspension blocks new service authorization for that DID; it does not automatically terminate existing calls or disable another eligible DID. Define local calling/registration behavior explicitly when the tenant has no eligible subscription.

Existing internal records require explicit classified compatibility handling. A global gateway or absent subscription must never become a blanket bypass for new customer businesses.

## Ownership, shared resources, and release

Extensions, menus, queues, media, reporting and recordings retain tenant boundaries. Limits must declare whether they apply per number or tenant; enforce creation limits transactionally. Customer credentials appear once in authenticated private/no-store responses; never expose provider passwords.

Assignment history is authoritative for historical attribution. Snapshot assignment/tenant ownership when calls start; a delayed CDR must not be attributed using the DID's new owner. Resale cannot expose former invoices, recordings, prompts, live snapshots, drafts, or sessions. Release removes the old DID's routes/caller-ID references while retaining tenant resources used elsewhere.

Queue reconciliation currently writes aggregate configuration and may reload XML. Replace that dependency through a verified dynamic mechanism before general customer queues; do not extend it into per-customer file generation.

No OTP, SIP/provider/payment secret, cookie, raw sensitive payment payload, or audio content belongs in logs/audit events. Use the selected provider's required minimal verification evidence with an explicit retention policy.

## Current inventory implementation

`inventory_state=null` is the untouched legacy marker, not subscription authorization. New stock starts as `draft` with no tenant and `status=available`; publication makes it `available` and sets `current_offer_id`. Withdrawal clears that reference, preserves the offer with `withdrawn_at`, and returns stock to draft. Disable/re-enable is allowed only on unowned unpublished stock. The reservation service now moves available → reserved → available on terminal expiry while preserving offer/hold/invoice history. Assigned/quarantined transitions remain later payment/release work. Limited payment initiation/status/callback endpoints exist; catalog and order/invoice screens remain pending.

Publication locks plan → plan version → gateway → DID, then rechecks plan publication, gateway/readiness fingerprint, inventory revision and current offer. Gateway mutations lock the gateway and reject changes while a linked DID has a current offer. Stock edits lock/recheck the DID, invalidate review and increment inventory revision. Historical offers reference immutable published plan versions; draft limit edits and plan archival share the plan lock with publication.

A technical review is a deliberate admin assertion with private evidence, not a network probe. Readiness checks canonical DID identity, no owner/request/routes, enabled inbound/outbound capabilities, approved global external/public gateway, allowed destination prefixes, and a fingerprint of operational settings plus gateway revision. The fingerprint stores no credentials. The stock list's review filter indicates that a review was recorded; the stock workspace revalidates current readiness.

Future allocation must replace the temporary model guard against legacy free assignment with a dedicated audited assignment action. It must retain the current-offer/history relationships and never reconnect old claim/release endpoints. Catalog and reservation reads must revalidate publication and technical eligibility, not rely only on a non-null offer reference.


## Implemented reservation backend — 2026-10-07

See [backend contracts](CHECKOUT_BACKEND.md) for exact tables, flags and operations. Checkout locks tenant → Customer → plan → plan version → gateway → DID → offer, then reservation/order/invoice as needed. Permission changes lock the Customer row. Publication retains its plan/version/gateway/DID order; withdrawal locks DID → offer and refuses current holds/assignments. Expiry locks DID → reservation → order → invoice → attempts and never locks plans/gateways. An idempotent replay only locks tenant/Customer/existing order and returns without acquiring stock locks. Transactions retry deadlocks up to three times.

New reservations freeze the published offer/version and server-generated amount/features/limits plus buyer/business names; they do not contain provider secrets or infrastructure details. One current reservation pointer anchors exclusive stock under the DID lock, while historical reservations remain immutable apart from lifecycle fields. A same-key retry cannot change price, extend the deadline or acquire a replacement hold. Both quote and reserve recheck eligibility and may expire a due current hold under that lock.

Only pro forma invoices are issued. Payment attempts/events now use the Mellat initiation/verification service; assignments/subscriptions remain restrictive schema foundations without allocation. Expiry preserves any attempt and uses `reconciliation_required` rather than recording a financial failure or pretending payment succeeded. Mellat verification resolves confirmed financial outcomes without allocation; an expired hold never grants rights over a new buyer's number. Billing service-period uniqueness and renewal/fiscal invoice rules are future work.


## Implemented Mellat batch — 2026-10-07

See [Mellat contracts and operations](MELLAT_INTEGRATION.md). Payment initiation locks tenant → Customer → payment gateway settings → plan → version → SIP gateway → DID → reservation → order → invoice → offer → attempts. Settings mutation locks only the payment gateway; callbacks use frozen credential versions and never acquire its mutable settings lock. Callback/reconciliation locks DID → historical reservation → order → invoice → attempt; leases/token fencing surround provider calls made after commit. Permission changes retain Customer locking.

Invoice payment is recorded exactly once after bank verification/settlement. Paid/unallocated orders are `paid_pending_allocation` only while the original hold is still eligible, otherwise `paid_unfulfilled`. Expiry releases only the current original hold and preserves paid evidence. This batch deliberately does not grant ownership, subscriptions, entitlements or call access. Atomic allocation is the next action, and call authorization remains gated on the later entitlement/configuration work.
