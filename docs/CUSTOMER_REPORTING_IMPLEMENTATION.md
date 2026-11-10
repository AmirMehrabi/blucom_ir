# Customer reporting implementation report

Date: 2026-11-10. Status: implementation in progress. The overview/shared filters are deployed; later dated progress entries distinguish local implementation from verified production rollout.

## Recommendation

Extend Blucom's existing call history and dashboard into one customer reporting area at `my.blucom.ir/reports`. Use five tabs: **نمای کلی**, **تماس‌ها**, **داخلی‌ها**, **اپراتورها**, and **ساعات پرترافیک**. Reuse the current Laravel, Blade, MySQL and FreeSWITCH integration. A separate analytics platform is unnecessary for the first release.

Build trustworthy call facts first, then operator attendance and performance. The requested reporting work explicitly expands the earlier MVP scope for reporting/event ingestion; it does not require a SIP redesign, billing engine, new queues, or recording infrastructure.

Two product definitions must remain separate throughout the module:

- A call answered by an IVR or voicemail is not necessarily answered by an operator.
- Portal login, SIP device registration, operator working status, and actual queue availability are different events.

## Existing application: what can be reused

This assessment is based on repository code, not verification of the running FreeSWITCH installation or deployed production data.

| Existing code | Current capability | Work required |
| --- | --- | --- |
| `app/Models/CallRecord.php` and call-record migrations | Tenant, DID, extension, queue and IVR links; direction; start/answer/end; duration; billable seconds; unique FreeSWITCH UUID | Add logical-call correlation, explicit customer outcomes, reliable human-answer and conversation facts |
| `app/Services/CallRecordImporter.php` | A-leg CSV ingestion; tenant attribution; DID assignment-history checks; queue/IVR outcome handling; duplicate prevention | Preserve ownership checks; version source formats; improve answer/agent attribution and error classification |
| `app/Console/Commands/ImportCallRecords.php` | Incremental cursor, import lock, replay command | Verify actual invocation and rotation behavior; add freshness, completeness and rejected-record monitoring |
| `app/Services/CallSummaryService.php` | Actual call totals, incoming/outgoing counts, answer rates, talk totals and time series | Shared metric definitions, extra outcomes, arbitrary report periods and extension/operator aggregation |
| `app/Services/CallReportFilterService.php` | Tenant, DID, team, direction and status filtering | Extension/operator/counterparty filters, date-window normalization, mandatory customer tenant scope |
| `app/Http/Controllers/CallHistoryController.php` | `/calls`, details, date ranges, single-day hour filter, scoped access | Reports entry point, shared filter contract, number search, exports and drill-down consistency |
| `app/Http/Controllers/QueueAvailabilityController.php` | Operator-linked extension can switch between Available and On Break | Historical events and work sessions; requested versus applied queue state |
| `app/Models/User.php` | User-to-extension relationship and permission model | Historical assignment intervals for operator attribution |
| `tests/Feature/CallRecordsTest.php` | Existing tenant-scope, import and queue scenarios | Extend fixtures for every new outcome and metric |

Current limitations matter: busy outcomes are folded into the broad failed category; inbound extension attribution can fall back to the currently configured route; `billable_seconds` feeds talk totals but may include automated answer time; and current availability updates overwrite state without an attendance history. These fields alone cannot support accurate operator evaluations.

`routes/console.php` does not schedule `voip:import-cdr`. An external timer may exist; inspect deployment scheduling before adding another runner. This report does not assume imports are absent in production.

## Customer journey

### Setup once

The customer owner opens **گزارش‌ها** and immediately sees existing call history. Call reports require no new setup wizard. A small setup card appears only for operator reports: **برای گزارش عملکرد، اپراتورها را به داخلی متصل کنید**.

Reuse existing users and extensions. Default to one identified operator per extension during a work session; prohibit overlapping assignments for this simple mode. Shared extensions remain available as extension reports, but person-level performance is marked unassigned unless a historical session identifies the operator. Preserve assignment history when staff or extensions change.

### Daily owner or supervisor workflow

