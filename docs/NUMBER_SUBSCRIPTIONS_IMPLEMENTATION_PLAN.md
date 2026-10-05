# Blucom: number purchases, subscriptions, and customer configuration

Date: 2026-10-05  
Repository reviewed: `d56047c`  
Status: customer-isolation foundation implemented in this checkout; production activation and legacy ownership migration pending. Commerce phases have not started.

## Implementation update — 2026-10-05

The first implementation now uses **a separate `Customer` model and account tables**, with the customer guard and login at **`https://my.blucom.ir/login`**. `User` remains exclusively the internal admin/operator model. Each customer owner gets an independent tenant, and customer staff are explicitly assigned to that business. This supersedes the earlier suggestion to represent customers as roles on `User`.

Implemented: admin customer management, independent customer OTPs and host-only session cookies, explicit membership without a Blucom fallback, customer-safe permission boundaries, same-tenant phone/number/IVR/queue/media/report access, live channel isolation, number-bound gateway authorization for new customer businesses, and a read-only legacy ownership audit. The existing internal workspace and SIP resources are preserved. New tests cover the customer boundary and regression tests cover existing behavior.

Deployment is still pending: database migration, customer DNS/TLS, ingress activation, browser session checks, and real SIP calls. No production resource reassignment or FreeSWITCH operation was performed. Legacy data classification and a reviewed migration apply tool remain open; the audit command deliberately makes no changes. Purchasing, plans, invoices, payments, and renewals remain later phases.

Final validation for this implementation: **183 tests passed, 1,168 assertions** using the isolated SQLite test database; PHP syntax checks passed for all 40 changed PHP source/test/config files, and the changed PHP files were formatted with Pint. Documentation links and whitespace checks passed.

See [customer tenancy and deployment instructions](CUSTOMER_TENANCY.md). The existing queue reconciliation command still writes aggregate callcenter XML and may reload XML; only tenant membership filtering was changed here. Replacing that pre-existing configuration dependency is a prerequisite for general customer queue rollout.

## 1. Outcome and recommendation

An admin prepares numbers and their provider settings, publishes monthly offers, and manages billing. A customer selects an available number, pays for a subscription, and configures its extensions, time conditions, IVR, and queues. Customers never supply provider credentials or choose infrastructure gateways.

Rewrite the customer onboarding and navigation around this journey. Reuse the existing telephony services and editors after correcting their ownership assumptions. The reviewed baseline used a shared organization panel. The new customer account boundary now supports independent businesses; the purchase journey and safe legacy migration remain to be implemented.

Recommended release sequence:

1. Establish independent customer tenants and preserve existing calls.
2. Add admin-managed inventory, plans, pricing, and billing records.
3. Implement reservation, verified payment, and atomic activation.
4. Deliver the customer number workspace using existing call configuration features.
5. Add recurring invoices, renewal payments, suspension, cancellation, and controlled release.
6. Pilot with real SIP calls before making self-service purchasing generally available.

Monthly subscriptions are in scope. Per-minute rating, prepaid call credit, automatic top-ups, and provider cost accounting are separate future projects. Existing recordings, reporting, and live monitoring remain supported during migration; expanding them is outside this plan.

## 2. Review scope and verification

Reviewed routes, controllers, models, migrations, services, permissions, portal templates, marketing plans, project documentation, and relevant tests in this checkout. No production database inspection, remote FreeSWITCH inspection, payment-provider verification, browser walkthrough, or live SIP call was performed. Deployment statements in older documents are historical evidence, not confirmation of current server state.

Executed the following existing test groups directly with PHPUnit using an explicitly selected in-memory SQLite database:

`SinglePanelTest`, `RoleAccessTest`, `SipNumberCatalogTest`, `CustomerSetupTest`, `FreeSwitchXmlTest`, `InboundScheduleTest`, `IvrMenusTest`, and `CallQueuesTest`.

Result: **57 tests passed, 305 assertions**. This confirms the tested current behavior, not readiness for purchases, billing, or customer SaaS isolation. MySQL concurrency and real SIP behavior still require separate validation.

## 3. Findings at the reviewed baseline

This table records the original review at `d56047c`. Completed changes are described in the implementation update and checked Phase 1 tasks below.

Paths below are relative to the repository root.

