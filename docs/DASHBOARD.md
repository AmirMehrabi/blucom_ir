# Dashboard statistics and actions

The dashboard period is a per-user database preference (`users.dashboard_period`).
Authenticated users with `dashboard.view` change it through a CSRF-protected POST.
The default is daily; invalid values are rejected. GET filters do not change the
preference. The selection follows the account across browsers and devices.

- Daily: today from midnight in `voip.display_timezone` (Asia/Tehran by default).
- Weekly: today and the preceding six days.
- Monthly: today and the preceding 29 days, explicitly labeled “۳۰ روز اخیر”.

These are rolling day windows, not calendar weeks or calendar months. The header
shows exact dates and the timezone. Current figures stop at now. Comparisons use
the preceding equally sized window, truncated at the same time of day so an
incomplete day is not compared against a complete one. Rate changes are percentage
points; count changes are percentages. Missing comparison data is not invented.

Incoming answer rate is incoming answered / all incoming calls. Outgoing answer
rate is computed separately. Both reflect the final business status stored by the
CDR importer, not a live channel or a claim about speech quality. Empty rates are
shown as a dash. Talk time is the sum of stored billable seconds, displayed as
HH:MM:SS. Counts and charts use imported call records; the last importer cursor
update is displayed as the last history check, not the latest call's timestamp.

Customer queries enforce tenant ownership. Admin queries cover the platform.
Number and team filters apply to metrics, previous periods, charts, recent calls,
and card destinations. Foreign filter IDs return 404. Card links carry explicit
local dates; chart links carry an exact day or hour. Call history offers today,
7 days, 30 days, all, or custom dates, with the same tenant/number/team filters.
Call detail pages enforce `calls.view` and tenant ownership.

Recent calls use a desktop table and mobile cards. Playback and download links
respect their separate recording permissions; expired recordings are not offered
for playback. Existing private recording endpoints continue to enforce audio
access. Call details and recent calls eagerly load only permitted recording data.

Attention alerts are current service snapshots, independent of the statistical
period and report filters. They cover pending admin provider requests, enabled
inbound lines without an enabled owned destination, unexpired failed recordings,
recordings delayed more than three minutes after an associated completed CDR,
and storage at or above 80% including reservations. Live recordings are excluded
from delay alerts. Recording and configuration links respect user permissions.

Configuration counts say “enabled in settings”; they do not imply live SIP
registration or gateway connectivity. No FreeSWITCH changes are required.

Metric and chart calculations run in database aggregates, without loading all
calls into PHP. Time bucket boundaries are converted to UTC in application code;
no database timezone tables or vendor-specific date functions are required.

Verification: automated coverage includes saved preferences and permission gates,
Tehran midnight boundaries, daily/weekly/monthly windows, incoming rate isolation,
same-time comparisons, tenant filters and details, alert behavior, and independent
recording playback/download permissions. Browser visual inspection was unavailable
in the implementation session. A canvas preview uses synthetic data only.
