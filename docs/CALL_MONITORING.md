# Live listen-only call monitoring

Admin and customer Live views expose “شنیدن تماس” on answered, bridged extension calls. Choose browser audio or a different enabled supervisor extension in the selected tenant. Answer the supervisor phone for extension mode; browser mode registers a temporary, receive-only identity automatically. Stop disconnects only the monitoring leg. Closing the dialog keeps monitoring visible through its management button; leaving the page stops it. Sessions are limited to one per actor and one hour. Portal loss stops audio within 45 seconds. Browser timer throttling can end monitoring in a background tab; keep the Live page active.

`live.view` plus the dedicated `calls.monitor` permission is required. Existing and new customer owners receive it; staff require an explicit grant. Platform admins can select an active tenant. Customer-supplied organization IDs cannot change tenant membership. Both supervisor and target extensions must belong to the same tenant. No arbitrary dial string, gateway, context or SIP URL is accepted. Native UUIDs are returned only to authorized monitoring users. Calls ending before dispatch fail safely.

`CallMonitoringService` owns authorization and audit sessions. `MonitorSwitch` uses the existing private Event Socket transport. An answered local leg must match its authenticated or directory-dialed SIP identity, configured realm and internal profile. The generic native Lua application repeats identity/bridge checks after answer and requires a server-issued cancellation marker before entering eavesdrop. DTMF controls and whisper injection are disabled. Laravel never handles media.

Browser credentials exist only for the monitoring session. The database encrypts them, model serialization hides them, and only preparation returns them over the authenticated no-store response. They remain in JavaScript memory and are removed when the session ends. XML-CURL returns a separate directory identity in a context without a dialplan; the identity is absent from normal extension CRUD and bulk directory listings and cannot use ordinary outbound routes. Existing WebRTC WSS/TURN settings are reused. The browser creates no microphone stream. No automatic recording is introduced.

`call_monitor_sessions` stores actor, tenant, target, mode, requested/connected/end times and status. Sanitized business logs record preparation, connection and ending. Run `voip:reconcile-monitoring` every minute to close abandoned sessions. The application scheduler includes it. On hosts using individual systemd timers instead of Laravel schedule:run, install `deploy/blucom-monitor-cleanup.service` and `.timer`, run daemon-reload and enable the timer; do not add a global scheduler that would activate unrelated tasks. Native scheduled hangups enforce the lease independently of PHP. Switch credentials stay on the backend and Event Socket stays on loopback.

## Infrastructure and deployment

1. Inspect loaded `mod_dptools`, `mod_lua`, private Event Socket and existing WSS/TURN listeners.
2. Privately back up `/usr/share/freeswitch/scripts/blucom_monitor.lua` if it exists.
3. Install `deploy/freeswitch/blucom_monitor.lua` as root, mode 0644, in the inspected script directory. Scripts load per call; no reload or restart is required.
4. Deploy the additive migration and application through the existing release pipeline.
5. Set `VOIP_MONITORING_ENABLED=true` in protected shared environment storage and rebuild the release configuration cache; gracefully refresh the application live monitor.
6. Verify modules/profiles/gateways and XML-CURL. Verify browser registration, receive-only SDP and tenant authorization. Real headset/Zoiper acceptance should cover both audio directions, DTMF non-escalation, stopping, target hangup, unregistered supervisor, network loss, expired/revoked permissions and tenant isolation.

Disable new sessions with `VOIP_MONITORING_ENABLED=false` and rebuild the configuration cache. Existing sessions fail closed on their next heartbeat/lease expiry. The prior release remains available for rollback; keep the additive table. To stop immediately, kill only the listener UUIDs recorded in active monitoring sessions.

References: [eavesdrop application](https://developer.signalwire.com/freeswitch/dialplan/dptools/), [private Event Socket](https://developer.signalwire.com/freeswitch/integration/event-socket/), and the upstream `mod_dptools.c` eavesdrop flags. Production module behavior is verified separately; documentation is not a substitute for SIP acceptance testing.

## 2026-11-10 validation

The production host had `mod_lua` and `mod_dptools` loaded, Event Socket bound to loopback 8021, WSS listening on 7443, both internal/external profiles running and provider gateways registered. Installed the generic script as root:root 0644 after backing up protected environment/configuration state under `/home/ammir/deploy-backups/monitoring-20261110`. Seven native runtime checks passed, including incoming/outgoing identity matching, tenant/realm/profile rejection and cancellation-marker enforcement. Private hash insert/select/delete and scheduler command formats were verified. No SIP profile, gateway or existing customer routing XML was changed, and FreeSWITCH was not restarted.

Browser automation verified receive-only SDP with zero microphone requests and zero sender tracks, plus extension selection/start/stop controls. Automated PHP coverage includes customer-host authorization, cross-tenant target/supervisor rejection, permission revocation, expired preparation, duplicate sessions, encrypted credentials, safe directory context, stale target rejection and session ownership. Physical Zoiper/headset calls and actual browser audio across customer networks remain operational acceptance checks.

The production host uses individual systemd timers. Installed and enabled `blucom-monitor-cleanup.timer` and verified its oneshot cleanup command succeeds as www-data. The application release pipeline passed 466 tests with 15 skipped after the originate parser regression fix.

Post-deployment acceptance used a disposable tenant, three temporary SIP endpoints and a synthetic conversation through production XML-CURL/WSS. Browser monitoring connected with receive-only media, zero sender tracks, decoded RTP and a non-zero audio waveform (RMS approximately 0.58). Monitoring through a registered supervisor extension also connected. Stopping either monitoring session left the original synthetic conversation connected. All probe calls and temporary database records were removed. No PSTN/provider calls or customer calls were used for these checks. Physical Zoiper hardware and customer-network NAT behavior remain separate acceptance scenarios.

## 2026-11-10 narrowband browser monitoring correction

The first acceptance covered WebRTC-to-WebRTC media and missed narrowband telephone calls. Inspection of the eavesdrop mixer showed an unsafe sample-rate path when the monitored leg is 8 kHz and the listener negotiates Opus. Browser monitor originates now explicitly negotiate mono PCMU at 8 kHz. This changes only the new monitoring leg, not the original conversation, Sofia profiles, provider gateways or supervisor-device codec preferences.

An isolated production check bridged PCMA/8000 test endpoints and monitored them with the proposed PCMU browser leg. The browser decoded a 440 Hz test signal with its strongest FFT bin at approximately 445 Hz (within FFT resolution), nonzero waveform/energy, and no sender track. Correct signal frequency is checked in addition to packet arrival, which alone cannot establish usable audio. The ordinary tenant and listen-only restrictions remain in place.

The codec-fix deployment encountered preexisting availability columns and an events table without a migration ledger entry. Schema inspection confirmed the expected column types, nullability, defaults and foreign keys; the composite events index was absent. The availability migration now preserves the existing columns/table, creates the missing index with a MySQL-safe short name, and can be retried. A recovery test verifies existing event rows survive repeated application. No availability data is deleted or recreated.
