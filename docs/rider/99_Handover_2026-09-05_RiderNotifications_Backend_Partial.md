# Handover — Rider Notifications (deep-plan §23), BACKEND PARTIAL

Session date: 05 Sep 2026 (same day as docs 94-98). Picks up the
standing "known gap" every recent handover has flagged: no rider ever
gets notified of anything. This session is a **partial, audit-first**
slice, not a full §23 build — see "Still open" below for the real size
of what's left.

> **Not built/tested against a live backend or device.** Same standing
> caveat as every other handover in this project — no PHP CLI, MySQL,
> Android SDK, or Gradle in this sandbox. The two touched PHP files
> were checked with a comment/string-aware brace/paren/bracket balance
> script (see doc 98 for the same script's Kotlin variant; this
> session used a PHP-flavored version — handles `//`, `#` line
> comments, `/* */` blocks, `'...'` and `"..."` strings with escape
> handling) and came back clean. **No `php -l`, no live DB, no
> Android/Gradle work at all this session** — this was backend-only.

## Where this session started

Before writing anything, this session audited every existing
`create_notification()` call site in the codebase against deep-plan
§23's full event list (Assignment / Order / Finance / Account), since
recent handovers' "known gap" note implied nothing rider-facing
existed. That assumption was **wrong** — grepping turned up:

- **Assignment → "New delivery offer"**: already fires from
  `lib/dispatch.php`'s `dispatch_next_candidate()` (built as part of
  the assignment engine itself, docs 83-85 — just never mentioned in
  any handover's notification-gap language since it predates the
  "rider notifications" framing).
- **Finance → Payout initiated/completed/rejected**: already fire from
  `lib/rider_earnings.php`'s payout functions (docs 95-96's own work).
- **Order**: genuinely nothing rider-facing existed (only
  customer-facing order notifications in `orders-accept.php`/
  `orders-pickup.php`/`orders-deliver.php`).
- **Account**: genuinely nothing existed — `admin/riders.php` had zero
  `create_notification()` calls despite being the only place
  approve/reject/suspend/document-verify/document-reject ever happens.
- **Finance → Earning posted**: genuinely missing — the one payout-
  adjacent event not already covered by docs 95-96.

This session built the two genuinely-missing pieces that fit in one
sitting: **Account** (all 5 transitions in `admin/riders.php`) and
**Finance → Earning posted** (`orders-deliver.php`). Order-category
events and Assignment-expired/cancelled were not reached — see "Still
open".

## What was built this session — Backend

