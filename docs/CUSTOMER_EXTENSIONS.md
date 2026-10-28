# Customer extension management

Customer navigation now exposes **مدیریت تماس → داخلی‌ها** at
`https://my.blucom.ir/extensions`, protected by `phones.manage` and the existing
customer authentication/account/billing middleware.

The list shows extension names, numbers, enabled state, registration (when the
actor has `live.view`), and the configured outbound number. Registration reuses
the existing tenant-scoped monitor; unavailable or stale monitoring is unknown.
Refresh the page for the latest snapshot.

Create an extension with a name and available extension number. The existing
CustomerLineSetupService enforces number validation, uniqueness, SIP identity,
secure generated passwords and plan capacity. Creating an extension does not
assign inbound or outbound routes. The detail page provides shared SIP device
instructions, one-time credentials, reset, name, registration limit and enable
controls. Updates only accept these settings; tenant ownership is resolved from
the authenticated customer. Reactivation checks plan capacity. Disabling an
extension retains its routing configuration.

The detail page lists direct inbound routes (including closed-hours routes),
queue memberships, published IVR references and the outbound number. Links open
the existing line, IVR and queue editors. Line links preselect the extension;
explicitly saving the line is required to change routing. Outbound caller ID and
gateway authorization continue through the existing line configuration flow.

From a line's phone section, “مدیریت و افزودن داخلی‌ها” opens the workspace with
the line context. Creating the extension leads to connection instructions and a
return action to the original line. All line and extension IDs are tenant-scoped;
no arbitrary return URLs are accepted. Credentials use non-cacheable responses
and the detail page sets a no-referrer policy.

No FreeSWITCH configuration or reload/restart is required. No database migration
is needed. CustomerExtensionsTest covers lifecycle, credential flashing,
permissions, cross-tenant reads/writes/return context, capacity, duplicate
numbers, live permission and explicit routing saves. Existing purchased-line,
customer-isolation, portal, setup and live-overview tests remain applicable.