| Area | Current evidence | Required change |
| --- | --- | --- |
| Customer identity | `app/Enums/UserType.php` contains admin/operator. `TenantService::forUser()` assigns users without a tenant to `BlucomOwner`. `CreateCustomer` is an operator alias and ignores the business option. | Introduce customer ownership/membership and remove the shared-tenant fallback for customer users. |
| Historical ownership | `2026_09_25_000004_consolidate_active_workspaces.php` moves active legacy tenant records into Blucom and retains previous IDs in `workspace_consolidation_log`. | Use an explicit, reviewed ownership migration. Historical IDs alone cannot classify newer users, routes, queues, menus, or media. |
| Current onboarding | `Customer/SetupController.php`, `LineSetupWizardController.php`, and `CustomerLineSetupService.php` collect customer provider settings and number submissions. | Replace with catalog → checkout → purchased number → configure. Retire customer provider and BYOD writes. |
| Permissions | `Permissions::OPERATOR_DEFAULTS` includes provider and number management. Wizard authorization requires those permissions. | Introduce purchase, billing, extension, and call-flow permissions; infrastructure management is admin-only. |
| Active admin DID path | `routes/web.php` uses `AdminDidController`. Creation assigns a DID to the Blucom owner; its list excludes unowned inventory. | Add an inventory lifecycle, publication readiness, price, and subscription links without requiring a customer owner. |
| Older catalog code | `AdminSipNumberController`, `SipNumberController`, and `SipNumberService` contain inventory, assignment, and release operations, but these controllers are not registered in current web routes. | Treat as migration/reference code. Do not reconnect the free claim/release flow as checkout. |
| Assignment safety | `SipNumberService::assignToTenant()` checks a previously loaded record before its transaction and updates without locking/rechecking. Release immediately returns stock to available. | Build reservation and allocation under database locking, plus cancellation and quarantine. |
| Pricing and billing | `/plans` renders static `marketing/plans.blade.php` with one-time setup offers. No billing, invoice, payment, subscription, or plan domain models/migrations were found. | Build billing and admin plan management; later render public plans from published records. |
| Customer gateway assumption | `setAnswerer()`, `createPhone()` with a number, and wizard outbound assignment require a gateway owned by the same tenant. | Authorize customer configuration through the purchased DID and its admin-selected gateway. |
| Reusable features | Existing inbound schedules, IVR draft/publish/restore, queue membership/fallbacks, media authorization, and tenant-scoped destination checks. | Adapt their portal entry points and entitlements; avoid replacing the call execution engine. |
| Dynamic XML | Protected `/internal/freeswitch/xml`, DOM-based XML services, tenant checks, and public/default separation already exist. | Add subscription service authorization across every relevant execution path. |
| Gateway authorization gap to address | Directory and outbound dialplan permit global gateways through a legacy branch without requiring DID gateway equality in that branch. | Require the outbound gateway to be the approved gateway configured on the purchased DID, with documented handling for imported legacy records. |
| SIP identities | Extensions are globally unique in the schema; outbound routes have a unique extension reference. | Preserve one shared directory and one authorized outbound DID per extension initially. Do not allow repeated SIP usernames per tenant without a separately verified namespace design. |
| Accounts and login | `AuthController` rejects unknown mobile numbers; admin user creation attaches operators to Blucom. | Initially support admin-created customer accounts; public signup is a later, explicit product choice. |
| Adjacent data | Calls, recordings, live snapshots, broadcast channels, queue agents, and audio paths retain tenant references. | Include all of them in isolation and migration checks, even when they are absent from the simplified navigation. |

Related historical documents: [single panel](SINGLE_PANEL_RBAC.md), [customer line setup](CUSTOMER_LINE_SETUP.md), [admin line setup](ADMIN_LINE_SETUP.md), [IVR](IVR.md), and [queues](CALL_TEAMS.md). These document different generations of the product; current routes and services determine active behavior.

## 4. Product rules and decisions

These are proposed defaults, not claims that the current application implements them.

- One tenant represents one customer business. Owners and staff authenticate as `Customer` accounts on `my.blucom.ir`; global admins and internal operators authenticate as `User`. A customer owner can purchase and configure once commerce is implemented; staff receive narrower permissions. Global admins manage infrastructure and billing.
- One subscription purchases one DID. Each published offer combines a plan version with that DID's monthly price. Start with one inclusive monthly plan that includes extensions, schedules, IVR, and queues; support additional plans through the same model without prematurely building an upgrade engine.
- The monthly price is the total recurring number price for the selected offer. If premium-number pricing is needed, make the breakdown explicit; do not silently charge both a plan fee and a DID fee.
- Extensions, IVR menus, and queues belong to the tenant and can be reused by several purchased DIDs. Each DID has its own inbound route and time conditions. Configure them from the number workspace.
- One extension has one selected outbound caller-ID DID initially, matching the existing schema. Its gateway is derived server-side from that DID. Multiple selectable outbound profiles are deferred.
- A completed browser redirect is not proof of payment. Service activation requires server-side provider verification.
- Recurring subscription means recurring billing. Automatic bank/card debits depend on the chosen provider's actual capabilities; renewal invoices with customer payment are sufficient for the first release.
- Customers retain access to invoices and renewal payment when their calling service is suspended. Configuration can remain readable; mutation rules are explicit and do not implicitly reactivate service.
- Suspension of one DID does not suspend other paid DIDs in the same tenant. A shared extension may remain registered for other services, but cannot use the suspended DID for inbound service or outbound caller ID.
- Suspension applies to new call authorization. The first release does not forcibly terminate in-progress calls; verify and document the behavior of IVR continuations and queue sessions.
- Cancellation defaults to the end of the paid period. Suspension never makes a number available for another customer.
- Released numbers enter quarantine and require cleanup and admin readiness confirmation before resale. Billing and historical call data stay associated with the former customer.
- Preserve current Sofia profiles, the working gateway, and the existing test call path throughout migration. No per-customer XML files or FreeSWITCH restarts for ordinary configuration changes.

