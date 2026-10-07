# Paid line activation

## Ownership and billing

Mellat server verification and settlement commit the invoice first. A separate database transaction allocates the original DID exactly once, consumes its reservation, records immutable assignment history, and creates a subscription with the frozen checkout price and plan. Duplicate callbacks and scheduled retries are safe. An expired hold becomes `paid_unfulfilled`; payment evidence stays intact. An administrator can repair it at `/admin/fulfillment`, with an audit reason, only when the same number is free, its original offer still matches, and no other customer holds it.

The first successful answering configuration activates the subscription and starts one calendar month (without month overflow). Creating phones and registering Zoiper are allowed before activation; PSTN calls require the active period. Saving configuration again does not extend the period. Renewal, metered usage, and automatic refunds are separate workflows.

The largest eligible plan limit applies across the tenant, not the sum of purchased lines. Customer phone, queue, and IVR creation locks the tenant before capacity checks. Existing non-commerce lines retain their routing behavior. Commerce stock cannot use legacy assignment forms.

## Customer flow

1. Register or log in at `https://my.blucom.ir`.
2. Open **خرید شماره**, select a number, review its monthly price and capacities, reserve, and pay through Mellat.
3. After verified payment, choose **تنظیم خط** from the order or **خط‌های من**.
4. Select a new/existing phone, team, or call menu and save. Saving starts the first month.
5. Connect Zoiper with the displayed extension username, generated password, public SIP host, and internal profile port 5060. Save the password when shown; resetting replaces it.
6. Enable outbound explicitly for selected phones. The server chooses the purchased DID as caller ID and checks allowed destination prefixes.
7. For teams, set answering phones to Available and allow the next scheduled queue reconciliation to finish. For IVR, upload a greeting, select digit destinations and a fallback, then save.

Provider credentials are never supplied to the customer. Credential responses use private/no-store and no-referrer headers. SIP secrets remain encrypted in the database.

## Dynamic queues

The protected XML-CURL endpoint now accepts `configuration` lookups for `callcenter.conf` only, optionally scoped by `CC-Queue`. Queue reload/load obtains database configuration through XML-CURL. `voip:sync-queues` reconciles queue, agent, tier and availability state without writing `/etc/freeswitch` or running `reloadxml`. Schedule Laravel every minute; the application schedule runs queue reconciliation, reservation expiry and paid allocation recovery.

Use the loopback Nginx listener in `deploy/nginx-freeswitch-internal.conf` on `127.0.0.1:9088`. It serves only the XML endpoint and still requires Laravel authentication. This avoids public edge filtering on authenticated server requests. Keep XML-CURL gateway credentials unchanged when changing its URL to `http://127.0.0.1:9088/internal/freeswitch/xml`.

Infrastructure setup needs a one-time addition of `configuration` to the existing XML-CURL binding sections (`directory|dialplan|configuration`). Back up the binding file privately before editing; keep its URL/secret unchanged. Reload only `mod_xml_curl`, then verify a harmless callcenter configuration lookup, directory lookup, dialplan lookup and Sofia status. Do not restart profiles or FreeSWITCH for customer CRUD. FreeSWITCH's [mod_callcenter source](https://github.com/signalwire/freeswitch/blob/master/src/mod/applications/mod_callcenter/mod_callcenter.c) loads queues through `switch_xml_open_cfg("callcenter.conf", ...)` with `CC-Queue`.

## Live acceptance record

Automated authorization/XML tests do not prove provider calling or media. Record actual results separately: verified bank purchase, Zoiper REGISTER, inbound DID ringing and two-way audio, outbound approved destination with correct caller ID and two-way audio, queue ring/availability, IVR greeting/digit/fallback, disabled/expired denial and cross-tenant rejection. Never claim these pass from registration status or historical CDRs alone.

Run `php artisan voip:check-line-acceptance <DID database ID> --since=<ISO-8601 test start with timezone>` after importing test CDRs. It checks the paid assignment, active subscription, answering route, current phone contact, and recent answered inbound/outbound calls for that DID. To record a full pass, add `--caller-id-confirmed --two-way-audio-confirmed` only after a human observed the receiving caller ID and audio on both calls. Without these confirmations it reports an incomplete check. The CDR template must append `blucom_sip_number_id` and `blucom_assignment_id` as columns 29 and 30, preserving all existing columns. These markers are set by authorized dialplans; delayed CDRs resolve historical assignment ownership.

On installations without Laravel's scheduler, install `deploy/blucom-commerce-maintenance.service` and `.timer` under `/etc/systemd/system`, reload systemd, and enable the timer. This separately runs paid allocation recovery and reservation expiry every minute. Existing queue synchronization timers can remain; the shared lock prevents overlapping reconciliations.
