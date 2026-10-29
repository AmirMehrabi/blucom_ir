# Wallet purchase and renewal recovery

Implemented 2026-10-29. Uses the existing Ravi/RTL portal, panels, fields, commerce buttons, status styles, reservation countdown and submit protection. No Superdesign or FreeSWITCH configuration changes.

## Activation

Deploy the code and run the normal database migrations before enabling `COMMERCE_WALLET_ENABLED=true`. The flag defaults to false. Keep `COMMERCE_SEPARATED_BILLING_ENABLED=true` and the existing catalog/reservation/checkout gates enabled for new wallet purchases. Top-ups require a configured **live** Mellat or Zibal gateway; sandbox payments cannot credit the wallet. Existing wallet funds can pay invoices when the bank gateway is unavailable, provided checkout remains enabled.

Publish new one-time number offers through the existing admin inventory forms. Previously published annual offers, existing annual terms, legacy monthly line subscriptions and historical documents are **not** silently converted. Withdraw and republish available annual offers to change new sales. Migrating already assigned annual/legacy subscriptions remains a separate business/data migration; enabling the wallet flag does not rewrite their contract or create opening balances.

The existing scheduler runs `commerce:run-billing` every fifteen minutes. It generates due invoices, recovers bank-confirmed wallet credits and interrupted purchase applications, attempts opted-in wallet renewals, then sends the existing reminders. Confirm the scheduler is running during rollout.

## First purchase

1. Choose a line and monthly plan. Review shows the one-time line price, first month, total today, existing wallet contribution, exact shortfall and future monthly amount.
2. Confirmation authorizes this purchase and discloses monthly wallet renewal. The server creates a reservation and immutable price snapshot.
3. Sufficient wallet balance completes the purchase without a bank trip. Otherwise only the shortfall is collected through a separate wallet top-up invoice. The target purchase is saved in the database, not in a browser session.
4. Bank verification/settlement credits the wallet once, then automatically pays and allocates the original purchase. The result leads to the existing line setup CTA.
5. If the reservation expires, the line changes, or allocation is no longer authorized, the purchase debit rolls back. The verified credit stays in the wallet. The retained order explains recovery and links to another line.

New one-time assignments have no number expiry, annual renewal or automatic release-review date. The business plan starts at technical allocation, matching the existing separated-billing readiness boundary. Additional line purchases do not reset an existing plan period.

## Renewal recovery

The overview prioritizes plan status, deadline, exact shortfall and one review/recovery action. Full subscription controls, line terms and invoices live in their tabs; the overview shows recent wallet transactions.

- New wallet purchases opt into monthly wallet renewal through the first purchase confirmation. Existing subscriptions default to **off**. Customers can switch it on/off explicitly. Consent changes are audited.
- Future renewal invoices can be paid manually after review. Automatic deduction occurs only at/after the due date and only with current consent, an active uncancelled subscription and sufficient balance.
- Insufficient funds do not partially debit the wallet. Existing seven-day grace/reminder rules apply. A verified standalone top-up retries eligible due renewal immediately; scheduler retries provide recovery if the browser is closed.
- A shortfall top-up for a specific renewal resumes that same invoice. Pending bank outcomes suppress a second payment; an undelivered failed session can be retried. Expired sessions are replaced only when no unresolved bank attempt exists.
- After grace, renewal starts one fresh month from settlement; inactive months are not charged. An old unpaid renewal remains valid only for the unchanged subscription period; an upgrade or changed period fails safely.
- Cancellation preserves purchased one-time lines. Requesting reactivation creates/reopens the review invoice but does not restore calls before payment. Successful settlement clears cancellation and starts a fresh month.

## Financial integrity and operations

`wallet_entries` is an append-only tenant ledger. Each invoice can create at most one entry: positive bank-funded credit or negative purchase/renewal debit. Balance is derived from entries. The tenant row serializes all wallet writes; unique invoice references prevent duplicate effects. Wallet deductions, invoice payment and allocation/subscription application share one database transaction. Bank settlement/credit survive a failed purchase attempt.

Wallet settlements have their own ledger evidence; they do not create artificial bank attempts. Historical bank-paid assignments remain valid. Nullable assignment bank references are accepted only with a valid paid invoice and matching tenant debit evidence.

Customers see completed credits/debits, resulting balances, receipts and separate unfinished top-ups. Staff can inspect the tenant wallet and ledger from the existing customer billing workspace, reconcile bank credits through existing payment tools and see one-time inventory pricing. No balance-edit field is provided.

In-flight callbacks continue to recover verified credits if new checkout is disabled. No live bank charges, customer data migrations or SIP calls are performed by the automated tests. Refund tooling, historical contract migration, exports/advanced ledger filtering and broader admin adjustment workflows are outside these two journeys.

## Validation

`php artisan test --compact` covers the existing suite plus `WalletBillingJourneyTest`: first-purchase handoff, shortfall-only funding, duplicate callbacks, expired reservations, insufficient balances, overdue recovery, automatic renewal consent, cancellation/reactivation, read-only members, tenant isolation, test-mode exclusion, immutable ledger and admin visibility.

MySQL process-level races are opt-in tests and require a disposable database; SQLite tests do not prove MySQL lock behavior. Before enabling for customers, use the established live-payment pilot procedure and verify real bank return, scheduler delivery and calling at grace/restoration boundaries.
