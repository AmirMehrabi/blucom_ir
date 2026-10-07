# Customer checkout screens

Implemented on 2026-10-07 for controlled Mellat acceptance testing. These screens record reservations and verified payment; they do not assign a DID, create an active subscription or permit calls. Atomic allocation/repair is still the next backend batch.

## Customer journey

### Update: 2026-10-08

Zibal settings now have explicit `live` and `test` modes. Test mode uses Zibal's public test merchant `zibal` automatically and retains encrypted real merchant credentials for later reuse. It applies to all new Zibal payments while selected; existing attempts use their immutable account version and `is_test` snapshot. Live mode rejects the test merchant and requires real credentials. The deployment leaves the existing live selection unchanged; admins deliberately save Test mode to start a test session, then restore Live mode after testing. Staging with synthetic numbers is preferable, but the allocation guard also protects production inventory when using this mode there.

Customer path: select a number → review the visible test notice → reserve → **پرداخت آزمایشی با زیبال** → Zibal's successful/unsuccessful test buttons → verified test result. Successful test verification records `test_succeeded` and `test_completed` order/invoice states; it never sets invoice `paid_at`/`paid_payment_attempt_id` or attempt `settled_at`, creates an assignment/subscription, or grants SIP entitlements. It releases only the original current hold; repeat/late callbacks cannot clear a newer buyer's hold. Failure retains the existing link for retry until expiry and allows cancellation. Test-only cancellation/expiry needs no financial reconciliation. Test badges appear in review, order history/details, callback results and admin payments; staff can filter Test/Live payments independently of status.

The payment form sends the mode the customer saw. A changed mode blocks a new attempt until the page is refreshed, preventing an old test confirmation from silently becoming a live payment. Successful tests are terminal: restore Live mode and create a new reservation for an actual purchase. Tests do not validate the real merchant's callback-domain or allowed-IP settings.

Checkout uses the admin-selected active Mellat or Zibal gateway. Enabling a gateway makes it eligible for selection; the selected gateway is identified separately in settings. An authorized, current `redirect_ready` Zibal attempt redirects directly to its fixed `gateway.zibal.ir/start/{trackId}` URL. Failed or uncertain initiation remains a local status page; it never asserts payment or sends another request automatically.

Customers can cancel an unpaid hold from order details through `POST /orders/{public-uuid}/cancel`, with fresh tenant membership and billing permissions. Active gateway operations and verified payments require staff review. Admins manage reservation history at `GET /admin/reservations` and release held stock through `POST /admin/reservations/{public-uuid}/cancel` with a recorded reason. Released reservations are terminal and cannot clear a newer hold.

Cancellation releases inventory and preserves all financial records. No unresolved attempt means the order/invoice becomes `cancelled`; otherwise it becomes `reconciliation_required`. A paid held order becomes `paid_unfulfilled` and its invoice stays paid. A late confirmed payment cannot allocate cancelled stock or another buyer's reservation. Reservation cancellation does not request a bank refund; existing Mellat reversal remains a separate staff action for verified, unsettled payments.

Zibal initiation creates a payment link, not a card charge. Rejected requests (including `115`, server IP not allowed), HTTP errors, and connection failures are recorded as `initiation_failed`. Older `unknown` Zibal attempts can retry with a new idempotency key only when they have no track ID, candidate/reference, verification/settlement, or active operation lease. Same-key replay retains the original attempt. Attempts with a track ID or payment evidence never use this recovery path. Admin payment review explains IP rejection; add the requesting server's IP to the merchant's allowed IP list in Zibal before retrying.

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
