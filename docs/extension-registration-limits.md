# Extension registration limits

Each SIP extension has `max_registrations`, an integer from 1 through 5, defaulting to 1. The additive migration sets existing extensions to 1 as well. The admin extension create/edit forms and customer line phone settings expose the limit. Customer changes require the existing phone-management permission, line entitlement, and tenant ownership checks. This setting belongs to the extension and applies wherever that extension is used; it is independent of DID routing and simultaneous call limits.

`FreeSwitchDirectoryService` emits `max-registrations-per-extension` inside each user's `params` in the protected XML-CURL directory response. FreeSWITCH owns registration enforcement. No customer XML files, polling, registration counters in Laravel, profile/gateway changes, or reload/restart hooks are introduced. Changing the value affects subsequent registration requests; it does not immediately evict existing registrations or terminate calls.

The upstream Sofia registration implementation counts other registrations by SIP username/domain while excluding the request's Call-ID, then rejects requests exceeding the configured limit. A client restart or network change can leave an old registration occupying capacity until expiration. This is an active-registration policy, not physical-device binding or protection against credential theft. The upstream check is a count followed by registration, so simultaneous registration races must be tested before treating it as a strict security boundary.

Reference: https://github.com/signalwire/freeswitch/blob/master/src/mod/endpoints/mod_sofia/sofia_reg.c (user parameter parsing and REGISTER limit checks).

## Release verification

Apply `2026_10_26_000001_add_max_registrations_to_sip_extensions.php` through the normal release process before serving the changed application. No production migration or FreeSWITCH change was performed during implementation. No local FreeSWITCH binary or profile configuration was available in this checkout.

Before rollout, inspect the installed FreeSWITCH build's support for the parameter and the internal profile's `multiple-registrations` setting. Do not assume that disabling multiple registrations rejects a new device; replacement behavior differs from rejection. Preserve existing profiles and gateway settings unless live tests demonstrate a necessary change, following AGENTS.md's backup and validation procedure.

With a controlled test extension:

1. Set the limit to 1. Register device A and confirm regular refreshes succeed.
2. Attempt device B with the same credentials; verify rejection while A remains registered and reachable. Also test simultaneous attempts.
3. Unregister A, then verify B can register. Repeat with a client restart, NAT/network changes, and an expired stale registration.
4. Set the limit to 2 and verify both devices register, refresh and receive calls as intended by the existing dial string. Test that a third registration is rejected. If the profile supports only one stored contact, inspect and test the required one-time infrastructure change before exposing multiple-device behavior.
5. Lower the limit and confirm subsequent requests enforce it without claiming immediate eviction.
6. Verify normal inbound/outbound calling, authorized caller ID, and tenant isolation.

Automated validation: `php artisan test --compact tests/Feature/VoipConfigurationTest.php tests/Feature/FreeSwitchXmlTest.php tests/Feature/PurchasedLineTest.php` (46 tests passed). These validate application behavior and XML contracts; they do not simulate Sofia or physical SIP devices.
