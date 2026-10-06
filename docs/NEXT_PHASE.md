# Next phase: admin inventory and monthly offers

Status: implementation plan, not started. This is the next code phase; customer production activation and ownership assessment are parallel release prerequisites.

## Outcome

An admin can prepare an unowned DID, select its approved infrastructure gateway and destination policy, attach an immutable monthly plan/price offer, validate readiness, publish it, and withdraw it. Publication does not assign ownership or enable customer calls. Customer checkout remains disabled.

This is the foundation for later payment work. Building payment against the existing admin DID creation path would incorrectly assign new stock to the internal Blucom owner.

## Code starting points

| Existing code | Planned change |
| --- | --- |
| `routes/web.php`, `AdminDidController` | Extend the active admin DID path; it currently lists owned numbers and creates assigned Blucom records. |
| `AdminNumberSetupController`, `AdminLineSetupController` | Separate stock preparation from tenant answerer setup; retain the existing internal working workflow. |
| `SipNumber`, `NumberNormalizer` | Preserve E.164 global uniqueness and existing assigned records; add a separate stock lifecycle. |
| `SipGateway`, `config/voip.php` | Validate trusted admin infrastructure, enabled state, profile/context, and provider policy without provisioning Sofia. |
| `SipNumberService`, older catalog controllers | Review as reference; do not reconnect unsafe free assignment/release actions. |
| `Permissions`, admin middleware | Keep writes internal-admin-only; customer purchase permission does not grant inventory access. |
| `CustomerLineSetupService`, FreeSWITCH directory/dialplan services | Regression targets in this phase; gateway/entitlement adaptation belongs to Phase C. |

Keep the current `app/Models` and `app/Services` conventions. Use thin controllers and transactional actions/services; no broad domain-folder refactor.

## Implementation sequence

### 1. Define and record inventory rules

- [ ] Finalize price unit, initial plan inclusions/limits, and offer price-change rules from [product decisions](PRODUCT_SCOPE.md).
- [ ] Define which admin may publish and what technical evidence is required.
- [ ] Identify baseline/internal DIDs that must never enter sellable stock automatically.
- [ ] Introduce explicit flags for catalog exposure and checkout, both off by default. Enforce flags server-side.

Deliverable: documented publication contract and reproducible test fixtures using synthetic numbers and no credentials.

### 2. Add additive schema and models

- [ ] Add inventory state separately from existing routing/service flags: draft, available, reserved, assigned, quarantined, disabled. Existing assigned DIDs retain routing state and are not published.
- [ ] Define nullable current reservation/assignment references in the later commerce migration; do not overload `tenant_id` as reservation state.
- [ ] Add plans and immutable plan versions with feature/limit definitions and scope.
- [ ] Add versioned number offers with DID, plan version, integer amount, currency, and publication timestamps/status.
- [ ] Guarantee one current published offer per DID through a locked DID/current-offer relationship; retain old terms instead of overwriting them.
- [ ] Add readiness review metadata and secret-free audit events. Review foreign keys so catalog edits/deletes cannot destroy future financial references.
- [ ] Add factories and migration coverage; do not convert historical owners or import live resources automatically.

Deliverable: schema that preserves existing records, supports unowned stock and frozen offers, and can evolve into reservations/assignments.

### 3. Implement lifecycle and readiness services

- [ ] Add `NumberInventoryService` for allowed transitions and `NumberOfferService` for publishing/withdrawal/version changes.
- [ ] Add `NumberReadinessService` validating unowned stock, global canonical identity, enabled approved gateway, permitted profile/context, required capabilities, and explicit destination policy.
- [ ] Require an admin technical review; database approval alone must not claim live SIP registration.
- [ ] Recheck DID, gateway, and offer under transaction locks at publication. Block stale edits and invalid transitions.
- [ ] Prevent generic DID updates, gateway edits, deletion, or internal setup from bypassing published-stock protections. Withdraw publication before changing readiness-critical settings; locked services must revalidate eligibility on later reservation.
- [ ] No answerer is required for stock; an unassigned DID has no executable customer route.
- [ ] Record actor, transition, resource, reason, and time without credentials.

Deliverable: one authoritative path for stock publication; no direct controller state toggles.

### 4. Build admin screens

- [ ] Extend DID list with Stock/Assigned views and lifecycle, owner, gateway, readiness, and publication filters.
- [ ] Add stock create/edit and review screens, with validation and explicit publish/withdraw actions.
- [ ] Add Plans create/edit/version/publish/archive screens and monthly offer editing.
- [ ] Adapt Quick Setup with a stock preparation path that ends at review/publication and does not create customer routes or extensions.
- [ ] Show internal legacy records separately and protect immutable DID identity after assignment.
- [ ] Hide/disable customer purchase exposure; no static marketing page should claim working checkout.

Deliverable: an admin can prepare and publish a synthetic offer end to end.

### 5. Validate and prepare deployment

- [ ] Feature tests: admin authorization; customer/direct URL denial; normalization; lifecycle transitions; readiness failures; version immutability; archival/withdrawal; stale edits; deletion protections; no assignment or telephony activation during publication.
- [ ] Regression coverage: existing internal DID setup, customer isolation, and XML for assigned baseline records.
- [ ] MySQL tests: concurrent offer publication and publication versus withdrawal/readiness changes, using isolated test records.
- [ ] Run relevant tests, PHP formatting, migration checks, asset build if views/assets changed, and review the diff.
- [ ] Browser checks for admin stock and plans pages, Persian RTL/mobile, validation and stale forms.
- [ ] Deploy additive migrations with flags off; verify existing SIP behavior before catalog exposure.

## Suggested reviewable change batches

1. Inventory/offer contracts, flags, additive migrations, models and factories.
2. Lifecycle/readiness/publication services and authorization/concurrency tests.
3. Active admin DID stock screens, plan/offer screens, and stock preparation path.
4. Regression/browser verification, deployment checklist update, and release notes with actual results.

Each batch should stand on its own and keep existing assigned resources working. Avoid empty payment/subscription scaffolding in this phase; introduce those records with Phase B behavior.

## Acceptance gate and handoff

- [ ] One synthetic unowned DID can be prepared and published at a monthly total with included features/limits.
- [ ] Owned, disabled, quarantined, malformed, unapproved, or unready DIDs cannot be published.
- [ ] Existing assigned numbers remain assigned and retain their existing XML/routing behavior.
- [ ] Price/version changes preserve previously published terms for later checkout snapshots.
- [ ] Customers cannot edit stock, gateway, infrastructure settings, or publication through any endpoint.
- [ ] No FreeSWITCH file write, reload, restart, rescan, or provider provisioning occurs during CRUD.
- [ ] Checkout stays off; no free claim endpoint is introduced.

Phase B starts after this gate, with the payment provider and financial policies selected. Its first deliverable is one-number reservation plus an immutable invoice, followed by verified payment and atomic assignment.