### `backend/admin/riders.php` (extended)
Added `require_once .../lib/notifications.php` and a `create_notification()`
call in every one of the five existing status-transition branches,
each placed immediately after that branch's own `write_audit_log()`
call (same "notify only once the write is already committed" ordering
every other call site in this codebase follows — see
`lib/notifications.php`'s own header):

- **Approve** (`action === 'approve'`) — captures `$fromStatus` before
  the UPDATE so the notification can distinguish first-time approval
  from reactivation: `"Account approved"` if `$fromStatus === 'pending'`,
  `"Account reactivated"` otherwise (rejected/suspended → approved).
  Deep-plan §23 lists these as two separate events even though they're
  the same DB transition — the wording split exists purely so a
  rider's first-ever approval doesn't read as a confusing
  "reactivated."
- **Reject** — `"Account rejected"`, body includes the admin's
  required rejection reason verbatim.
- **Suspend** — `"Account suspended"`, body includes the admin's
  required suspension reason verbatim.
- **Verify documents / Reject documents** (`verify_documents`/
  `reject_documents` branches) — `"Documents verified"` /
  `"Documents rejected"` (the latter includes the reason). Not
  explicitly named in deep-plan §23's own Account bullet list (that
  list predates migration 75's document-verification workflow), but
  grouped under the same `'account'` notification type rather than
  inventing a fifth type for it, since it's the same "tell the rider
  their submission was actioned" need.

All five use `data: ['screen' => ...]` matching the screen the rider's
own tap-through should land on (`dashboard` for approve/reactivate,
`application_status` for reject/suspend, `submit_documents` for the
document pair) — same deep-link convention every existing
`create_notification()` call site in this codebase already uses.

### `backend/api/v1/rider/orders-deliver.php` (extended)
Added one `create_notification('rider', ...)` call, placed right after
the existing customer `"Order delivered"` notification (both fire
post-commit, same transaction-then-notify ordering). Type `'payout'`
(reusing the existing value from migration 74, since this is the same
"a rider's balance just changed" event a payout notification already
represents) with body `"You earned ₹{amount} for order {code}."` —
`$earningResult['amount']` was already being returned by
`record_rider_delivery_earning()` for the JSON response, so this adds
zero extra queries, just reads a value already in hand.

Deliberately fires from the endpoint, not from inside
`record_rider_delivery_earning()` itself — keeps that ledger-writing
function notification-free, same split `rider_earnings.php`'s own
payout-approval/completion/rejection functions already establish
(ledger write and notification decision are separate concerns, the
call site owns the second one).

### New: `backend/sql/76_migration_notification_type_account.sql`

**Caught during self-review, not by any test** (no PHP CLI/DB in this
sandbox to catch it the normal way): `notifications.type` is an ENUM,
currently `'order','promo','system','security','review','wallet','payout'`
(migrations 31/43/74 each widened it once). This session's
`admin/riders.php` calls above all pass `'account'` as the type — a
value that has **never existed** in that ENUM. Caught by re-reading
migration 74's own header (which documents the exact same widening
pattern for `'payout'`) and checking whether `'account'` had ever been
added anywhere — it hadn't.

Without this migration, every new `create_notification(..., 'account', ...)`
call from this session would throw inside `create_notification()`'s
own try/catch — caught, logged to `error_log()`, **non-fatal to the
admin action that triggered it** (an admin clicking Approve/Reject/
Suspend/Verify/Reject-documents would see it succeed normally), but
the bell-row would silently never be written. The FCM push step in
`create_notification()` is a separate, independent try/catch block
that reads `riders.fcm_token` directly and doesn't touch
`notifications.type` at all, so — separately from this migration —
push delivery for these five events already doesn't work yet anyway,
for the unrelated reason covered in "Still open" below (nothing writes
`riders.fcm_token`).

Migration adds `'account'` to the ENUM, same `MODIFY COLUMN` (not
`CONTINUE HANDLER`-guarded — `MODIFY COLUMN` is naturally re-runnable)
style as migrations 31/43/74 exactly. **This migration has not been
run** — same standing gap as migration 75 before it (still outstanding
per doc 98).

## Verification done this session

1. **Full audit** of every `create_notification()` call site in the
   codebase (`grep -rn "create_notification("`) against deep-plan
   §23's Assignment/Order/Finance/Account event list, cross-checked by
   opening and reading each hit's surrounding context — not just
   grep-and-assume. This is what surfaced that Assignment and most of
   Finance were already done, narrowing real scope correctly before
   writing anything.
2. **Brace/paren/bracket balance** — PHP-flavored comment/string-aware
   checker (handles `//`, `#`, `/* */`, `'...'`/`"..."` with escapes)
   run against both touched files (`admin/riders.php`,
   `api/v1/rider/orders-deliver.php`). Both `OK`.
3. **ENUM value cross-check** — read `notifications.type`'s actual
   current ENUM definition (via migrations 01/31/43/74, since this
   sandbox can't query `information_schema` or a live DB directly) and
   confirmed `'payout'` (used in the earnings-posted call) already
   exists there, while `'account'` (used in all five riders.php calls)
   did not — this is what prompted writing migration 76 rather than
   shipping a silently-broken notification path.
4. **Variable-scope check** — confirmed `$riderId` is already in scope
   at the point the new earning-posted `create_notification()` call
   was inserted in `orders-deliver.php` (defined near the top of the
   file from `$owner['owner_id']`), and confirmed `notifications.php`
   was already `require_once`'d in that file (it was — the existing
   customer notification call already needed it).

