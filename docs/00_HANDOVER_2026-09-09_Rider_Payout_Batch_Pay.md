# Handover — 2026-09-09 (session 4) — Rider Payout: Monthly Batch Pay (Admin)

Continuation of session 3 (rider bank-details OTP save). App owner
clarified the actual business model this session: a rider's "Request
Payout" button is only ever a REQUEST — no money moves at request
time from the app's side, no OTP needed there. The admin pays riders
out manually on a fixed monthly date, reviewing/approving requests in
bulk. This session builds that: **monthly batch pay**, modeled
directly on the restaurant Settlement "Pay Now" pattern (UTR/reference
+ optional screenshot proof) — but per rider, since one bank transfer
batch can't share a single UTR across N different riders.

---

## ✅ DONE — Batch approve + batch process (each rider keeps own reference/screenshot)

**New migration** `backend/sql/76_migration_rider_payout_batch.sql`:
- `rider_payout_requests` gains `payout_screenshot_url` (optional,
  same convention as `restaurant_payments.screenshot_url`) and
  `payout_batch_id` (nullable grouping tag, NOT a foreign key to any
  new table — no separate "batches" entity exists, it's just a shared
  string so the admin UI can show "these N were processed together").
  Indexed for the batch-history query.

**`backend/lib/rider_payout.php`** — additive only, no existing
call-site broken:
- `mark_rider_payout_request_processing()` gained two new optional
  params (`$screenshotUrl = null`, `$batchId = null`) — the existing
  single-row call (Mark Processing button, unchanged) still works
  exactly as before, just passes null for both.
- New `generate_rider_payout_batch_id()` — `BATCH-YYYYMMDD-HHMMSS-XXXX`
  format, purely a display/grouping tag.
- New `list_rider_payout_batches()` — groups by `payout_batch_id`
  (excludes ungrouped/individually-processed rows), returns count,
  total amount, first-processed timestamp, completed-count — backs the
  new "Past Batches" card.
- New `list_rider_payout_requests_by_batch()` — used by the new
  "Complete Batch" action to find every row in a batch.

**`backend/admin/rider-payouts.php`** — extended, not rewritten from
scratch, so the existing single-row Approve/Reject/Mark Processing/Mark
Completed buttons and their behavior are untouched:
- New `save_payout_screenshot()` — same size cap (5MB)/MIME-sniff
  pattern as `settlements.php`'s `save_settlement_screenshot()`,
  duplicated rather than shared (this codebase's established
  convention — `support.php` already has its own copy too). Saves to
  new `backend/uploads/rider_payout_screenshots/`.
- Existing single-row `processing` POST handler now also accepts an
  optional screenshot upload (form gained `enctype="multipart/form-data"`).
- New `batch_approve` handler: loops `approve_rider_payout_request()`
  over checked `requested` rows. No batch id stamped here — approving
  doesn't move money (see file's own kdoc), only the *processing* step
  represents an actual sent transfer, so only that step gets grouped.
- New `batch_processing` handler: loops checked `approved` rows,
  reading a **per-row** `reference[$id]`/`screenshot[$id]` from the
  POST (PHP's native array-named-input handling — `name="reference[42]"`
  arrives as `$_POST['reference'][42]`) and calls
  `mark_rider_payout_request_processing()` per row with its own
  reference/screenshot, all stamped with one shared
  `generate_rider_payout_batch_id()` value. A row missing its
  reference, or with a bad screenshot, is skipped (reported back in the
  flash message) rather than aborting the whole batch — the admin
  shouldn't lose already-typed references for other riders over one
  bad file.
- New `batch_complete` handler: given a `batch_id`, marks every
  `processing` row in that batch `completed` (still one
  `complete_rider_payout_request()` call per row underneath — each row
  still gets its own `platform_ledger` entry, unchanged).
- UI: checkboxes on `requested`/`approved` rows, "Approve Selected" /
  "Batch Process Selected" buttons above the table, a client-side modal
  (`btnBatchProcess` click handler) that builds one reference+screenshot
  input pair per checked rider (reads the rider name straight off the
  table row so the admin can tell which input is whose), then moves
  those inputs into the main form and submits. New "Past Batches" card
  below the main table lists batch id / request count / total amount /
  processed-at / completion status, with a "Complete Batch" button once
  any row in it is still `processing`.

**No changes** to the rider Android app or the customer/restaurant
apps this session — this is admin-panel-only, matching that the
person's clarification was specifically about the admin-side payment
cadence, not anything the rider app needs to do differently (rider
still just taps "Request Payout" whenever, no OTP, exactly as it
already worked).

Checked, not touched: `admin/rider-earnings.php`'s separate "Record
Payout" form (admin proactively paying a rider with no request behind
it) is untouched — batch pay only applies to rider-INITIATED requests
sitting in the `rider_payout_requests` table, same distinction the
file's own kdoc already drew before this session.

Only brace/paren-balance checks run (no PHP CLI in this sandbox, same
standing limitation as every session on this track) — **migration 76
needs to be run, then a real click-through test**, specifically:

## Suggested order for whoever continues

1. Run migration 76.
2. Live test: create 2-3 rider payout requests (via the rider app) →
   on this admin page, checkbox-select them while `requested` → Approve
   Selected → checkbox-select the now-`approved` rows → Batch Process
   Selected → confirm the modal shows one reference+screenshot field
   per rider with the right rider name/amount labeled → fill distinct
   references (+ try one screenshot) → Submit Batch → confirm each row
   now shows ITS OWN reference/screenshot plus a shared batch id →
   check the new "Past Batches" card shows the batch with the right
   count/total → Complete Batch → confirm all rows flip to `completed`
   and each wrote its own `platform_ledger` `rider_payout_out` row.
3. Also sanity-check the skip-on-bad-input path: submit a batch leaving
   one rider's reference blank — confirm the others still process and
   the flash message calls out which id(s) were skipped.
4. Once verified, this closes out the "monthly batch pay" ask in full.
   Next unscoped work per the standing backlog: customer-app
   tracking-screen work, Rider Earnings (§19) polish, or Admin live map
   (§25) — to be confirmed with the person.
