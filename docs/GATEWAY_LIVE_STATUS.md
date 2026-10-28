# Gateway live status

The admin `/sip-gateways` table separates database enablement from observed
FreeSWITCH gateway registration and OPTIONS reachability. Refresh the page using
«تازه‌سازی وضعیت» to read the latest cached snapshot. No ESL request runs in the
web request, and no gateway configuration, reload, restart, or call is triggered.

The existing `voip:monitor` worker reads the fixed, read-only command
`sofia xmlstatus gateway` once per snapshot cycle (normally five seconds). It
retains only name/profile identity, allowlisted registration/status values and
whether OPTIONS probing is enabled (`pingfreq > 0`). Raw Sofia output is never
cached, logged or returned to the browser. Gateway monitoring errors invalidate
only the gateway snapshot; extension monitoring continues.

Requires the existing `VOIP_LIVE_ENABLED`, private ESL credentials and
`VOIP_LIVE_CACHE_STORE` configuration and a running monitor. Deployment already
requests a graceful monitor restart, which loads this change without restarting
FreeSWITCH. Missing/disconnected snapshots and snapshots older than
`voip.live.stale_after` (20 seconds) display unknown. A fresh snapshot without the
record's exact name and profile displays “not loaded in FreeSWITCH”.

`REGED` is registered. `NOREG` is expected for trunks that do not register.
Reachability is shown only when the loaded gateway has OPTIONS probing enabled;
otherwise the page explicitly states that probing is disabled. Registration and
reachability do not prove call completion or working audio. Database enablement
and loaded runtime state may differ until gateway configuration is provisioned.
This change does not enable OPTIONS or alter provider behavior.

Protocol reference inspected:
https://github.com/signalwire/freeswitch/blob/master/src/mod/endpoints/mod_sofia/mod_sofia.c
(`cmd_xml_status` and `xml_gateway_status`). The one-argument gateway form emits
`<gateways>` with gateway children containing `name`, `profile`, `state`,
`status`, and `pingfreq`.

Validation: GatewayLiveStatusTest covers safe parsing, malformed/entity XML,
registration and non-registering snapshots, stale/disconnected/disabled
monitoring, profile matching and admin authorization. LiveOverviewTest,
AdminCrudUxTest and SipGatewayProvisioningTest cover existing monitor consumers
and gateway behavior. Actual provider registration/calls must be checked on the
FreeSWITCH host; no live SIP acceptance test is performed by this change.
