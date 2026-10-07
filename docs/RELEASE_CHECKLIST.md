# Release, migration, and verification checklist

Unchecked production acceptance gates remain pending. Dated records below distinguish local validation from production rollout; earlier deployments do not establish later acceptance results.

## Reservation/pro forma backend — 2026-10-07 (local only)

Phase B schema and reservation/invoice services are implemented; see [contracts and operations](CHECKOUT_BACKEND.md). No deployment, production migration, external payment, SIP call, gateway/profile edit, reload or FreeSWITCH restart was performed for this batch. At the time of this backend batch, Mellat payment endpoints and settings were pending; batch 3 below now implements them locally. Catalog/order/invoice screens, paid allocation and subscription enforcement remain pending.

Local validation:

- Full SQLite regression: 233 passed, 1,579 assertions; ten opt-in MySQL concurrency cases skipped in that run.
- New checkout feature tests on disposable MariaDB 11.8.6 with Laravel's MySQL driver: 23 passed, 102 assertions.
- New MySQL races: five passed, 49 assertions, covering competing buyers, duplicate keys, expiry/new holds, withdrawal and repricing.
- Existing publication/gateway concurrency: three passed, 25 assertions. Existing customer registration concurrency: two passed, 19 assertions after permission locking changed.
- Empty checkout schema migration rollback/reapply passed on SQLite and MariaDB. An issued order blocks schema rollback; immutable records and restrictive foreign keys retain finance history.
- Changed PHP files passed Pint; whitespace checks passed. No frontend changes were made in this backend batch.

Deployment gates for this batch:

- [ ] Back up and apply `2026_10_07_000002_create_checkout_records` through the release process.
- [ ] Keep catalog, reservation and checkout flags off; confirm the additive pointers are null on legacy SIP resources.
- [ ] Confirm Laravel scheduler invocation, shared overlap-lock cache, bounded expiry processing and monitoring of nonzero review counts.
- [ ] Keep additive schemas/history during application rollback; do not discard financial records.

The 15-minute hold is a configurable pilot default; invoices are pro forma, not official tax invoices. Finalize merchant amount units and commercial invoice/activation/late-payment policies before real billing. Batch 3 below adds payment verification/expiry races; allocation races and real payment/refund testing remain later acceptance work. Real call acceptance and Phase C/D gates still apply before paid customer launch.

## Public site, portal separation, and customer registration — 2026-10-07

Implemented and locally validated before deployment; see the production rollout record below. Canonical hosts are
`blucom.ir` (landing/plans/contact), `my.blucom.ir` (customer OTP login and
self-registration), and `admin.blucom.ir` (internal staff login/panel).
`AGENTS.md`, environment examples, Nginx hostnames, and Reverb origin defaults
now use `my.blucom.ir`. Registration adds a separate challenge table and creates
the tenant/customer owner only after successful verification. Existing accounts,
SIP resources, gateway/profile configuration, and XML-CURL endpoint protection
are preserved.

Local validation:

- Full SQLite regression suite: 210 passed, 1,480 assertions; five opt-in MySQL
  concurrency tests skipped in this run.
- Disposable MariaDB 11.8.6, Laravel MySQL driver: both registration concurrency
  tests passed (19 assertions). Simultaneous verification creates one customer
  and tenant; simultaneous requests issue one challenge.
- Real Chrome with isolated SQLite data and a synthetic SMS provider: desktop
  and mobile signup, OTP verification, onboarding, cookie isolation, logout,
  customer login, and denial of admin access passed without page errors. Mobile
  registration used Persian digits. Registration/OTP screenshots were inspected.
- Landing, plans, and contact have no horizontal overflow at 320, 375, 768,
  and 1,440 pixels. Admin/customer login scripts and registration script parsed.
- Production Vite build passed, with the existing unresolved
  `/assets/images/blucom-hero.png` stylesheet warning. Laravel route caching
  succeeded using an isolated cache file; domain/redirect checks also passed
  with compiled routes. Changed PHP files passed Pint; whitespace checks passed.

Deployment gates:

- [x] Back up the database and deploy through the existing release process.
- [x] Apply `2026_10_07_000001_create_customer_registration_challenges`; rebuild
  assets and config/route/view caches.
- [x] Verify DNS/TLS for the three canonical hosts and secure host-only cookies.
- [x] Update any explicit `REVERB_ALLOWED_ORIGINS` deployment setting to include
  `my.blucom.ir` and restart the Reverb worker through the deployment process.
- [ ] Verify public pages, customer links/registration/login/logout, guest admin
  redirects, and existing staff login using controlled production accounts.
- [ ] Verify real SMS delivery with a controlled mobile; synthetic-provider tests
  do not establish production delivery.

No production database, SMS provider, DNS/TLS, Nginx process, or FreeSWITCH
configuration/service was changed during implementation. Rollback must preserve
newly registered customers/tenants and the additive schema.

## Production rollout — portal domains and registration, 2026-10-07

