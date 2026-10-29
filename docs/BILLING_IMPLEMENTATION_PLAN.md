# Subscription billing implementation plan

For the new one-time line purchase and wallet-funded renewal paths, see [Wallet billing journeys](WALLET_BILLING_JOURNEYS.md). The annual billing description below documents the earlier model, which remains supported for existing contracts.

Status: application implementation is behind `COMMERCE_SEPARATED_BILLING_ENABLED=false` by default. The production rollout and migration of existing combined-price customers are pending.

## Implemented application behavior

- Published plan versions can carry a monthly price and limits for numbers, extensions, queues, and IVRs. New annual number offers show their annual amount. First checkout charges one month of the plan and one year of the number; later number purchases charge the annual number amount only.
- Verified, settled paid orders create a tenant subscription and number term when the number is technically allocated. Existing monthly commerce orders retain their legacy path. FreeSWITCH still reads live database entitlements; no SIP/RTP or static FreeSWITCH configuration was changed.
- The billing job issues separate monthly and annual renewal invoices, retries application of settled payments, and sends owner SMS plus in-app reminders. An undelivered SMS prevents a pending price increase from starting its 30-day notice clock.
- The customer Billing page shows terms, capacity, invoices, a printable receipt, upgrade/downgrade, cancellation, renewal, and early number release. A paid number that is not allocated after 24 hours offers a staff-reviewed refund or replacement request. Staff can see these requests and annual numbers eligible for release review.
- New call authorization stops at the end of a 7-day grace period, evaluated from persisted timestamps. Past records and Billing remain available during suspension. Number release and refund are never performed automatically for nonpayment.

## Release requirements and current limits

1. Keep `COMMERCE_SEPARATED_BILLING_ENABLED=false` until existing combined monthly customers are classified and their annual number price is approved. Run `php artisan commerce:legacy-billing-report` for a read-only report of each tenant's latest paid-through date and lines needing a free bridge. The application does **not** silently convert or charge legacy customers.
2. Configure a live payment gateway and `KAVENEGAR_API_KEY` plus `KAVENEGAR_SMS_SENDER`; run the Laravel scheduler. A missing SMS configuration leaves reminders in-app and holds increases at the old price.
3. Confirm official tax-invoice requirements and implement that document format if required. The current printable invoice and payment receipt show the saved purchase snapshot and bank reference; they are not labeled as an official statutory tax invoice.
4. Staff must complete refund or replacement requests through an approved external operational process and record the result in the existing audit/support process. This application records the customer request and staff queue; it does not issue a settled bank refund or assign an unrelated replacement DID automatically.
5. Pilot with a test tenant, verify live Mellat/Zibal settlement and SMS delivery, then test Zoiper registration and real inbound/outbound calls at current term, grace, suspension, and restoration boundaries. No production FreeSWITCH configuration was changed as part of this work.

## Product contract

- One tenant has one monthly application subscription. Its immutable plan version defines the monthly price and tenant-wide limits for **numbers, enabled extensions, enabled queues, and enabled IVRs**. A published version also snapshots feature availability and price; edits create a new version.
- Each assigned DID has its own **annual number term**, annual price, renewal date, and payment history. Buying another DID does not multiply plan limits or charge for another application plan.
- A customer must have a service-eligible monthly plan *and* a service-eligible annual term for a DID before that DID can make or receive new calls. A tenant can retain its account and records when telephony is suspended.
- One checkout can present the plan and first number together with separate, clearly labeled invoice items and terms. Subsequent number purchases charge only the annual number amount. The customer sees the monthly and annual totals and renewal dates before paying.
- Billing is in integer `IRT`; store UTC instants and display Persian dates in `Asia/Tehran`. The annual term is a recurring service right, not an immediate stock release at expiry. Exact invoice/tax wording requires business review.

## Recommended default policy for product approval

| Event | Customer-visible behavior | Access rule |
| --- | --- | --- |
| First purchase | Verify payment, then show plan activation and number provisioning as separate progress states. Start paid terms when their respective service becomes usable; reconcile paid-but-unready cases. | No calls before both are eligible. |
| Monthly renewal | Issue invoice 7 days before the current period ends; due at period end. Self-service payment and retry remain available. | Service continues through a proposed 7-day grace period after the due time. |
| Annual DID renewal | Issue invoice 30 days before the number term ends; due at term end. Show it separately from the plan invoice. | Only that DID continues through a proposed 7-day grace period. |
| Grace expires | Show a persistent reason, exact deadline, amount, and Pay now action. Notify owner and permitted billing contacts. | Stop **new** inbound and outbound calls; keep assignment and configuration intact. Do not cut off calls already in progress. |
| Payment succeeds late | Recheck verified, settled payment and current assignment under lock; extend from the previous paid-through date when continuous, otherwise from the approved reactivation date. | Restore new calls automatically; never require XML edits or a FreeSWITCH restart. |
| Plan cancellation | Schedule for the end of the paid period, with an undo option before then. Explain what happens to annual DIDs and any remaining term before confirmation. | No automatic DID release or resale. |