1. Open **گزارش‌ها → نمای کلی**. Default to today in Asia/Tehran, all owned DIDs, all extensions. Show the selected period and data freshness beside the title.
2. See six primary cards: total calls, incoming, outgoing attempts, operator-answered incoming, unanswered incoming, and conversation time. Put busy and technical failures in the outcome breakdown below. Counts by direction and counts by outcome describe different dimensions and are not added together.
3. Click **بی‌پاسخ** to open the exact contributing calls. Preserve period, DID, extension and other filters.
4. Open a call to see its timeline, final outcome, attempted/answering extensions, wait and conversation duration. Show recordings only with existing recording permissions.
5. Click a phone number to open all matching calls within the current period. Offer an explicit **تمام سابقه موجود** action to expand to retained history.
6. Choose **خروجی CSV** to download the same result and filter definition. Queue large exports and show their status inside Blucom.

### Operator workflow

Provide explicit actions **شروع کار**, **استراحت**, **ادامه کار**, **پایان کار**. Show current work status and its start time. Reuse the queue availability control behind these actions, with separate requested and confirmed states.

Closing a browser must not silently record an accurate end-of-work time. Leave an open session visible, flag unusually long sessions, and let an authorized supervisor close/correct it with a reason and audit trail. Handle duplicate clicks and multiple tabs idempotently. Portal logout is a separate security event, not automatically shift completion.

### Report navigation and shared controls

| Tab | Main question | Main output |
| --- | --- | --- |
| نمای کلی | What happened in my business? | Counts, outcome mix, conversation totals and comparison |
| تماس‌ها | What happened to this call or phone number? | Searchable call list and call timeline |
| داخلی‌ها | How much did each extension handle? | Counts, outcomes and conversation duration by extension |
| اپراتورها | Who worked, when, and what did they handle? | Work-session history and explainable performance metrics |
| ساعات پرترافیک | When do we receive and miss calls? | Hourly chart and weekday/hour heatmap |

Common filters: today, yesterday, last 7 days, last 30 days, custom dates, DID, extension, operator, direction, outcome and phone number. Keep date and phone search visible; place advanced controls behind **فیلترهای بیشتر**. Put filters in the URL so refresh, back navigation and drill-down work predictably. A shared URL still requires authorization.

Use Persian RTL labels and Jalali date entry/display with conversion at the boundary. Display phone numbers and durations left-to-right. Empty states distinguish no calls, no matching results, missing operator setup, unavailable historical detail, and delayed imports. Never render unavailable data as a measured zero.

## Coverage of every requested report

| Requested report | Implementation and definition |
| --- | --- |
| گزارش ورود و خروج اپراتور | Work-session start/end, break intervals, total working/available time and corrections. Separate optional portal login/logout audit from attendance; label the distinction explicitly. |
| گزارش کل تماس‌ها | One row per logical external call attempt, not one row per FreeSWITCH leg. Internal-only calls are excluded from this first release and explicitly identified as outside report coverage. |
| تعداد تماس‌های دریافتی | Logical external inbound attempts to an owned DID, including unanswered calls. |
| تعداد تماس‌های برقرارشده | Show **تماس خروجی** for outbound attempts and **تماس خروجی متصل‌شده** for successful conversations; avoid using this ambiguous phrase for both. |
| تماس‌های پاسخ داده‌شده | Inbound calls connected to an operator; expose system/IVR and voicemail answers separately. Outbound connected calls have their own measure. |
| تعداد تماس‌های ازدست‌رفته | Customer-facing unanswered inbound group, with no-answer and caller-abandoned subtypes. Busy, unreachable and technical failures remain distinct and visible. |
| تماس‌های مشغول | Final logical-call busy outcome, plus separate busy attempts in extension details. An initial busy leg followed by a successful fallback is an answered call overall. |
| تماس‌های ناموفق به دلیل خطا یا عدم دسترسی | Separate unreachable and technical-error outcomes with readable reason and restricted diagnostic detail. |
| گزارش بر اساس داخلی | Extension summary and drill-down, using actual attempts and answering participation rather than current route configuration. |
| تعداد و مدت تماس هر داخلی | Distinct calls attempted, answered, inbound/outbound, conversation total and average; separate ring/wait time. |
| تمام تماس‌های یک شماره مشخص | Canonical counterparty search across source and destination, scoped to the tenant. Owned DID selection remains a separate filter. |
| گزارش بر اساس تاریخ | Local date range, daily buckets, same-filter comparison to an equivalent prior period. |
| گزارش بر اساس ساعت | Single hour or time-of-day window across selected local dates, including overnight windows. |
| میزان تماس در ساعات مختلف | Local-hour counts, incoming/outgoing split, unanswered overlay and weekday/hour heatmap; include zero-activity buckets. |
| گزارش مدت مکالمه | Conversation total/mean/median and duration buckets, with explicit exclusion of ringing, IVR and queue wait where measurable. |
| ارزیابی عملکرد اپراتورها | Answered calls, offered attempts, answer rate, conversation time, average conversation duration, response delay and work availability; no unexplained composite score. |

