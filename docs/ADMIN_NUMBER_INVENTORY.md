# Admin number inventory

The admin sidebar separates **موجودی شماره‌ها** (`/admin/inventory`) from customer line routing. Existing `?scope=stock` bookmarks redirect to inventory. This release adds nullable `sip_numbers.inventory_sale_settings` JSON; it does not alter existing ownership or routes.

## Daily journeys

- Add one number, paste one number per line, or upload CSV (up to 500 rows / 1 MiB). CSV columns are `number,label`; header and label are optional. Preview normalizes numbers to the existing E.164 policy, identifies existing/in-batch duplicates, and leaves invalid rows unselected. Persian/Arabic digits and local formats use the existing normalizer. The final import rechecks uniqueness.
- Apply shared provider, explicit destination prefixes, label, plan and price. No broad destination permissions are supplied automatically. Save unpublished, or save and publish after supplying actual technical-review evidence. Publication prerequisites are unchanged. Each imported row is atomic: failed publication does not leave a partly created number. Successful rows cannot be imported again from the same preview; remaining rows can be retried or imported unpublished.
- Filter by sale state, search, provider, customer or published price; export the filtered list as CSV. Prices always show their stored billing period. CSV text cells that could become spreadsheet formulas are escaped.
- Select numbers on the current page, choose publish / withdraw / hold / restore / price / label, review the per-number result, then confirm. Eligible rows succeed independently; failures remain visible and selected. Stale revisions are rejected, rather than overwriting another administrator's work. Preview is read-only, and apply repeats domain authorization/readiness checks.
- Withdrawing preserves pricing and valid technical review for republishing. New publication creates an immutable offer. Holding a published number atomically withdraws and disables inventory; restoring returns it to draft. Reservations, assigned numbers and quarantined cancellations remain protected by the existing workflows. These actions affect new sales, not customer service.
- The number page shows current state, actionable readiness issues, sale settings, technical settings, review evidence and audit/offer history. Label/price changes retain technical approval; operational changes invalidate it. Saved prices from a different billing model cannot silently be reused under the new billing model.

Publishing does not enable catalog/checkout feature flags. Existing offer, reservation, tenant, caller-ID and FreeSWITCH authorization services remain authoritative. No SIP credentials, FreeSWITCH files, profiles, gateways or reload operations are changed by this release.

## Verification and rollout

Run `php artisan test --compact`, the Vite production build, and Blade view compilation. Use a disposable SQLite database and synthetic admin/stock records for desktop/mobile browser verification; do not create production test inventory.

Before deployment, take a private database dump and retain the current release path. Deploy through the authenticated master-push webhook. Verify the actual serving SHA, additive migration, inventory guest redirect, built asset and `/up` after switching. For rollback, restore the previous release symlink and retain the additive column and all inventory/history data. No schema rollback or FreeSWITCH restart is needed.

Local verification on 2026-10-25: 378 tests passed (2,619 assertions); 15 opt-in integration tests skipped. Vite build and Blade compilation passed. Browser checks covered desktop inventory, mixed-validity import, mobile layout, readiness fix links, save/review/publish and bulk publish on disposable synthetic data. Production rollout requires confirmation of the serving commit and migration after the webhook finishes.
