# Mellat / Behpardakht integration

Implemented locally on 2026-10-07, Phase B batch 3. Not deployed or validated with a live merchant. General checkout remains disabled. Payment recording is implemented; paid ownership allocation, subscriptions, service entitlement enforcement and purchased-line configuration are the next batches.

## Admin configuration

Open `https://admin.blucom.ir/admin/settings/payment-gateways` (Management → Payment gateways). Mellat is the first supported payment gateway; SIP provider gateways are separate infrastructure.

- Enable/disable Mellat for new payment attempts.
- Enter the merchant terminal ID, username and password privately in the admin form. All three are required for initial configuration; blank fields retain their existing values. Stored values are never prefilled or flashed after validation errors.
- Confirm from the merchant contract that request amounts use rial (`IRR`) before enabling. Customer prices are integer toman (`IRT`); the adapter converts exactly once using checked integer `IRT × 10`.
- Saves require the current settings revision; concurrent stale forms fail. Changes record the admin actor and safe state/version identifiers in commerce audits.

`2026_10_07_000003_create_payment_gateway_settings` seeds one disabled Mellat entry without credentials. Merchant credentials use Laravel encrypted array storage in immutable `payment_gateway_versions`; the current gateway points to the latest version. Preserve the application encryption key and decryptable prior keys during rotation and backups. Password changes retain the merchant account namespace; terminal changes create a new namespace. Each attempt freezes its credential version and account, so disabling or rotating settings does not discard a started payment. Retain old versions for investigation; revoked old credentials may require merchant-side assistance for outstanding attempts.

Payment credentials are never returned to customer clients. Payment audit evidence contains record identifiers, outcomes and numeric bank codes, without passwords, raw SOAP, card data or cookies. The adapter does not dispatch framework HTTP payload telemetry. Do not enable request/transport body capture for payment settings, SOAP calls or callbacks.

## Implemented endpoints

| Host | Method/path | Contract |
| --- | --- | --- |
| Admin | `GET /admin/settings/payment-gateways` | Admin-only settings, private/no-store. |
| Admin | `PUT /admin/settings/payment-gateways/mellat` | Revision, enabled, unit confirmation and optional replacement merchant fields; normal CSRF protection. |
| Admin | `GET /admin/payments?status=…` | Paginated payment attempts and related invoice state; no merchant secrets. |
| Admin | `POST /admin/payments/{id}/reconcile` | Admin-only server-side retry/inquiry, required audit reason. |
| Admin | `POST /admin/payments/{id}/reverse` | Explicit admin request, required reason; restricted to verified, unsettled payments. |
| Customer | `POST /invoices/{invoice-public-uuid}/payments` | Authenticated Customer, fresh `numbers.purchase` and `billing.manage`, active own tenant, UUID `idempotency_key`; checkout flag required. |
| Customer | `GET /payments/{attempt-public-uuid}` | Own-tenant payment status, fresh `billing.view`, private/no-store; bank continuation also requires purchase/billing management permissions and a live hold. |
| Customer | `POST /payments/mellat/callback/{attempt-public-uuid}` | Anonymous/session-independent verification trigger, rate limited, on the customer host only. |

The callback is canonical HTTPS on `my.blucom.ir` by default, constructed from `portal.customer_domain`, never the incoming request origin. Its CSRF exemption applies only to this callback path; customer payment initiation and admin writes still require CSRF. Inactive customer sessions and disabling checkout cannot block a legitimate callback. The public return page displays no customer or invoice details. Card-holder fields are ignored and removed from request input; they are not saved as evidence.

Catalog, quote confirmation, customer order/invoice lists and complete retry screens remain batch 5. These payment endpoints operate on the batch 1/2 backend's existing pro forma invoices; registration alone still does not expose a purchase journey.

## Transport and payment integrity

