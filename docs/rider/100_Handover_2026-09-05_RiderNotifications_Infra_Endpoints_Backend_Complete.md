# Handover — Rider Notifications infra endpoints, BACKEND COMPLETE (Android/live-DB still untouched)

Session date: 05 Sep 2026 (continues doc 99, same day). Picks up doc
99's "Still open → All Android/infra work" bullet — specifically the
two missing pieces it named as blocking the FCM push and bell-list
halves of everything doc 99 built: no endpoint ever wrote
`riders.fcm_token`, and no endpoint let the rider app read its own
notifications back.

> **Not built/tested against a live backend or device.** Same standing
> caveat as every other handover in this project — no PHP CLI, MySQL,
> Android SDK, or Gradle in this sandbox. All four new PHP files were
> run through the same comment/string-aware brace/paren/bracket balance
> script doc 99 used (rewritten in Python this session, same
> `//`/`#`/`/* */`/`'...'`/`"..."` handling) and came back clean. **No
> `php -l`, no live DB, no Android/Gradle work at all this session** —
> this was backend-only, same scope-limit as doc 99.

## What was built this session — Backend

Four new files under `backend/api/v1/rider/`, all following the
flatter one-file-per-action convention doc 99 identified (confirmed
against `payout.php` / `payout-bank-details-get.php` /
`payout-bank-details-save.php` — no `.htaccess` routing, method-based
dispatch, query-string params instead of path segments):

