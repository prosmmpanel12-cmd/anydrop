# Handover — Settlement Screenshot Upload (2026-08-26)

Closes the one remaining gap flagged in recall.md section 13 / item 16
("Settlement Screenshot Upload — screenshot_url column exists, form
doesn't yet have a file input — small follow-up"). Admin-panel-only
change, no migration needed (`restaurant_payments.screenshot_url`
already existed, and `lib/ledger.php`'s `record_settlement()` already
accepted a `$screenshotUrl` parameter that nothing was passing).

## What changed — `backend/admin/settlements.php` only

- New `save_settlement_screenshot()` — mirrors `banners.php`'s
  `save_banner_image()` validation exactly (5 MB cap, real-content MIME
  sniff via `finfo`, not just the extension; JPG/PNG/WEBP only). No
  crop support here — unlike a banner, a settlement proof screenshot
  should be saved as-is, not reframed. Stores to a new
  `backend/uploads/settlement_screenshots/` directory (created on
  first use, same `mkdir(..., 0755, true)` pattern as
  `admin_banners`). Returns `null` with no error when no file was
  chosen (screenshot is optional), and `null` with `$error` set for a
  genuinely bad upload.
- Pay Now form (`enctype="multipart/form-data"` added) gained a new
  optional "Payment Screenshot" file input.
- `pay_now` POST handler now calls the new function and, on a real
  validation error (too large / wrong type), blocks the whole
  settlement rather than silently recording it with no proof attached
  — same "don't silently drop what the admin thought they attached"
  reasoning any required-evidence upload should follow, even though
  the screenshot itself is optional. A successful/absent upload flows
  straight into the existing `record_settlement()` call, which already
  knew what to do with a `$screenshotUrl` — no `lib/ledger.php` change
  needed.
- Settlement History table gained a Screenshot column — a thumbnail
  (`<img style="height:40px">`) linking to the full-size file in a new
  tab when `screenshot_url` is set, "—" otherwise. `$payments` already
  came from `SELECT *`, so no query change was needed for the column
  to have data.
- `write_audit_log('settlement_recorded', ...)`'s payload now also
  includes the screenshot path when one was attached.

## Deliberately NOT touched

- No new admin permission — reuses the existing `payouts_manage` gate
  the rest of this form already has.
- No `.htaccess` change — `backend/.htaccess`'s `RewriteCond
  %{REQUEST_FILENAME} !-f` / `!-d` already serves any real file/dir
  directly without rewriting, the same mechanism `uploads/admin_banners`
  and `uploads/address_photos` already rely on. A file saved into the
  new `uploads/settlement_screenshots/` directory is servable the
  moment it exists — nothing to configure.
- Restaurant-side self-submission of a settlement screenshot is a
  separate, still-unbuilt idea (nothing in recall.md asked for it) —
  this is the admin-recorded Pay Now flow only, matching how the rest
  of the settlement system already works (admin does the transfer and
  records it, per doc 19 §6's model — no restaurant-initiated flow
  exists here).

## Needs a real machine, not this sandbox

Same standing limitation as every other session: no PHP CLI, no live
DB, no browser here. Manual brace/paren balance-check done on the
edited file in place of `php -l`. Needs, on a real environment:

1. `php -l backend/admin/settlements.php`.
2. Open a restaurant's Settlement page, Pay Now with no screenshot
   attached — confirm it still records exactly as before (regression
   check).
3. Pay Now with a valid JPG/PNG/WEBP under 5 MB attached — confirm the
   file lands in `backend/uploads/settlement_screenshots/`, the
   Settlement History thumbnail renders, and clicking it opens the
   full image.
4. Try a >5 MB file and a non-image file (e.g. a `.pdf` or `.txt`) —
   confirm both are rejected with the specific message and, critically,
   that the settlement itself is NOT recorded when the screenshot
   fails validation (no ledger entry, no restaurant_payments row).
5. Confirm `restaurant_due_ledger` / `platform_ledger` entries from a
   screenshot-attached settlement are identical in every other respect
   to one without — this change should not alter any financial math,
   only add the proof attachment.

## Suggested next step

Per doc 45's own queue: resume a fresh read of
`docs/21_Production_Feature_Gap_Plan.md` to pick the next production
gap. Genuinely admin-panel-scoped candidates still open there:

- Restaurant Finance / Payout Analytics (recall.md item 13's own
  still-🔴 sub-item — Today/Weekly/Monthly earnings + GST breakdown
  columns on `admin/settlements.php`'s per-restaurant view; doc 19 §6
  describes the exact columns).
- Admin reported-review moderation queue (recall.md item 8/11 — schema
  already reserves `reviews.is_reported`, no admin queue page exists
  yet).
- Customer Support / Ticket admin side (recall.md item 9/20) — larger,
  needs a new DB migration first, not a same-session admin-only fix
  like this one was.
