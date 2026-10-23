# Manual two-party outbound calling

## Scope and architecture

The customer portal at `/outbound/two-party` initiates an internal-agent-first conversation. There is no public API authentication, TTS, SIP proxy, RTP handling in Laravel, or per-tenant FreeSWITCH XML.

Laravel authorizes and snapshots each request, reserves agent/customer channel UUIDs and a background-job UUID, and persists dispatch intent **before** sending `bgapi originate`. The shared `blucom_two_party.lua` application executes on the agent's answered leg. It plays the optional agent introduction, optionally collects a fresh `1`, originates the customer, plays the customer introduction/required recording notice, and bridges. Blocking playback and digit collection complete on the switch; there are no delay-based workflow transitions. Early media is ignored and both sessions must report answered before bridging.

The native application continues safely through a short backend outage. Agent hangup kills the reserved customer UUID, customer origination is associated with its originating agent, both legs have bounded lifetimes, and either remaining leg is hung up after the bridge finishes.

The independent `voip:outbound-worker` subscribes to switch events and reconciles persistent sessions. The existing `voip:monitor` and its WebSocket snapshots remain independent. Portal status uses bounded polling and sanitized session resources.

## Existing services reused

- Tenant membership and customer permissions, `NumberNormalizer`, outbound routes and gateway approval.
- `LineEntitlementService` for billing/assignment authorization.
- The inbound ESL framing/authentication transport, extended with background-job support.
- Recording quota reservation, private spool/storage, completion Lua hook, processing and tenant-scoped playback/download controllers.
- Existing `CallRecord` history. Both leg UUIDs resolve to the same canonical session record; the CSV importer does not duplicate or reclassify these calls.

## Data and permissions

The migration adds `outbound_call_sessions`, sanitized `outbound_call_events`, `tenant_audio_assets`, `tenant_calling_settings`, and nullable session links on call records/recordings. Leg UUIDs and the single durable dispatch claim live directly on the session; a separate generic command queue is unnecessary because customer origination is controlled by one running native application.

`outbound.initiate` permits initiation and cancellation inside the actor's tenant. Owners receive it; staff need an explicit grant. Initiators can read their own status without `calls.view`. Users with `calls.view` can inspect tenant history. `outbound.manage` permits tenant defaults and audio upload. Existing recording permissions separately govern playback/download.

The selected DID must be the enabled route authorized for that extension. The UI does not make arbitrary tenant numbers usable as caller IDs. Current one-route-per-extension semantics remain intact.

## Tenant SIP identities

The migration backfills `sip_username` from existing extension numbers, preserving installed registrations. Display extension uniqueness changes from global to `(tenant_id, extension)`. A new extension can reuse another tenant's display number, but receives a distinct SIP username. Directory, bridge targets, queue contacts, authenticated caller lookup, live state and CDR ownership use the SIP username. Customers must configure their device's Username/Auth ID from the portal, not infer it from the display extension.

The legacy public-to-default transfer remains available only for unambiguous legacy extensions. Other inbound routes bridge directly to the resolved directory identity. Provider calls still enter `public`, customer SIP endpoints still use `internal:5060` and `default`, and no provider is granted unrestricted outbound access.

## Configuration

`config/voip.php` contains `two_party` settings:

- `VOIP_TWO_PARTY_ENABLED=false` by default.
- `VOIP_TWO_PARTY_TENANT_IDS=1,2` can restrict a pilot. Empty permits all tenants when globally enabled.
- Destination allow/deny prefixes, per-tenant session concurrency, requests per minute, confirmation timeout and maximum lifetime are backend policy. Empty destination allowlists deny all destinations. Line-specific destination prefixes further restrict inventory numbers. Configure these conservatively for approved national/mobile/international/premium destinations before enabling.
- Defaults: 30-second agent timeout, 45-second customer timeout, no announcements, no confirmation and recording off. Tenant defaults and bounded per-call overrides are available in the portal.

ESL host, port and password reuse `voip.live` environment/secret settings. The worker does not require the live dashboard feature flag, but does require a Redis cache store for its exclusive renewable lease. Do not expose port 8021 publicly or put credentials in source control.

## Deployment

