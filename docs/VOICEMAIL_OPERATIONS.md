# Production voicemail — activated 2026-10-29 (Asia/Tehran)

Production serves release `664f7ffd2b48ac84ceb2d2055d31681c90b67187` at activation.
The shared production environment now has `VOIP_VOICEMAIL_ENABLED=true`.
Laravel's configuration cache was rebuilt and PHP 8.5 FPM gracefully reloaded.
The source configuration and example environment retain a disabled default for
new installations that have not been inspected.

## FreeSWITCH inspection and adjustment

- FreeSWITCH was already running with `mod_voicemail` loaded and configured to
  autoload. Its `default` voicemail profile was present in both the file and
  runtime XML.
- FreeSWITCH could write to its native storage and database directories. About
  10 GiB was free at inspection. The existing voicemail database had no messages
  or mailbox preferences, and there were no active voicemail channels.
- English Callie voicemail prompts and phrase macros were installed, including
  8 kHz and 16 kHz audio. No Persian prompt pack was installed or generated.
- The vanilla profile exposed operator and vmain transfers into the static
  `default` dialplan. In `/etc/freeswitch/autoload_configs/voicemail.conf.xml`,
  `operator-extension`, `operator-key`, `vmain-extension`, `vmain-key` and
  `login-keys` were set to empty values. Public callers can leave messages but
  cannot use those shortcuts to reach operator/default routes or mailbox login.
  Authenticated employee mailbox access remains through Blucom's `*98` rule.
- XML was parsed and validated. `voicemail reload default reloadxml` successfully
  reloaded that profile. FreeSWITCH itself, Sofia profiles, and provider gateways
  were not restarted, rewritten or re-registered.

Private rollback copies of the original voicemail profile, shared application
`.env`, and native voicemail database were saved under:

`/home/ammir/deploy-backups/voicemail-20261028T203547Z`

The backup directory is mode 0700; files are mode 0600. It contains secrets and
must not be copied into source control or logs.

## Tenant isolation

Native voicemail menus include message forwarding. Mailboxes therefore use
`vm-<tenant-id>.blucom.internal` domains, separate from the SIP registration
domain. XML-CURL returns mailbox-only directory entries for the selected tenant:
no SIP password, dial string, or calling context. SIP authentication requests
against these domains are rejected. Numeric extension aliases resolve only
within the same tenant; the underlying mailbox uses its unique SIP identity.
Unconfigured/disabled mailboxes are absent, and ordinary SIP directory entries
explicitly disable native voicemail in the shared registration domain. Default
greetings use the human-readable extension number rather than an opaque identity.

## Live validation

An isolated temporary tenant and mailbox were created with runtime-generated
credentials, no DID, no provider route, and no customer login. No customer or
PSTN number was called.

1. Laravel generated the mailbox's directory parameters and authenticated
   own-mailbox dialplan; the anonymous mailbox rule was absent.
2. Live FreeSWITCH `user_exists` and `user_data ... param vm-enabled` confirmed
   that XML-CURL returned the synthetic user and enabled mailbox.
3. A native loopback caller left a 10-second audio message. FreeSWITCH stored
   the message metadata and a nonempty WAV file.
4. A separate native mailbox playback call retrieved the message. Its captured
   audio contained 9.2 seconds of the original test tone, and FreeSWITCH marked
   the message read.
5. Both test calls ended. The synthetic message, mailbox preferences, capture
   files, application extension/tenant, and live-cache entry were removed.
   FreeSWITCH subsequently returned false for that directory user, and no test
   messages remained. Existing operational logs were retained.
6. Both provider gateways remained `REGED`. The voicemail profile remained
   loaded. There were no registered SIP clients at final inspection, so actual
   Zoiper registration, handset `*98`, and real PSTN inbound/outbound audio were
   not exercised by this validation.

## Customer use

Open Lines → the line → unanswered calls. Choose “تماس‌گیرنده پیام صوتی بگذارد”
and save. This enables voicemail for that direct-extension route; activation of
the global flag does not automatically change existing line destinations.
The employee uses `*98` from that extension's registered SIP phone to listen to
messages and follow the spoken menus to record a personal greeting. Native
instructions currently use the installed English prompts. Messages live in
FreeSWITCH storage; web inbox and email delivery are not implemented.

## Disable or recover

To hide and disable the application feature, set the shared production
`VOIP_VOICEMAIL_ENABLED=false`, rebuild Laravel's config cache, and gracefully
reload PHP FPM. The shared environment survives application deployments.
Existing voicemail messages should be preserved. The profile backup is available
for recovery; ordinary feature disabling does not require restoring unsafe
public login/operator shortcuts or restarting FreeSWITCH.
