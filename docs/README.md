# Customer number subscriptions

Updated: 2026-10-07.

Product goal: **buy a number → pay → configure → renew**.

This directory is the working backlog and implementation plan for that journey. Historical feature and deployment documents have been replaced. Plans describe work still to do; they are not evidence of deployment or completed functionality.

## Read in this order

| Document | Purpose |
| --- | --- |
| [Product scope](PRODUCT_SCOPE.md) | Customer journey, commercial rules, and unresolved decisions. |
| [Roadmap](IMPLEMENTATION_ROADMAP.md) | Remaining phases, dependencies, deliverables, and acceptance gates. |
| [Next phase](NEXT_PHASE.md) | Concrete implementation plan for reservation, invoices, and Mellat checkout. |
| [Checkout backend](CHECKOUT_BACKEND.md) | Implemented reservation/invoice contracts, lifecycle, and operations. |
| [Mellat integration](MELLAT_INTEGRATION.md) | Admin merchant settings, payment endpoints, integrity, reconciliation and live-bank gates. |
| [Customer checkout](CUSTOMER_CHECKOUT.md) | Customer routes, guarded payment states and Farsi RTL screen behavior. |
| [Production payment testing](MELLAT_PRODUCTION_TESTING.md) | Deployment confirmation, admin setup and controlled merchant test steps. |
| [Architecture](SUBSCRIPTION_ARCHITECTURE.md) | Data model, payment integrity, call entitlements, and isolation. |
| [Release checklist](RELEASE_CHECKLIST.md) | Customer activation, ownership migration, tests, pilot, and rollback. |


## Starting point

The checkout has separate Customer accounts, OTPs, customer sessions and permissions, independent business tenants, admin customer management, and a read-only ownership audit. Existing extension/answerer setup, schedules, IVR, queues, reporting, recordings, and live monitoring are reusable services. Preserve them while changing their customer entry points and authorization where necessary.

Admin stock preparation, technical review, monthly plans/versions, and immutable monthly offers are implemented and deployed (2026-10-06). Prices are positive integer toman amounts (`IRT`). Mellat is the selected payment provider.

Phase B billing/allocation schemas and customer-authorized quote, exclusive reservation, immutable pro forma invoice, and expiry services are implemented locally (2026-10-07), with exposure disabled. Batch 3 Mellat initiation/verification/settlement, admin encrypted merchant settings, payment reconciliation and limited customer payment endpoints are also implemented locally. Customer catalog, quote confirmation, order/pro forma history and Farsi RTL Mellat checkout are now implemented; see [customer screens](CUSTOMER_CHECKOUT.md). Controlled pilot purchasing requires enabled exposure flags and configured Mellat. Paid allocation, active subscriptions, renewals and subscription-based call authorization remain unimplemented.

The customer and inventory migrations are applied in production, and `my.blucom.ir/login` responds over HTTPS. Authenticated pilot/customer isolation checks and legacy ownership transfer remain open. No FreeSWITCH configuration change, provider payment, or SIP call was performed. FreeSWITCH was subsequently recovered and operational health checked; real call acceptance remains open. See the dated records in the [release checklist](RELEASE_CHECKLIST.md).

## Immediate implementation target

Implement batch 4 of [Mellat checkout](NEXT_PHASE.md#4-atomic-allocation-and-repair): atomic paid allocation and repair using the completed reservation/payment backend. Customer checkout screens are implemented; admin fulfillment/repair screens remain open. Complete the prerequisite deployment and ownership assessment in the [release checklist](RELEASE_CHECKLIST.md). Do not open paid checkout until verified payment, service enforcement, and customer configuration pass their gates.

`AGENTS.md` remains authoritative for FreeSWITCH operations. This roadmap expands the customer product scope without changing those infrastructure rules.

## Implemented admin inventory phase

- `/admin/sip-numbers?scope=stock`: unowned stock, lifecycle/publication/recorded-review filters, stock creation and review entry point. Assigned/internal numbers keep their existing path.
- `/admin/inventory/{number}`: settings, explicit outbound destination prefixes, technical review, publication, withdrawal, price history and disable/re-enable. Quick Setup links to this preparation path without requiring a customer owner or answerer.
- `/admin/plans`: monthly inclusive plans, draft limit editing, immutable published versions and archive. Initial limits cover tenant-wide extensions, queues and IVR menus; enforcement arrives with entitlements.
- `NumberInventoryService`, `NumberReadinessService`, `NumberOfferService`, `PlanService`, and `CommerceAudit` implement transactional state changes and secret-free business events.
- Gateway changes require all current offers to be withdrawn first. A gateway revision invalidates recorded technical reviews even for same-second credential changes. Stale stock forms are rejected by inventory revision.
- Existing SIP resources have `inventory_state=null`; no legacy record was converted or republished. Commerce stock cannot use the older free assignment/release service. Offer/plan history cannot be removed through the admin workflow.

Apply `2026_10_06_000001_create_number_inventory_and_offers` through the normal deployment process, with catalog and checkout off. `config/commerce.php` records IRT and Mellat. Apply `2026_10_07_000002_create_checkout_records` when deploying the new backend. Catalog, reservation and checkout flags default to false. Quote/reservation services check their flags; payment initiation checks checkout exposure and Mellat readiness. Apply `2026_10_07_000003_create_payment_gateway_settings` for the admin/payment endpoints; its initial Mellat gateway is disabled without credentials. See [backend operations](CHECKOUT_BACKEND.md).

See the [release checklist](RELEASE_CHECKLIST.md) for actual validation results and outstanding production gates.