1. Deploy the application through the normal reviewed release process. Do not deploy unrelated local changes. Back up the database and current generic Lua script/service unit before installing replacements.
2. Run additive migrations with `php artisan migrate --force`. Ensure all application nodes run the identity-aware code before allowing duplicate display extensions.
3. Install `deploy/freeswitch/blucom_two_party.lua` as `/usr/share/freeswitch/scripts/blucom_two_party.lua` (or the inspected FreeSWITCH `script_dir`), owned by root and readable by FreeSWITCH. `mod_lua`, `mod_event_socket`, `mod_dptools`, `user` endpoint resolution, PCM playback and the recording-completion hook must be available. The script is loaded per call, so this installation requires no reload/restart.
4. Install `deploy/blucom-outbound-worker.service` under `/etc/systemd/system/`, then run `systemctl daemon-reload`. Its working directory follows the existing immutable deployment layout. Add a worker restart to the normal release procedure, alongside the existing live monitor restart.
5. Provision the native result spool with `sudo install -d -o freeswitch -g www-data -m 2770 /var/www/html/blucom-deploy/shared/storage/app/two-party-spool`. Set `VOIP_TWO_PARTY_SPOOL` if a different shared path is required. The PHP worker and FreeSWITCH must see the same directory. Each native application atomically writes a small UUID/timestamp/failure journal; completed journals are removed after successful reconciliation and channel cleanup. Ensure FreeSWITCH can read the shared IVR WAV directory and write to the existing recording spool, and PHP can process completed recordings. Audio uploads require ffmpeg. Storage paths must use safe absolute path characters; no portal input becomes a filesystem path or dial string.
6. Enable recording globally only after the existing recording pipeline is verified. Enable outbound recording for the selected DID in its recording policy. The per-call checkbox cannot bypass that policy. If its announcement flag is enabled, a valid configured recording notice is required and played to the customer independently of optional introductions.
7. Set the feature flag and optional pilot tenant allowlist in secret/environment configuration; rebuild Laravel config cache. Start/enable the outbound worker. Verify `systemctl status blucom-outbound-worker` and the application health endpoint.
8. Perform the manual acceptance checks below. No real PSTN calls are made by automated tests.

No FreeSWITCH profile, gateway or dynamic customer XML rewrite is needed. Normal feature CRUD requires no FreeSWITCH reload/restart.

## Failure, recovery and recording behavior

- A request key is unique per tenant. Identical retries return the same session; changed input under the same key returns 409. Rate and concurrency admissions are serialized by locking the tenant.
- New requests require a fresh worker health heartbeat. A disconnected worker returns 503 before accepting new call sessions; identical retries can still recover an existing session.
- A dispatch marked `sending` is never automatically originated again, even when the response was lost. The worker observes reserved UUIDs instead. A claim lost before network submission eventually fails safely rather than risking duplicate calls.
- Reconnect/restart reconstructs phases and timestamps from agent channel variables, switch-owned bridge timing, native result journals and CDR evidence. Completed native journals recover even a short call entirely missed during an outage. Missing evidence (for example a switch/process crash before its final journal) is reported as `control_unavailable`, never guessed as a successful conversation.
- Cancel requests are persisted and the worker kills both reserved UUIDs. Cancellation waits for worker connectivity during an ESL outage; switch-native hangup hooks and maximum lifetime bound the call independently. Cancellation stays available for an expired billing subscription.
- Terminal sessions remain in cleanup until both legs are absent and the outstanding originate window has elapsed. They continue to consume concurrency capacity during that interval to prevent oversubscription or leaked billable channels.
- Conversation recording is armed immediately before bridging with `RECORD_BRIDGE_REQ=true`, after all announcements. Waiting audio is not conversation. Quota/storage reservation failures do not grant new recording authorization; recording availability/failure is shown separately from call success. Unbridged attempts release their unused reservation.
- Recording privacy, expiry, download permission and tenant ownership use existing services. There is one canonical agent UUID for recording finalization.
- Operational logs contain session/tenant/actor identifiers, not raw SIP/ESL payloads or credentials. CDR and existing switch logs remain sensitive operational data.

## Automated checks

```sh
php artisan test --compact
python3 tests/Telephony/run_lua.py
npm run build
```

The Lua fixture runner uses Python's standard ctypes library and an installed liblua (5.2, 5.3 or 5.4). It replaces every switch/network/media API with a deterministic fake. PHPUnit skips that fixture if liblua is unavailable. It does not install dependencies or connect to SIP.

Tests cover request authorization, isolated overlapping extensions, idempotency, call limits, revoked routes, early media, announcements, confirmation, busy/unanswered/unreachable cases, recording timing, cancellation, hangup cleanup, duplicate events and uncertain-dispatch/reconnection behavior. Existing XML-CURL, recordings, live monitoring and billing regression suites remain required.

## Manual acceptance checklist

Using an explicitly selected test tenant, registered SIP agent and permitted destination:

