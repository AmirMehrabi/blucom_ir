# Direct extension dialing from IVR

An IVR manager can enable **اجازه شماره‌گیری مستقیم داخلی‌ها** in the menu editor,
select the extensions callers may reach, update the recorded greeting, and use
**ذخیره و انتشار تغییرات**. **فقط ذخیره پیش‌نویس** leaves the live menu unchanged.
The same tenant-scoped editor serves administrators and customer phone managers.
New extensions are not automatically exposed to incoming callers.

The setting and selected extension IDs live in the existing versioned IVR JSON
configuration. Existing menus retain single-digit behavior until deliberately
enabled and published; no schema migration or FreeSWITCH reload is required.
Publishing and restoring validate the selected extensions against current tenant
ownership and enabled state. Save-and-publish is a database transaction; failed
publication does not overwrite the saved draft.

## Call behavior

FreeSWITCH's existing `play_and_get_digits` collects a menu key or a selected
extension's display number (3–9 digits). Maximum collection length follows the
longest currently enabled selected extension. Input has an 8-second initial
wait, a 3-second inter-digit wait, two attempts, and optional `#` completion.
With direct dialing enabled, a menu key waits for further digits or `#`, allowing
option `2` and extension `2001` to coexist. Shorter extensions also finish with
`#` or the inter-digit timeout. The greeting should explain direct dialing and,
where helpful, `#` for immediate completion of a menu choice.

The XML-CURL lookup stays in `blucom_ivr`. It validates the called number, route,
tenant, entitlements, gateway and menu version before resolving the input.
Single-digit choices retain precedence. Multi-digit input can reach only an
explicitly selected, currently enabled extension in that menu's tenant. Bridging
uses the extension's database-resolved SIP identity, including opaque usernames.
Invalid, missing, disabled, foreign and unselected extensions use the existing
fallback; they never enter outbound/default routing. In-flight lookups retain
the existing current/previous menu-version behavior.

## Validation and rollout

Automated coverage includes legacy menus, digit-collection XML, overlapping menu
keys, tenant allowlists, disabled targets/numbers, opaque SIP identities, invalid
input, draft/publication/restore, manager permissions and atomic save-and-publish.
Desktop and 390-pixel mobile browser checks cover the toggle and extension list.

Production preflight on 2026-10-26 confirmed FreeSWITCH 1.11.3 ready,
`mod_xml_curl` loaded, internal :5060 and external :5080 running, and the existing
provider gateway registered. A private database backup and previous release path
were retained under `/home/ammir/deploy-backups/ivr-direct-dial-20261026`.
Deploy through the existing master-push webhook and verify its serving SHA,
`/up`, login redirects, built assets and Sofia status. No FreeSWITCH configuration
change, restart or reload is necessary. Rollback uses the previous release
symlink; legacy code ignores the additional JSON fields.

After enabling this feature on a chosen menu, verify with a real incoming call:
menu option, option plus `#`, full selected extension, invalid extension, and
no-input fallback. Confirm extension registration and a normal outbound call.
These real handset/provider checks are separate from automated XML tests; the
implementation does not place production calls or enable existing menus itself.