### `rider/fcm-token-update.php` (new)
`POST { "fcm_token": "..." }` → `UPDATE riders SET fcm_token = :t
WHERE id = :id`. Direct structural copy of
`customer/fcm-token-update.php` (the simpler of the two existing
twins — restaurant's has no extra logic worth diverging from either),
same "plain overwrite, no token-format validation" reasoning: an FCM
token is opaque to this backend, the only validation that makes sense
is "non-empty after trim."

This is the piece doc 99 flagged as making every rider push a silent
no-op: `create_notification()` (`lib/notifications.php`) already reads
`riders.fcm_token` in its push step and already handles a NULL token
by skipping the push non-fatally — nothing in that function needed to
change. The column already existed too (`01_schema.sql`, confirmed via
migration 60's own header, which notes `riders.fcm_token` predates
that migration). The only genuinely missing piece was something to
write to it, which is all this file does.

### `rider/notifications-list.php` (new)
`GET ?page=&per_page=&unread_only=` → thin wrapper around
`lib/notifications.php`'s `fetch_notifications('rider', $riderId, ...)`.
Zero new query logic — that helper was already fully recipient-type-
agnostic (customer/restaurant's own `notifications.php` already call
it with `'customer'`/`'restaurant'`), so this file is the same
request-parsing shape as customer's GET branch with the type constant
swapped.

### `rider/notifications-read.php` (new)
`POST ?id=123` → thin wrapper around `mark_notification_read('rider',
$riderId, $id)`. Same 404-on-false handling as customer's
`?action=read` branch — `mark_notification_read()` already scopes its
UPDATE to `recipient_type='rider' AND recipient_id=:rid`, so a
mismatched/foreign/missing id can't be used to mark another rider's
(or another recipient type's) notification read; this endpoint just
surfaces that as a 404 rather than a silent no-op.

### `rider/notifications-read-all.php` (new)
`POST` (no body) → thin wrapper around
`mark_all_notifications_read('rider', $riderId)`, returns
`{ "marked_read": <int> }`. Same shape as customer's
`?action=read-all` branch.

None of the four files touch `lib/notifications.php` itself — every
function they call already existed, already recipient-agnostic, built
when the customer/restaurant bell lists were first wired. This session
added zero new shared logic, only rider-side call sites.

## Why four files instead of extending an existing one

Doc 99 already made this call explicitly (see its "Still open" section)
after checking rider endpoint-naming precedent — this session just
followed through on it rather than re-litigating: rider endpoints in
this codebase use one plain filename per action with method-based
dispatch (`payout.php` handles GET+POST itself, but
`payout-bank-details-get.php`/`payout-bank-details-save.php` are split
by action, not method), never the customer/restaurant
`?action=`+`.htaccess`-rewrite shape. `notifications-list.php` /
`notifications-read.php` / `notifications-read-all.php` matches that
second (split-by-action) precedent, since read/read-all/list are three
independently-callable actions rather than one GET-vs-POST resource
the way `payout.php` is.

## Verification done this session

1. **Confirmed `riders.fcm_token` already exists** in `01_schema.sql`
   (not added by migration 60 — that migration's own header says it
   only added the column to `customers`/`restaurants`, since riders
   already had it ahead of the Rider App being built at all).
2. **Confirmed `require_auth('rider')` is a valid owner type** by
   reading `lib/auth.php`'s `require_auth()` signature and its
   per-owner-type branches directly, rather than assuming from other
   rider endpoints' usage.
3. **Confirmed `fetch_notifications()` / `mark_notification_read()` /
   `mark_all_notifications_read()` are already fully recipient-type-
   parameterized** by reading `lib/notifications.php` in full (not just
   grepping call sites) — none needed a single line changed to support
   `'rider'` as a fourth recipient type; `'admin'` was already the
   third alongside `'customer'`/`'restaurant'`.
4. **Brace/paren/bracket balance** — rewrote doc 99's PHP-flavored
   checker as a standalone Python script this session (same
   `//`/`#`/`/* */`/string-with-escapes handling), ran against all four
   new files. All four `OK`.
5. **Cross-checked routing convention** by re-reading `payout.php`,
   `payout-bank-details-get.php`, and `payout-bank-details-save.php`
   side by side before deciding the split-by-action shape, rather than
   guessing from file names alone.

None of this substitutes for `php -l`, a live DB, or a real
Android-side FCM token registration + push round-trip — same standing
limitation as every prior handover in this project.

## Still open (carried over + updated)

- **Migrations 75 and 76 have still never been run** — both outstanding
  against a live DB, unrelated to this session (this session added no
  new migration; no schema change was needed for any of the four new
  files).
- **`php -l` on all four new files** — not available in this sandbox,
  never run.
- **Order category (deep-plan §23), entirely unbuilt** — unchanged from
  doc 99: restaurant-ready re-notify, customer cancellation, admin
  cancellation, delivery issue. Not touched this session.
- **Assignment expired/cancelled** — unchanged from doc 99, still
  blocked on the same product decision (does an expiry push duplicate
  the live-offer-screen countdown timer? that screen's behavior still
  hasn't been confirmed).
- **COD settlement warning** — still not investigated.
- **Android side — this is now the actual blocker, not backend:**
  - No rider `FirebaseMessagingService` to receive a token and call the
    new `fcm-token-update.php` on refresh (customer/restaurant's own
    services are the template — not opened this session to confirm
    their exact shape, should be step one of the Android side).
  - No rider `NotificationListActivity`-equivalent screen (or
    ViewModel/Retrofit interface entries) to call the three new
    `notifications-*.php` endpoints. `ui/common/InAppNotifier.kt`
    exists (snackbar-style, in-app-only) but nothing persistent — same
    gap doc 99 already named, still open.
  - No notification channel / tap-through deep-link handling for the
    `data.screen` values doc 99's five `admin/riders.php` calls and the
    one `orders-deliver.php` call already send (`dashboard`,
    `application_status`, `submit_documents`).
  - Once a `FirebaseMessagingService` exists: needs to call
    `fcm-token-update.php` on both initial token generation and any
    later token-refresh callback, not just once at login — same pattern
    customer/restaurant's own services presumably already follow
    (not confirmed this session, worth checking before assuming).
- **Live click-through, once the above exists:**
  1. Register a token via `fcm-token-update.php` from a real device.
  2. Trigger any of doc 99's six notification-producing events (five
     `admin/riders.php` transitions + one earning-posted).
  3. Confirm the bell-row lands and is readable via
     `notifications-list.php`.
  4. Confirm `notifications-read.php` / `notifications-read-all.php`
     actually flip `is_read` and that `unread_count` reflects it.
  5. Confirm a real FCM push arrives on the registered device.
  None of this is possible without a live DB (migrations 75/76 run
  first) and an Android build with the FCM service wired — this
  session only removed the backend half of the blocker.
- Everything doc 96/98 already listed for Rider Payout Requests /
  Rider Documents (migration 74/75 live runs, `php -l`, live click-
  throughs) — still untouched, unrelated to this session's work.
- PENDING.md/recall.md still not re-audited (flagged stale since doc
  97) — not done this session either.
