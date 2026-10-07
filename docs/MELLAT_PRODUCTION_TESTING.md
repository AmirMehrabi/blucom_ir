# Production setup and Mellat acceptance testing

This release adds customer catalog, quote confirmation, reservation/pro forma, order history and Mellat checkout/settings. It does not allocate a paid DID or activate a subscription. Test admin setup with checkout off first. Prefer a merchant-approved staging account for bank acceptance; a live merchant pilot records a real payment and needs an agreed refund/repair procedure outside this application's unsettled-reversal action.

## 1. Confirm deployment

Push to `master` queues the existing webhook deployment. A queued delivery does not prove success. On production:

```sh
tail -n 100 /var/www/html/blucom-deploy/logs/deploy.log
readlink -f /var/www/html/blucom-deploy/current
cd /var/www/html/blucom-deploy/current
git rev-parse HEAD
php artisan migrate:status
php artisan schedule:list
```

Match the serving SHA to the pushed commit and look for `Deployed <sha>`. The pipeline runs tests/build/cache generation and `migrate --force` before switching the release. Both `2026_10_07_000002_create_checkout_records` and `2026_10_07_000003_create_payment_gateway_settings` should be **Ran**. If the pipeline failed, inspect the failure and resolve it before manually changing the serving application; do not blindly rerun migrations or use `migrate:fresh`.

Verify PHP `curl`, `dom` and `intl` are available in CLI and the active PHP-FPM pool. `ext-soap` is not needed. For CLI:

```sh
php -r 'foreach (["curl", "dom", "intl"] as $extension) { echo $extension, ": ", extension_loaded($extension) ? "yes" : "NO", PHP_EOL; }'
```

Keep the application's existing encryption key. Do not use `key:generate`: it would invalidate existing encrypted credentials. Use the existing private database backup/recovery process and retain finance history on rollback.

## 2. Keep exposure disabled and check the scheduler

In `/var/www/html/blucom-deploy/shared/.env`, confirm these values without printing the whole file:

```dotenv
COMMERCE_CATALOG_ENABLED=false
COMMERCE_RESERVATION_ENABLED=false
COMMERCE_CHECKOUT_ENABLED=false
COMMERCE_RESERVATION_MINUTES=15
```

If you changed environment values after deployment, rebuild this release's config as the application/deployment user:

```sh
cd /var/www/html/blucom-deploy/current
php artisan config:cache
```

Use the established Laravel scheduler. If no scheduler exists, install this once for the appropriate application user, with output sent to a monitored application log:

```cron
* * * * * cd /var/www/html/blucom-deploy/current && php artisan schedule:run >> /var/www/html/blucom-deploy/shared/storage/logs/scheduler.log 2>&1
```

Avoid a duplicate cron/systemd scheduler. The schedule should include `commerce:expire-reservations` every minute. Use a shared scheduler-lock cache, monitor errors and review counts. A bounded manual expiry run is:

```sh
php artisan commerce:expire-reservations --limit=100
```

## 3. Configure and test admin settings

Log into `https://admin.blucom.ir` as an admin. Open Management → Payment gateways at `/admin/settings/payment-gateways`.

1. Mellat initially shows disabled and unconfigured.
2. Enter the real terminal ID, merchant username and password **only in the private admin form**. Save with enable unchecked.
3. Confirm with Behpardakht the merchant's approved public source IP, callback host/path, and request amount unit. The callback is `https://my.blucom.ir/payments/mellat/callback/{attempt-uuid}`. It must accept the bank's POST without requiring customer login and must not be blocked by a WAF challenge. TLS must be valid.
4. If the contract confirms rial, check the IRR confirmation, then enable Mellat. Checkout stays off independently.
5. Reload: all three merchant fields must be empty, with a configured indication. Save blank fields: credentials must be retained. Disable then re-enable: only new initiation is gated.
6. Open two settings tabs, save one, then save the older one: the older form must show a stale-settings error.
7. Trigger an invalid form: no merchant values should be repopulated in the page or flashed input. Avoid body logging, screenshots or copied credentials.
8. Open `/admin/payments`: the empty/list state and status filter should work on desktop and mobile.
9. As a customer, the admin settings/payments URLs must be denied/not found. As a guest, the admin page must redirect to `https://admin.blucom.ir/login`.

Credential rotation preserves old versions for started payments. If the bank revokes an old password, those outstanding attempts may require merchant assistance. Do not rotate solely to manufacture a production failure test.

## 4. Controlled bank pilot

Use the customer screens; no Tinker or browser-console submission is needed. Prepare one dedicated unassigned, technically reviewed and published stock offer and a designated customer owner. Do not repurpose existing working or customer-owned DIDs. Use a merchant-approved amount and record the expected **IRT amount** and **IRR = IRT × 10**. Agree on refund/repair handling before paying; this application cannot refund a settled payment yet.

For the controlled window, set these in the shared environment and run `php artisan config:cache` from the serving release:

