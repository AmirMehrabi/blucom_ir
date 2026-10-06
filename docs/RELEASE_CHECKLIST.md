# Release, migration, and verification checklist

Production checks below remain pending. Local Phase A validation is recorded at the end. Previous feature deployment history is not confirmation of current production state.

## Customer portal prerequisite

- [ ] Back up the database, verify restore access, and record the working SIP registration/inbound/outbound/caller-ID baseline without secrets.
- [ ] Verify customer-account migration status; deploy `2026_10_05_000001_create_customer_accounts` if unapplied through the normal release process.
- [ ] Configure DNS/TLS and validated ingress for `my.blucom.ir`.
- [ ] Set `CUSTOMER_PORTAL_DOMAIN` and a unique `CUSTOMER_SESSION_COOKIE`, distinct from internal sessions, host-only and secure over HTTPS.
- [ ] Refresh route/config/view caches and release long-lived application workers normally; this is not a FreeSWITCH restart.
- [ ] Expire old wildcard session cookies. Verify customer/admin login and logout isolation in both directions.
- [ ] Verify unknown-number denial, disabled accounts/businesses, permissions, downloads, notifications, and private broadcasts using two independent pilot businesses.

## Ownership migration prerequisite

- [ ] Run read-only `customer:ownership-audit --json`; classify tenants, accounts, DIDs, extensions, routes, drafts, IVR, queues/members, media, calls, recordings and live ownership.
- [ ] Treat consolidation history as evidence, not automatic authorization to restore ownership. Leave ambiguous records unchanged until reviewed.
- [ ] Build a dry-run transfer manifest and apply tool with source/destination validation, transaction boundaries, stale-source checks, explicit operator approval of the manifest, and result reporting.
- [ ] Prepare private media moves/copies with verified FreeSWITCH read access and rollback; filesystem work must be recoverable around database commits.
- [ ] Preserve historical reporting/recording attribution and worker/importer ownership. Invalidate obsolete snapshots, session access, staged drafts and broadcasts.
- [ ] Validate one pilot transfer before broader migration. Do not reassign the working baseline automatically or downgrade the additive customer schema.

## Deployment sequence

- [ ] Introduce separate catalog, checkout, and enforcement rollout controls. Checkout/catalog initially off.
- [ ] Apply additive inventory/billing migrations without converting legacy ownership implicitly.
- [ ] Prepare reviewed stock/offers and verify provider readiness through controlled infrastructure checks; approval is not registration evidence.
- [ ] Deploy entitlement enforcement before paid customer activation. Feature flags must never turn a suspended purchased DID into eligible service.
- [ ] Deploy customer workspace and billing, validate provider sandbox, then enable checkout only for the pilot scope.
- [ ] Enable reminders/renewal workers with idempotency, failed-job visibility, invoice lag monitoring, and tested replay/reconciliation.
- [ ] Replace queue XML-writing/reload debt before general queue access. Preserve the existing CSV importer layout compatibility and private media/spool access needed by reused features.

## Automated and browser acceptance

- [ ] Customer/tenant isolation for every read/write/download/broadcast and account-ID namespace collision.
- [ ] Offer readiness, immutable prices/plans, lifecycle transitions, infrastructure-only admin authorization.
- [ ] MySQL concurrency: two buyers, duplicate callbacks, expiry versus late success, publication/withdrawal races, renewals, and limit enforcement.
- [ ] Provider sandbox: forged/mismatched verification, timeouts, retries, duplicate/late events, reconciliation and refunds.
- [ ] XML: unpaid, paid, expired, grace, suspended, canceled, disabled, unknown, foreign and malformed relationships; unauthorized caller ID/gateway/destination.
- [ ] Billing: integer units, anniversary/month-end/leap-year boundaries, early/late payment, repeated jobs, delayed scheduler and failed notifications.
- [ ] Resale: tenant A → quarantine → tenant B, delayed CDRs and stale jobs/callbacks/drafts cannot leak or restore A's access.
- [ ] Existing internal setup, reporting, recordings, IVR, schedules, queues and live monitoring regressions.
- [ ] Customer Persian RTL desktop/mobile, keyboard controls, one-time credentials/no-store, empty/configuration/payment/technical states and stale price recovery.

## Real-call pilot

Inspect actual loaded modules, Sofia profiles, XML-CURL bindings/requests and logs before changes. Back up only affected infrastructure, make the smallest change, validate, and reload only if required. Preserve the working profiles and provider gateway.