Before billing implementation, record answers for: payment provider; settlement currency and rial/toman display conversion; business definition of a month and end-of-month behavior; grace period; cancellation/refund rules; taxes and invoice identity; plan limits and their scope; price-change policy; quarantine duration; customer account creation; outbound destination restrictions. Provider and legal requirements must be verified when those implementations are selected.

Suggested initial commercial policy: paid monthly periods, no proration or mid-period plan changes, fixed prices for existing subscriptions until an explicit future-renewal change, payment reminders, a configurable grace period, and admin-reviewed refunds. This keeps the first release focused without blocking later expansion.

## 5. Proposed domain and data design

Keep models/services in the current `app/Models` and `app/Services` structure if practical. A broad folder refactor is not a prerequisite. Controllers remain thin; introduce dedicated transactional actions/services for commerce.

| Record | Responsibility and important fields |
| --- | --- |
| Tenant/customer membership | Separate `customers` and `customer_permissions` tables, owner/staff roles, `tenants.owner_customer_id`, and explicit single-business membership; no implicit assignment to Blucom. Internal users remain separate. |
| Existing `sip_numbers` | Canonical DID identity, admin-selected gateway, capabilities, current owner projection, inventory state, publication metadata. Preserve global normalized uniqueness. |
| Plans / plan versions | Admin-managed name, publication status, billing interval, included features, explicit limits and scope. Published versions referenced by subscriptions are immutable. |
| Number offers | DID + plan version + monthly amount + currency + publication/readiness. One published offer per DID initially; version pricing rather than overwriting subscription terms. |
| Orders / order items | Tenant, `customer_id` purchaser (never a customer ID in a User foreign key), immutable offer/amount/currency snapshot, status, idempotency key, creation/expiry. Start with one DID per order while keeping line items explicit. |
| Number reservations | DID, order, tenant, expiry and status; one exclusive current reservation enforced through the locked DID/current-reservation reference. Retain historical reservations. |
| Number assignments | DID, tenant, subscription, start/end dates and reason. Preserve ownership history; ensure only one current assignment per DID. |
| Subscriptions | Tenant, assignment, plan/version and price snapshot, paid-through/current period, renewal/cancellation/suspension state, service activation state. |
| Invoices / invoice items | Tenant, subscription/order references, currency, integer amounts, service period, due date, immutable issued lines and totals, status. |
| Payment attempts / payments | Invoice/order, provider, unique provider reference, amount/currency, pending/verified/failed status, reconciliation metadata. No stored payment credentials. |
| Payment events | Unique provider event identity when available, minimal sanitized audit data, processing state and retries. |
| Adjustments / refunds | Explicit linked credit/refund records, reasons, actor, provider outcome. Never erase a paid invoice to simulate a refund. |
| Audit events | Actor, tenant, resource, transition, reason, timestamps; secret-free and separate from debug logs. |

Use integer monetary amounts in a documented canonical currency unit, never floating point. Snapshot commercial terms on orders, subscriptions, and invoices. Preserve financial records through customer/account deletion policies; review existing cascading VoIP foreign keys before connecting commerce records.

Suggested boundaries: `NumberCatalogService`, `NumberReservationService`, `CheckoutService`, `PaymentProvider` adapter, `PaymentVerificationService`, `NumberActivationService`, `SubscriptionService`, `InvoiceService`, `VoipEntitlementService`, and `NumberReleaseService`. These names describe responsibilities, not a requirement to create empty classes.

### State transitions and invariants

Inventory: `draft → available → reserved → assigned → quarantine → available`, with an admin-disabled state. Payment timeout can return `reserved → available` only after the reservation is terminal and cannot later activate.

Subscription: `pending_payment → active → past_due → suspended`; verified renewal restores eligibility; cancellation at period end becomes `canceled`. Commercial status and infrastructure readiness are separate: a paid number can be awaiting activation or have a technical incident.

