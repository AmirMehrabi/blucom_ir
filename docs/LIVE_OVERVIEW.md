# Live organization and team overview

`/live` shows the organization's phones, with team filtering, search (including
Persian digits), status counters, card/list views, a keyboard-accessible detail
drawer, and the assigned operator's Available / On Break control. Team settings
link directly to the team's filtered live view. Ordering stays stable as calls
change. Empty states and missing monitoring have explicit explanations.

Phone registration, call activity, and team availability are independent.
Ready means enabled, registered, no current call, and Available. On call is a
connected local phone leg with a live bridge, not just an answered provider leg
or an IVR. On hold is FreeSWITCH's HELD call state. Actual voice activity is not
measured. Registration may remain valid briefly after a phone loses connectivity.
Multiple contacts and simultaneous calls are supported; the counters count
extensions, not the switch's individual call legs.

## Data flow and access

FreeSWITCH -> private inbound Event Socket -> `voip:monitor` -> Redis -> Laravel
JSON snapshot + private Reverb invalidation -> browser.

The monitor uses separate event and read-only API connections. Relevant local
phone/registration events trigger reconciliation, and an authoritative snapshot
is taken at least every five seconds. Events from provider call legs cannot
identify an extension by caller ID. Internal originating legs require Sofia's
authenticated user and realm. Called phone legs require the local directory
user/domain. Database ownership determines each tenant's projection. Ambiguous
extension numbers in a shared domain are not attributed to either tenant.

The browser receives invalidations without numbers, credentials, UUIDs, or SIP
headers. `/live/state` authorizes each fetch against current permissions and
tenant ownership. `live.view` permits viewing status; `calls.view` separately
permits caller numbers. Admins can select an active organization; operators
cannot change scope through a query parameter. Private-channel subscriptions
enforce the same ownership boundary. Revoked subscriptions have no sensitive
data to receive. A five-second snapshot refresh provides reconciliation and
continues during WebSocket reconnects. Hidden tabs suspend snapshot requests.

Snapshots are ephemeral and expire in Redis after 120 seconds. After twenty
seconds without a healthy monitor snapshot, phones become Unknown, rather than
Offline or stuck On call. Browser request failures likewise clear live states.
The worker exits on connection loss; systemd retries after five seconds and
rebuilds from registrations and current channels. No call history is added by
this monitor. XML-CURL remains the configuration source, and FreeSWITCH keeps
handling SIP and media.

## Operations

1. Configure the private ESL password in environment/secret storage and enable
   `VOIP_LIVE_ENABLED=true`, `VOIP_LIVE_CACHE_STORE=redis`.
2. Configure Reverb's app ID/key/secret privately. Laravel publishes to
   `127.0.0.1:8080`; Nginx proxies `/app/` for browser WSS. The public app key is
   rendered in the live page; the app secret and ESL password never reach it.
3. Install the three unit files in `deploy/`, reload systemd, and enable the
   firewall, Reverb, and monitor services. Install the PHP Redis extension.
4. Allow only loopback access to port 8021. Keep the existing password and
   configure the bootstrap ESL bind/ACL for loopback. Back up files first.
5. Future deployments restart active monitor/Reverb application services after
   switching the release. They never restart FreeSWITCH.

```sh
sudo systemctl status blucom-live-monitor blucom-reverb blucom-esl-firewall
sudo journalctl -u blucom-live-monitor -n 20 --no-pager
php artisan voip:monitor --once  # only while the continuous monitor is stopped
```

To stop monitoring, stop its service. The page becomes Unknown within twenty
seconds; routing and registration continue. Retain the private ESL firewall
even if monitoring is disabled. Backups can restore Nginx, environment, and the
ESL bootstrap independently. Browser access to the live page requires login.

## Server verification — 2026-09-30

Backups are under `/root/blucom-voip-backups/2026-09-30-live-overview`. Redis was
already present; the PHP Redis extension was installed. The environment is
readable by application workers and the existing FreeSWITCH queue worker via
an explicit ACL. The Event Socket module was already loaded. A persistent
IPv4/IPv6 firewall restriction now allows port 8021 only over loopback. The
bootstrap XML now specifies `127.0.0.1` and `loopback.auto`; the existing loaded
listener was retained, with the firewall enforcing privacy immediately, to
avoid touching running call processing. Nginx's WSS proxy was validated and
reloaded. Sofia profiles, gateways, and routing were not changed.

An isolated temporary tenant and two randomly assigned test extensions verified
authenticated SIP registration, internal dialing, called-phone ringing, a
bridged call, hold/resume, hangup, and unregistration through the real switch.
The live projection kept the existing organization's phones separate. Test
credentials stayed on the server and the temporary records were removed.
These tests exercised signaling and monitoring; provider PSTN calls and two-way
audio were not exercised by the synthetic clients.

Automated tests cover tenant and channel isolation, permission/number redaction,
disabled and stale states, availability ownership, duplicate identities,
registration expiry, provider spoofing, call-leg attribution, and call states.
Desktop/mobile browser checks cover rendering, search, filters, view switching,
drawer/Escape behavior, availability, and monitoring failures.

References: [FreeSWITCH Event Socket](https://developer.signalwire.com/freeswitch/integration/event-socket/),
[events](https://developer.signalwire.com/freeswitch/programming/events-catalog/),
[Laravel broadcasting](https://laravel.com/framework/docs/broadcasting).

The deployed page was checked through an authenticated, temporary browser
session: the state endpoint returned HTTP 200, the private channel authorized
with HTTP 200, and WSS invalidations reached the browser. Four configured
extensions and two registrations were observed. Both application services were
healthy, the existing queue sync continued, and the public page required login.
The temporary browser account/session was removed after verification.
