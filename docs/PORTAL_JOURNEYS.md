# Customer and admin journeys

Implemented locally on 2026-10-25. This document does not assert production deployment.

## Customer entry and setup

`/start` offers two paths: buy a Blucom number, or request connection of an existing number. The existing-number path collects a normalized number, provider name and optional notes, without asking for credentials. Requests are tenant scoped, deduplicated by normalized number, and reviewed by administrators. Recording a request or resolution does not provision a gateway or activate a line.

The dashboard highlights the next relevant action. Payment return links resolve the authenticated customer's exact order or invoice; an allocated purchase can continue directly to line setup. Line setup first asks who should answer and progressively reveals the relevant choices.

## Admin workspace

`/admin/work` groups allocation issues, payment reconciliation, existing-number requests, connection reviews, customer requests and release reviews. Filters preserve customer context when opening the relevant operational screens.

`/admin/customers/{customer}` provides overview, lines, billing, orders, activity and access tabs for one tenant. Request resolution records an administrator, timestamp, outcome and audit event. Customers can see the outcome. It does not perform a refund, banking operation or provisioning action.

## Billing

`/billing` combines the next payment, invoices and receipts, purchases and preparation, and subscriptions. Existing order URLs remain supported. Initial-purchase invoices continue through their order; recurring invoices use the existing guarded payment service. Uncertain or pending payment attempts do not offer another payment action. Gateway availability and customer billing permissions remain enforced.

## Deployment and validation

Apply the additive migration `2026_10_25_000001_create_line_connection_requests` through the normal release process. No FreeSWITCH configuration changes or restarts are required.

Validation: the full Laravel suite passed with 388 passing tests and 15 skipped tests, and the Vite production build succeeded. New coverage exercises onboarding authorization, tenant isolation, payment handoff, admin workspace isolation, request resolution, and guarded billing actions. Desktop browser checks used disposable local data. Mobile browser verification remains incomplete because the viewport override did not take effect.