Payment: `initiated → pending → verified | failed | expired`; refunds have their own transition records. Delayed successful verification after inventory reservation expiry enters reconciliation rather than assigning a number already sold to someone else.

Core invariants:

1. At most one customer owns a DID at a time, and at most one checkout holds it.
2. Amount, currency, provider reference, and invoice association match before payment is accepted.
3. A verified payment activates at most once; a renewal invoice covers a period at most once.
4. Paid-through dates, not browser actions or scheduler availability, determine current service eligibility.
5. Gateway, caller ID, extension, and destinations resolve through authorized database relationships.
6. UI feature visibility, customer writes, and XML call execution use consistent entitlements.
7. Old calls, invoices, media, and recordings never become visible to a new owner after resale.

Do not keep a database transaction open during an external payment request. Reserve and persist first; call the provider after commit. Reacquire locks and revalidate when verification is applied. Define a consistent lock order for DID, order, invoice, payment, and subscription operations. Use MySQL-backed tests for competing checkouts and callbacks; SQLite tests do not establish lock correctness.

## 6. Phase-by-phase implementation plan and checklist

Checked items have been implemented in this checkout. Unchecked items remain open, including production validation. Each phase has a release gate; passing application tests alone does not establish deployment or SIP readiness.

### Phase 0 — Baseline, business policy, and migration inventory

Deliverable: agreed product rules, inventory of existing ownership, and a reversible migration runbook.

- [ ] Record the commercial decisions in section 4, including what happens after payment failure.
- [ ] Classify accounts as platform admins, internal Blucom staff, customer owners, or customer staff.
- [ ] Inspect actual production gateway/profile/XML-CURL state through authorized read-only operations before infrastructure work.
- [ ] Back up application database and any FreeSWITCH configuration relevant to a planned change; prove restore access.
- [ ] Record registration, inbound, outbound, and caller-ID baselines without copying secrets into documentation.
- [ ] Inventory DID/extension/routes, drafts, IVR, queue members, media, recordings, calls, and live cache ownership.
- [ ] Compare consolidation history against current records; flag ambiguous ownership for manual classification.
- [ ] Define feature flags for catalog visibility, checkout, activation, enforcement, and renewal processing.
- [ ] Specify rollout cohort, rollback criteria, and observable error/latency thresholds.

Gate: no unclassified live record will be reassigned automatically; current calls have a documented baseline.

### Phase 1 — Restore customer tenancy and access boundaries

Deliverable: separate `Customer` accounts on `my.blucom.ir` with tenant-scoped access, while `User` and Blucom keep their internal workspace. Code foundation complete; deployment and legacy transfer remain open.

- [x] Define global admins/internal operators on `User` and owner/staff roles on `Customer`; customer roles cannot grant global privileges.
- [x] Add separate customer tables, guard, session-cookie name, OTP challenges, and exact-host login routing for `my.blucom.ir`.
- [x] Change `TenantService` so customer access requires an explicit valid tenant and never falls back to Blucom; internal operators also require explicit membership.
- [x] Add `/admin/customers` for owner/business and staff creation, permission/account management, business status, and same-tenant extension assignment. `customer:create` creates a Customer/business; `operator:create` remains internal.
- [x] Keep OTP login and unknown-mobile rejection; customer OTPs are independently stored, session-bound, transactionally consumed, and rate-limited.
- [x] Introduce customer-safe configuration and reserved purchase/billing permission keys; reject infrastructure permissions regardless of stored customer grants. Purchase/billing screens remain unimplemented.
- [x] Audit and test authenticated customer request paths, model bindings, downloads, account notifications, broadcasts, and live cache access. Harden queue reconciliation against foreign members.
- [ ] Complete historical worker/importer/media ownership validation as part of a classified legacy transfer.
- [x] Keep admin ownership selection distinct; customer pages resolve membership server-side and ignore/reject attempts to switch tenants. Ordinary customer account updates prohibit reassignment.
- [x] Add an additive customer-account schema migration that preserves all existing users and VoIP resources.
- [x] Add `customer:ownership-audit` with optional JSON output; it reports ownership counts/history and performs no writes.
- [ ] Build a reviewed legacy transfer manifest/apply tool with validation and transaction boundaries after ambiguous ownership is classified.
- [ ] Migrate related media ownership and paths without breaking FreeSWITCH prompt access or leaking old files.
- [ ] Preserve historical reporting ownership and invalidate obsolete live snapshots/session access after migration.
- [x] Add customer-model two-tenant tests for reads, writes, lists, downloads, broadcasts, foreign extension/queue membership, forged tenant/gateway IDs, disabled accounts, and model/guard ID collisions.
- [x] Update customer CLI/login tests to assert separate Customer/business creation; preserve explicit internal operator and baseline regression tests.

