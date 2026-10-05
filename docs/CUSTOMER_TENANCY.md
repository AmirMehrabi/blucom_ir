# Customer tenancy and dedicated portal

Implemented in the application checkout on 2026-10-05. Production activation requires the deployment steps below.

## Account boundary

`User` remains the internal admin/operator account model with the `web` guard. Customers use a separate `Customer` model, `customers` table, `customer` guard, `customer_permissions`, and `customer_otp_challenges`. `Customer` extends Laravel's authenticatable base class; it does not extend or authenticate through the application `User` model.

Customer login is `https://my.blucom.ir/login`. `CUSTOMER_PORTAL_DOMAIN` sets this exact hostname without a scheme or path. Domain-specific customer OTP, logout, and account routes are registered before the internal login routes. Shared dashboard and call-configuration pages use the host-selected guard. Admin role middleware accepts only `User`, and customer permissions exclude provider/number-infrastructure management even if an invalid permission is stored manually.

Customer and internal sessions have different cookie names and both are host-only. The application selects the customer guard only on the configured customer hostname and the `web` guard elsewhere. Logout invalidates the current portal session. Existing wildcard-domain session cookies should be expired at deployment; existing users may need to log in again. The application still uses the configured Laravel session backend; guard keys distinguish principals and numeric IDs must never be interpreted without the model/guard namespace.

The same mobile number may exist once in each independent account table. An admin OTP cannot authenticate a customer, or the reverse. Customer OTPs are session-bound, single-use, stored as keyed hashes, expire, and have a transactionally enforced attempt limit. Unknown mobile numbers never create accounts. OTP codes and provider secrets are not logged.

## Customer businesses and staff

Each new owner account gets a new active tenant with `owner_customer_id`; its `owner_user_id` and `system_key` remain empty. Additional staff accounts can be created only in an active customer business. Roles are `owner` and `staff`; neither grants global admin rights.

Admins manage these accounts at `/admin/customers`: create an owner/business, add staff, set configuration permissions, enable/disable an account, assign a same-tenant extension, and enable/disable the business. Customer membership, role, and mobile reassignment are deliberately unavailable through the ordinary update endpoint. Owner transfer or account migration needs a separate reviewed workflow.

An owner receives customer configuration permissions by default. Staff initially get dashboard, line visibility, and queue availability. Purchase/billing permissions are reserved for the later commerce phases; their screens and checkout do not exist yet.

Customer access requires a valid active tenant and its matching customer owner. Missing membership never provisions access to the internal Blucom workspace. Disabled accounts and businesses cannot continue using authenticated portal requests or broadcasts. A disabled tenant is also excluded from the existing XML-CURL directory/dialplan authorization.

The existing `operator:create` command still creates an internal `User` in Blucom. The `customer:create` command now creates a `Customer` and independent business:

```bash
php artisan customer:create 09123456789 --name="Contact name" --business="Business name"
```

The command creates no `User`, assumes no verified mobile, assigns no existing DID, and does not configure a gateway. Staff are created through the admin customer page. Global admins can continue preparing number/extension/routes through existing admin configuration, selecting the customer tenant explicitly where supported.

## Telephony and data isolation

Customer requests resolve their tenant server-side; submitted tenant IDs cannot switch the business. Existing editors enforce owning numbers, extensions, destinations, IVR menus, queues, media, recordings, and reports. Live state and notification access use customer membership and account-specific ownership. A phone credential flash is displayed only for its matching extension.

Customers can configure a number assigned by an admin using that number's approved, enabled infrastructure gateway. They cannot submit provider credentials, gateways, new DID infrastructure, or use the old provider setup wizard. For new customer businesses, outbound gateway authorization also requires the route's gateway to equal the gateway configured on the DID. Foreign queue members are omitted from queue reconciliation and cannot make an inbound queue route eligible; customer queue views do not reveal foreign members or fallbacks.

This release preserves the legacy internal SIP baseline and globally unique extension usernames. No Sofia profile or gateway configuration has been changed, no FreeSWITCH command has been executed, and no live SIP call has been placed during this implementation. Tests exercise generated XML; real deployment behavior must be verified separately.

The existing queue reconciliation command still writes aggregate callcenter XML and can invoke `reloadxml`. This is pre-existing architecture debt and is not compliant with the intended fully dynamic configuration model. It was not run here. Only membership filtering was changed. Before general customer queue rollout, replace that configuration dependency through a separately validated dynamic approach; do not add per-customer files or use restarts for CRUD.

## Existing data and ownership review

The additive migration creates customer account tables and `tenants.owner_customer_id`. It does not convert internal users, move DIDs/extensions/routes, reverse consolidation, copy media, or delete existing data.

Use the read-only report to prepare a classification manifest:

```bash
php artisan customer:ownership-audit
php artisan customer:ownership-audit --json
```

The report contains tenant/resource counts and previous ownership identifiers, not credentials or mobile numbers. `workspace_consolidation_log` is evidence about earlier records, not authorization to restore their ownership automatically. Newer menus, queues, calls, recordings, staged uploads, and media paths must be classified independently. A reviewed transfer tool and production ownership migration remain pending. Internal operators retain their explicit current memberships; an operator without membership is denied rather than silently assigned to Blucom.

## Deployment checklist

- [ ] Back up the database and record registration/inbound/outbound behavior.
- [ ] Set DNS for `my.blucom.ir` to the application ingress and obtain a TLS certificate covering that hostname.
- [ ] Set `CUSTOMER_PORTAL_DOMAIN=my.blucom.ir` and a unique `CUSTOMER_SESSION_COOKIE=blucom_customer_session`.
- [ ] Verify it differs from `SESSION_COOKIE`; use HTTPS with secure session cookies.
- [ ] Apply `2026_10_05_000001_create_customer_accounts` with the normal deployment migration step.
- [ ] Update ingress using the repository Nginx template only after validating certificate coverage. No live ingress file was changed here.
- [ ] Refresh application route/config/view caches after updating environment configuration; restart long-lived workers through the usual deployment process.
- [ ] Expire old wildcard session cookies and verify host-only cookie behavior in a browser.
- [ ] Verify admin login on the internal host and customer OTP login on `my.blucom.ir`; test both directions of session separation.
- [ ] Create two pilot customer businesses and verify HTTP/broadcast/download isolation.
- [ ] Assign a pilot DID and route explicitly, keeping the working baseline intact.
- [ ] Verify registration, inbound, outbound, caller ID, schedules, and denied foreign destinations on FreeSWITCH.
- [ ] Review legacy ownership before transferring any shared-workspace resource.

Rollback: disable the customer hostname/entry point and return to the prior application release while retaining the additive schema and existing resources. Do not downgrade the migration after creating customer businesses, or automatically reassign customer resources into the internal workspace.

Automated coverage is in `CustomerIsolationTest`, `CreateCustomerCommandTest`, and the existing telephony/portal tests. Tests use an isolated in-memory SQLite database; they do not establish production DNS/TLS readiness, MySQL concurrency behavior, or live SIP behavior.

Implementation validation: 183 tests passed with 1,168 assertions; PHP syntax passed for all 40 changed PHP source/test/config files. Changed PHP files were formatted with Pint. These checks ran without applying the migration to production or executing FreeSWITCH commands.