```dotenv
COMMERCE_CATALOG_ENABLED=true
COMMERCE_RESERVATION_ENABLED=true
COMMERCE_CHECKOUT_ENABLED=true
COMMERCE_RESERVATION_MINUTES=15
```

These flags are global. Limit published pilot stock and review any existing unpaid invoices before exposure. The pilot customer needs `numbers.purchase`, `billing.view` and `billing.manage`; new owners receive these permissions. Mellat must be enabled and its rial amount unit confirmed in admin settings.

1. Log into `https://my.blucom.ir`. Open **خرید شماره** (`/numbers`). Only eligible published numbers appear; check Persian copy, phone direction, plan and monthly toman amount on desktop and mobile.
2. Choose **انتخاب و بررسی خرید**. Confirm the selected number, included plan features and amount. Account capacities are explicitly tenant-wide. Check the confirmation box, then click **رزرو و ادامهٔ خرید**.
3. The order detail shows the immutable pro forma, buyer/business, Jalali dates, price and original reservation countdown. This creates no active line. A stale price or unavailable number sends you back to select again.
4. Click **پرداخت با بانک ملت**, then **ورود به درگاه بانک ملت**. Inspect the bank's displayed amount/unit before completing the approved payment. Card details are entered only at the bank.
5. On return, use **مشاهدهٔ سفارش‌های من**. The customer session may require login again. A server-confirmed settlement displays **پرداخت شما تأیید شد** and **در انتظار آماده‌سازی**. It does not claim that the line is usable.
6. Check `/admin/payments` and the merchant portal against the expected amount and settlement result. Keep the order/invoice/attempt references in a private test record.
7. Reload the order. An existing ready attempt offers continuation; uncertain payments offer tracking/support and no second payment. Definitive initiation failure or confirmed unsettled reversal can permit a new attempt only within the original hold.

After the pilot, disable all three exposure flags and rebuild config. Customer order history, bank callback and admin reconciliation remain reachable for outstanding payments. Do not erase finance history or change ownership manually. See [customer checkout contracts](CUSTOMER_CHECKOUT.md).

## 5. Test cases and expected outcomes

| Test | Expected |
| --- | --- |
| Normal approved payment within hold | One attempt `settled`, one invoice `paid`, order `paid_pending_allocation`; bank merchant portal confirms settlement and expected IRR amount. No assignment, subscription, tenant ownership or active line is created. |
| Reload status; replay the same initiation form/key | Same attempt and bank order; no new charge or second invoice payment. |
| Cancel at bank | No invoice marked paid from browser cancellation; original deadline remains. A ready/unknown state can remain until merchant truth is established. |
| Valid callback with customer logged out/inactive | Verification still works; no login requirement and no customer details on return page. Coordinate this with the bank's real callback rather than inventing successful evidence. |
| Wrong RefId/order/reference or forged success | No paid invoice without server confirmation. Run destructive/malformed scenarios with mocked tests or staging. |
| Second tenant opens payment URL | Not found/denied; no continuation token or invoice information. A billing-view-only user can view state but cannot continue payment. |
| Disable Mellat or checkout | New initiation denied. Existing started payments remain verifiable/reconcilable with their frozen account/version. |
| Unpaid expiry | After the deadline and scheduler run, only the original hold is released. No assignment or activation. Attempts are retained for reconciliation. |
| Delayed payment after deadline | Confirmed invoice remains `paid`; order `paid_unfulfilled`. A newer customer's hold is preserved. No automatic refund or reassignment. Test with provider-approved staging scenarios first. |
| Verify/settle uncertainty | `unknown` or `pending_settlement`, retained evidence, no second `bpPayRequest`; admin reasoned reconciliation checks bank truth. Do not create network faults in production to manufacture this test. |
| Explicit unsettled reversal | Only verified/unsettled/unpaid attempt; bank inquiry must confirm not-settled. Already-settled payments are never reversed. Reversal timeout retries only inquire. |

For reconciliation, open `/admin/payments`, enter an audit reason and use the action. Unknown initiation without saved references needs the merchant portal; the application does not reissue it. The admin reversal control is not a settled-payment refund. Confirm actual credit/refund outcomes with the merchant separately.

Record test invoice/attempt IDs, expected amount/unit, application status, bank-confirmed result, timing and any discrepancy. Keep card data, merchant secrets, raw SOAP and cookies out of records. After testing, turn the exposure flags off, rebuild config and retain callback/reconciliation accessibility for outstanding attempts. Preserve the resulting finance history; never wipe pilot orders with `migrate:fresh`.

Existing SIP registration, inbound ringing/audio and outbound caller ID/isolation should receive their usual release smoke checks. This release needs no FreeSWITCH restart or configuration changes. General paid launch still waits for atomic allocation, entitlement enforcement and customer configuration.

See [Mellat integration](MELLAT_INTEGRATION.md), [next batch](NEXT_PHASE.md#4-atomic-allocation-and-repair), and [deployment details](../deploy/README.md).