None of this substitutes for `php -l`, a live DB with migration 76
actually run, or a real admin-panel click-through — same standing
limitation as every prior handover in this project.

## Still open (carried over + new)

- **Migration 76 has never been run** — brand new this session, on top
  of migration 75 (doc 97/98) which was *also* never run. Both are
  outstanding against a live DB.
- **`php -l` on both touched files** — not available in this sandbox,
  never run.
- **Order category (deep-plan §23), entirely unbuilt:**
  - Restaurant ready (rider should know a pickup is waiting — separate
    from "New delivery offer," which already fires; this would be a
    later re-notify if the rider has already accepted and is waiting
    on prep).
  - Customer cancellation (rider already assigned/en route, order gets
    cancelled out from under them).
  - Admin cancellation (same, admin-initiated).
  - Delivery issue (no clear single call site identified yet — would
    need scoping before building).
- **Assignment expired/cancelled — deliberately not built yet, needs a
  product decision first:** started auditing `expire_stale_offers()`
  in `dispatch.php` and stopped before adding anything, because a push
  for "your offer expired" is likely redundant with the countdown
  timer the rider is already looking at on the live offer screen (this
  session did not confirm that screen's existence/behavior before
  running out of room — needs checking next session before deciding).
  "Assignment cancelled" (a *different* rider's order getting pulled
  after assignment, e.g. an admin-cancelled order) was not looked at
  at all.
- **COD settlement warning** — not investigated this session.
- **All Android/infra work, entirely unbuilt:**
  - No `rider/fcm-token-update.php` endpoint — `riders.fcm_token`
    column already exists (migration 60's own header notes it
    predates that migration, added ahead of the Rider App itself) but
    nothing has ever written to it, so **the FCM push half of every
    notification this session added (and the two pre-existing ones)
    silently no-ops today** — `create_notification()`'s push step
    reads a token column that's always NULL for every rider.
  - No `rider/notifications-list.php` / `rider/notifications-read.php`
    / `rider/notifications-read-all.php` endpoints — bell-row writes
    are happening (once migration 76 runs) but nothing lets the rider
    app read them back. Restaurant/customer's `notifications.php`
    (single file, `?action=read`/`?action=read-all` routing via
    `.htaccess`) was identified as the template, but rider endpoints
    in this codebase follow a different, flatter convention (one
    plain-filename-per-action, no `.htaccess` rewrite — confirmed by
    checking `rider/payout-bank-details-get.php` /
    `payout-bank-details-save.php` / `payout.php`'s naming and
    Retrofit's exact call strings). Next session should follow that
    flatter convention (e.g. `notifications-list.php`,
    `notifications-read.php?id=`, `notifications-read-all.php`) rather
    than copying restaurant's `.htaccess`-routed shape verbatim.
  - No rider `FirebaseMessagingService` / notification channel / bell
    UI screen / `InAppNotifier` wiring for any of this — the rider app
    currently has `ui/common/InAppNotifier.kt` (in-app snackbar-style,
    already used elsewhere) but no persistent notification history
    screen at all, unlike Restaurant/Customer's
    `ui/notifications/NotificationListActivity.kt`.
- **Live click-through, once the above exists:** approve/reject/
  suspend a rider from admin → confirm the right notification type/
  body lands in the `notifications` table → (once an Android bell
  screen exists) confirm the rider app actually displays it → (once
  `fcm-token-update.php` exists and an Android FCM service registers a
  token) confirm a real push arrives.
- Everything doc 96/98 already listed for Rider Payout Requests /
  Rider Documents (migration 74/75 live runs, `php -l`, live click-
  throughs) — still untouched, unrelated to this session's work.
- PENDING.md/recall.md still not re-audited (flagged stale since doc
  97) — not done this session either.