1. Verify existing registration, inbound DID routing and ordinary outbound calls before and after enabling.
2. With both introductions disabled, verify agent first, customer second, two-way audio and one history entry.
3. Verify no customer call on agent busy, unanswered, confirmation timeout, rejection or hangup.
4. Verify confirmation accepts only a fresh `1`, with each introduction separately and together. No conversation audio should pass during either introduction.
5. Verify customer ringing/waiting audio, customer busy/unanswered/unreachable and provider early media without SIP answer.
6. Verify cancellation while ringing, during a prompt and while bridged; verify either hangup cleans the peer.
7. With recording on, verify only the connected conversation is present, policy notice plays if required, processing completes and authorized playback/download works. Repeat with recording off and a full quota.
8. Restart the outbound worker while ringing, in confirmation, during playback and while bridged. Interrupt ESL connectivity briefly. Verify no duplicate customer call and eventual correct cleanup/status.
9. Use two tenants with the same display extension and verify registration, inbound, ordinary outbound, manual calls, live monitoring and recordings never cross tenants.
10. Check failures and lifecycle times, delayed CDR import, user permissions, concurrent limits and double-click/retry behavior.

Live registration/calling acceptance is performed manually by the operator; the implementation run does not place PSTN calls.

## Rollback

Disable new initiation with `VOIP_TWO_PARTY_ENABLED=false` first. Let the worker finish/reconcile active calls before stopping it; the switch application continues independently if the worker stops. Keep the generic script until active calls finish. Preserve session/CDR/recording data and the identity-aware schema/code; do not restore global extension uniqueness or roll back SIP identity support after overlapping numbers have been created. No Sofia profile or provider gateway rollback is required.

## Production staging record — 2026-10-23

Read-only checks confirmed FreeSWITCH 1.11.3 with `mod_event_socket`, `mod_lua` and `mod_dptools` loaded. Sofia internal/external profiles remained running and the existing provider gateway remained registered.

The generic Lua application and disabled `blucom-outbound-worker.service` were staged. Previous target files, when present, were backed up under `/home/ammir/deploy-backups/two-party-20261023.p7UTjr`. The shared journal directory is `freeswitch:www-data`, mode `2770`; a harmless native Lua write and PHP-user read/delete check passed under the actual FreeSWITCH process umask (`0007`). Native `mod_lua` syntax validation and all 17 isolated Lua scenarios also passed on that host.

No Sofia profile/gateway configuration was changed, FreeSWITCH was not reloaded/restarted, no application release or database migration was deployed, and no PSTN calls were placed. Feature activation and supervised calling checks remain for the normal application release and the operator's manual tests.

## Production activation — 2026-10-23

Commit `0a3af296592620a243ebb58d83117d49cde9fde0` was pushed to `master` and deployed by the existing GitHub webhook. The calling migration completed, owner permissions were granted, and `VOIP_TWO_PARTY_ENABLED=true` was set in the protected production environment. The outbound worker is enabled at boot and running with a fresh ESL health heartbeat. The shared result spool uses the stable shared-storage path. Subsequent deployments gracefully restart the active outbound worker.

The customer health endpoint returned 200, the calling page required customer authentication, and FreeSWITCH returned valid directory XML. The native script matched the release. Existing Sofia profiles and provider gateway remained healthy, with no FreeSWITCH reload/restart and no PSTN test calls. The pre-release database/environment backup is retained in the protected server backup directory.

### Quick live test

1. Assign an outbound-enabled DID and approved provider route to your tenant. Create an agent extension, enable its outbound route, and register Zoiper using the **SIP username shown in the portal**, its password and the internal SIP server/port.
2. Open `https://my.blucom.ir/outbound/two-party`. Select the agent and authorized caller ID, enter your mobile number, leave introductions/recording off and start the call. The agent must ring first; only after answering should the mobile ring. Answer both and verify two-way audio.
3. Repeat without answering the agent: the mobile must never ring. Enable confirmation and verify the mobile rings only after a fresh `1`. Select introductions independently and verify each finishes before conversation begins.
4. Enable outbound recording in the DID's recording policy, then enable recording for a call. End the call and check the history, duration, recording playback and download with the appropriate permissions. Also try cancellation while the mobile is ringing and confirm both legs end.

If the form has no eligible agent/caller-ID pair, finish the line's DID, gateway and extension outbound-route setup first. A 503 indicates missing/stale worker health; check `systemctl status blucom-outbound-worker` and its journal before retrying. No further manual FreeSWITCH configuration changes are required for ordinary setup or CRUD.
