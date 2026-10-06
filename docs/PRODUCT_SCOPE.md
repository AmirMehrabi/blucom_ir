# Product scope and decisions

Status: target behavior; admin inventory/offers implemented; customer commerce remains pending.

## Customer journey

1. An admin-created customer owner logs in on `my.blucom.ir` using the existing customer OTP boundary. Staff have explicit permissions; unknown mobile numbers do not create accounts.
2. Browse Numbers shows only published, available offers with a monthly total, included features, limits, and clear availability.
3. Checkout snapshots the offer, reserves one DID temporarily, issues an invoice, and starts payment. Changed prices or lost availability require a recoverable confirmation/retry.
4. Verified server-side payment grants an assignment and subscription exactly once. The customer sees payment, provisioning, and configuration status separately.
5. My Numbers opens a number workspace. The customer creates or selects extensions, sets inbound routing and hours, optionally publishes an IVR or selects a queue, and chooses an eligible owned outbound caller ID. Provider settings remain admin-only.
6. Billing shows invoices, receipts, paid-through date, next renewal, and payment retry. Renewal extends service exactly once after verified payment.
7. Overdue service follows a defined grace/suspension policy. Billing and login remain accessible. Cancellation normally ends service at the paid-through date; release is controlled and never immediate resale.

## Initial scope

- One business per customer membership; multiple customers and multiple subscriptions per business.
- One DID per subscription and one DID per checkout order initially.
- One inclusive monthly plan initially, with immutable plan versions and per-number offers so more plans can be added later.
- A number's advertised monthly amount is the recurring total. No hidden second plan charge.
- Existing tenant-owned extensions, IVRs, and queues may serve multiple purchased DIDs; the UI explains affected routes before shared-resource edits.
- Each extension selects one outbound caller-ID DID, matching the existing route model. Its gateway is derived from the DID server-side.
- Existing reports, recordings, and live monitoring stay available under their own permissions. Do not expand those products for this project.
- Renewal uses customer-initiated payment initially. Automatic debit is a separate capability requiring provider support and customer authorization.
- Public self-registration, per-minute rating, prepaid credit, top-ups, mid-period upgrades, proration, and new SIP/media features are outside the initial release.

## Proposed defaults to finalize

IRT and Mellat are confirmed by the product owner. Other commercial rules below remain proposed defaults until finalized.

| Decision | Proposed starting rule | Required before |
| --- | --- | --- |
| Money — confirmed | Store/display integer toman as `IRT`. Convert exactly once to the gateway unit at the payment boundary; keep the IRT amount and submitted gateway amount/unit as separate snapshots. | Implemented for offers; conversion verification required in Mellat checkout. |
| Payment provider — confirmed | Mellat / Behpardakht, using the customer portal merchant account. | Phase B adapter and controlled payment validation. |
| Monthly period | Anchor anniversary to the original activation day; clamp to the last day in short months without moving the original anchor. Store instants in UTC, display in Asia/Tehran. | Subscription implementation. |
| Period start | Start the first paid period at successful service activation; keep paid-but-unready orders in reconciliation. | Activation. |
| Price changes | Existing subscriptions retain their snapshotted amount; changed offers affect new purchases only initially. | Offer publication. |
| Grace | Zero grace until an explicit duration and policy are selected; make any later grace visible and timestamp-based. | Call entitlement launch. |
| Cancellation | End of paid period; allow undo before effective cancellation. | Customer billing launch. |
| Refunds | Admin-reviewed, linked adjustment/refund records; never delete original payment evidence. | Live payment pilot. |
| Resale | Quarantine until cleanup and admin readiness review; no automatic resale initially. | Cancellation/release. |
| Limits | Published plan explicitly declares each limit and its tenant/number scope; do not infer unlimited service from missing values. | Plan publication. |

Mellat is selected. Verify the merchant contract and current initiation, verification, settlement, callback, reconciliation, gateway amount unit, and reversal/refund behavior before implementing the adapter. Also finalize invoice identity/tax treatment, reservation timeout, reminder schedule, late-payment service-period treatment, destination restrictions, and quarantine retention policy. No payment, legal, or provider-specific behavior is assumed by this plan.

## Customer screens

| Screen | Required behavior |
| --- | --- |
| Browse Numbers | Search available offers, total monthly price, included capabilities, availability, buy action. |
| Checkout | Frozen quote, reservation expiry, invoice, payment/retry, clear errors. |
| Activation progress | Pending payment, verified payment, awaiting technical readiness, active but unconfigured, ready. |
| My Numbers | Owned number, billing state, technical state, monthly amount, next renewal, configure. |
| Number workspace | Overview, Extensions, Call Routing, Time Conditions, IVR, Queues, Subscription. |
| Billing | Owned invoices/receipts, due dates, retries, cancellation and paid-through date. |
| Dashboard | Purchase/setup/renewal/suspension actions alongside existing permitted statistics. |

Implement Persian RTL responsive pages, accessible keyboard controls, validation, loading, empty, stale-price, and failed-payment states. Customer responses must exclude gateway credentials, infrastructure identifiers/settings, and foreign-tenant data.