Code gate: two independent Customers cannot access each other's resources; existing internal behavior passes regression tests. Deployment gate remains open until DNS/TLS, browser sessions, and real Blucom/pilot SIP calls are verified. Do not use migration rollback to undo consolidation blindly.

### Phase 2 — Admin inventory and subscription-ready number provisioning

Deliverable: admin can prepare unowned stock and publish only usable numbers.

- [ ] Extend active admin DID screens to include unowned stock and lifecycle filters.
- [ ] Preserve global number normalization/uniqueness and immutable DID identity after assignment.
- [ ] Add draft, available, reserved, assigned, quarantined, and disabled inventory transitions.
- [ ] Separate publication/readiness from owner and service-enabled flags.
- [ ] Require an admin-selected approved gateway, provider capabilities, and outbound destination policy for a sellable number.
- [ ] Keep provider credentials, gateway identity, SIP profile/context, and network settings admin-only.
- [ ] Adapt admin Quick Setup to prepare inventory without requiring a customer answerer or extension.
- [ ] Keep customer destinations unset until customer configuration; unknown/unconfigured inbound calls fail safely.
- [ ] Resolve legacy gateway exceptions explicitly and preserve the working provider until validated replacement is available.
- [ ] Prevent deleting assigned/financially referenced stock or editing an active assignment through a generic status update.
- [ ] Audit inventory publication, withdrawal, gateway changes, and manual assignment actions.
- [ ] Add readiness validation and admin inventory lifecycle tests.

Gate: available inventory has trusted provider configuration and cannot execute customer calls while unassigned.

### Phase 3 — Plans, offers, and billing foundation in the admin panel

Deliverable: admins manage monthly offers and inspect financial records; public checkout remains disabled.

- [ ] Add plan/version, number-offer, order, reservation, assignment, subscription, invoice, payment, refund, and audit schema incrementally.
- [ ] Specify integer money units, currency, timezone, and monthly anniversary/end-of-month rules.
- [ ] Add admin Plans screens for draft/publish/archive, included features, scoped limits, and versions.
- [ ] Add monthly price editing on the number offer; distinguish retail price from any future provider cost tracking.
- [ ] Require every offer to include the requested basic configuration features; document any limits clearly.
- [ ] Add admin Subscriptions, Invoices, Payments, and reconciliation views with tenant/number/status filters.
- [ ] Preserve immutable issued invoice and subscription price snapshots when catalog prices change.
- [ ] Define constrained, audited manual subscription creation and payment recording; no invisible free assignment bypass.
- [ ] Add foreign-key/deletion rules that preserve invoices, payments, and previous assignments.
- [ ] Add transactional entitlement checks for creation limits, including concurrent extension/queue creation.
- [ ] Render public plans from published records when purchasing launches; remove conflicting one-time-setup promises.
- [ ] Test price/version publication, totals, currency conversion, authorization, archive behavior, and invoice ownership.

Gate: admins can prepare a complete monthly offer, and changing its current price cannot alter existing invoices.

### Phase 4 — Reservation, checkout, payment verification, and activation

Deliverable: one customer can buy one available number exactly once.

- [ ] Implement a public-safe catalog projection: number, monthly price, currency, included features, and availability only.
- [ ] Create orders/reservations with database locking and server-side offer validation, plus request idempotency.
- [ ] Enforce one current hold/assignment per DID at the database/transaction boundary.
- [ ] Persist pending invoice/payment attempts before contacting the provider outside the transaction.
- [ ] Integrate the chosen provider through a small adapter using secret storage and sanitized errors.
- [ ] Verify signed notifications when supported and independently verify payment status server-side.
- [ ] Reject mismatched invoice, amount, currency, merchant, or provider transaction references.
- [ ] Deduplicate notifications and callbacks; retries and out-of-order events cannot double-activate or regress paid state.
- [ ] Atomically link verified payment, assignment, subscription period, and DID owner; notify after commit.
- [ ] Reconcile pending/ambiguous provider outcomes, including a successful charge after reservation expiry.
- [ ] Expire unpaid reservations safely and handle browser abandonment, provider timeouts, and disabled inventory.
- [ ] Distinguish payment success from technical readiness; show a clear pending-activation state when necessary.
- [ ] Test competing buyers/callbacks on MySQL, provider failure/retry cases, duplicate payments, and late settlement.

Gate: only verified payment grants ownership; two buyers cannot acquire the same DID; paid-but-unfulfilled orders are recoverable and visible to admins.

### Phase 5 — Subscription-aware XML-CURL and routing authorization

Deliverable: purchased service state governs actual calls, independent of portal navigation.

