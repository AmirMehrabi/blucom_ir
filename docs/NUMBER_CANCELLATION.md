# Purchased number cancellation

Admin opens **Manage number → Cancel service and release number** (لغو سرویس و آزادسازی شماره). This action applies to commerce purchases with a current assignment; unpaid holds still use Reservations.

1. Review the owning tenant, number, order, paid invoice, and routing. Enter a reason, choose `no_refund` or `refund_pending`, and confirm loss of customer access.
2. Cancellation locks the tenant, DID, assignment, order and subscription. It closes the assignment, cancels the subscription and records `service_cancelled` on the order. It removes the DID's inbound/outbound routes and recording configuration, clears its customer label and owner, disables call capabilities, and places it in `quarantined` inventory. The closed assignment pointer remains for release review.
3. From the cancellation page, or Stock → Prepare → Review cancellation, confirm readiness to return the number and enter a release reason. The number becomes `available` with no assignment or published offer.
4. Prepare settings, enable capabilities, record a fresh technical review, and explicitly publish a new offer before resale.

`service.cancelled` and `service.returned_to_stock` audit events retain admin, reason, resource, timestamps and metadata. The assignment retains cancellation reason, refund decision, release time and stock-return time. No bank refund is issued or claimed. Refund processing is separate; `refund_pending` is a recorded follow-up decision, not an automated refund job.

Issued invoices, settled payments, subscription snapshots, call records, recordings, extensions and customer accounts are retained. Existing calls continue to completion; database changes affect subsequent XML-CURL lookups. No SIP infrastructure configuration changes, reloads, or restarts are used.

Requests carry the expected assignment and inventory revision. Stale transitions fail safely. Repeated completed actions are no-ops bound to the historical assignment, including after resale. Allocation retry cannot reclaim an assignment that was released.

Deployment requires migration `2026_10_08_000010_add_number_assignment_cancellation.php` through the normal release process, plus normal route/view cache refresh. Rollback refuses to discard recorded cancellation fields. Automated tests use isolated SQLite and synthetic payment fixtures; live SIP acceptance was not performed for this application-only change.