The 7-day grace, reminder timing, late-renewal anchor, and cancellation/refund treatment are proposed defaults. Approve them before customer-facing rollout. Do not treat a missed job as extra grace: evaluate stored timestamps in authorization paths.

## Customer journey and screens

1. **Discover plans:** show monthly price, all four limits, an example annual number price, and the two billing rhythms. Public plans link to customer registration/login. Avoid showing a number's annual price as a monthly total.
2. **Choose a plan:** after registration, offer a short plan comparison with a recommended fit. Show the four capacities as `used / included` for existing tenants. Keep the choice editable through review.
3. **Add a number:** browse annual DID prices and availability, with a clear count such as `1 of 3 numbers used`. At capacity, show the next qualifying plan and a comparison of added monthly cost instead of a dead-end error.
4. **Review and pay:** show monthly plan item, annual DID item, amount due today, future monthly renewal, future annual renewal, and relevant terms. Preserve quote/idempotency and recover from stale availability or changed prices without double charging.
5. **Set up service:** show Payment confirmed, Number being prepared, Configure answering, and Ready. Keep billing state distinct from SIP/route readiness. Existing setup guidance can continue after the subscription is eligible.
6. **Use and manage:** a Billing workspace shows current plan, four usage meters, next plan and DID invoices, payment history, receipts, payment retry, change plan, and annual DID renewal. Number workspace shows its own annual paid-through date.
7. **At a limit:** inline message says what is used, what the selected plan permits, and what action helps. Example: “3 of 3 extensions are active. Disable one or upgrade to a plan with 5.” Preserve unsaved form data and return to the task after upgrade. Never imply that buying another DID raises extension capacity.
8. **Before suspension:** send one concise reminder at invoice issue and escalating reminders near due date and grace expiry; deduplicate messages. Show a persistent in-app banner. On suspension, allow login, Billing, invoices, support, and read-only access to past records; explain that new calls are paused and numbers are retained.

Design for Persian RTL, mobile screens, local date display, accessible status text, and an owner/billing-contact notification preference. Never send OTPs, payment secrets, or SIP credentials in reminders.

## Plan changes

- **Upgrade:** preview new limits, price difference for the remaining monthly period, amount due now, and next full renewal amount. Apply the new version only after verified payment. Keep the current billing anchor. An unpaid upgrade remains pending and cannot grant capacity. If an overdue invoice exists, include it in the review and collect it before activation.
- **Downgrade:** run a server-side eligibility check against numbers, enabled extensions, enabled queues, and enabled IVRs, plus pending number reservations. Give a concrete checklist of excess resources and links to each. A DID requires a deliberate release workflow; explain routing loss and annual-term/refund consequences. Do not silently release, delete, or disable resources. Queue the downgrade for the next monthly boundary after the tenant is eligible; recheck eligibility under lock at execution. If usage grows again, keep the existing plan and tell the customer why.
- **Price changes:** preserve the subscribed plan version and price until the published migration rule and customer notice say otherwise. Do not rewrite historical invoices or annual terms.

## Data changes

1. Add a tenant-scoped `tenant_subscriptions` record with plan version, frozen monthly price/currency, original billing anchor, current paid-through time, due/grace times, status, pending change, and revision. Enforce one current subscription per tenant. Keep immutable term history or period records.
2. Evolve `plan_versions` to include an explicit `numbers` limit and monthly plan price. Validate all four limits and price before publication; published versions remain immutable. Do not infer unlimited capacity from a missing key.
3. Evolve number inventory/offers to carry an annual DID price and immutable annual term snapshot. `number_assignments` remain the ownership history. Replace the per-number monthly plan interpretation of `number_subscriptions` with an annual DID term or migrate into a new `number_terms` table; do not repurpose old fields in place without a data migration.
4. Generalize orders/invoice items beyond the current single DID plus monthly total. Each issued invoice identifies plan renewal, DID renewal, or plan change; records exact service period, due time, immutable item descriptions/amounts, and one settlement state. Unique `(subject, service period, invoice kind)` keys plus idempotency keys prevent duplicate renewal invoices and charges.
5. Reuse existing Mellat/Zibal initiation, verification, settlement, reconciliation, and audit boundaries for the new invoice kinds. Provider callbacks are evidence to verify server-side, never direct entitlement grants. Keep receipt, refund, and adjustment records append-only.