## Metric contract

Use one versioned metric service for cards, charts, lists and exports.

- **Counting unit:** one logical external call attempt. Queue fan-out, transfer legs and the two legs of a two-party call do not create extra top-level calls. A new dial attempt after a completed call is a new call.
- **Period:** include calls by `started_at` in a half-open UTC interval derived from local dates: `[start, end)`. Counts use this rule consistently. Session and staffing-duration reports clip intervals to the selected period. Explicitly label call-duration totals as full durations of calls started in the period; do not silently switch to interval-clipped duration.
- **Outcome:** one final result per logical call: `answered`, `no_answer`, `abandoned`, `busy`, `unreachable`, `failed`, `voicemail`, `system_only`, or `unknown`. Keep coarse legacy status for compatibility and do not infer historical detail without evidence. Expose **بی‌پاسخ** as no_answer + abandoned on inbound calls.
- **Answer evidence:** track `system_answered_at` separately from `human_answered_at` and verified conversation intervals. For an external outbound call, bridge/answer evidence indicates connection; it cannot prove the remote party was a human rather than an answering machine. Label it connected, not human-answered.
- **Inbound answer rate:** operator-answered inbound / all inbound attempts under the current filters. Show denominator and count. A queue-specific rate uses that queue's defined population and has its own label.
- **Operator answer rate:** answered eligible offers / distinct eligible offers to that operator. Mark peer-cancelled ring-all attempts separately and exclude them from this rate; retain them as informational attempts. Do not blame every ringing operator when a colleague answers.
- **Conversation duration:** union of verified conversation intervals for the call. Per-operator time uses that operator's intervals. Distinguish aggregate participant time from unique call time if participants overlap. Do not reuse billing seconds as human conversation time without validating the source.
- **Average conversation:** conversation seconds / answered calls with valid conversation evidence. Zero denominator returns unavailable. A genuinely connected call rounded to zero seconds still counts as connected.
- **Response delay:** measured from an offer to that operator's answer; queue wait starts at queue entry. Do not mix these two measures.
- **Availability:** sum of confirmed available intervals intersected with the report period. Report work-session duration and breaks separately. No occupancy percentage until busy/available/wrap-up intervals and concurrent-call handling are dependable.
- **Comparison:** compare equal elapsed windows for an incomplete current day. Show percentage change as unavailable when the prior denominator is zero.

Preserve raw cause and bridge outcome. Establish the mapping using installed-version documentation plus sanitized fixtures from the actual server. For example, a busy cause is evidence for busy, but an A-leg normal clearing cause alone is not proof of an operator answer. Apply final-result precedence over intermediate failures and record the mapping version.

## Data collection and architecture

Keep FreeSWITCH responsible for SIP, RTP and call execution. Keep XML-CURL responsible for dynamic configuration lookup; it is not a call reporting feed. Reporting reads asynchronously collected facts and never blocks call routing.

Initial path:

```text
FreeSWITCH existing CDR spool
  → existing incremental importer
  → validated call/ownership facts
  → normalized logical call + participation facts
  → MySQL report queries
  → customer reports and queued exports

Operator action → Laravel work-session/state audit
  → existing queue sync → confirmed or failed application state
```

Start with the existing CSV pipeline. Inspect loaded modules, active CSV template, available bridge/queue fields, scheduled runners and actual sample calls before proposing a source change. Append/version fields compatibly rather than changing positional columns silently. Inspect extension, queue, IVR, fallback and two-party paths independently.