- [ ] Add a shared entitlement service resolving assignment, paid period/grace policy, tenant, and DID capability.
- [ ] Require the purchased DID's trusted gateway relationship for all outbound routes, including global gateways.
- [ ] Refactor customer setup service guards to accept authorized admin-managed gateways through purchased DIDs.
- [ ] Apply service eligibility to inbound dialplan, outbound dialplan, directory caller-ID metadata, IVR continuation, and queue entry authorization.
- [ ] Define registration/local-extension behavior when a tenant has no eligible subscription; preserve valid shared-extension use when another number remains paid.
- [ ] Resolve authenticated extension identity server-side; reject spoofed tenant, caller-ID, gateway, and SIP destination fields.
- [ ] Add admin-managed allowed destination policy instead of relying only on broad numeric patterns.
- [ ] Inspect actual XML-CURL miss and static fallback behavior; ensure denied calls cannot reach permissive legacy dialplans.
- [ ] Return deterministic escaped XML and safe denial/no-match output without JSON, HTML, or secret-bearing errors.
- [ ] Preserve public/default separation and the existing working baseline; do not use customer CRUD to rewrite infrastructure XML.
- [ ] Make service eligibility evaluate timestamps directly even if the renewal scheduler is late.
- [ ] Document XML caching, registration-held variables, invalidation, and in-progress call behavior; reauthorize new outbound calls from database state.
- [ ] Add XML tests for unpaid, active, grace, suspended, canceled, disabled, missing, and malformed relationships.
- [ ] Verify another paid DID continues working when one subscription is suspended.

Gate: portal bypass and stale client credentials cannot authorize calls through an unpaid or foreign DID. Real inbound/outbound tests confirm safe fallback behavior.

### Phase 6 — Customer portal rewrite around purchased numbers

Deliverable: choose → pay → configure, with no provider setup steps.

- [ ] Replace the provider/number submission wizard with Browse Numbers, checkout, and activation progress.
- [ ] Build My Numbers with number, service status, monthly price, next renewal, and Configure action.
- [ ] Build a number workspace with Overview, Extensions, Call Routing, Time Conditions, IVR, Queues, and Subscription access.
- [ ] Add an empty state for customers without numbers and an active-but-unconfigured state after purchase.
- [ ] Provide customer extension create/edit/disable/reset through tenant authorization and one-time credential delivery.
- [ ] Reuse inbound schedule validation, Persian/Jalali input handling, timezone semantics, and closed actions.
- [ ] Reuse IVR draft/publish/restore, uploads, valid destinations, and safe missing-prompt behavior.
- [ ] Reuse queue membership, availability, fallback, and enabled-state validation under the tenant boundary.
- [ ] Allow a customer to select an owned eligible outbound caller-ID DID; derive its gateway automatically.
- [ ] Show when an extension/IVR/queue is shared across numbers and warn which routes an edit affects.
- [ ] Display billing and technical status separately; do not label admin approval as confirmed registration.
- [ ] Remove customer gateway selection, server/credential submissions, BYOD writes, and free claim/release endpoints.
- [ ] Ensure obsolete write URLs return an authorized denial or are removed; changing the sidebar alone is insufficient.
- [ ] Adapt dashboard/notifications to purchase, setup, renewal, suspension, and activation states.
- [ ] Verify responsive Persian RTL pages, keyboard controls, errors, loading states, and stale price/availability messaging.
- [ ] Retain existing reporting/recording/live access according to explicit permissions without expanding those products.

Gate: a customer can buy and configure the full requested call flow without understanding provider infrastructure; prohibited fields cannot be changed through direct requests.

### Phase 7 — Renewals, customer billing, suspension, and recovery

Deliverable: subscription service continues or expires predictably each month.

- [ ] Add customer Billing with owned invoices, payment receipts/history, next due date, retry payment, and cancellation status.
- [ ] Generate exactly one renewal invoice per subscription period using uniqueness and idempotent scheduled jobs.
- [ ] Send deduplicated reminders and overdue notifications through configured channels after commit.
- [ ] Use customer-initiated renewal payment initially; add automatic debit only after verifying provider capability and authorization.
- [ ] Apply verified renewal payments exactly once, including early payment and multiple overdue invoices.
- [ ] Implement grace/past-due/suspension policy without changing unrelated subscriptions or customer login access.
- [ ] Recover service after verified overdue payment using the defined service-period policy.
- [ ] Test month boundaries, short months, leap years, timezone display, scheduler delay, and repeated job execution.
- [ ] Add admin delinquency, activation failures, and payment reconciliation queues with constrained repair actions.
- [ ] Expose failed jobs/worker health and invoice generation lag; payment records must survive notification failure.
- [ ] Test service denial despite a delayed scheduler and restore despite a duplicate payment callback.