Application revision `d1f3fd6a49a37bcca9999f15f23627b2b617e728` was pushed to
master and deployed successfully by the existing authenticated push webhook.
The previous release `a88ae1adbcfcaf419bba99d9617c2fd583281236` remains available
for rollback. The deployment pipeline passed 210 tests (1,480 assertions), skipped
five opt-in concurrency tests, built assets, optimized caches, and applied the
registration challenge migration in batch 15 before switching the release.

Private backup directory:
`/home/ammir/deploy-backups/portal-domains-20261007T074342Z`.
It contains a mode-0600 transaction-consistent database dump (206,689,386 bytes),
the previous release reference, and environment/Nginx snapshots. Secrets are
retained only in private server backup/configuration files.

Production environment now explicitly selects `blucom.ir`, `admin.blucom.ir`,
and `my.blucom.ir`. Reverb's explicit origin list includes the customer hostname.
Nginx's canonical server names were updated, validated with `nginx -t`, and
reloaded. The installed certificate covers `*.blucom.ir` and `blucom.ir`.
Secure host-only cookies were preserved. Reverb was explicitly restarted after
its deployment restart signal left the old worker running; both Reverb and the
live monitor are active with refreshed application processes.

Verified through public HTTPS and, for admin redirects, directly at the origin:

- Landing, plans, and contact return 200 on the main domain. Homepage login/signup
  links point to the customer portal. Main-domain `/login` and `/register` redirect
  to the corresponding customer pages.
- Admin root/dashboard redirect guests to `https://admin.blucom.ir/login`, which
  returns 200. Admin `/register` is unavailable. Customer root/dashboard redirect
  guests to customer login; customer login/signup return 200; admin customer
  management routes are unavailable on the customer hostname.
- Marketing links from the customer portal return to the main domain.
- Admin/customer sessions use distinct Secure, HttpOnly, host-only cookies.
- Customer registration validates an empty JSON submission with a real CSRF token
  and session (422 with name/business/mobile errors). Registration POST endpoints
  are unavailable on public/admin hosts. No customer/tenant was created and no
  SMS was sent by these checks.
- The XML-CURL endpoint denies an unauthenticated main-domain request with 401
  and minimal XML; the customer hostname returns 404 with safe XML.
- Real Chrome rendered the public homepage/plans and customer signup, and followed
  the admin-root redirect to admin login. The deployed signup screenshot was
  visually inspected.

The existing SMS provider key/template are configured, but real SMS delivery,
verified production signup, and authenticated pilot/customer login remain pending
controlled account/mobile testing. No FreeSWITCH configuration, service, gateway,
profile, or customer SIP resource was changed by this rollout.

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

## Production deployment recovery — 2026-10-06

Application/inventory code `46a89fd` and deployment hardening `0057f79` were deployed from master. The recovery ran the corrected bootstrap script with `setpriv --no-new-privs`; it succeeded without sudo inside the pipeline. The webhook service remains active with `NoNewPrivileges=yes`.

Root cause: serving release `d56047c` still invoked sudo during media provisioning. Because webhook execution used that old script, newer commits removing sudo could not deploy themselves. The IVR root was already `www-data:www-data:2775`; no permission repair or relaxation of service hardening was needed.

A second failure was a full 30 GB root volume. Generated Node dependencies were removed from 30 releases, reclaiming approximately 2.8 GB; source, vendor, compiled assets, shared customer data and rollback releases were retained. Future deployments require 1 GiB free before dependency installation and remove generated Node dependencies after building. FreeSWITCH operational logs occupied approximately 18 GB and were preserved; log retention needs a separate reviewed maintenance action.

Backup/evidence directory: `/home/ammir/deploy-backups/deploy-recovery-20261006`, private mode 0700. It contains a mode-0600 database dump, previous release link, deployment script/service snapshots and sanitized application checks. Recovery log: `/var/www/html/blucom-deploy/logs/recovery-20261006.log`.

Actual checks:

- Production pipeline: 193 tests passed, 1,269 assertions; three opt-in concurrency tests skipped (already run separately on disposable MariaDB).
- Customer-account and inventory migrations completed; assets and application caches built.
- Admin, hub and customer `/up` endpoints returned HTTPS 200; public customer login returned 200.
- Existing admin identity read-only application smoke checks: plans, assigned DID list and stock list returned 200. No test customer or SIP resource was created.
- Telephony counts before/after remained one tenant, one DID, five extensions, one inbound route and five outbound routes. Public XML remained valid with one route.
- IRT/Mellat configuration verified; catalog/checkout disabled; customer, plan and offer tables empty.

FreeSWITCH was already stuck in `activating/start` with no SIP/ESL listeners before deployment, and the live monitor was retrying. The same condition remained afterward. No FreeSWITCH restart/configuration edit was made; registration, PSTN calls and actual gateway health are unverified. This is an open operational issue, not a successful real-call acceptance gate.

