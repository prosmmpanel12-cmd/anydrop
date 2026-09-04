# Handover — 2026-08-30 — Android Maintenance-Mode Screens (Customer + Restaurant) + admin/app-settings.php Hardening

**Status: 🟡 BUILT — NOT PHP CLI / Android SDK / device verified.** Same
standing constraint as every session in this project: no `php` binary,
no Android SDK/Gradle, no live device in this sandbox. "Works" below
means "reads correctly and is structurally sound" (manual brace/paren
balance checks), not "compiled" or "ran".

This continues directly from doc 76's "Still pending" item 6
(Android-side maintenance-mode handling — flagged there as genuinely
un-started) and from a follow-up review pass on that session's 8 PHP
files.

## Part 1 — admin/app-settings.php hardening (from manual review)

Reviewed all 8 files doc 76 touched. No functional bugs found — traced
the wallet fix through its actual call chain (`complete_refund_to_wallet()`
→ `credit_wallet()` → `get_or_create_wallet()`) and confirmed the
transaction nesting is correct, confirmed `admin/refunds.php`'s new
`<dialog>` ids match `admin.js`'s existing generic open/close handler,
confirmed no duplicate refund/wallet API endpoints exist.

One real (if narrow) bug fixed: `admin/app-settings.php` read
`$_GET['app']` / `$_POST['app']` and passed them straight into
`array_key_exists($app, $apps)`. If either ever arrives as an array
(e.g. `?app[]=x` or a form field renamed to `app[]`), PHP 8's
`array_key_exists()` throws a `TypeError` — unhandled on the POST path,
so that one is a straight 500, not just a bad fallback. Fixed by adding
an `is_string($app)` / `is_string($postedApp)` guard before the
`array_key_exists()` call in both places.

## Part 2 — Android maintenance-mode screens (Customer + Restaurant apps)

**Rider App excluded** — it doesn't exist as a built project in this
codebase yet (Phase 4/unbuilt, same as doc 76 and earlier docs already
note), so there's nothing to wire this into there.

Backend already exposed `maintenance_mode` (bool) / `maintenance_message`
(string) in `GET /api/v1/system/app-version.php`'s response as of doc
76's session — this pass is purely the client side reading it.

### What was built, per app (Customer: `com.anydrop.food`, Restaurant: `com.anydrop.restaurant`)

- **`network/Models.kt`** — `AppVersionInfo` gained
  `maintenanceMode: Boolean = false` and `maintenanceMessage: String? = null`,
  mapped from the same JSON keys. Defaulted (not required) so an older
  cached response or a field missing entirely still deserializes safely
  as "not in maintenance" rather than a Gson crash.
- **`ui/common/MaintenanceDialogFragment.kt`** (new) — same shape as the
  existing `UpdateDialogFragment` (`DialogFragment` +
  `MaterialAlertDialogBuilder`, `isCancelable = false`), but with only
  one action: **Retry**, which calls `requireActivity().recreate()` to
  restart the whole splash flow and re-hit the backend. No "Later"/dismiss
  path — unlike an outdated app version, a maintenance window is a
  server-side state that can flip off at any moment, so there's nothing
  for the user to meaningfully act on except check again. Also overrides
  `onCancel()` to re-show via `recreate()` as defense in depth, even
  though `isCancelable = false` should already block back-press/outside-tap.
- **`res/layout/dialog_maintenance.xml`** (new) — same visual pattern as
  `dialog_update.xml` (icon + title + message + button row), using the
  existing `ic_clock` vector and `bg_card_rounded` background, both of
  which already existed in each app's drawables — no new assets needed.
- **`res/values/strings.xml`** — added `maintenance_title`,
  `maintenance_message_default` (mirrors migration 68's own default
  copy — if the backend ever sends a blank message, the layout's bundled
  string shows instead, never a raw empty string), `btn_maintenance_retry`.
- **`ui/common/UpdateChecker.kt`** — added a `info.maintenanceMode ->`
  branch to the existing `when` block, positioned **before** the
  min/latest version-code checks (a maintenance window blocks regardless
  of whether the installed build is current — no point evaluating version
  first). Same "show a non-cancellable dialog over splash and never call
  `onDone()`" pattern the forced-update branch already used, so
  `SplashActivity` simply never proceeds to `LoginActivity`/`HomeActivity`
  while it's showing.

### Design choices worth flagging for the next session

1. **Retry via `Activity.recreate()`, not a targeted re-check callback.**
   Simpler and reuses the exact same code path a cold app launch would
   take (re-runs `SplashActivity.onCreate()` → `UpdateChecker.check()`
   from scratch), at the cost of replaying the splash entrance animation
   and the splash-config fetch too. If that turns out to feel janky in
   practice, a next session could thread a narrower re-check callback
   through instead — deliberately not built that way here since it adds
   complexity for a screen that (by design) most users should rarely see.
2. **No countdown/auto-retry.** The dialog only re-checks on an explicit
   tap. An auto-poll-every-N-seconds version is possible but wasn't
   requested and adds a background timer to reason about inside a
   `DialogFragment`'s lifecycle — flagged as a possible enhancement, not
   guessed at.
3. **Rider App has nothing to build against yet.** When the Rider App
   project exists, its splash/`UpdateChecker` equivalent should get the
   same three-file treatment (`Models.kt` field, `MaintenanceDialogFragment`,
   `UpdateChecker` branch) — the backend already returns
   `maintenance_mode_rider`/`maintenance_message_rider` via the same
   `?platform=rider` query, so no backend work is needed when that day
   comes.

## Still pending

Everything doc 76 already listed as pending (migration 68 run, `php -l`
the 8 files, live wallet/refunds/FCM tests) is unchanged and still
applies. Additionally now:

1. **Build + device-test both apps' maintenance dialogs** — no Android
   SDK/Gradle in this sandbox, so `MaintenanceDialogFragment` /
   `dialog_maintenance.xml` / the `UpdateChecker` branch have only been
   manually read, never compiled. Toggle
   `maintenance_mode_customer`/`maintenance_mode_restaurant` on via
   `admin/app-settings.php` (needs migration 68 run first) and confirm
   each app's splash actually shows the dialog and blocks proceeding,
   then confirm Retry recovers once the flag is turned back off.
2. **`app-settings.php`'s two-line hardening fix** — same "reads
   correctly, never run" caveat as everything else; worth a quick
   `?app[]=x` / `app[]=x` POST-field manual test once PHP is available,
   to confirm it now falls back to `customer` instead of 500ing.

## Next session should start here

If Android SDK/emulator or a real device + live backend become
available: build both apps, run migration 68, flip maintenance mode on
per-app from the admin panel, and work through pending item 1 above.
Otherwise the reconciliation/Rider App/Email OTP priority order from
doc 76 (itself carried from doc 76's predecessor) still stands
untouched by this session.