Gate: one billing cycle, failed renewal, suspension, and recovery work end to end without manual XML changes.

### Phase 8 — Cancellation, refunds, quarantine, and safe resale

Deliverable: ending a subscription never leaks prior ownership or loses financial history.

- [ ] Add cancel-at-period-end and undo-cancellation while still eligible; clearly state the paid-through date.
- [ ] Add audited admin immediate suspension/cancellation and explicit refund/credit records.
- [ ] End assignment and move the number to quarantine only when the release policy allows it.
- [ ] Revoke old DID inbound/outbound/caller-ID associations and invalidate stale configuration references.
- [ ] Keep shared tenant extensions/IVR/queues that other purchased numbers still use.
- [ ] Remove prior DID prompts/configuration from the resold number's view without exposing historical recordings or invoices.
- [ ] Preserve historical owner IDs for calls/recordings and ensure importers do not attribute delayed old calls to the new owner.
- [ ] Protect against old callbacks, queued jobs, wizard drafts, live cache entries, and IVR markers reactivating a released assignment.
- [ ] Require admin readiness review after quarantine; infrastructure incidents cannot silently return stock to sale.
- [ ] Test tenant A cancellation → quarantine → tenant B purchase, with tenant A unable to control or view the new service.

Gate: number resale is isolated in both live routing and historical data; all prior financial records remain intact.

### Phase 9 — Pilot, rollout, and removal of obsolete flows

Deliverable: production-ready release with documented operations and recovery procedures.

- [ ] Run relevant full application regressions and MySQL concurrency tests against isolated test data.
- [ ] Exercise payment sandbox and provider verification/reconciliation; validate live payment with a controlled purchase before broad launch.
- [ ] Migrate an explicitly classified pilot tenant and number without changing the Sofia profiles or working gateway.
- [ ] Test softphone registration, inbound open/closed hours, IVR selection/fallback, queue ringing/fallback, outbound, and authorized caller ID.
- [ ] Test suspended/unknown DID, foreign extension, forged caller ID, unauthorized gateway, and static fallback denial on FreeSWITCH.
- [ ] Confirm customer changes take effect without restart or manual XML files; distinguish any infrastructure maintenance from CRUD.
- [ ] Exercise renewal, cancellation, failed notification, payment reconciliation, and application rollback runbooks.
- [ ] Reconcile database assignment, subscription, invoice, payment, and inventory counts before/after migration.
- [ ] Launch gradually using checkout and enforcement feature flags; monitor XML latency/errors and billing outcomes.
- [ ] Keep entitlement enforcement effective during rollback of portal changes; never regain service by disabling the billing screen.
- [ ] Retire obsolete controllers/routes/views only after reference searches, data retention review, and pilot acceptance.
- [ ] Update single-panel, customer setup, admin setup, billing, and deployment documentation to describe the final product.

Gate: the pilot satisfies all release acceptance criteria below, and rollback preserves paid ownership and financial records.

## 7. Suggested screens and API ownership

This is a navigation proposal; exact URLs can be selected during implementation.

| Admin panel | Customer portal |
| --- | --- |
| Number Inventory / preparation / publication | Browse Numbers / monthly offer |
| Provider Gateways / technical readiness | Checkout / payment result |
| Plans / versions / feature limits | My Numbers / per-number workspace |
| Customers / membership / account status | Extensions / one-time credentials |
| Subscriptions / service status | Inbound Routing / Time Conditions |
| Invoices / payments / refunds | IVR / Queues |
| Activation failures / reconciliation | Billing / invoices / renewal / cancellation |
| Audit history / operational tools | Tenant staff permissions if needed |

Example new customer routes: `/numbers`, `/numbers/{number}/checkout`, `/my-numbers`, `/my-numbers/{number}`, `/billing`, `/billing/invoices/{invoice}`. Reservation and purchase are authorized mutations, never GET side effects. Number configuration routes require current assignment plus permission; billing routes require financial ownership. Payment notification routes use provider verification rather than customer session authentication and expose no invoice or customer data in their responses.

## 8. Migration and rollback strategy

1. Use additive migrations and feature flags. Do not edit historical migrations to simulate a new install.
2. Keep existing Blucom internal resources in their workspace unless an explicitly reviewed manifest transfers them.
3. Create a deliberate legacy/internal entitlement for preserved baseline DIDs. It must be scoped and audited, with an expiry/review policy; do not exempt every global gateway or every record missing a subscription.
4. For migrated customer DIDs, preserve IDs and call behavior. Establish assignment/subscription records with a documented effective date and terms; do not invent historical payments or charge immediately without an agreed transition.
5. Reassign complete graphs of dependent records and media. Validate both old ownership logs and records created after consolidation.
6. Deploy customer access boundaries before exposing catalog purchase. Deploy subscription enforcement before paid activation.
7. Turn on checkout for a pilot cohort, then renewals, then broader availability.
8. Application rollback disables new purchases while preserving reservations, verified payments, ownership, and call authorization. Reconcile in-flight payments before reopening sale. Avoid schema downgrade once financial records exist.