`MellatClient` separates the payment service from transport. `MellatSoapClient` implements SOAP 1.1 using the existing Guzzle dependency and DOM XML serialization; no SDK or `ext-soap` dependency was added. It uses fixed HTTPS bank endpoints, TLS verification, no redirects, five-second connection and 15-second total-transfer timeouts through an explicit cURL handler, a 32 KiB capped response sink, entity/DOCTYPE rejection and strict envelope/result parsing. Transport failures have a generic exception without the original payload/cause. PHP `ext-curl` and DOM must be available on the payment workers; `ext-soap` is unnecessary. The explicit handler avoids per-read streaming deadlines and enforces the supported connection/total limits described in the [Guzzle request options](https://docs.guzzlephp.org/en/stable/request-options.html#connect-timeout).

The SOAP operation names, namespace, expected arguments, case-sensitive RefId and POST callback contract follow the [archived provider-authored general user manual](https://usermanual.wiki/Document/MellatPGWGeneralUserManualVer2012.2114604271). This historical document is a contract reference, not evidence that the current merchant account has been certified. Confirm current credentials, allowed IP, amount unit, callback registration and response semantics with Behpardakht before controlled acceptance testing. TLS verification remains enabled regardless of insecure examples in older material.

`MellatPaymentService::initiate()` commits a durable payment attempt before `bpPayRequest`. Its database primary key is the unique bank order ID, not a timestamp or hash. It freezes invoice amount/currency, merchant version/account and gateway amount/unit; rechecks current offer, technical readiness, invoice, membership, permission and live reservation. A per-invoice key replays the original attempt. A different browser key cannot replace an unresolved attempt; explicit initiation rejection or bank-confirmed reversal permits a new key/bank order while the original hold is valid. Initiation timeout, malformed response or uncertain error is `unknown`, never an automatic second charge.

Callback validation correlates the local attempt, exact case-sensitive RefId and local bank order before calling the bank. Both `SaleOrderId` and the documented `saleOrderId` alias are supported, but conflicting values fail. A successful browser `ResCode` supplies only a candidate sale reference. It becomes authoritative after provider verification. Callback failure/cancellation does not invalidate a genuine financial outcome. Invoice/order, account and currency/amount snapshots are checked again before verification or settlement.

The server invokes `bpVerifyRequest` and `bpSettleRequest` with persisted local order IDs and the correlated sale reference. Already-verified responses use `bpInquiryRequest`. An inquiry success code alone does not establish settlement: settlement needs confirmation from settle success/already-settled or an already-settled inquiry result. Timeouts and ambiguous states retain evidence and remain `unknown` or `pending_settlement`. No automatic reversal runs after uncertainty.

Every provider call occurs outside service database transactions. A two-minute persisted operation lease and token fence concurrent callbacks and stale workers. Completion locks DID → historical reservation → order → invoice → attempt. Unique bank references, immutable verified facts and the invoice's one paid-attempt pointer prevent double recording. A second confirmed attempt is retained as `duplicate_payment`, without replacing the invoice's first successful payment.

A confirmed settlement marks the invoice `paid` and the order `paid_pending_allocation` if its original hold is still eligible, otherwise `paid_unfulfilled`. This batch creates no assignment, subscription, tenant projection or SIP route. Expiry preserves paid evidence, changes an unallocated paid order to `paid_unfulfilled`, and releases only its own hold. Late payment cannot seize another customer's current reservation or number.

## Reconciliation and reversal

Use `/admin/payments` to filter uncertain attempts, supply an audit reason and request server-side reconciliation. Existing verified attempts inquire before retrying settlement. Repeated callbacks/retries cannot create another financial effect.

An initiation that timed out before RefId/sale reference was saved cannot be safely repaired by submitting `bpPayRequest` again. Its admin screen directs the operator to the merchant portal; automated recovery/import of externally discovered references remains future repair work. A process lost during initiation stays visible as `initiating`; the lease expires, but no background worker blindly reissues it. Other expired verification/settlement leases can be retried through admin reconciliation when references exist.

Reversal is an explicit admin action on a bank-verified unsettled attempt and an unpaid invoice. Inquiry must report not-settled before `bpReversalRequest` is submitted. Already-settled results are recorded as paid, never reversed. A reversal timeout retains reversal intent; subsequent reconciliation only inquires and cannot turn into a settlement request. Bank reversal acknowledgement is retained as `reversed`; it is not a settled-payment refund workflow. Refunds/credits for settled, duplicate or paid-unfulfilled payments still need a separate audited implementation and merchant validation.

## Release and validation

Follow the [production setup and test runbook](MELLAT_PRODUCTION_TESTING.md) for deployment confirmation, configuration and the controlled invoice/payment pilot.

Apply the additive migrations with `COMMERCE_CATALOG_ENABLED=false`, `COMMERCE_RESERVATION_ENABLED=false` and `COMMERCE_CHECKOUT_ENABLED=false`. Configuring/enabling Mellat does not enable general checkout. Confirm `ext-curl` and DOM on actual PHP-FPM workers before the merchant smoke test. Current merchant credentials belong only in the private admin form. Existing SIP configuration is untouched.

Run `vendor/bin/phpunit` for mocked contract/security/regression tests. For race tests, use `PAYMENT_CONCURRENCY_TESTS=1`, MySQL, `pcntl`, an explicitly disposable database beginning `blucom_payment_test`, and an empty `DB_URL` when setting connection variables. The tests intentionally run `migrate:fresh` after checking these guards; never use the application database. See [dated release evidence](RELEASE_CHECKLIST.md#mellat-batch-3-local-validation--2026-10-07).

Empty-schema rollback/reapply is tested on SQLite and MariaDB. Migration rollback refuses once a merchant credential version or a linked attempt exists; retain financial schema, history and encryption keys during application rollback. Disable new initiation while keeping this callback/reconciliation implementation reachable for outstanding attempts. Do not roll back to code without the callback before accounting for all in-flight payments.

Before accepting real money, perform controlled merchant-side initiation, callback, verify, settlement/inquiry and reversal tests with approved private credentials and record outcomes without raw secrets/card data. This implementation's tests use synthetic credentials and mocked SOAP responses; no real payment or refund has been attempted. Paid launch also requires batch 4 atomic allocation, Phase C entitlement enforcement, Phase D customer configuration and real SIP acceptance.

The next implementation batch is [atomic allocation and repair](NEXT_PHASE.md#4-atomic-allocation-and-repair): consume one confirmed payment into one eligible assignment/subscription transactionally, retain paid-unfulfilled outcomes, and test allocation against expiry and competing reservations.
