# Remaining implementation roadmap

Phase A code is implemented, tested and deployed. Real SIP call validation remains open after operational FreeSWITCH recovery. Phase B schema, reservation/pro forma, Mellat payment/settings and reconciliation are implemented locally; paid allocation, admin fulfillment screens and Phases C–G remain open. Existing customer isolation and telephony services are inputs, not tasks to rebuild. Each phase requires its acceptance gate before exposure to customers.

## Phase A — Admin inventory and monthly offers: implemented in checkout

Deliver an admin workflow that prepares unowned DIDs, validates technical readiness, and publishes versioned monthly offers. Keep customer checkout disabled. Implementation is summarized in [the docs index](README.md); the [next phase](NEXT_PHASE.md) now targets Mellat checkout.

- [x] Store offers in IRT, record Mellat as payment provider, require explicit limits/destination policy, and leave legacy stock untouched.
- [ ] Finalize remaining commercial policies and classify production baseline/resources.
- [x] Add independent inventory lifecycle/readiness/publication state without changing existing assigned DIDs.
- [x] Add plans, immutable published plan versions, and versioned per-number monthly offers.
- [x] Extend active admin DID routes/screens to show stock, ownership, readiness, and publication.
- [x] Adapt admin preparation so stock needs no customer extension or inbound answerer.
- [x] Add readiness validation, audit events, authorization, regression, and publication tests.

Gate: an admin can publish one technically reviewed unowned number at an unambiguous monthly amount; unavailable, owned, disabled, or unready stock cannot be published. Existing calling behavior remains intact.

## Phase B — Reservation, invoices, and verified payment

Backend batches 1–3 implemented and pushed for deployment: see [reservation contracts](CHECKOUT_BACKEND.md) and [Mellat integration](MELLAT_INTEGRATION.md). Batch 4 atomic allocation is next. General checkout remains off; customer catalog/order/pro forma and Mellat checkout screens are implemented; paid service activation remains pending. See [customer checkout](CUSTOMER_CHECKOUT.md).

- [x] Add orders/items, exclusive reservations, invoices/items, payment attempts/events, and assignment/subscription schema.
- [x] Snapshot prices, currency, capabilities, and purchaser Customer identity.
- [x] Implement locked pro forma reservation, idempotent creation and expiry with retained history; tested with competing MySQL buyers.
- [x] Integrate expiry with provider verification and an admin reconciliation screen; allocation races remain batch 4.
- [x] Implement Mellat initiation, verification, settlement, inquiry and explicit unsettled reversal with exact IRT conversion and encrypted admin merchant settings.
- [ ] Validate current merchant contract/live payment outcomes and implement settled-payment refund/credit repair.
- [x] Verify invoice association, amount, currency and frozen provider account/reference before recording bank-confirmed success.
- [x] Record confirmed payments once; handle duplicate callbacks, browser retries, late success and provider timeouts without automatic second charges.
- [ ] Allocate ownership/subscription atomically; surface paid-but-unfulfilled orders for repair or refund.
- [x] Add admin merchant settings and payment reconciliation with related invoice state, constrained actions and audited reasons.
- [ ] Complete admin order/invoice fulfillment and settled refund/repair screens.

Gate: competing buyers cannot acquire one DID, forged returns cannot activate service, retries cannot charge/activate twice, and every accepted payment has an accountable fulfillment outcome. Use isolated MySQL concurrency tests and the provider sandbox.

## Phase C — Subscription-aware call authorization

- [ ] Implement one entitlement service for tenant, assignment, subscription period, grace, capabilities, and technical readiness.
- [ ] Apply it to inbound/outbound XML, directory caller-ID metadata, IVR continuation, queue admission, and customer writes.
- [ ] Derive gateway exclusively from the eligible owned DID, including global infrastructure gateways.
- [ ] Adapt customer setup services that currently require tenant-owned gateways.
- [ ] Define registration/local calling when no DID is eligible; preserve shared extensions when another DID stays paid.
- [ ] Add admin-managed destination policy and reject forged SIP identity/caller-ID/gateway claims.
- [ ] Inspect live XML-CURL request variables, denial/static fallback behavior, and registration-held variables before cutover.
- [ ] Enforce time-based expiry even with stopped/delayed renewal jobs; document cache and in-progress call behavior.
- [ ] Create explicit audited compatibility handling for classified internal legacy resources; no blanket bypass for global gateways.