If existing CDR fields cannot reliably identify answering operators and intervals, pilot a richer CDR source. FreeSWITCH documents `mod_json_cdr` as producing per-leg records at hangup with HTTP/disk delivery; this requires explicit leg correlation, not counting every payload as a customer call. See the [official module reference](https://developer.signalwire.com/freeswitch/module-reference/event-handlers/mod_json_cdr/). Module presence and behavior on this installation remain to be checked.

If per-offer/transfer detail requires events, extend the existing outbound event worker where suitable or add a narrowly scoped private collector. FreeSWITCH documents finalized hangup events for call tracking in its [events catalog](https://developer.signalwire.com/freeswitch/programming/events-catalog/). Queue agent lifecycle support is documented in the [queue reference](https://developer.signalwire.com/freeswitch/applications/call-queues/). Validate exact event payloads locally; this report does not assert particular undocumented headers.

Do not make a live event connection the only durable call ledger. Persist incoming evidence before acknowledging it, replay from disk after outages, deduplicate source records, tolerate late/out-of-order events, and reconcile terminal call facts against CDRs. Preserve the CSV fallback during a richer-source pilot. Ensure both sources converge on the same logical call instead of creating duplicates.

Any new ingestion endpoint is internal, authenticated with a mechanism supported by the selected sender, network-restricted and size-limited. Server-issued call identifiers and validated historical database relationships establish ownership; arbitrary SIP headers do not. Quarantine unattributable records for restricted operational review rather than exposing them to a guessed tenant. Allowlist stored payload fields because raw CDR/event data may contain credentials or sensitive SIP metadata.

### Proposed schema additions

Adapt names to existing conventions and add fields incrementally.

| Entity | Essential facts and constraints |
| --- | --- |
| Existing `call_records` | Remains the customer-facing logical call; add stable correlation ID, final outcome, answer kind, system/human answer times, verified conversation seconds, normalized counterparty, metric version and completeness state |
| `call_legs` | Tenant, call, switch/source ID, leg UUID, parent/bridge correlation, leg role, actual extension, timestamps and raw outcome; unique switch + leg UUID |
| `call_participations` | Tenant, call, operator/extension snapshots, offered/answered/left times, talk intervals or seconds, and offer result; stable unique source participation key |
| `operator_extension_assignments` | Tenant, operator, extension, valid-from/to; preserve history and prevent overlapping active assignments in simple mode |
| `operator_work_sessions` | Tenant, operator, start/end, opening/closing actor and correction metadata; at most one open session per operator, enforced transactionally |
| `operator_state_events` | Append-only requested state, applied state, effective/received times, actor, session and idempotency key; include break and sync-failure evidence |
| `report_exports` | Tenant, requester, authorized scope, normalized filters, snapshot cutoff, metric version, status, private storage path and expiry |
| Ingestion receipts/checkpoints | Source identity, deduplication key, schema version, processing state, safe rejection category and replay position |

Use UTC timestamps and server-side Asia/Tehran display conversion. Keep the existing `NumberNormalizer` as the normalization authority after validating its behavior. Handle Persian/Arabic digits at the input boundary. Phone identifiers are strings; never lose leading zeroes through numeric conversion. Do not guess malformed international numbers.

Preserve tenant ownership at call time, including after DID resale or staff reassignment. The importer already has assignment-history protections: extend them rather than replacing them with current-owner lookup. Preserve display snapshots for deleted/renamed resources. Show unknown historical operator as unknown; a current user-extension link is not historical evidence.

## Laravel implementation boundaries

Reuse existing services and add focused reporting services rather than duplicating aggregation in controllers:

- `ReportFilterData`: validated immutable shared filter/date/snapshot contract.
- `CallOutcomeClassifier`: versioned source-evidence-to-outcome rules.
- `CallFactProjector`: idempotent correlation and logical-call/participation updates.
- `CallMetricsService`: overview, extension, hourly and duration aggregations; existing `CallSummaryService` delegates to shared definitions.
- `OperatorSessionService` and `OperatorPerformanceService`: session transitions, audit and evidence-based performance.
- `ReportExportJob`: uses the same scoped query and definitions as the UI.

Proposed customer routes: `GET /reports`, `/reports/calls`, `/reports/extensions`, `/reports/operators`, `/reports/traffic`, `POST /reports/exports`, and authorized export status/download routes. Keep existing `/calls` links working. Use existing detail routes where possible.

Provide explicit permissions such as `reports.view`, `reports.operators.view`, `reports.export`, and `operators.sessions.manage`, alongside existing call/recording permissions. Operators can see their own work sessions; supervisors see tenant-wide performance only when permitted. Preserve existing access during permission migration with an explicit role mapping. Do not automatically grant all staff tenant-wide personal performance visibility.

Customer queries require a resolved tenant. Avoid the current optional-null-tenant pattern in new customer services: missing tenant must fail closed. Put admin cross-tenant operational views on `admin.blucom.ir` with separate authorization. Scope filter options, charts, details, exports, job execution and downloads, not just list queries. Validate extension/operator/DID/team ownership independently.

## Reliability, exports and performance

Start with indexed MySQL aggregations. Existing tenant/time and tenant/direction/time indexes are useful; evaluate `(tenant_id, sip_extension_id, started_at)` and source/destination normalized-number indexes using actual query plans. Use stable `(started_at, id)` ordering and cursor pagination for large histories. Avoid loading all calls into PHP for aggregation.

Only introduce hourly/daily rollups when measured query costs justify them. Rollups must be rebuildable, metric-versioned and corrected for late arrivals; include tenant, local-time policy and filter dimensions. Do not sum distinct-call counts across operator participation rows to obtain the tenant total. Cache keys include tenant, permission scope, filters and data version.

The original plan proposed CSV first; the customer subsequently requested native Excel, so the current implementation delivers XLSX first (see dated progress below). CSV remains optional follow-up work. Use UTF-8, explicit phone strings, spreadsheet formula-injection protection and localized column headings. No spreadsheet library decision is required for the initial CSV release. Store exports privately, recheck current permissions and tenant ownership on download, expire files and audit exports. Apply permissions again in queued jobs. Preserve filters, timezone, data cutoff and definition version so an export is reproducible.

Recommended initial service targets, to validate under realistic load: completed calls normally visible within two minutes; common 30-day report under two seconds at p95 for the agreed tenant volume; large exports run asynchronously. Track last successfully processed call time and pipeline lag per source. An import cursor update alone is not proof all recent calls were ingested. Show a customer delay indicator when data is stale; give ingestion failures and quarantined records to operations.

Agree retention separately for call facts, diagnostic evidence, operator attendance and export files. Enforce retention in queries/backfills/rollups, disclose the available historical range, and preserve existing recording permissions and expiry rules. This report proposes no new legal retention period.

## Delivery plan and acceptance gates

Estimates are planning ranges for one engineer familiar with the codebase, excluding unpredictable provider/device troubleshooting and stakeholder review.

| Phase | Deliverables | Acceptance gate | Estimate |
| --- | --- | --- | --- |
| 0: verify evidence and definitions | Inspect actual collection/configuration; sanitized fixtures; agree answer, missed, outgoing and attendance definitions | Inbound, outbound, queue, IVR, fallback and two-party examples can be reconciled to facts; unsupported history identified | 2–3 engineering days |
| 1: dependable call reports | Compatible outcome/fact changes; common filters; overview/calls/extensions/traffic/duration views; CSV; freshness | Totals, charts, drill-down and exports agree; busy/unreachable visible; isolation tests pass | 6–9 days |
| 2: operator sessions | Historical assignment, explicit start/break/end, applied queue-state audit and corrections | Concurrent/duplicate actions safe; cross-midnight duration correct; no false attendance inferred from browser closure | 3–5 days |
| 3: operator performance | Verified answering/offer/transfer participation; operator metrics; richer source pilot only if necessary | IVR-only call is not an operator answer; ring-all peer cancellation is not an operator failure; transferred time attributed correctly | 5–8 days |
| 4: controlled pilot | Load checks, replay/reconciliation, role review, customer walkthrough and operational runbook | Reporting outage does not affect calls; representative customers complete journeys; baseline SIP acceptance passes | 2–3 days |

Indicative full delivery: **18–28 engineering days**, revised after phase 0. Release call reports before waiting for complete operator analytics, but visibly label any missing historical fields. Do not publish estimated operator metrics as measured performance.

Use feature flags and a small tenant pilot. Backfill in restartable batches using stable identifiers and historical ownership. Because the existing importer uses insert-or-ignore, replay alone will not enrich or reclassify existing rows: implement a controlled versioned projection/backfill that updates reporting facts without overwriting authoritative ownership. Only backfill conversation or operator detail when retained evidence supports it. Label the coverage start date for new session/participation data.

Before any FreeSWITCH change, inspect and back up relevant infrastructure configuration, make one controlled change, validate it, reload only the required component, and verify registration, inbound and outbound calls. Preserve profiles, gateways, context isolation and current routing. Reporting CRUD needs no restart or manual customer XML changes. Roll back collection changes independently of customer routing.

### Required test cases

- Tenant A cannot enumerate or export tenant B's calls, filter options, operator sessions, phone history or recordings; forged IDs and stale downloads fail closed.
- One incoming call ringing several extensions counts once; per-extension attempts remain available. A transfer retains one logical call and correctly allocates conversation intervals.
- Queue abandonment, no answer, busy, unreachable, technical error, voicemail, IVR-only and fallback success have deterministic distinct outcomes. A recovered intermediate error is not a final failure.
- A two-party call contributes one logical attempt; the operator leg answering alone does not mean the external customer was connected.
- Duplicate imports/events and dual-source replay produce unchanged totals; late events can safely correct provisional facts. Lost-event recovery works from durable evidence.
- Malformed or unattributable records are isolated; DID reassignment and deleted resources do not expose old calls to a new tenant.
- Midnight, month boundaries, local hour windows, overnight filters and Jalali conversion are correct; zero-volume buckets render; short and overlapping conversations do not inflate duration.
- Operator assignment changes do not rewrite history. Concurrent session starts, break transitions, abandoned browser sessions and audited corrections behave predictably.
- For each filter set, card count equals drill-down count and exported row count at the same snapshot. Outcome totals reconcile to the total, including unknown/system-only outcomes. Rates show their denominator.
- Reports stay readable during ingestion delay and continue to respect permissions after a queued export requester loses access.

## First implementation batch

Start with phase 0 and the smallest usable call-report slice: shared filter contract, versioned outcome classification, explicit busy/unreachable categories, number/extension search, overview-to-calls drill-down and scoped CSV export. Reuse the current UI and importer. Operator sessions and participation evidence follow as separately testable changes.

This sequence gives customers useful reporting early while ensuring that attendance and performance numbers become available only when the underlying evidence is reliable.

## Implementation progress — 2026-11-10

- [x] Added extension filtering to existing call history and shared query service, including tenant ownership validation and scoped selection options.
- [x] Added exact phone search across source/destination with canonical, country-code and international-prefix variants; input is bounded and validated. Broader historical normalization remains open.
- [x] Added visible Persian phone/extension controls; filters persist through existing pagination and reset behavior.
- [x] Made call-list ordering deterministic using start time and record ID.
- [x] Existing call-record regression suite passes: 6 tests, 32 assertions. Targeted new coverage remains required.
- [ ] Remaining report views, outcome evidence/classification, export pipeline, operator sessions/assignments, performance attribution, richer collection verification, UX walkthrough and rollout gates remain unimplemented. This progress entry does not mark the full module complete.

### Next-step delivery — reports overview and shared filters

- [x] Added customer `/reports` entry point with existing `calls.view` permission; tenant membership is mandatory and admin access fails closed.
- [x] Added a Persian RTL overview with six clickable count cards, recent calls, a helpful empty state and explicit reporting-data limitations. Conflicting direction/status cards are hidden so their drill-down cannot silently replace an active filter.
- [x] Added overview navigation in the customer sidebar and two-way overview/call-history navigation preserving filters.
- [x] Extracted common filter controls, validation and Tehran date-window application so overview and call history use the same query definitions. Overview defaults to today; call history retains its existing 30-day default. Date ends are exclusive.
- [x] Added regression coverage for canonical phone variants on both call sides, extension filtering and foreign ownership rejection, scoped options, overview/count agreement, local midnight boundaries, empty state, malformed filters and permission denial.
- [x] Verified the reporting and existing CDR suites together: 11 tests, 54 assertions. Targeted PHP formatting passed.
- [ ] Jalali date controls, browser visual walkthrough, source freshness instrumentation, outcome/agent evidence, exports and remaining report tabs still require separate implementation. Current date inputs explicitly use the existing Gregorian browser date controls; the overview does not claim human-answer metrics or measured pipeline freshness.

### Outcome classification and Excel delivery — 2026-11-10

This entry supersedes the earlier pending items for outcome classification and Excel export. The changes are implemented locally; deployment, processor provisioning and a live customer browser walkthrough are still release gates.

- [x] Inspected the installed CSV template without modifying FreeSWITCH: the Blucom template has 31 fields, including originate disposition, queue cause and fallback evidence. Aggregate inspection of the latest 300 rows found 31 fields on every row, with `NO_ROUTE_DESTINATION` and no queue/originate result. No phone numbers, credentials or raw SIP payloads were copied into documentation.
- [x] Added a versioned outcome classifier for connected, no answer, caller abandonment, busy, unreachable, technical failure, system-only answer and unknown. Positive bridge/queue-connection evidence takes precedence over an earlier failure. Unrecognized causes, including rejection without a more specific supported category, remain unknown rather than being mislabeled technical failures.
- [x] Kept legacy `status` unchanged for compatibility. Added nullable outcome, answer evidence, classifier version and final originate disposition fields. SQL filters and model labels share the same conservative legacy rules: an A-leg answer alone does not establish a human answer or successful bridge.
- [x] Applied classification to CSV ingestion and authoritative two-party session finalization. Replay can enrich previously unclassified reporting fields within the already validated tenant; it cannot rewrite ownership, phone numbers or other call facts. Existing versioned classifications are preserved.
- [x] Added final-outcome filters, count breakdowns and consistent Persian badges to overview, call list and details. Detail pages explain answer evidence; billable/network-answer duration is explicitly separated from a claim of human conversation duration. Existing coarse status filters/cards remain available with legacy labeling.
- [x] Added background native `.xlsx` generation from the exact shared filter query, with a request-time cutoff and maximum call ID. Relative date ranges are evaluated at request time, so a job crossing midnight does not change the requested day. Later call-fact corrections may be reflected and are disclosed in the workbook.
- [x] Added a Persian RTL workbook with call rows and a summary/filter sheet, frozen headings, blue header styling and column filters. Summary outcome counts are accumulated from the actual exported rows. Phone numbers and formula-like input are explicit literal text cells; durations/counts are numeric. The writer streams worksheet XML and reads calls in batches of 500 rather than loading the entire report into memory.
- [x] Added a separate `reports.export` permission, customer defaults and assignable labels. A compatibility migration grants it to existing call-history viewers. Both `calls.view` and `reports.export` are required at request, generation and download time. Admin exports are excluded from this customer feature.
- [x] Added private export storage and a requester-owned, tenant-scoped download endpoint. Customer and legacy-user identities are separated even when their numeric IDs match. Downloads expire 24 hours after request, use no-store headers, and are re-authorized instead of relying on publicly accessible file URLs.
- [x] Added “ساخت خروجی Excel” from overview and call history and “خروجی‌های من” with background status polling, manual refresh, download, retry and delay messaging. Duplicate submissions are disabled in the browser; server-side throttling and a maximum of three active requests per principal remain authoritative.
- [x] Added `reports:process-exports`, minute scheduling, optional dedicated systemd timer/service, expiry cleanup, atomic job claims, stale-job failure/partial-file cleanup and readable failure reasons. Oversized reports fail with a request to narrow the range rather than silently truncating Excel's row limit.
- [x] Added tests for classification precedence, IVR/system-only evidence, unknown historical answers, legacy SQL/model agreement, replay ownership preservation, filtered export isolation, XLSX structure and literal cells, cutoff behavior, permission revocation, real customer guard isolation, expiry cleanup, input validation and pending-request limits.
- [x] Opened and saved a synthetic generated workbook using LibreOffice. Leading-zero phone values and formula-like strings remained text; the saved workbook contained no formula cells.
- [x] Full PHP regression suite passed: 454 passed, 15 skipped, 3,252 assertions (469 tests). PHP formatting and diff whitespace checks passed. Targeted outcome/export suites were repeated after the final export-summary adjustment: 11 tests, 97 assertions passed, including cross-midnight generation and stale-file cleanup.
- [x] Production frontend asset build passed in an isolated temporary directory on the server (the active release was not changed). Latest JavaScript also passed Node syntax checking. Added button recovery after browser back navigation and no-store status polling; browser UX acceptance remains open.
- [ ] Explicit voicemail identification, person-level operator attribution and verified human conversation duration still require richer source evidence. No voicemail or human answer is inferred from the current route configuration or A-leg answer stamp.
- [ ] Live SIP call acceptance and authenticated browser UX verification remain open. This change does not modify profiles, gateways, customer dialplan XML or FreeSWITCH configuration.

#### Outcome evidence policy

`USER_BUSY` maps to busy; `NO_ANSWER`, `NO_USER_RESPONSE` and `ALLOTTED_TIMEOUT` map to no answer; `ORIGINATOR_CANCEL` maps to caller abandonment. Unregistered/absent/unallocated/unroutable destinations map to unreachable. Explicit network, gateway, congestion and protocol failures map to technical failure. A successful originate, confirmed queue answer or authoritative two-party bridge establishes connected status; it does not prove the remote party was human. Unknown or contradictory historical evidence remains unknown. A queue `cancel` alone cannot distinguish abandonment from timeout and is not automatically called abandonment.

Reviewed primary references: [FreeSWITCH cause table](https://developer.signalwire.com/freeswitch/reference/hangup-causes/) and [call setup failures and bridge outcomes](https://developer.signalwire.com/freeswitch/troubleshooting/call-setup/). The observed installed template is the source-field contract; broader live call scenarios remain to be verified.

#### Deployment and operations

Apply the three `2026_11_10_*` reporting migrations through the normal release process. No bulk ownership migration or switch reload is needed. Backward-compatible nullable columns let older application code continue operating during deployment. Preserve the schema if rolling application code back.

The export writer requires PHP `zip`, `xmlwriter` and `mbstring` extensions; the GitHub CI setup explicitly enables them. There is no added spreadsheet package or external service. Files live under private shared `storage/app/report-exports`, outside `public`; the application/processor user must be able to create directories there.

**A running processor is required before exposing exports to customers.** Choose either the existing Laravel scheduler (`php artisan schedule:run` every minute) or install the included dedicated timer using the normal infrastructure deployment procedure:

```sh
sudo install -m 0644 deploy/blucom-report-exports.service /etc/systemd/system/
sudo install -m 0644 deploy/blucom-report-exports.timer /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now blucom-report-exports.timer
```

The dedicated service runs as `www-data`, uses the active release and needs no queue worker or public ingestion endpoint. A manual smoke test is `php artisan reports:process-exports --limit=1` under the application user, followed by an authorized download. Verify the timer's journal, a ready export, an expired-file cleanup and a denied foreign-user download before calling the rollout complete. Do not install the timer and assume that it proves the customer download journey.

Processing never runs on the call-routing path. An export failure does not affect SIP calls. Export request/completion/failure/download logs contain identifiers, counts and safe error classes, not phone numbers, passwords or raw payloads. Retention of underlying call records is unchanged.

### Production Excel activation — 2026-11-10

- [x] Deployed commit `0f484144a978b0d9f0fa8d5aadfa592e4856e6c4` through the normal tested release pipeline; verified the active release and all reporting migrations completed.
- [x] Provisioned private export storage for `www-data`, installed and enabled `blucom-report-exports.timer`, and ran the processor service successfully (`Result=success`, exit 0).
- [x] Ran a synthetic XLSX write/open smoke test as the application user in private export storage and removed the test file. Health returned HTTP 200; unauthenticated export access redirected to login (302).
- [ ] A signed-in customer browser request/download acceptance walkthrough remains open. No customer account was impersonated and no real customer export was created for this smoke test.

### Production reports incident — 2026-11-10

- [x] Diagnosed `/reports` HTTP 500 as MySQL error 1055 under `ONLY_FULL_GROUP_BY`: grouping the repeated calculated outcome expression was rejected. SQLite regression tests did not expose the production-engine incompatibility.
- [x] Changed both overview and Excel export outcome aggregation to group by the selected `result` alias. Preserved strict SQL mode and tenant filters.
- [x] Backed up both affected production source files under shared backups and applied the minimal fix to the active release. No switch configuration or database mode was changed.
- [x] Executed the corrected query on production MySQL successfully with `ONLY_FULL_GROUP_BY` enabled. An authenticated production Laravel HTTP-kernel request to `/reports` returned 200.
- [x] Reporting, Excel and classification regression suites passed: 16 tests, 119 assertions. Source fix is maintained in the repository so future deployments retain it.