- [ ] Verify softphone registration against database-backed directory and correct authenticated context.
- [ ] Test provider inbound to purchased DID, open/closed hours, IVR keys/fallback, queue ringing/wait/fallback, two-way audio and call records.
- [ ] Test outbound approved gateway and authorized caller ID; deny foreign DID, spoofed identity, prohibited destination and unauthenticated provider outbound access.
- [ ] Test unknown/unconfigured/unpaid/suspended DIDs and inspect static fallbacks on the real switch.
- [ ] Suspend one DID while another paid DID remains usable with shared extensions.
- [ ] Verify renewal recovery, canceled service, and assignment isolation.
- [ ] Verify normal number/extension/route/schedule/menu changes require no FreeSWITCH restart or manual customer XML editing.
- [ ] Reconcile stock, assignment, subscription, invoice and payment counts; confirm worker health and private-media access.

## Failure recovery and rollback

- [ ] Disable new checkout during a payment/provisioning incident; preserve pending attempts and continue verification/reconciliation.
- [ ] Keep paid-but-unfulfilled orders visible with audited fulfillment/refund actions. Do not manually flip ownership or erase payments.
- [ ] Preserve financial schemas and verified entitlements when rolling back portal screens. Never roll back to code that grants purchased service without billing checks.
- [ ] Keep call eligibility timestamp-based during scheduler/notification failures; billing remains accessible to suspended customers.
- [ ] Preserve additive customer schema and independent resources if disabling the customer hostname. Do not merge resources back into Blucom automatically.
- [ ] Restore infrastructure only from validated backups after checking active calls; retain historical data and private media.
- [ ] Rehearse payment reconciliation, ownership/media rollback, worker recovery and controlled application rollback before broad launch.

## Evidence to record at each release

Record date/revision, applied migrations, feature-flag scope, tests and actual results, MySQL/provider validation, browser checks, SIP scenarios/outcomes, secret-free reconciliation totals, remaining blockers, backup location and rollback compatibility. Never mark an acceptance gate complete solely because application tests passed.

## Phase A implementation validation — 2026-10-06

Implemented in the application checkout; not deployed. Migration adds plans, plan versions, number offers, commerce audits, stock/review fields and gateway revision. It makes no ownership/routing data changes.

Validation completed:

- Final SQLite regression suite: 193 passed, 1,267 assertions; three opt-in database concurrency tests skipped in this run and executed separately below.
- Disposable MariaDB 11.8.6 using Laravel's MySQL driver: ten commerce feature tests passed (99 assertions); competing publication, gateway-disable/publication, and withdrawal tests passed (three tests, 25 assertions).
- Real Chrome on isolated fixtures: desktop/mobile plan creation, draft/version publication, stock creation, review, IRT publication, withdrawal and replacement with preserved price history passed. No page errors or mobile document overflow. Desktop stock and mobile plans screenshots were inspected.
- Vite production build passed. An existing unresolved `/assets/images/blucom-hero.png` reference remains a build warning.
- Changed PHP files passed Pint and syntax validation; relative documentation links and whitespace checks passed.

No production database, FreeSWITCH configuration/service, live SIP endpoint, or Mellat account was touched. General rollout still requires current production review, deployment, actual MySQL-version validation where different, and live SIP baseline checks. Customer purchases remain unavailable until later phases.

### Reproduce database concurrency tests

Use a disposable database whose name starts with `blucom_commerce_test`; never the application database. The test deliberately runs `migrate:fresh` only after validating this name, the MySQL driver and explicit opt-in. PHP `pcntl` is required. Configure test-only connection variables privately, then run:

```bash
COMMERCE_CONCURRENCY_TESTS=1 DB_CONNECTION=mysql DB_DATABASE=blucom_commerce_test vendor/bin/phpunit --testsuite Integration
```

Ordinary SQLite runs skip the three opt-in concurrency tests. The MariaDB result establishes InnoDB behavior on that version; repeat on the deployment's actual MySQL/MariaDB version before release.

Deploy the additive migration with `COMMERCE_CATALOG_ENABLED=false` and `COMMERCE_CHECKOUT_ENABLED=false`. Test the admin preparation workflow and existing assigned-number XML. No provider rescan, reloadxml, or FreeSWITCH restart is required for this phase.