Gate: unpaid/suspended/foreign DIDs cannot authorize calls, denied XML cannot fall through to permissive static rules, and another paid DID in the same tenant continues working.

## Phase D — Buy, pay, and configure portal

- [ ] Build Browse Numbers, checkout, activation progress, and My Numbers using Phases A–C.
- [ ] Build the number workspace and tenant-authorized extension create/edit/disable/reset with one-time credential delivery.
- [ ] Reuse schedules, Jalali inputs, IVR publishing/restore, queue validation, media authorization, and caller-ID selection.
- [ ] Show shared-resource effects and independent billing/technical states.
- [ ] Replace customer provider/number submission entry points; remove or deny obsolete write URLs and free claim/release paths.
- [ ] Preserve admin/internal flows still required for operations; review references before removing code.
- [ ] Adapt customer navigation, dashboard, notifications, empty states, and public plans to published offers.
- [ ] Replace queue reconciliation's aggregate XML-writing/reload dependency with a separately validated dynamic approach before general customer queue rollout.
- [ ] Check Persian RTL mobile/desktop, keyboard navigation, errors, and stale checkout states.

Gate: a pilot customer can buy one number, configure a working call flow, and use an authorized caller ID without entering provider settings.

## Phase E — Renewals and customer billing

- [ ] Generate one invoice per subscription period with database uniqueness and idempotent jobs.
- [ ] Implement reminders after commit, payment retries, receipts/history, and upcoming due dates.
- [ ] Apply verified renewals once, including early payment, overdue periods, duplicate callbacks, and scheduler delay.
- [ ] Enforce grace/past-due/suspended states per DID without removing login/billing access.
- [ ] Restore service according to the selected late-payment period policy.
- [ ] Add admin delinquency/reconciliation queues and worker/job/invoice-lag monitoring.
- [ ] Test month ends, leap years, timezone boundaries, notification failure, and repeated execution.

Gate: renewals extend the correct period exactly once; delayed jobs cannot grant free service; successful payment recovers only eligible service.

## Phase F — Cancellation, release, and safe resale

- [ ] Add cancel-at-period-end, eligible undo, and audited immediate admin suspension/cancellation.
- [ ] Preserve invoices/payments and linked refunds/credits.
- [ ] End the assignment, revoke DID routing/caller-ID relationships, and enter quarantine.
- [ ] Preserve shared extensions/menus/queues used by other active DIDs.
- [ ] Keep historical calls/recordings/media/invoices with the original owner, including delayed CDR imports.
- [ ] Prevent stale callbacks, jobs, drafts, caches, and IVR markers from reviving ended assignments.
- [ ] Require cleanup and admin readiness review before resale.

Gate: tenant A cancellation → quarantine → tenant B purchase never exposes A's history or lets A control B's number.

## Phase G — Pilot and rollout

- [ ] Complete [deployment, migration, validation, and rollback checks](RELEASE_CHECKLIST.md).
- [ ] Pilot two independent businesses and multiple DIDs, including one suspended DID beside an active DID.
- [ ] Verify live provider calls, caller ID, schedules, IVR/queue fallback, and renewed service.
- [ ] Reconcile assignment, subscription, invoice, payment, and inventory counts.
- [ ] Exercise failed activation, payment reconciliation, cancellation, and application rollback.
- [ ] Launch gradually; retire obsolete customer flows only after reference/data-retention review.

Gate: the complete buy → pay → configure → renew journey passes with observable failures and recoverable financial outcomes.
