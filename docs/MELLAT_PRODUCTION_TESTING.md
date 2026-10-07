# Production setup and Mellat acceptance testing

This release adds reservation/pro forma and Mellat payment/settings. It does not allocate a paid DID or activate a subscription. Test admin setup with checkout off first. Prefer a merchant-approved staging account for bank acceptance; a live merchant pilot records a real payment and needs an agreed refund/repair procedure outside this application's unsettled-reversal action.

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

Verify PHP `curl` and `dom` are available in CLI and the active PHP-FPM pool. `ext-soap` is not needed. For CLI:

```sh
php -r 'foreach (["curl", "dom"] as $extension) { echo $extension, ": ", extension_loaded($extension) ? "yes" : "NO", PHP_EOL; }'
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

There is currently no Browse Numbers or invoice checkout button. For a controlled pilot, create one invoice using the backend, then submit the authenticated customer payment form below. No customer-owned SIP resources or existing working DID should be repurposed for this test.

Prepare one dedicated unassigned, technically reviewed and published stock offer and a designated customer owner. Obtain their Customer and NumberOffer IDs through admin records. Use a merchant-approved test amount and record the expected **IRT amount** and **IRR = IRT × 10** before paying. Finalize the test's refund/repair handling; the application cannot refund a settled payment yet.

For this controlled window only, set catalog/reservation/checkout flags to true in the shared environment and run `php artisan config:cache`. The flags are global, so first ensure there are no other customer invoices exposed for payment. Disable them again after the pilot. The customer-host callback and admin reconciliation continue functioning with the flags off.

### Create the pilot invoice

Run `php artisan tinker` from the serving release as the application user. Replace `PILOT_CUSTOMER_ID` and `PILOT_OFFER_ID` below with your selected actual integer IDs. These placeholders fail if left unchanged. Do not bypass model/service validation or edit ownership projections directly.

```php
$customer = App\Models\Customer::findOrFail(PILOT_CUSTOMER_ID);
$checkout = app(App\Services\Commerce\CheckoutService::class);
$quote = $checkout->quote($customer, PILOT_OFFER_ID);
$order = $checkout->reserve($customer, PILOT_OFFER_ID, $quote, (string) Illuminate\Support\Str::uuid());
dump(['invoice_uuid' => $order->invoice->public_id, 'amount_irt' => $order->total_amount, 'expires_at' => $order->expires_at->toIso8601String()]);
```

This creates a real pro forma and a 15-minute hold, without assigning the number. If readiness/availability/permissions fail, resolve the admin configuration; do not remove guards. Complete the payment within this original deadline for the normal success test.

### Start payment as the pilot customer

Log into `https://my.blucom.ir` as that Customer and open an authenticated portal page. In browser DevTools, run the following once, replacing the invoice placeholder. It submits a normal CSRF-protected form and redirects to the payment status/continuation page. It does not print any session token or merchant credential.

```javascript
const invoice = 'PILOT_INVOICE_UUID';
const storageKey = 'blucomMellatPilot:' + invoice;
const key = sessionStorage.getItem(storageKey) || crypto.randomUUID();
sessionStorage.setItem(storageKey, key);
const form = document.createElement('form');
form.method = 'POST';
form.action = '/invoices/' + encodeURIComponent(invoice) + '/payments';
for (const [name, value] of Object.entries({
  _token: document.querySelector('meta[name="csrf-token"]').content,
  idempotency_key: key
})) {
  const input = document.createElement('input');
  input.type = 'hidden'; input.name = name; input.value = value;
  form.appendChild(input);
}
document.body.appendChild(form);
form.submit();
```

Check the displayed toman total and deadline. Click Continue in Mellat and inspect the bank's displayed amount/unit before completing the approved test payment. Bank verification/settlement happens server-side after the POST callback. The return page is generic; log back into the portal or use the admin payment list to check the outcome.

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
