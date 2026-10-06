# Customer number subscriptions

Updated: 2026-10-06.

Product goal: **buy a number → pay → configure → renew**.

This directory is the working backlog and implementation plan for that journey. Historical feature and deployment documents have been replaced. Plans describe work still to do; they are not evidence of deployment or completed functionality.

## Read in this order

| Document | Purpose |
| --- | --- |
| [Product scope](PRODUCT_SCOPE.md) | Customer journey, commercial rules, and unresolved decisions. |
| [Roadmap](IMPLEMENTATION_ROADMAP.md) | Remaining phases, dependencies, deliverables, and acceptance gates. |
| [Next phase](NEXT_PHASE.md) | Concrete implementation plan for admin inventory and monthly offers. |
| [Architecture](SUBSCRIPTION_ARCHITECTURE.md) | Data model, payment integrity, call entitlements, and isolation. |
| [Release checklist](RELEASE_CHECKLIST.md) | Customer activation, ownership migration, tests, pilot, and rollback. |

## Starting point

The checkout has separate Customer accounts, OTPs, customer sessions and permissions, independent business tenants, admin customer management, and a read-only ownership audit. Existing extension/answerer setup, schedules, IVR, queues, reporting, recordings, and live monitoring are reusable services. Preserve them while changing their customer entry points and authorization where necessary.

Purchasing, monthly offers, checkout, verified payment, subscriptions, invoices, renewals, and subscription-based call authorization are not implemented. Reserved purchase/billing permission keys do not constitute working commerce screens.

Dedicated customer portal production activation and legacy ownership transfer were pending in the last documentation update. Recheck deployment state before rollout. This documentation rewrite does not inspect production, execute migrations, change FreeSWITCH, or run SIP calls.

## Immediate implementation target

Implement [admin inventory and monthly offers](NEXT_PHASE.md). In parallel with code preparation, complete the prerequisite deployment and ownership assessment in the [release checklist](RELEASE_CHECKLIST.md). Do not open paid checkout until verified payment, service enforcement, and customer configuration pass their gates.

`AGENTS.md` remains authoritative for FreeSWITCH operations. This roadmap expands the customer product scope without changing those infrastructure rules.
