# Customer number subscriptions

Updated: 2026-10-06.

Product goal: **buy a number → pay → configure → renew**.

This directory is the working backlog and implementation plan for that journey. Historical feature and deployment documents have been replaced. Plans describe work still to do; they are not evidence of deployment or completed functionality.

## Read in this order

| Document | Purpose |
| --- | --- |
| [Product scope](PRODUCT_SCOPE.md) | Customer journey, commercial rules, and unresolved decisions. |
| [Roadmap](IMPLEMENTATION_ROADMAP.md) | Remaining phases, dependencies, deliverables, and acceptance gates. |
| [Next phase](NEXT_PHASE.md) | Concrete implementation plan for reservation, invoices, and Mellat checkout. |
| [Architecture](SUBSCRIPTION_ARCHITECTURE.md) | Data model, payment integrity, call entitlements, and isolation. |
| [Release checklist](RELEASE_CHECKLIST.md) | Customer activation, ownership migration, tests, pilot, and rollback. |


## Starting point

The checkout has separate Customer accounts, OTPs, customer sessions and permissions, independent business tenants, admin customer management, and a read-only ownership audit. Existing extension/answerer setup, schedules, IVR, queues, reporting, recordings, and live monitoring are reusable services. Preserve them while changing their customer entry points and authorization where necessary.

Admin stock preparation, technical review, monthly plans/versions, and immutable monthly offers are implemented in this checkout. Prices are positive integer toman amounts (`IRT`). Mellat is the selected future payment provider.

Customer purchasing, checkout, verified payment, subscriptions, invoices, renewals, and subscription-based call authorization are not implemented. Reserved purchase/billing permission keys do not constitute working commerce screens.

Dedicated customer portal production activation and legacy ownership transfer were pending in the last documentation update. Recheck deployment state before rollout. This implementation ran migrations only against disposable SQLite/MariaDB test databases. No production migration, FreeSWITCH operation, provider payment, or SIP call was performed.

## Immediate implementation target

Implement [reservations, invoices, and Mellat checkout](NEXT_PHASE.md). In parallel with code preparation, complete the prerequisite deployment and ownership assessment in the [release checklist](RELEASE_CHECKLIST.md). Do not open paid checkout until verified payment, service enforcement, and customer configuration pass their gates.

`AGENTS.md` remains authoritative for FreeSWITCH operations. This roadmap expands the customer product scope without changing those infrastructure rules.

## Implemented admin inventory phase

- `/admin/sip-numbers?scope=stock`: unowned stock, lifecycle/publication/recorded-review filters, stock creation and review entry point. Assigned/internal numbers keep their existing path.
- `/admin/inventory/{number}`: settings, explicit outbound destination prefixes, technical review, publication, withdrawal, price history and disable/re-enable. Quick Setup links to this preparation path without requiring a customer owner or answerer.
- `/admin/plans`: monthly inclusive plans, draft limit editing, immutable published versions and archive. Initial limits cover tenant-wide extensions, queues and IVR menus; enforcement arrives with entitlements.
- `NumberInventoryService`, `NumberReadinessService`, `NumberOfferService`, `PlanService`, and `CommerceAudit` implement transactional state changes and secret-free business events.
- Gateway changes require all current offers to be withdrawn first. A gateway revision invalidates recorded technical reviews even for same-second credential changes. Stale stock forms are rejected by inventory revision.
- Existing SIP resources have `inventory_state=null`; no legacy record was converted or republished. Commerce stock cannot use the older free assignment/release service. Offer/plan history cannot be removed through the admin workflow.

Apply `2026_10_06_000001_create_number_inventory_and_offers` through the normal deployment process, with catalog and checkout off. `config/commerce.php` records IRT and Mellat. Exposure flags are reserved for future customer endpoints; no customer catalog or checkout route exists in this phase, even if flags are set true.

See the [release checklist](RELEASE_CHECKLIST.md) for actual validation results and outstanding production gates.
