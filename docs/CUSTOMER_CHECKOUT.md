# Customer checkout screens

Implemented on 2026-10-07 for controlled Mellat acceptance testing. These screens record reservations and verified payment; they do not assign a DID, create an active subscription or permit calls. Atomic allocation/repair is still the next backend batch.

## Customer journey

On `my.blucom.ir`: **خرید شماره** → select a number → review plan/amount → confirm → reserve → order/pro forma → Mellat continuation → bank return → **سفارش‌ها و پرداخت‌ها**. Prices are integer toman; the existing adapter submits checked IRT × 10 in rial. Historical price, plan and buyer details come from immutable snapshots.

| Method / route | Contract |
| --- | --- |
| `GET /numbers` | Fresh `numbers.purchase`; catalog flag; paginated current published, eligible offers. Disabled/empty catalog uses customer-facing Persian copy. |
| `GET /numbers/{offer}/checkout` | Authoritative quote and UUID reservation key. Rechecks availability/readiness. Confirmation shows monthly inclusive price and tenant-wide plan capacities. |
| `POST /orders` | CSRF, throttle, accepted confirmation, fresh purchase/billing permissions, reservation/catalog/checkout gates and configured enabled Mellat. Frozen displayed quote is rechecked under existing backend locks; same UUID key creates one order. |
| `GET /orders` | Fresh `billing.view`, active tenant, paginated tenant-owned order history. |
| `GET /orders/{public-uuid}` | Tenant-owned pro forma/order details, deadline, payment outcome and authorized actions. Works when exposure is disabled. |
| `POST /invoices/{public-uuid}/payments` | Existing payment service; original hold and current attempt determine whether initiation or continuation is safe. |
| `GET /payments/{attempt-uuid}` | Owned state; bank RefId appears only to a permitted customer continuing the current eligible attempt. |
| `POST /payments/mellat/callback/{attempt-uuid}` | Existing correlated server verification/settlement; generic return contains no invoice/customer identity and requires no login. |

Explicit presentation data excludes merchant credentials, provider infrastructure and raw bank errors. Pages use private no-store caching and no-referrer policy. Scoped commerce failures are Persian, including expired browser sessions; unauthenticated customer requests still redirect to customer login.

## Payment states

A ready current attempt is continued without creating another bank request. Definitive initiation failure or confirmed unsettled reversal can permit retry within the original deadline. Initiating, verifying, settling, unknown or pending outcomes show tracking/support and suppress another payment. Paid orders show preparation or follow-up, never an active line. Deadline expiry blocks payment even before scheduler cleanup; the browser countdown supplements server enforcement.

Disabling new checkout retains order history, callbacks and reconciliation. A started eligible bank continuation retains its frozen account/version. Catalog, reservation and checkout flags default false. Configure only through established admin services and private merchant settings; do not bypass readiness or modify ownership projections to enable a pilot.

## Farsi and RTL

Existing Ravi font and blue/navy portal styling; RTL navigation and steps; isolated LTR phone/invoice identifiers; Persian digits and separators; Jalali dates in Tehran timezone; explicit toman/month labels; 48px action controls and visible keyboard focus. Forms use native required confirmation, duplicate-submit protection and Persian busy/status messages. Reservation clocks use server time plus elapsed browser time and resume on browser back navigation. No Superdesign canvas was used.

## Validation and live pilot

See [production setup and screen-by-screen testing](MELLAT_PRODUCTION_TESTING.md). Automated feature tests cover the complete mocked purchase, immutable totals, duplicate reservation, disabled/unready/stale offers, cross-tenant access, reduced staff permissions, uncertain payments, expiry, CSRF and forged callback. Real bank credentials/payment and real SIP calls remain separate production acceptance gates.
