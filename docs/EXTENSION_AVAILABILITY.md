# Extension pause / resume

Implemented locally on 2026-11-10. Production rollout and live SIP acceptance remain pending.

## Customer behavior

Pause means temporarily stop new incoming calls to an extension. It is separate from disabling the account or taking a queue break. It persists until explicitly resumed, including across logout, reconnect and extension disable/re-enable.

| Situation | Behavior |
| --- | --- |
| SIP registration, credentials and device limits | Unchanged; all devices sharing the extension share its pause state |
| Existing conversation or already-issued ringing offer | Not terminated by this feature |
| Outbound SIP calls / customer-initiated outbound workflows | Remain subject to existing authorization; pause does not revoke them |
| Direct DID to paused extension | Skip that extension and run the existing authorized one-step unanswered fallback |
| Closed-hours destination is paused | End safely; do not bypass closed-hours routing into an open-hours fallback |
| Local extension dialing | Explicit busy hangup prevents falling through to outbound dialing |
| IVR key/direct-dial destination is paused | Try the published menu fallback; a paused extension fallback ends safely |
| Queue offers | Effective agent status becomes On Break after synchronization and readback |
| Paused fallback extension | Never bridge to it; preserve existing bounded fallback/IVR depth rules |
| Voicemail fallback | Remains usable; pausing the phone does not disable its mailbox |
| Resume while operator break remains active | Direct calls resume; queue status stays On Break |

XML-CURL evaluates availability when generating each dialplan. Calls/bridges already generated or offered before the change may continue. Queue enforcement is asynchronous: do not claim that a database write instantly stops offers already being dispatched by FreeSWITCH.

## Journey

Customer extension lists and details show registration separately from incoming-call availability, with **توقف دریافت تماس** / **ادامه دریافت تماس**. Persistent explanatory text describes active-call, outbound and fallback behavior without a blocking confirmation dialog. The operator availability page remains discoverable for assigned operators even when queue availability is hidden, and includes the same control for the assigned extension, alongside the existing independent queue-break controls. The existing admin extension list also exposes the control within its existing owner scope.

A submission records an explicit desired state and current version. The button shows a submitting state, then the standard redirect displays the updated state. Repeated identical submissions are idempotent. Conflicting stale changes return 409 to JSON clients; HTML clients return to a fresh page with an explanation. Disabled extensions reject pause/resume until enabled.

If queue confirmation is outstanding, the page explicitly warns that offers may continue until synchronization. It polls a scoped, private JSON endpoint for up to approximately one minute, with manual refresh available. Confirmed status updates in place without discarding other forms or credential displays. If another actor changes the version, the page asks for refresh instead of presenting stale confirmation. Missing synchronization never appears as confirmed; confirmations older than two minutes are treated as stale on refresh. Disabled queues do not leave the phone permanently waiting for confirmation.

The extension detail page shows the ten latest pause/resume and queue-break events separately. The event ledger supports future interval-based reporting; it is not an implemented attendance or operator-performance score.

## Data and authorization

Migration `2026_11_10_000004_extension_availability` adds nullable `paused_at`, monotonic `availability_version`, `queue_sync_version`, and `queue_synced_at`. `extension_availability_events` stores tenant, extension, actor type/ID, action, version and timestamp. No credentials or phone numbers are logged in availability events.

`ExtensionAvailabilityService` owns eligibility, effective queue status and pause transitions. It resolves membership server-side, locks the actor and extension, rechecks authorization and writes the state/event atomically. `phones.manage` allows tenant extension management; `queues.work` permits only the actor's own assigned extension. Foreign IDs fail as not found, and no submitted tenant ID or SIP identity establishes authority.

Endpoints:

- `PUT /extensions/{extension}/availability`: `paused` boolean and `version` integer.
- `GET /extensions/{extension}/availability`: authorized private state/version/pending status.

The shared host-selected authentication guards support separate customers and legacy users. Administrative requests retain the existing system-owner scope; this endpoint does not introduce cross-tenant admin impersonation.

Pause fields are not added to general mass-assignment inputs. Existing enabled/disabled actions remain separate. Registration/directory queries and outbound authorization queries are deliberately independent of pause.

## Routing and synchronization

Direct, local, closed-hours, IVR and fallback bridges use the shared eligibility service. Direct incoming routes that skip a paused extension clear that extension's attribution marker so the intentionally skipped phone is not credited with a missed attempt by the current importer. This does not manufacture per-offer queue performance data that is not yet collected.

`voip:sync-queues` derives effective agent status from both pause and operator break. It reads back agent statuses before confirming the availability version. A compare-and-update prevents confirmation of a version changed during synchronization. Queue CRUD still uses existing XML-CURL configuration; pause/resume does not write customer XML, restart FreeSWITCH or kill channels.

Existing queue-break updates now preserve pause, advance the availability version when changed, and append their own audit event. Ending a break while paused displays an explicit message that pause remains active.

## Verification and rollout

Automated coverage includes ownership, own-extension permissions, disabled state, idempotency, stale versions, independent break state, direct/local fallback behavior, IVR fallback, directory availability, authorized outbound calls, customer controls/audit rendering and queue readback/version races. Queue synchronization tests override switch command execution and do not operate the real switch.

Before production exposure:

1. Apply the additive migration through the normal release pipeline.
2. Verify that the existing `voip:sync-queues` runner is actually scheduled and executing on the switch host; the Laravel schedule already declares minute reconciliation. Do not infer runner health from database writes.
3. Pilot with an authorized test extension and its existing configured fallbacks. Check all registered devices, direct inbound, IVR, queue offers, pause during a conversation, already ringing calls, outbound calls and resume while on break.
4. Simulate switch/sync unavailability and verify that the customer sees pending confirmation and that retry eventually confirms the current version.
5. Inspect secret-free availability audit records and sanitized switch evidence. Never replace live routing or remove working static migration configuration without acceptance.

No FreeSWITCH infrastructure change is needed for this application change. For rollback, return to the previous application release and leave the additive schema intact. Before reverting to code that ignores pause, explicitly decide how paused phones should behave; rollback must not silently promise that they remain protected by older code.

Validation record: full suite 462 passed / 15 skipped / 3,302 assertions; the final targeted availability and queue suite passed 8 tests / 52 assertions after the operator-page adjustment. Final frontend production build and PHP formatting passed. Builds used an isolated temporary server directory that was removed afterward; production code was not changed.