## Enforcement and jobs

- Introduce one tenant entitlement decision for app access/capacity and one number entitlement decision for calling. All customer writes, FreeSWITCH directory/dialplan/XML-CURL, queue configuration, and outbound caller-ID selection use those decisions. Use the database as authority and evaluate period/grace timestamps per request; do not toggle `sip_numbers.enabled` merely for nonpayment, because that field also carries operational intent.
- Number purchase and setup require an active or grace-eligible tenant subscription and available number capacity. Capacity checks lock the tenant/subscription and include active assignments plus held reservations so concurrent purchases cannot exceed the limit.
- Inbound `public` lookups still use normalized `destination_number`; outbound still resolves the authenticated extension, owned eligible DID, and approved gateway. A suspended tenant or DID yields no new call route. Preserve the existing contexts, gateway, and Sofia profiles.
- Billing jobs issue monthly/annual invoices, send deduplicated reminders, mark past-due/suspended states, apply due plan changes, and reconcile payments. Make jobs idempotent, lock affected records consistently, and use scheduler overlap/one-server protection where appropriate. A queued notification failure must not affect payment or entitlements.
- Retain billing access and historical data during suspension. Support/admin override, if offered, must be time-limited, reasoned, audited, and visible to the customer; it must not be a silent bypass in SIP authorization.

## Migration from today's model

Current code sells a DID with one inclusive **monthly** amount and plan version. `LineEntitlementService` derives tenant limits from the largest eligible DID plan, `NumberSubscription` holds the monthly period, and checkout invoices contain one DID item. The catalog and marketing copy present a monthly combined price. These are the specific flows to replace; simply adding a number limit would leave the old charge and entitlement model in place.

1. Add new schema and read paths behind a feature flag without changing live entitlements. Build a report classifying legacy non-commerce lines, paid commerce lines, unpaid reservations, and paid-but-unfulfilled orders.
2. Stop publishing new monthly inclusive offers. Give existing customers a documented transition: preserve their paid-through dates and contracted price; do not retroactively charge an annual DID fee. Create explicit migrated plan and DID term records with provenance and customer notice, or grandfather the old product until renewal. Never silently convert an active customer to a more expensive product.
3. Run the new entitlement decision in shadow mode and compare it with current decisions for registration, inbound, outbound, and limits. Resolve differences before activation. Pilot with an internal/test tenant, then a small customer cohort.
4. Switch new checkouts to separate monthly/annual items. Enable renewals and reminders, then enforce new entitlement decisions for migrated tenants. Keep legacy behavior only for explicitly classified records during the transition; set an end date and remove the bypass after reconciliation.
5. Verify payment recovery, overdue and recovery states, Zoiper registration, inbound and outbound calls, caller ID, queue/IVR routing, and cross-tenant isolation before expanding rollout. Keep current FreeSWITCH configuration unchanged throughout.

## Delivery slices and acceptance

1. **Commercial rules and schema:** approve prices, grace, renewal anchors, tax/receipt wording, cancellation/refund rules; add four-limit plan versions and tenant/annual-term records with migration report.
2. **New checkout:** clear dual cadence, plan subscription, annual DID purchase, immutable invoice items, settlement-driven allocation; test concurrent reservations and payment retries on MySQL.
3. **Renewals:** scheduled invoice issuance, Billing workspace, reminders, payment retry/reconciliation; test month-end/leap-year anchors and duplicate job/callback execution.
4. **Entitlements:** unified capacity and SIP checks, grace and suspension, automatic restoration; test unknown/disabled records, stale jobs, tenant isolation, approved gateway/caller-ID authorization, and live SIP calls.
5. **Plan changes:** upgrade quote/payment/proration and guided downgrade with release checklist and next-cycle recheck; test concurrent resource creation, number holds, failed payments, and unchanged historical invoices.
6. **Rollout:** shadow comparison, internal pilot, customer cohort, production acceptance log, support playbook, and removal of temporary legacy paths.

Done means one tenant pays one monthly plan invoice, each DID pays on an annual schedule, all four limits enforce at creation and concurrent purchase, overdue service pauses only after the stated grace, payment restores new calls without manual SIP work, and the customer can understand every charge and next action from Billing.