Any required FreeSWITCH infrastructure adjustment follows AGENTS.md: inspect and back up actual config, make one controlled change, validate XML, reload only what is needed, check Sofia, test registration/inbound/outbound, inspect logs, and document. Existing customer static XML is removed only after equivalent dynamic behavior and deny/fallback behavior are verified.

## 9. Comprehensive release acceptance checklist

### Admin commercial operations

- [ ] Multiple admins can manage inventory and plans without exposing secrets.
- [ ] A draft/unready/disabled/quarantined/owned number cannot be purchased.
- [ ] Every published number shows an unambiguous monthly price and included capabilities.
- [ ] Plan/price edits preserve existing commercial snapshots and issued invoices.
- [ ] Admin billing views link customer, number, subscription, invoices, payments, and refunds.
- [ ] Sensitive overrides have authorization, a required reason, and an audit event.

### Customer journey

- [ ] A customer can choose, pay, receive ownership, and configure one number.
- [ ] Multiple customers and multiple purchased numbers per customer work independently.
- [ ] Customers configure extensions, hours, IVR, and queues without provider settings.
- [ ] Shared extensions/menus/queues have clear effects across numbers.
- [ ] Customer staff cannot purchase or change billing unless explicitly authorized.
- [ ] Price changes or lost availability during checkout produce a clear recoverable result.
- [ ] Pending payment, paid-but-unready, unconfigured, active, overdue, and suspended states are understandable.
- [ ] Credentials are delivered once over authenticated no-store responses and never appear in provider-facing UI data or logs.

### Payment and billing integrity

- [ ] Forged return URLs and notifications cannot activate service.
- [ ] Amount/currency/invoice mismatches and reused provider references fail safely.
- [ ] Concurrent buyers, duplicate callbacks, retries, and late success cannot double-assign a number.
- [ ] Paid-but-unfulfilled orders appear in reconciliation and have a defined refund/fulfillment path.
- [ ] Monthly boundaries and scheduler retries produce one invoice per service period.
- [ ] Payment failures suspend service according to policy while billing remains accessible.
- [ ] Cancellation and refunds preserve original payments/invoices and assignment history.
- [ ] Rial/toman display and gateway settlement units are verified with representative amounts.

### Tenant and telephony isolation

- [ ] Tenant A cannot view, edit, reset credentials for, or call tenant B's resources through forged IDs.
- [ ] Only owned eligible DIDs can become caller ID; gateway selection comes from the DID configuration.
- [ ] Disabled gateways, malformed configuration, unknown DIDs, and unauthorized destinations fail safely.
- [ ] Unauthenticated provider traffic cannot enter unrestricted default/outbound dialing.
- [ ] Billing denial cannot fall through to permissive static routes.
- [ ] Subscription expiry is enforced even when background jobs are late.
- [ ] One suspended DID does not break another eligible DID or its shared extensions.
- [ ] Reassigned DIDs do not expose old calls, recordings, media, invoices, or cached/live data.
- [ ] XML remains escaped, minimal, deterministic, and free of debug output and secrets.

### Real-call and operations validation

- [ ] Registration, inbound, outbound, caller ID, office-hours routing, IVR, and queue flows pass on the actual deployment.
- [ ] Baseline SIP profiles and provider behavior are preserved.
- [ ] Customer CRUD needs no FreeSWITCH restart or per-customer XML editing.
- [ ] Payment/renewal jobs, reconciliation, notifications, and XML-CURL have observable health and failure recovery.
- [ ] Logs omit OTPs, SIP/provider/payment secrets, cookies, and raw sensitive payloads.
- [ ] Backup, ownership migration, rollback, and payment reconciliation procedures are exercised.
- [ ] Historical documents and customer-facing plans match the deployed subscription product.

## 10. Recommended first implementation slice

The **Phase 1 code foundation is implemented**. Complete the deployment/ownership checks in Phase 0–1, then implement one admin-published monthly offer, one-number checkout, verified payment, subscription-aware XML routing, and a customer number workspace that reuses existing schedule/IVR/queue services. Add renewal and release automation after that vertical slice is verified.

Rewriting the portal before correcting shared ownership would produce the new screens while retaining the wrong customer access model. Preserve the working call engine and invest first in the ownership, purchase, and entitlement boundaries that make the new experience valid.