Database schema and HTTP activation are completed. Authenticated two-business pilot checks, existing browser wildcard-cookie cleanup, reviewed legacy transfers, service recovery and real calls remain required before customer rollout. Keep additive schemas during application rollback.

## FreeSWITCH operational recovery — 2026-10-06

This supersedes the earlier availability/log-retention blocker above. FreeSWITCH
was repeatedly failing systemd startup, not running as a separate manual daemon.
Stopping the unit cleared its processes; no residual manual instance or SIGKILL
was needed. Nine SQLite databases passed integrity checks; `core.db` was corrupt.
After private backups, only that runtime database was moved aside and rebuilt.
SIP registration databases, call records, recordings, profiles and gateway
configuration were preserved.

The service is enabled and `active/running`, with one daemon matching systemd's
MainPID. TCP/UDP SIP 5060/5080 and loopback ESL 8021 listen. Authenticated ESL
reported all four Sofia profiles running, the configured provider gateway REGED,
and one internal registration. The application's live-monitor service recovered
to active/running. The rebuilt core database passed integrity checks.

Removed approximately 18 GB of operational logs. Native logging now rotates at
10 MiB with ten archives (approximately 110 MiB including the active log), excludes
debug, and requires no service restart when a size threshold is reached.
See the [maintenance policy](../deploy/README.md#freeswitch-process-and-log-maintenance).
Private recovery evidence is under
`/home/ammir/deploy-backups/freeswitch-recovery-20261006`.

These are operational health checks, not real-call acceptance. Verify softphone
reauthentication, inbound ringing/audio, outbound calling/caller ID and isolation
before customer rollout. All remaining payment and pilot gates still apply.


## Mellat batch 3 local validation — 2026-10-07

Implemented encrypted/versioned admin merchant settings, Mellat SOAP initiation, callback correlation, server verification/settlement/inquiry, explicit unsettled reversal, durable attempt leases, paid evidence retention and admin reconciliation. See [integration contracts and operations](MELLAT_INTEGRATION.md). No deployment, production migration, merchant credential configuration, live bank transaction, SIP call or FreeSWITCH change was performed.

Validation completed:

- Final SQLite regression: **280 passed, 1,796 assertions**; 15 guarded MySQL race cases skipped here and run separately below.
- New payment/settings/transport tests: **47 passed, 217 assertions**. Cover encrypted/blank/rotated secrets, admin/customer/domain isolation, real CSRF enforcement, session-independent callback, forged identifiers, bank uncertainty, exact small/large IRT conversion, malformed account/unit/amount, duplicate financial effects, stale leases, late success and reversal intent.
- Disposable MariaDB 11.8.6, Laravel MySQL driver: payment/settings/transport plus checkout feature regressions **70 passed, 319 assertions**.
- Five new payment races passed (**53 assertions**): competing initiation keys, duplicate callbacks, verification/expiry, new buyer/old late payment/expiry and gateway disable/initiation.
- Existing checkout races passed (five, 49 assertions), publication/gateway races passed (three, 25 assertions), registration races passed (two, 19 assertions). Separate disposable database prefixes protect every destructive fixture.
- Native SOAP transport tested with mocked HTTP responses: XML escaping, fixed endpoints, TLS/no redirects/timeouts, oversized/invalid/entity responses, bounded sink writes, SOAP faults and sanitized failures. Explicit cURL handling applies the total-transfer deadline rather than a per-read streaming timeout. These are contract/error tests, not live provider acceptance.
- Real Chrome, isolated SQLite fixtures: desktop/mobile settings, empty credential fields, disable/enable/save/password rotation, keyboard focus and reconciliation controls passed without page errors or horizontal overflow. Mobile settings and desktop reconciliation screenshots were inspected.
- Empty latest migration rollback/reapply passed on SQLite and MariaDB; stored credential versions block rollback. Financial facts cannot be erased through model saves.
- Vite production build, isolated route caching, changed PHP syntax/Pint and whitespace checks passed. The existing unresolved `/assets/images/blucom-hero.png` stylesheet warning remains.

Deployment and merchant gates:

- [ ] Back up and apply the additive batch 2/3 migrations through the release process; retain financial history and encryption keys on rollback.
- [ ] Keep catalog/reservation/checkout disabled. Verify admin-only settings and customer-host callback over HTTPS in the deployed release.
- [ ] Obtain and enter real merchant credentials privately; confirm approved server IP, callback hostname and IRR contract before enabling Mellat.
- [ ] Record controlled bank initiation, callback, verify, settle/inquiry and explicit unsettled reversal outcomes; do not use mock results as merchant certification.
- [ ] Implement atomic allocation/repair, complete commerce screens, entitlements/configuration and settled refund/credit workflows; run allocation races before paid launch.
- [ ] Finish customer pilot, ownership migration assessment and actual SIP registration/inbound/outbound acceptance.

General checkout remains off. Payment success currently records `paid_pending_allocation` or `paid_unfulfilled`, never an active line. Keep callback/reconciliation reachable when disabling new attempts so in-flight payments remain accountable.
