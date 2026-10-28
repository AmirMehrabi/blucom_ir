# Customer line setup journey

The customer sidebar lists Lines, Extensions, Menus and Teams in that order.
`/lines/{number}` now starts with a caller-oriented summary and three equally
visible choices: ring an extension, wait in a team queue, or choose a department
from a voice menu. Select existing destinations or create them inline. Empty
categories default to creation, with clear links to manage prerequisites.

The page separates four tasks:

1. Incoming destination, with a draft summary before saving.
2. Working hours, holidays, outside-hours handling and temporary closure.
3. Busy/offline/unanswered handling, or links to the selected queue/menu's rules.
4. Saved-route preview for a chosen time and a real-call test checklist.

Phone credentials/device instructions live on the focused extension screen.
Creating an extension from a line opens that screen with a return link to the
line. Outbound settings are a separate collapsible section. Saving the incoming
destination does not change outgoing calling authorization or working hours.

## Common scenarios

- Reception/direct employee: configurable ring timeout (10–120 seconds); after
  busy, offline, rejection or no answer, end the call or try a different tenant
  extension, queue or published menu. Fallback is bounded, not recursive.
- Sales/support queue: quick creation exposes ring-all, round-robin or
  longest-idle, maximum wait and a fallback extension. Existing queue edits
  affect all numbers using that queue, which the customer screen explains.
- Company main number: quick creation exposes keys 1–3, with 4–9 and 0 behind
  “more keys”, greeting upload, no-selection/invalid-selection fallback and
  optional allowlisted extension dialing.
- Nested menus: published menus of the same tenant can be destinations of menu
  keys or fallback. Publishing rejects cycles and paths deeper than five menus.
  The runtime verifies reachability from an authorized line root and caps depth.
- Office hours: reuse the shared schedule editor for multiple daily intervals,
  timezone, Jalali holiday dates, closed announcement/disconnect or a different
  tenant destination. Changing an answerer preserves an existing schedule.
- Temporary closure: use the saved outside-hours handling until a specified
  time (up to 30 days), then automatically resume the normal schedule. Always-open
  lines can choose a closure destination without first creating working hours. Can be
  cancelled early. Saving working hours explicitly cancels a temporary closure,
  as explained next to the save button.
- External forwarding on unanswered calls: requires the manager's
  `outbound.manage` permission and an already authorized enabled outbound route
  for this extension using this line. The UI explains outgoing-call charges and
  caller ID. Reuses destination-prefix policy from CallPolicyService; rejects
  malformed SIP targets and the tenant's own DIDs. XML generation rechecks all
  tenant, extension, DID, gateway and destination authorization, so revocation
  immediately fails closed. Uses the authorized DID as caller ID. No arbitrary
  gateway or SIP URI enters a bridge target.

## Voicemail rollout gate

Voicemail support is implemented but intentionally hidden by default. Only set
`VOIP_VOICEMAIL_ENABLED=true` after inspecting the FreeSWITCH installation and
confirming `mod_voicemail`, its `default` profile, writable storage and usable
prompts. The local environment cannot connect to the production ESL endpoint,
so these prerequisites and real voicemail operation are unverified.

When enabled, a customer can choose voicemail for an unanswered direct extension.
The system generates an encrypted random mailbox secret, keeps it hidden from
model serialization and the frontend, and supplies it only in internal directory
XML. Mailbox IDs use the extension's unique SIP identity, preserving tenant
isolation even for overlapping display numbers. The employee dials `*98` from
that registered extension; the default-context rule resolves the authenticated
SIP identity server-side and opens only that extension's mailbox. Anonymous
mailbox access and selection of another mailbox are not exposed. Messages remain
in FreeSWITCH storage; there is no email delivery or web inbox in this change.

## Deployment and verification

Run the standard deployment migration. New inbound-route fields are ring timeout,
unanswered destination/phone, and temporary-closure expiry. Extensions gain an
encrypted voicemail-secret field. Existing routes default to their prior behavior;
the working legacy transfer remains when no fallback or custom timeout is set.
No FreeSWITCH files, modules, profiles, gateway registrations or restarts are
changed by application CRUD or by this implementation.

Automated tests cover schedule preservation, hours, temporary expiry, preview,
tenant/permission checks, fallback isolation, forwarding authorization and
revocation, voicemail gating and authenticated mailbox identity, nested routing
and cycle rejection. Existing SIP XML, billing, tenant and customer tests remain
part of the regression suite. Browser checks cover desktop/mobile layout, all
three choice transitions, empty categories, JS errors and horizontal overflow.
Real registration, inbound/outbound audio, forwarding billing, queues and
voicemail acceptance must still be verified against the live switch.

Protocol references checked:
- https://developer.signalwire.com/freeswitch/reference/channel-variables/
- https://developer.signalwire.com/freeswitch/applications/voicemail/

Validation result: 414 tests passed, 15 skipped by existing integration
conditions; frontend production build passed. Desktop (1440px) and mobile
(390px) browser checks found no horizontal overflow or JavaScript errors.
