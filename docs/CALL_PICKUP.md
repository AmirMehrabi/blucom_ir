# Call pickup

Implemented 2026-11-10. Production activation and real SIP acceptance remain separate from automated verification.

Customer owners and staff with `phones.manage` open **Extensions → پاسخ‌گویی به تماس همکار** at `https://my.blucom.ir/extensions/call-pickup`.

1. Create a pickup group, such as Sales, and save.
2. Assign the desired extensions to that group. An extension belongs to at most one pickup group.
3. Allow selected extensions to pick up colleagues' calls.
4. Enable tenant pickup and the desired directed/group modes, then save.
5. On a registered Zoiper/desk phone, dial `**2345` to answer an eligible call for extension 2345, or `*8` to answer the oldest eligible call in the same group. Press Call.

A pickup group controls authorization, not ringing distribution; it is independent of call queues. Both parties must belong to the same tenant and pickup group. Disabling an extension's pickup permission stops it initiating pickup; colleagues can still pick up its calls. A tenant-wide switch stops all subsequent pickup attempts. Connected conversations are unaffected. Unknown, disabled, unauthorized and subscription-ineligible callers fail closed. Disabling a group mode rejects its corresponding code. Deleted groups clear membership. Existing tenants and extensions start with pickup disabled.

## Supported calls

This initial implementation handles unanswered direct extension bridges: internal extension calls, direct inbound DID calls, and direct extension fallback before answer. Legacy inbound transfers use the existing local extension bridge. Multiple eligible calls are ordered by caller-channel creation time, with UUID as a stable tie-breaker.

Already answered calls, including calls answered for IVR, queue handling, or recording announcements, are excluded even if a downstream extension is ringing. Queue agents, held calls, voicemail and active conversations are outside this version. The page communicates this limitation. Unavailable pickup ends with `NO_PICKUP`; permission denial ends with `CALL_REJECTED`. No custom spoken prompt or live browser pickup button is included.

## Architecture and security

Database-backed tenant flags, `pickup_groups`, and extension membership/permission are authoritative. `CallPickupService` resolves targets from the authenticated SIP identity and current tenant/group/entitlement state on each XML-CURL request. Neither tenant IDs, target UUIDs, nor gateway names are accepted from the SIP dial code. Customer mutations use the existing customer host, session, permission and CSRF controls, tenant-scoped validation, and a transaction. Configuration changes are logged without credentials.

`FreeSwitchDialplanService` handles pickup codes only in `default` and returns a specific Lua invocation or an explicit rejection; pickup requests cannot fall through to outbound rules. Direct extension bridges receive a trusted `blucom_pickup_target` database ID immediately before the bridge and clear it afterward. Group permissions are checked when pickup is attempted, not cached in registrations or ringing calls.

The generic Lua application enumerates live UUIDs, checks the trusted target marker/current bridge application, and reads native ringing state and unanswered timestamps from `uuid_dump`. An atomic `mod_hash` claim serializes pickup requests; a scheduled owner-conditional cleanup expires abandoned claims. Native `intercept_unanswered_only` and `intercept_unbridged_only` checks reject calls answered/bridged after discovery. The server executes SIP/media handling locally. There is no additional browser registration, ESL connection, customer XML file, reload, or restart for CRUD.

## Production activation

The repository does not install scripts into the remote FreeSWITCH server. Before enabling:

1. Inspect the production module list, `script_dir`, XML-CURL binding and existing dialplan. Confirm `default` uses this Laravel XML-CURL endpoint and feature codes are not intercepted by an earlier static dialplan. Preserve the working profiles, gateway and inbound routing. Back up any existing pickup script and any bootstrap file you actually change.
2. Ensure `mod_lua`, `mod_dptools`, and `mod_hash` are loaded. Read-only checks: `fs_cli -x 'module_exists mod_lua'`, likewise for the other modules, and `fs_cli -x 'global_getvar script_dir'`. Modules already used by other features may already be present. If missing, install/load only the required module and add it to the existing modules bootstrap for persistence.
3. Install `deploy/freeswitch/blucom_pickup.lua` as `blucom_pickup.lua` in the inspected script directory, owned by root, mode 0644. For the usual package path: `sudo install -o root -g root -m 0644 deploy/freeswitch/blucom_pickup.lua /usr/share/freeswitch/scripts/blucom_pickup.lua`. Run from the deployed release, or copy this generic file securely to the SIP host first. Lua loads the file per invocation; installation itself needs no reload/restart.
4. Deploy the application and additive migration through the normal pipeline. If deploying manually, run `php artisan migrate --force` in the deployed release.
5. Set `VOIP_PICKUP_ENABLED=true` in protected application environment storage and rebuild Laravel configuration (`php artisan config:cache`) in the active release. This platform flag stays false until the script is installed. No FreeSWITCH shared secret is added.
6. Enable a disposable tenant's pickup settings and test the scenarios below before enabling customer tenants.

No change is required to internal/external ports, SIP profiles, gateway credentials, directory registration settings, or provider configuration. Existing XML-CURL must remain authoritative and must not cache pickup authorization/dialplans. Do not create a static `*8`/`**` rule bypassing Laravel. Phones must send the literal dialed feature code; adjust a device's local dialing rules only if they block or rewrite star codes.

## Acceptance and rollback

Automated feature tests cover customer permissions, tenant ownership, malformed cross-tenant memberships, tenant and mode switches, disabled targets, subscription revocation, XML-CURL request fields, explicit rejection and bridge markers. Isolated Lua scenarios cover oldest-call selection, wrong target, answered calls, non-bridge channels, competing claims, disappearing calls, invalid identity/arguments, answer races and scheduler failure. These mock runtime primitives and do not establish real SIP/media compatibility.

On production, use controlled test extensions and a test DID:

- Verify registration and ordinary inbound/outbound calls before and after activation.
- Ring one test extension; directed pickup from a permitted peer must connect both audio directions and stop the original ringing.
- Repeat with group pickup; ring two targets and confirm oldest-call selection.
- Have two peers request pickup concurrently; only one may connect. Answer the target at the same time; pickup must never steal an answered call.
- Reject another tenant, another group, disabled extension, revoked entitlement, and every disabled setting. Confirm unknown pickup codes cannot reach a provider gateway.
- Confirm a call with an already answered IVR/queue/announcement cannot be picked up.
- Inspect operational logs privately; do not copy SIP credentials or sensitive call logs into Git.

Rollback: set `VOIP_PICKUP_ENABLED=false` and rebuild the active release's config cache. Keep the additive schema and generic script for rollback compatibility. Existing connected calls remain connected. No FreeSWITCH restart is required.

Native implementation references: [intercept checks](https://github.com/signalwire/freeswitch/blob/master/src/switch_ivr_bridge.c), [atomic hash operations](https://github.com/signalwire/freeswitch/blob/master/src/mod/applications/mod_hash/mod_hash.c). Verify behavior against the installed release during SIP acceptance.
