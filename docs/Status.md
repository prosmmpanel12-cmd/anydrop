# Anydrop — Project Status

## 🎉 2026-08-28 — Full build + device verification pass confirmed, whole project (Restaurant + Customer + Admin)

App owner confirmed a full build and on-device test pass across **all
three apps** — Restaurant App, Customer App, and Admin Panel. Every
entry in this file, `PENDING.md`, `recall.md`, and
`docs/restorent/00_Status.md` marked "🟡 NOT build/device-verified" /
"NOT tested" / "not build-verified" as of 2026-08-27 (through session
14 / docs/29-53) is now **superseded and resolved** — treat those
caveats as closed unless a specific bug is separately logged in
`docs/bugs.md`. This includes: Admin Order Control, Admin Analytics,
Restaurant Insights, Full Offers Engine, Review Moderation, Support
Ticket System (admin side), Admin Feedback View, Customer
Complete-Profile flow (both backend and Android), Settlement CSV
Export, Restaurant Payout Analytics, Settlement Screenshot Upload,
Customer Wallet Checkout Integration, and Wallet Refund Integration
(the latter two were also found mis-marked as "PENDING" in
`PENDING.md` during this same session's doc-audit — corrected to
built-and-verified, having actually been built back on 2026-08-23).

**Not covered by this pass** (still genuinely not built, not a
verification gap): Rider App and its dependent systems, Restaurant
Self Delivery, Restaurant Staff/RBAC, Temporary Closure/Holiday full
scheduling, Restaurant Bank Details submission form, Cashback/Reward
Engine, Support AI, Google Login backend verification, Payment/Refund
Reconciliation (production layer), Email OTP Provider Failover,
Security Hardening audit. These remain on `PENDING.md` as before —
this session confirmed *tested*, not *built new features*.

---

**Last Updated:** 2026-08-25 (session 14) — Restaurant App offer create/
edit screen now has UI for `apply_mode` (Default vs Coupon Based),
`code`, and `is_public` — the one gap docs/35's coupon-based-offers
session left open. **Not build-verified, not tested on-device.** See
`docs/38_Handover_2026-08-25_Offer_ApplyMode_CouponBased_UI_Wired.md`
for full detail. Everything else in this file (below the 2026-08-20
entry that follows) predates docs/33 through docs/38 — those five
sessions' worth of Offers Engine / Offers browse screen / coupon-
stacking-toggle / apply-mode work were tracked via their own numbered
handover docs rather than fresh entries here; check `docs/` for
anything numbered 29 or higher before assuming this file's older
entries are current.

## 2026-08-20 (even later) — Notification bell, Customer App Android UI — NOT tested, NOT build-verified

Continues the "2026-08-20 (later)" entry directly below — that session built
the Type 1 backend only and explicitly scoped out the Android side. This
session did the Customer App half of that Android side. **Restaurant App
Android side (bell + badge + list screen) is still not started** — same
backend endpoints already support it, nothing app-side built yet.

**What was built:**
- `network/Models.kt` — `NotificationItem`, `NotificationsResult`,
  `MarkReadResult`, `MarkAllReadResult`, matching `fetch_notifications()`'s
  response shape exactly (`items`, `has_more`, `unread_count`; each item's
  `data` deserializes as a raw `Map<String, Any?>?` via Gson's default
  behavior — no custom type adapter needed since it's read-only, keyed
  access, e.g. `data?.get("order_id")`).
- `network/ApiService.kt` — `getNotifications()` (paginated,
  `unread_only` query param), `markNotificationRead(id)`,
  `markAllNotificationsRead()`. Calls `customer/notifications.php`
  directly with `action`/`id` query params (same convention every other
  endpoint in this file uses) rather than the `.htaccess` pretty-route
  paths, which exist for direct-hit completeness but aren't what the app
  itself calls.
- `res/layout/item_notification.xml` (new) + `drawable/bg_notification_icon.xml`,
  `drawable/bg_unread_dot.xml` (new) — card row: circular tinted icon
  (type-mapped: order→`ic_restaurant`, promo→`ic_offer_tag`,
  security→`ic_lock`, system/default→`ic_notification`), small red dot on
  the icon corner when unread, title/body/timestamp. Timestamp reuses
  `OrderHistoryAdapter.formatOrderDate()`'s exact "d MMM, h:mm a" pattern
  (copy-pasted, not factored out yet — same as that function's own
  history of being copy-pasted before `ScheduledTimeFormatter` existed;
  a future session could fold this one in too if it recurs a third time).
- `ui/notifications/NotificationAdapter.kt` (new) — same
  `submit()`/`appendPage()`/single-item-patch shape as
  `OrderHistoryAdapter` (`markRead(id)` here instead of `markRated(id)`).
- `ui/notifications/NotificationListActivity.kt` (new) — reuses
  `activity_simple_list.xml` exactly like `OrderHistoryActivity` does
  (infinite scroll, swipe-refresh, empty state). `btnAction` (normally
  "add" elsewhere) is repurposed here as "mark all read"
  (`ic_check_circle`). Tapping a row: patches the adapter to read
  locally + fires `markNotificationRead` (fire-and-forget, non-fatal on
  failure — local state already shows read, next list fetch
  self-corrects), then deep-links via the notification's own `data`
  payload — currently only `{screen: "order_status", order_id}` is
  handled (the only shape any Type 1 call site sends today, per
  `orders/create.php` + the three `restaurant/orders-*.php` files) into
  `OrderStatusActivity`. Unrecognized `screen` values just mark-read,
  don't navigate — no crash on a missing/unexpected extra.
- `AndroidManifest.xml` — registered `NotificationListActivity`.
- `res/layout/activity_home.xml` — bell icon + count badge added to the
  top bar between the cart icon and profile icon, same
  `FrameLayout`-with-overlaid-`TextView` badge shape `btnCart`/`cartBadge`
  already use (reused `bg_cart_badge` drawable for the badge itself, for
  visual consistency with the existing cart badge in the same bar).
- `ui/home/HomeActivity.kt` — `btnNotifications` click → launches
  `NotificationListActivity`. `updateNotificationBadge()` (new) fetches
  `unread_count` via `getNotifications(unread_only="1", per_page=1)` —
  deliberately cheap (only the envelope's `unread_count` is used, not the
  one item the call returns) — called from both `onCreate` and
  `onResume`, same cadence `updateCartBadge()` already runs at. Network
  failure is silent/non-fatal (same reasoning as this screen's other
  soft-fail calls like `loadPromoBanners()`) — badge just doesn't update
  until the next resume.

**Manual verification done this session** (same standing sandbox
limitation as everywhere else in this project — no Kotlin/Gradle compiler
here): grepped every new/touched file's own `import` list against every
type it references (`ItemNotificationBinding`, `NotificationItem`,
`ActivitySimpleListBinding`, `ApiClient`, `InAppNotifier`,
`OrderStatusActivity`, etc. — all present where used, none missing).
Brace/paren balance checked on all 5 touched/new Kotlin files (all
balanced). Confirmed `ic_check_circle.xml` and the three type-icon
drawables referenced by `NotificationAdapter` already exist. Confirmed
`R.string.notifications_title` / `empty_notifications` / `mark_all_read`
were actually added to `strings.xml` and match what the activity calls.

**🟡 Not tested at all / not build-verified** — same caveat as the Type 1
backend entry below: nothing here has touched a real device, a live DB,
or a Kotlin/Gradle compiler. Before anything else: confirm a Customer App
build (Gradle/Actions), then on-device — place a test order, accept/
reject/mark-ready it from the Restaurant App side (once that side can
trigger it), confirm the bell badge appears on Home with the right count,
open the list, confirm rows render with the right icon/read-state
styling, tap a row and confirm it marks read + deep-links into
`OrderStatusActivity` for the right order, tap "mark all read" and
confirm the badge clears.

**Deliberately NOT done this session:**
- **Restaurant App Android side** — bell icon/badge in `MainActivity`'s
  top bar + a list screen there, mirroring everything above. Backend
  (`restaurant/notifications.php`) already supports it identically; only
  the Android UI is missing. This is the natural next step.
- **No push (FCM)** — still pull-only, same standing gap noted in the
  backend entry below; the badge only updates on Home's own
  onCreate/onResume, not live while the app sits open.
- **No per-category notification-settings toggle** — doc 18 flags this
  as a small follow-up once the bell exists at all; not started.

**2026-08-20 (earlier):** 🎉 App owner confirmed a full pass: **both Customer App and Restaurant App now have a real GitHub Actions `BUILD SUCCESSFUL`** (Restaurant App's first-ever compiler-confirmed green build — see `docs/restorent/00_Status.md`'s 2026-08-20 entry for that track's detail), and every feature/fix tested on-device worked correctly, no bugs found. This includes doc 22's UI/UX overhaul (now fully complete, including illustration panels that were still open as of 2026-08-19) and the alert/alarm system, address-delete fix, and restaurant Accept/sound hardening documented below. Every "🟡 not build-verified"/"not yet tested on-device" caveat anywhere in this file, as of 2026-08-18, is now superseded — treat those as resolved unless a specific bug is separately called out. **Not superseded:** doc 20 (restaurant offers system) and doc 21 (production feature gap plan) — both remain planning-only, nothing built.

## 2026-08-20 (later) — Notification bell, Type 1 (system-generated) — backend only, NOT tested yet

Started doc 18's next feature-queue item. Scoped down first — "notification
bell" turned out to mean two different things once discussed with the app
owner:
- **Type 1 (built this session):** system-generated notifications, fired
  automatically off real events (new order, order accepted/rejected/ready).
- **Type 2 (explicitly NOT built — separate future item):** admin-sent
  broadcast notifications — optional image, targeted by area/radius or by
  specific customer_ids, sent from an Admin Panel screen that doesn't exist
  yet. Don't conflate the two; this session is Type 1 only.

**What was built (backend only, per this session's own scope call —
bell UI in both apps is the next piece, not done yet):**
- **`backend/lib/notifications.php`** (new) — `create_notification()`
  (mirrors `write_audit_log()`'s pattern; never throws outward, logs and
  swallows on failure so a notification can't break the real action it's
  attached to), `fetch_notifications()` (paginated, `unread_only` filter,
  overfetch-by-one `has_more` convention already used elsewhere in this
  project), `mark_notification_read()`, `mark_all_notifications_read()`.
  Writes into the `notifications` table from `01_schema.sql` §7 — that
  table already existed, fully designed, but nothing had ever written to
  or read from it before this session.
- **Wired into 4 existing order-lifecycle endpoints**, each call placed
  after its DB write/transaction already committed (never inside):
  - `orders/create.php` → notifies the **restaurant** ("New order
    received"). Deliberately doesn't replace `OrderPollingService`'s
    urgent sound/alarm path — this is the persistent look-back-later
    record the bell list is for, not the urgent alert.
  - `restaurant/orders-accept.php` → notifies the **customer** ("Order
    accepted... ready in about N min").
  - `restaurant/orders-reject.php` → notifies the **customer** ("Order
    rejected: <reason>").
  - `restaurant/orders-status.php` → notifies the **customer** only on
    `ready` (not `preparing` — a low-signal internal step the customer
    doesn't need a separate alert for, same "don't over-notify"
    instinct as the rest of this project's alert design).
- **New endpoints**, both apps, same shape:
  - `GET /api/v1/customer/notifications` / `GET
    /api/v1/restaurant/notifications` — paginated list
    (`?page=&per_page=&unread_only=1`), returns `{items, has_more,
    unread_count}`.
  - `POST .../notifications/{id}/read` — mark one read.
  - `POST .../notifications/read-all` — mark every unread one read (bell
    list's "Mark all read" action).
  - `.htaccess` routes added for all 6 new URL shapes (3 per app).

**Deliberately NOT done this session:**
- **No bell icon/badge/list screen in either app yet** — this was scoped
  backend-only for this pass. The endpoints exist and are ready to call;
  next session should build the Android side (bell + unread badge in the
  top bar, a list screen reusing `activity_simple_list.xml`'s pattern
  customer-side, tap-to-deep-link via each notification's `data.order_id`
  + `data.screen`).
- **No push (FCM)** — these are pull-only (the client has to call `GET
  .../notifications` to see anything new; nothing pings the device). Same
  standing gap as everywhere else in this project that could use real
  push — flagged, not fixed, consistent with prior sessions' notes.
- **Type 2 (admin broadcast)** — not started, see scope note above.
  `lib/notifications.php` has a closing comment flagging that its
  single-recipient shape shouldn't be stretched to fit Type 2 later; build
  a separate targeting/fan-out layer on top instead.

### 🟡 Not tested at all
Nothing in this entry has touched a real device or a live DB — same
standing sandbox caveat as always (no PHP CLI/DB here), plus this is
brand-new code that's never run once. Brace/paren balance checked on
every touched file (all balanced). Before anything else: place a real
test order end to end and confirm a row actually lands in `notifications`
for the restaurant, then accept/reject/mark-ready it and confirm a
customer-side row lands each time; then hit the two `GET` endpoints
directly (e.g. via browser/Postman with a real token) and confirm the
JSON shape looks right and `unread_count` moves after a `read`/`read-all`
call.

---

## 🎉 2026-08-20 — Full test pass confirmed on both apps, doc 22 fully done

App owner tested everything pending across the whole project and got a
real green Actions build for both apps. Result: **both apps build
successfully via Gradle, and every feature/fix tested worked as
expected — no bugs found.** Separately confirmed: doc 22 (UI/UX
overhaul — dialogs modernization, bundled category-icon picker, coupon
date-time picker, `is_public` toggle, and all illustration panels
including the 6 that `docs/restorent/00_Status.md` had flagged as still
open) is **fully built and tested**.

**Not itemized line-by-line here** — confirmed as a whole rather than
re-derived from a checklist. If any specific feature is later found
broken, treat that as a regression to investigate fresh, not as evidence
the confirmation was wrong.

**Explicitly NOT included in this confirmation:** doc 20 (restaurant
offers system — quantity/bundle deals, buy-X-get-Y, percentage/flat
discounts, free delivery) and doc 21 (production feature gap plan) are
still planning-only, nothing built. Don't read "everything tested" as
covering those two.

---

App owner's follow-up after the previous hardening pass: **vibration
worked sometimes, sound never did, and nothing happened at all once the
app was closed.** That last part was the real tell — the previous
approach (`NewOrderAlertSound`, a raw `MediaPlayer` driven by
`OrdersFragment`'s own poll loop) was architecturally unable to work once
the app wasn't open: closing the app (or the fragment's view being
destroyed on some lifecycle timings) stopped the polling coroutine
entirely, so nothing was left to ever detect a new order. Playing through
the alarm audio stream also explains the inconsistent sound/vibration —
that's a separate, muteable-independently volume slider most phones never
touch, unrelated to the phone's normal ringer/notification volume people
actually keep audible.

**Replaced with the standard Android mechanism for this:**

- **`service/OrderNotificationHelper.kt`** (new) — creates two
  `NotificationChannel`s (`IMPORTANCE_HIGH` for the actual new-order alert,
  with sound + vibration configured on the channel itself — the only way
  Android 8+ reliably honors either; `IMPORTANCE_LOW`/silent for the
  persistent "watching for orders" service notification) and builds both
  notifications.
- **`service/OrderPollingService.kt`** (new) — a foreground `Service` that
  polls `getOrders(status="pending")` every 15s **independent of any
  Activity/Fragment being open**, diffs against a *persisted* (SharedPreferences,
  survives process death) set of already-seen order ids, and posts the
  alert notification via the helper above when something new shows up.
  `START_STICKY` so Android tries to respawn it after being killed.
  Started from `MainActivity.onCreate()`, stopped from `AccountFragment`'s
  logout handler.
- **`OrdersFragment.kt`** — reverted to just displaying data; all
  alert-detection logic removed (the service is now the single source of
  truth, avoiding double-alerting while the app happens to be open too).
- Manifest: added `FOREGROUND_SERVICE`, `FOREGROUND_SERVICE_DATA_SYNC`,
  `POST_NOTIFICATIONS` permissions + the `<service>` declaration.
  `POST_NOTIFICATIONS` is requested at runtime from `MainActivity` (a
  hard requirement on API 33+ — without it the alert notification
  silently never shows, no error anywhere, which is itself a very
  plausible contributor to "no sound" if the app owner's test device is
  Android 13+ and never got prompted before this fix).
- Deleted the now-superseded `ui/common/NewOrderAlertSound.kt`.

**Known, honest limitation — not fully solvable without FCM push:** a
foreground service significantly improves survival odds but Android
still can *not* guarantee it against aggressive OEM battery-management
skins (MIUI/ColorOS/FunTouch on Xiaomi/Oppo/Vivo devices especially) — a
user may need to manually exempt the app from battery optimization /
disable "auto-start management" restrictions for fully reliable delivery
with the app swiped away. The only way to remove this caveat entirely is
a real push-notification backend (Firebase Cloud Messaging), which this
project doesn't have set up — worth a future session if background
reliability keeps being a problem after this.

### 🟡 Not build-verified
Standard sandbox caveat — no Android SDK here. Retest should specifically
check: (1) new-order alert sound/vibration/notification with the app
**fully closed** (swiped from recents), not just backgrounded, (2) the
POST_NOTIFICATIONS permission prompt actually appears on first login
(Android 13+ devices only), (3) tapping the alert notification opens the
right screen.

---

## Session update (2026-08-18, earlier) — App-owner testing round: address delete FK bug (found + fixed) + restaurant Accept/sound hardening (unconfirmed root cause)

### ✅ Customer app — real root cause found: address delete FK constraint

App owner reported: newly-added test addresses delete fine, but **any
older address, or whichever address is left last, fails with "Network
error."** This wasn't a client bug — every AddressBookActivity code path
was re-read line by line and was already correct from the earlier
session's fix. The actual cause was in the schema the whole time:

```sql
CONSTRAINT fk_order_address FOREIGN KEY (delivery_address_id) REFERENCES customer_addresses(id)
```

No `ON DELETE` clause on this FK (01_schema.sql) — InnoDB's default is
RESTRICT, so MySQL refused the DELETE with error 1451 for any address
ever referenced by a real order. `customer/addresses.php`'s DELETE
handler had no try/catch, so that PDOException went uncaught — no JSON
response body at all — which is what made the Android client's own
generic `catch (e: Exception)` show a plain "Network error" with no real
explanation. A freshly-added test address has no order referencing it
yet, so it always deleted fine — which is exactly why this looked like
it only affected "old" addresses.

**Fixed:**
- **`backend/sql/26_migration_address_delete_fk_fix.sql`** — drops and
  recreates `fk_order_address` with `ON DELETE SET NULL` instead of the
  implicit RESTRICT. Confirmed safe: grepped every backend endpoint for
  `delivery_address_id` — nothing anywhere currently reads it back out
  for display (order detail/history, restaurant order screens all render
  fine without it), so NULLing it out on old orders breaks nothing today.
  (Worth flagging separately, not fixed here: that also means no screen
  currently shows a customer's delivery address *to the restaurant* at
  all — a real, pre-existing gap, different bug.)
- **`backend/api/v1/customer/addresses.php`** — DELETE handler now wraps
  its `execute()` in try/catch as defense-in-depth, returning a clean
  `address_delete_failed` JSON error instead of a raw crash if any other
  DB issue ever hits this in the future.

**⚠️ Migration 26 must be run against the live DB before this is fixed
live** — same as every other migration, pushing to GitHub does not run
it automatically.

### 🟡 Restaurant app — Accept dialog / new-order sound: root cause NOT confirmed, hardened as a precaution

App owner reported: sent a genuine new order in real time, got **no
sound, no vibration**; tapping **Accept never showed the prep-time
dialog**. Every line of `OrdersFragment.kt`, `OrderAdapter.kt`,
`OrderDetailActivity.kt`, `PrepTimeDialog.kt`, `NewOrderAlertSound.kt`,
`MainActivity.kt`, and the layout XML was re-read — unlike the address
bug, **no concrete root cause was found this pass**. Two real,
plausible-but-unconfirmed risk points were hardened defensively instead
of leaving them as-is:

1. **`PrepTimeDialog`** used `AlertDialog.setSingleChoiceItems()`, which
   (unlike the app's other existing dialog, `promptRejectReason`'s plain
   `setView()`) inflates Android's internal single-choice list-item
   layout — a real, documented pitfall where custom app themes can fail
   to render/inflate it correctly on some devices, with no clean
   exception path if the click listener itself isn't wrapped in a
   try/catch. Switched to `setItems()` — the most basic, theme-agnostic
   list mode AlertDialog has, tap-one-and-go instead of select-then-
   confirm (also one less tap).
2. **`NewOrderAlertSound`** only alerts via the alarm audio stream +
   vibration — both are entirely device-setting-dependent (alarm volume
   muted, DND blocking vibration) and can fail **completely silently**,
   no exception, nothing to catch. Added a Toast banner ("New order
   received") alongside it in `OrdersFragment.loadNew()` — Toasts have no
   equivalent silent-failure mode, so this is a guaranteed-visible signal
   to isolate whether the *detection* logic is firing at all, independent
   of whether the phone's audio/vibration settings allow sound/vibration
   through.
3. Cleaned up sloppy fully-qualified inline class references
   (`com.anydrop.restaurant.ui.common.NewOrderAlertSound.play(...)` etc.)
   into proper top-of-file imports across all three files — cosmetic,
   not a functional fix, but worth doing while in there.

**This is explicitly NOT a confirmed fix** — unlike the address bug,
there's no smoking gun here yet. Next real-device retest should
specifically report: does the new Toast banner appear when a new order
arrives (isolates detection-logic vs. alert-delivery)? Does tapping
Accept do *anything* now (even just opening the dialog, regardless of
sound)? If both are still silent after this, the bug is somewhere this
review hasn't found yet and needs a logcat from the actual device, not
another round of static code reading.

---

## Session update (2026-08-18, earlier) — First-ever real Gradle build results (from GitHub Actions logs)

This is the first time either app has actually gone through a real Gradle
build — every prior session's "🟡 not build-verified" caveat only meant
"never confirmed", not "confirmed working". App owner uploaded the
Actions run logs (`Build Customer App`, `Build Restaurant App`).

### ✅ Customer app — `BUILD SUCCESSFUL in 3m 38s`, 36 tasks, 0 errors
Only pre-existing deprecation/unused-variable warnings (`requestSingleUpdate`
deprecated, a couple unused locals in `RateOrderDialog.kt` /
`ApiClient.kt`) — none new, none blocking. **First confirmed-working
build of the Customer app in this project's history.**

### ❌ Restaurant app — `BUILD FAILED in 3m 7s`, 1 Kotlin compile error
```
e: OrdersFragment.kt:183:36 Type mismatch: inferred type is Set<Int> but MutableSet<Int>? was expected
```
Caused by this same session's earlier "loud sound on new order" addition
— `knownNewOrderIds` was declared `MutableSet<Int>?` but every write to
it is a full reassignment (`knownNewOrderIds = currentIds`, where
`currentIds` comes from `.toSet()`, an immutable `Set`), never an
in-place mutation. **Fixed** — field retyped to `Set<Int>?` (its actual
usage never called `.add()`/`.remove()`, so this is a pure type fix, no
behavior change). This was the *only* error in the whole log — nothing
else to fix from this run.

### ⏭️ Next
Re-run the Restaurant app build to confirm this one-line fix is actually
enough (only checked by reading the log, not by running Gradle myself —
still no Android SDK in this sandbox). If that comes back green too,
this is the first time *both* apps will have a real confirmed-working
build.

---

## Session update (2026-08-18) — Admin Panel: first real screen, "Approve/Reject pending restaurants"

Resumes `docs/19_Admin_Panel_Full_Spec_And_Payment_Email_Architecture_2026-08-14.md`
— that whole doc was "planning only, nothing built" until now. Picked the
one item flagged as overdue since 2026-08-14 across multiple session
handovers (`docs/18` build order, `docs/restorent/NEXT_SESSION_PROMPT.md`):
self-signup (`auth/restaurant-signup.php`) has been producing
`restaurants.status = 'pending'` rows this whole time with **no way to
approve or reject one except a manual DB UPDATE**.

### What was built
- **`backend/admin/`** — a plain server-rendered PHP admin UI
  (`_bootstrap.php`, `login.php`, `logout.php`, `index.php`). Deliberately
  **session-based, not Bearer-token** — this isn't a new architectural
  choice, it's doc 02 §6's own heading verbatim: *"Admin Panel (web,
  session-auth instead of Bearer token since it's server-rendered)"*. No
  JS build step, nothing to install — works by pointing a browser at
  `/admin/login.php`, logging in with the same `admins` table credentials
  `scripts/seed-admin.php` already creates.
- `index.php` lists every `pending` restaurant (oldest first) with owner
  name/mobile/email/address/GST/FSSAI, an **Approve** button, and a
  **Reject** button that expands a required-reason textarea inline. Both
  actions write to `write_audit_log('admin', ...)` — the same audit trail
  every other sensitive action in the codebase uses.
- New migration **`backend/sql/25_migration_restaurant_rejection_reason.sql`**
  — adds `restaurants.rejection_reason TEXT NULL` (didn't exist; `status`
  already had `'rejected'` as a valid value with nowhere to record why).
  Idempotent CONTINUE-HANDLER-for-1060 pattern, same as
  `11c_fix_item_customization_safe.sql` — safe to run any number of times.
- New `.htaccess` note: none needed for `/admin/*` — these are plain
  `.php` files accessed directly (`/admin/login.php`, `/admin/index.php`),
  not routed through the `api/v1/*` rewrite rules.

### Explicitly NOT built this pass (kept in scope, not silently dropped)
- `suspend` / `/activate` for restaurants already past `pending` — doc
  02 §6 lists these alongside approve/reject but they're a different
  screen (acting on an *already-approved* restaurant, not a new
  applicant) — deferred.
- Full RBAC (doc 19 §1 — `admin_roles`/`admin_permissions` tables, named
  roles, per-module permission grid) — still planning-only. This screen
  only checks "is *some* valid admin logged in" (`admin_require_login()`),
  same scope as everything else in the codebase today (`admins.role` flat
  ENUM, unchanged).
- No JSON `/api/v1/admin/*` endpoints were added — doc 02 explicitly
  scoped Admin Panel as session-auth web-only; a Bearer-token JSON layer
  would only matter for a future SPA/native admin app that doesn't exist
  and isn't being built yet, so adding one now would just be an
  unused, unmaintained second auth path for the same actions.

### 🟡 Not verified live
- Migration 25 needs to run against the real DB before `rejection_reason`
  exists.
- `seed-admin.php` needs to have actually been run (and then deleted, per
  its own instructions) on the live server for `/admin/login.php` to have
  any credentials to check against — can't confirm from here whether
  that's already been done.
- Never tested against a real PHP session/cookie flow outside this sandbox
  (no way to run a PHP server + browser here) — logic was written to
  match this codebase's existing patterns (`lib/response.php`,
  `lib/audit.php`) as closely as possible, but a first real-server pass
  should specifically check: login → cookie persists across pages →
  approve/reject actually updates the DB → CSRF token doesn't break on a
  second form submit after one action.

### ⏭️ Next
Doc 19 §14 / `docs/restorent/NEXT_SESSION_PROMPT.md`'s build order:
Coupon system → Notification bell → Reviews reply → Settings
(GST/FSSAI/language/dark mode) → Payments/Settlement → Analytics → Staff
Management → Rider App last.

---

## Session update (2026-08-13) — I4 follow-ups + "pause taking orders" toggle. **Built, NOT tested, NOT built with Gradle.**

Both parts of `docs/16_Handover_I4_Followups_And_Order_Toggle.md` are now built:

**Part A — I4 leftovers:**
- Customer App's `OrderStatusActivity` now shows "Scheduled for h:mm a"
  for a scheduled order (one-shot fetch via `GET /orders/{id}`, since the
  5s tracking poll's `OrderTrackResult` doesn't carry `scheduled_for`).
  Factored the duplicated date-format pattern out of
  `RestaurantDetailActivity`/`CheckoutActivity` into a shared
  `util/ScheduledTimeFormatter` while there.
- Restaurant App's `Order` model now has `scheduledFor`; surfaced as a
  "Scheduled for h:mm a" badge on both the order list card (`OrderAdapter`)
  and order detail screen (`OrderDetailActivity`), with its own copy of
  the same formatter util (separate Gradle module).

**Part B — "Accepting orders" toggle:**
- New `POST /restaurant/status-update.php` (restaurant auth) — restricted
  to `open`/`busy`/`temp_closed` (excludes the admin-only/long-term enum
  values).
- `lib/orders.php`'s `price_cart()` now rejects with
  `restaurant_not_accepting_orders` (422) whenever `operational_status !==
  'open'` — shipped together with the endpoint per the handover doc's own
  requirement, and covers `orders/create.php` + `cart/validate.php` both
  since they share `price_cart()`.
- `GET /restaurant/dashboard` now also returns `operational_status`, used
  to initialize the new "Accepting orders" switch on `DashboardActivity`.
- **Follow-up done this session too:** Customer App now shows a distinct
  "Temporarily unavailable" (amber) badge instead of a plain "Closed" (red)
  when a restaurant is on-demand paused rather than simply outside its
  fixed hours — `restaurants/list.php` and `search.php` both now return
  `is_paused`, consumed by `RestaurantAdapter` (Home) and
  `SearchResultsAdapter` (Search). Still dims the card either way (can't
  order regardless); only the label/color differ.

**Still open, deliberately deferred (not decided, not oversights):**
- Whether a paused restaurant should show customers a reason/ETA message
  (e.g. "Kitchen too busy, back around 8 PM") — shipped as plain ON/OFF
  with no message per the handover doc's own recommendation.

**Not done this session:** no Gradle build or on-device test of any of
the above — next actual step is a build+test pass before calling this done.

## Older open thread, still unresolved — H6 Google Maps key decision
(carried over from 2026-08-10, unrelated to the above) Location Picker /
map pin-drop screen still needs a real Android-restricted Maps key —
Google Cloud billing isn't set up. Two options still on the table: (a) get
billing sorted and generate real keys, or (b) do a build-only sanity pass
now (map renders blank/grey, expected). See
`docs/12_Handover_H6_Map_PinDrop_Photo.md`.

---

## Session update (2026-08-10, H6 part 1) — Location Picker screen (screenshot 12). **Built, NOT tested, NOT built with Gradle.**

New `LocationPickerActivity` (saved addresses + distance, tap-to-activate,
current-location row, add-address entry), wired into `HomeActivity`'s
location bar and given `AddressBookActivity`'s missing "tap card to
activate" behavior. Decided **OpenStreetMap (osmdroid + Nominatim), not
Google Maps SDK** for the still-to-come map pin-drop screen — no billing
account or API key needed. See `docs/12_Handover_H6_Map_PinDrop_Photo.md`
for the full handover — what's built, what's left (map pin-drop screen +
photo upload), and exact next steps.

---

## Session update (2026-08-10, H5 build+test) — "View all offers & coupons" page on Checkout. **✅ Confirmed working on-device.**

**User confirmed (2026-08-10):** `CheckoutActivity.kt` wiring done, two
build-blocking Kotlin errors found and fixed (`rowViewAllOffers` binding
from a stale layout copy on first push; `InAppNotifier.show()` passed
`Context` instead of `Activity?` in `CouponsListBottomSheetFragment`),
Gradle build green, and the full feature tested on a real device:

- "View all offers & coupons" row opens the sheet from Checkout.
- Coupon list loads (restaurant-specific + platform-wide).
- Tapping an eligible coupon fills the code box, applies it, bill updates.
- Ineligible coupon (below `min_order_amount`) renders dimmed with
  "Add ₹X more to unlock", not tappable.
- Already-applied coupon shows the check-circle state, not re-tappable.
- Empty state renders correctly when no coupons exist for the restaurant/platform.

H5 is now ✅ Done in `docs/features.md`. See `docs/11_Handover_H5_Coupons_Page.md`
for the original build notes.

---

## Session update (2026-08-10, H5) — "View all offers & coupons" page on Checkout. **Backend + UI pieces built, final wiring NOT done. NOT tested, NOT built.**

See `docs/11_Handover_H5_Coupons_Page.md` for the full handover — what's
built, what's left (one method in `CheckoutActivity.kt`), and exact next
steps to finish and test this feature.

---

## Session update (2026-08-10) — Build + on-device test CONFIRMED. Item detail sheet, dish customization, and rating system all marked DONE.

**User confirmed (2026-08-10):** a real Gradle build has now been run and
the app tested on-device. This supersedes every earlier "not yet tested on
a device" / "Gradle build hasn't happened" caveat scattered through this
file for the following, which are now all ✅ confirmed working:

- **Item detail bottom sheet tap-wiring** (bug tracker 1.9) —
  `ItemDetailBottomSheetFragment` opens from dish-card taps on Restaurant
  Detail (`MenuAdapter`), Home Popular row (`PopularItemsAdapter`), and
  Search (`SearchResultsAdapter`). Marked ✅ RESOLVED in
  `docs/07_Phase_3.7_Bug_Tracker.md`.
- **Full dish customization sheet** (bug tracker 2.6, Phase D item 10) —
  addon groups, quantity stepper, cooking notes, sticky Add button. Marked
  ✅ RESOLVED / ✅ DONE in `docs/07_Phase_3.7_Bug_Tracker.md`.
- **features.md §1** — Rating sort toggle / Filters-and-Sorting bottom
  sheet. Marked ✅ Done in `docs/features.md`.
- **Part 13 — Rating system** (see dedicated section further below) —
  order rating (restaurant/food/delivery stars), auto-prompt on delivery,
  manual "Rate Order" in Order History, restaurant `rating_avg`/
  `rating_count` auto-recalculation.

**Still open / unconfirmed** (not covered by this session's build+test pass
— see "Known Limitations" below for the full list): Address Book "make
active" tap, initial-resolution GPS prompt for zero-address accounts, cart
`addon_ids` client-side JSON-encoding, restaurant app reviews/reply UI,
`is_bestseller`/`discount_percent`/`is_spicy`/`is_kids_choice` admin
toggles, shimmer/skeleton loaders (intentionally deferred to last).

---

## Session update (2026-08-09, part 10) — Home now sends lat/lng; "active delivery address" concept added. **Not yet tested on-device.**

**Why:** part 9 closed the loop server-side (`restaurants/list.php` excludes
restaurants outside their `delivery_radius_km`) but flagged that
`HomeActivity.kt` never sent `lat`/`lng` and that there was no "currently
selected delivery address" concept anywhere in the app. This session builds
that missing piece.

**✅ Done this session:**
- **New `data/ActiveAddressManager.kt`** — SharedPreferences-backed cache
  (same pattern as `VegModeManager`) holding the current delivery address's
  id/label/short text/lat/lng. Not a new backend concept — `Address` already
  had `is_default`/`latitude`/`longitude`, this just remembers on-device
  which one Home should be using so every screen doesn't refetch and re-pick
  it. Null/unset is a valid state (no address saved yet) and is handled as
  "browse unfiltered," not an error.
- **`HomeActivity.kt`:**
  - On `onCreate`, resolves the active address before the first restaurant
    load: uses the cached one if present, otherwise calls `getAddresses()`,
    picks the account's `is_default` address (falls back to the first
    address if none is flagged default), caches it via
    `ActiveAddressManager.set()`, and updates the location bar text.
  - `loadRestaurants()` now passes `ActiveAddressManager.get()`'s lat/lng
    into `api.getRestaurants(lat=, lng=, ...)` — this is the actual fix
    part 9 was missing. Every existing call site of `loadRestaurants()`
    (filter chips, category switch, pull-to-refresh, service-area retry
    button) picks this up automatically since they all funnel through the
    same function.
  - Tapping the location bar still opens `AddressEditorBottomSheet` as
    before, but `onSaved` is no longer a no-op — it now re-resolves the
    active address (`forceRefresh = true`) so a freshly saved/edited address
    (which becomes the new server-side default) actually updates the
    location bar and re-filters the restaurant list, instead of silently
    doing nothing like before this session.
  - A logged-in account with zero saved addresses still sees the exact
    pre-part-9 behaviour — `loadRestaurants()` with null lat/lng, nothing
    filtered out — same "don't hide behind an unresolved fix" stance as the
    rest of this file.
- **`strings.xml`** — added `delivery_location_placeholder` (moved the
  previously-hardcoded "Delivering to your location" out of
  `activity_home.xml` into a string resource, same text, no visual change
  for the no-address case).

**❌ NOT done — deliberately out of scope this session:**
- **`AddressBookActivity` has no way to switch which saved address is
  "active."** New addresses become default automatically (existing
  `AddAddressBody` behavior), and editing keeps the existing default state,
  but there's no "make this one active" tap target on a *non-default*
  saved address card yet. Today the only way to change the active address
  is the location-bar editor (which always saves/edits, i.e. always becomes
  default). Real "tap a saved address to make it active without editing it"
  needs its own small session — likely a tap handler on `AddressAdapter`'s
  card body plus a `PUT` to flip `is_default`, since there's no dedicated
  "set default" endpoint yet, only default-via-add/edit.
- No GPS-not-enabled prompt reuse for the *initial* Home resolution path —
  if `getAddresses()` returns nothing (brand new account), Home just falls
  back to unfiltered browsing silently. Prompting the user to add an
  address (or use current location) proactively on first Home open is a
  reasonable next add but wasn't asked for this session and would touch
  first-run UX, so left alone.
- **Not tested on-device** — no DB/build access from this environment.
  Needs: an account with a saved address that has real lat/lng (part 8's
  Osian relocation script already gives restaurants real lat/lng), device
  GPS or a manually-entered address near Osian, then confirm the location
  bar shows the picked address, the restaurant list actually narrows when a
  far-away address is set, and the "not available in your area yet" screen
  still triggers correctly when literally no restaurant is in range (should
  reuse `setServiceAreaUnavailable()`, untouched this session — worth
  double-checking it still reads right now that `list.php` can legitimately
  return zero results for a real reason, not just an unserved account).

---

**Last Updated:** 2026-08-09 (part 9)

## Session update (2026-08-09, part 9) — delivery-radius filtering fix. **On-device confirmed working.**

**Why:** user asked for admin-configurable delivery distance (default 5km) —
restaurants outside a customer's radius should be hidden, with the
existing "We're not available in your area yet" screen showing instead.
Turned out `delivery_radius_km` already existed in the schema (default
5.0) and the "service area unavailable" empty state was already fully
built in `HomeActivity.kt`/`strings.xml` — neither piece was ever wired
together.

**✅ Done this session:**
- **`backend/api/v1/restaurants/list.php`** — now excludes a restaurant
  from results when `distance_km` (computed from `?lat=&lng=`) exceeds
  that restaurant's own `delivery_radius_km` (falls back to 5.0 if null).
  Only enforced when both sides' coordinates are known — a restaurant
  with no lat/lng, or a request with no `lat`/`lng`, is still shown
  rather than incorrectly excluded (same "don't hide behind an
  unresolved fix" stance as the rest of this file).

**✅ Confirmed on-device this session** (user tested against a real
restaurant, "The Roll 'A' Wrap"): checkmark-style tags (`Near & Fast`,
`No packaging charges` — light-green pill + checkmark icon, part 7's
restyle) render correctly on the detail screen; the location row shows
`13.8 km · Near Kali Devi Mandir` (part 8's Osian relocation + part 6's
locality parsing both confirmed reading correctly); ETA row shows
`70 mins`; the `2 offers` strip renders and is tappable. This closes out
part 6's two long-standing "judgment call" items (locality-from-address
parsing, distance/ETA display) as confirmed-good.

**❌ Found but NOT done — real gap, next session's scope:**
- **`HomeActivity.kt`'s `loadRestaurants()` never sends `lat`/`lng` to
  `getRestaurants()` at all** — so today's fix above has nothing to
  filter against on the actual Home screen. There's also no "currently
  selected delivery address" concept anywhere in the app — the address
  book (`AddressBookActivity`, `AddressEditorBottomSheet`, saved
  addresses) exists and can save/edit addresses with lat/lng, but nothing
  reads an "active" one back into the restaurant list call. This is the
  real missing piece behind the user's "Zomato-type location" ask
  (reference: real Zomato screenshots showing select-address → filtered
  restaurants → "not available here" flow) — needs its own session:
  Home header "current address" display, address selection driving
  `loadRestaurants(lat, lng)`, GPS-not-enabled prompt reuse from the
  existing `fetchCurrentLocation()` pattern.
- Not tested against a real DB from this environment, same caveat as
  every other backend change in this project — logic reviewed carefully,
  not run.

---

**Last Updated:** 2026-08-09 (part 8)

## Session update (2026-08-09, part 8) — test data: relocate all restaurants to Osian, Jodhpur.

**Why:** GitHub Actions build failed on part 7's push — root cause was part
5/6's changes (`ApiService.kt` lat/lng params, new detail-screen views/
strings) never actually landed on GitHub, only part 7's 2-file diff did.
Separately, once the full source was in sync, distance/ETA still wasn't
visible on-device because every test restaurant has null/placeholder
lat-lng — nothing for `menu.php`'s `haversine_km()` to compute against.

**✅ Done this session:**
- **New `backend/scripts/update-test-restaurant-locations.php`** — assigns
  every restaurant a real Osian, Jodhpur landmark (New Bus Stand,
  Government Hospital, Sachiya Mata Mandir, Railway Station, Osian Fort,
  Mahavir Circle, Kali Devi Mandir, Osian Bypass Road, Government School,
  Surya Mandir, Police Station, Sand Dunes Point), round-robin by
  restaurant id, deterministic re-runs. `address` written as
  `"Near <Landmark>, Osian, Jodhpur"` — deliberately matches
  `RestaurantDetailActivity.kt`'s `address?.substringBefore(",")` locality
  parse (part 6's judgment call #1) so that logic now has real data to be
  checked against. Gated behind `?key=SEED_ME`, same convention as
  `auto-update-bestseller-discount.php`.
- **Not run yet** — no DB access from this environment; needs to be run
  from the phone browser like the other scripts, then verified on-device
  that the distance/locality text reads correctly.
- **Caveat flagged to user:** restaurants now sit in Osian, Jodhpur:
  device's actual GPS location needs to be near there too (or mocked) for
  distance/ETA to show small, realistic values instead of a real (likely
  large) number.

---

**Last Updated:** 2026-08-09 (part 7)

## Session update (2026-08-09, part 7) — features.md §6: checkmark-chip restyle. **Item 1 from part 6's handover closed.**

**Scope:** picking up item 1 from part 6's "NOT done yet" list — the
"✓ Frequently reordered" / "✓ No packaging charges" checkmark-on-light-
green-pill restyle for `detailTagsGroup` chips, confirmed against
`docs/screenshots/09_restaurant_header_filters_pureveg_offers.jpg`
(both remaining tags — `frequently_reordered`, `no_packaging_charges` —
get the checkmark treatment; `pure_veg` was already filtered out
upstream in part 6, so no per-slug branching was needed here).

**✅ Done this session:**
- **`RestaurantDetailActivity.kt`, `bindRestaurantDetail()`:** the
  `detailTagsGroup` chip-building loop now uses `R.drawable.ic_check_circle`
  as `chipIcon` (tinted `success_fg`) and `R.color.success_bg` /
  `R.color.success_fg` for background/text instead of the old
  `anydrop_primary_container` / `anydrop_primary` plain style — reusing the
  same color pair already used for the "Highly reordered" pill on menu
  item cards (`item_menu_item.xml`, `item_popular_dish.xml`) rather than
  introducing new colors. No new drawables/colors needed — both
  `ic_check_circle` and `bg_pill_highly_reordered`'s underlying
  `success_bg`/`success_fg` combo already existed, unused, exactly as
  part 6 flagged.
- Binding cross-check re-done for this one method — no new ids
  introduced, only existing `binding.detailTagsGroup` chip-build code
  touched.

**❌ Still open (unchanged from part 6, not touched this session):**
- Item 2 — the two judgment calls (locality-from-address parsing,
  `etaMinutes`-over-intent-extra precedence) are still unconfirmed against
  a real device.
- Item 3 — still never built/run/Kotlin-compiled in this environment (no
  Android SDK here); this change is "should compile," not "confirmed
  compiles," same caveat as everything else in this project.
- Item 5 — the possible GPS-resolve "flash" on second `loadMenu()` call
  is still untested on-device.

**Migration order:** unchanged — `14_migration_restaurant_offers_and_tags.sql`
before `auto-update-bestseller-discount.php`.

---

## Session update (2026-08-09, part 6) — features.md §6 continued: Activity + Offers sheet built. **STILL IN PROGRESS — not finished, see handover below.**

**Scope:** picking up exactly where part 5 left off (see that entry directly
below this one for full backend/model/layout context — not repeated here).

**✅ Done this session:**
- **`RestaurantDetailActivity.kt`:**
  - New `resolveLocationThenLoad()` / `fetchCurrentLocation()` /
    `onLocationResolved()`, mirroring `HomeActivity`'s existing GPS pattern
    (permission check via `ActivityResultContracts.RequestPermission`,
    `LocationManager` last-known-location first, single-update fallback) —
    but non-blocking and silent: no toast on permission denial or provider
    unavailability, since `loadMenu()` already rendered the screen without
    lat/lng before this even starts.
  - `onCreate()` now calls `loadMenu()` immediately, then
    `resolveLocationThenLoad()` right after — location resolution runs in
    parallel, and `onLocationResolved()` silently re-calls `loadMenu()` a
    second time once (if) a fix lands, now with `resolvedLat`/`resolvedLng`
    passed to `getMenu()`.
  - `bindRestaurantDetail()` extended:
    - `detailPureVegRow` shown/hidden off `restaurant.isVegOnly`.
    - `detailLocationRow`/`detailLocationText` filled via
      `detail_distance_format` when `distanceKm` is non-null, row hidden
      entirely otherwise. Locality text is
      `restaurant.address?.substringBefore(",")` — **flagging this as a
      judgment call**, not explicitly specified; worth confirming it reads
      right against real address data once this is on a device.
    - `detailEtaRow`/`detailEta` filled via `detail_eta_format`;
      `restaurant.etaMinutes` (this screen's own GPS-resolved value) wins
      over the old `EXTRA_ETA_MINUTES` intent extra when both are present —
      **also a judgment call**, flagged in part 5's handover as needing a
      decision, resolved here but worth a second look.
    - `detailEtaRow` click → `InAppNotifier.show(..., R.string.coming_soon,
      ...)` per the "Schedule for later" stub.
    - `detailTagsGroup` now filters out `slug == "pure_veg"` before building
      chips (avoids double-showing with the new dedicated badge). **Not
      done:** the screenshot's "✓ Frequently reordered" checkmark restyle
      (`ic_check_circle` on `bg_pill_highly_reordered`) — chips still use
      the old plain style. Left alone this session to keep scope to the
      binding work; both drawables already exist and are unused, ready
      for whoever picks this up.
    - `detailOffersDivider`/`detailOffersStrip`/`detailOffersCount`
      shown/hidden off `restaurant.offers`, count text via
      `detail_offers_count_format`, click opens the new
      `OffersBottomSheetFragment` with the offers list.
- **New `OffersBottomSheetFragment.kt`** — same lightweight pattern as
  `MenuFiltersBottomSheet` (Gson-serialized `List<RestaurantOffer>` through
  the args `Bundle`). No footer (nothing to "apply," it's a viewer only).
  Renders rows via a plain `LinearLayout` + inflated `ItemOfferRowBinding`
  per offer rather than a RecyclerView — the list is always short (offers
  are seeded 1–3 per restaurant), so a RecyclerView felt like unneeded
  ceremony for this one.
- **New `fragment_restaurant_offers.xml`** — same drag-handle/title/close-icon
  shell as `fragment_menu_filters.xml`, no sticky footer.
- **New `item_offer_row.xml`** — title (bold) + description (grey) pair,
  plus a top-border `View` (`offerRowDivider`) between rows, hidden on the
  first row. (`fragment_menu_filters.xml`'s footer button used
  `android:divider`/`showDividers` conventions that need a divider
  *drawable*, not a raw color — this codebase doesn't have one, so a
  per-row border `View` was used instead, matching the
  `detailOffersDivider` convention already used on the header itself.)
- **Binding cross-check done** (step 3 of the plan) — every `binding.<id>`
  in the updated `RestaurantDetailActivity.kt` matches an id in
  `activity_restaurant_detail.xml` with none missing/unused either
  direction; same check done for `OffersBottomSheetFragment.kt` against
  both its new layout files. All `@string`/`@color`/`@drawable` references
  in the two new layout files resolve to something that exists.

**❌ NOT done yet — exactly where the next session picks up:**
1. ~~"✓ Frequently reordered" chip restyle~~ — **done in part 7, see that
   entry above.**
2. **Two flagged judgment calls above** (locality-from-address parsing,
   `etaMinutes`-over-intent-extra precedence) — implemented but not
   confirmed against user preference or a real device. Not blocking, but
   don't treat either as settled without a second look.
3. **Not built/run on a device or even Kotlin-compiled** — no Android SDK
   available in this environment, same caveat as every other session in
   this project. XML was hand-validated (every `@id`/`@string`/`@color`/
   `@drawable` reference traced to something that now exists, confirmed via
   grep cross-check) and the Kotlin was written/reviewed carefully but
   never run through a real compiler — treat as "should compile," not
   "confirmed compiles," until it's actually built.
4. **`AndroidManifest`** — still no change needed (confirmed again this
   session, `ACCESS_FINE_LOCATION`/`ACCESS_COARSE_LOCATION` already present
   from before).
5. Once this is actually on a device: confirm the non-blocking GPS flow
   doesn't cause a visible "flash" when the second `loadMenu()` rebinds
   (category tab bar rebuild + adapter resubmit both run again — should be
   cheap/invisible per `applyFiltersAndSubmit()`'s existing idempotency, but
   untested).

**Migration order for whoever deploys this mid-session:** unchanged from
part 5 — run `14_migration_restaurant_offers_and_tags.sql` before
re-running `auto-update-bestseller-discount.php`.

---

## Session update (2026-08-09, part 5) — features.md §6: Restaurant detail header parity pass. **IN PROGRESS, not finished — see handover below.**

**Scope:** features.md's suggested session order — §6 is next after §3+§4
(part 1) and §1 (part 3+4) shipped. User explicitly chose the bigger-scope
option on both open questions asked at the start of this session:
- Offer strip ("3 offers ⌄") → build a **real `restaurant_offers` table +
  migration**, not just restyle the existing single `offer_badge_text`.
- Distance ("2.7 km · Sardarpura") → **wire actual GPS fetch on this screen**
  (RestaurantDetailActivity), not leave it permanently null like Home does.

**✅ Done this session:**
- **New migration:** `backend/sql/14_migration_restaurant_offers_and_tags.sql`
  — `restaurant_offers` table (id, restaurant_id FK, title, description,
  sort_order, is_active) + two new `restaurant_tags` rows
  (`frequently_reordered`, `no_packaging_charges`) reusing the existing
  generic tags mechanism from migration 05, not new boolean columns.
- **New:** `backend/lib/geo.php` — `haversine_km()` extracted out of
  `restaurants/list.php` (was a private function at the bottom of that file)
  so `restaurants/menu.php` can reuse the exact same distance calc.
- **`restaurants/list.php`** — now `require_once`s `lib/geo.php` instead of
  defining `haversine_km()` locally. Behavior unchanged.
- **`restaurants/menu.php`** — accepts optional `?lat=&lng=` (same
  null-until-resolved contract as `list.php`), computes `distance_km` /
  `estimated_delivery_minutes` (same 15-plus-4-per-km placeholder formula,
  no OSRM yet — that's Phase 4), and returns the restaurant's active
  `restaurant_offers` rows as `offers: [{id, title, description}]`.
- **`backend/scripts/auto-update-bestseller-discount.php`** — extended with:
  - **Step 5 (offers):** seeds 1–3 demo offers from a fixed text pool for
    any restaurant with **zero** existing offer rows — deliberately
    **additive**, not a full recompute like every other step, so it never
    wipes out a real offer added later once an actual admin control exists.
  - **Step 6 (restaurant tags):** randomly assigns `frequently_reordered` /
    `no_packaging_charges` to a configurable fraction of restaurants, fully
    reset + reassigned every run (same "full recompute" philosophy as
    bestseller/discount/spicy/kids). Skips cleanly with a printed note if
    migration 14 hasn't run yet, same backward-compat pattern `menu.php`
    already uses for `is_spicy`/`is_kids_choice`.
  - New optional params: `&offer_count=`, `&frequently_reordered_ratio=`,
    `&no_packaging_ratio=` — documented in the file's own header comment.
- **`network/Models.kt`** — `RestaurantDetail` gets `distanceKm`,
  `etaMinutes` (both nullable, null until a GPS fix resolves), and `offers:
  List<RestaurantOffer>?`. New `RestaurantOffer(id, title, description)`
  data class.
- **`network/ApiService.kt`** — `getMenu()` now takes optional `lat`/`lng`
  query params (both default null — old call sites/behavior unaffected).
- **New drawables:** `ic_bolt.xml` (ETA lightning icon), `ic_chevron_down.xml`
  (expandable-row chevron, reused for both the ETA row and the offers
  strip), `bg_rating_pill.xml` (solid `anydrop_secondary` green, for the new
  rating pill).
- **`strings.xml`** — `detail_pure_veg_badge`, `detail_distance_format`,
  `detail_eta_format`, `detail_offers_count_format`, `offers_sheet_title`.
- **`activity_restaurant_detail.xml`** — info card rebuilt to match the
  reference screenshot (`09_restaurant_header_filters_pureveg_offers.jpg`):
  - Pure Veg badge (`detailPureVegRow`) — reuses the app's existing veg
    square-and-dot icon (`bg_badge_veg`, same one used per-item) instead of
    a new leaf icon; driven by `restaurant.isVegOnly` directly, not the
    generic tags list, to avoid double-showing if a `pure_veg` tag is also
    mapped on that restaurant.
  - Name + rating pill (`detailRatingPillGroup`) restyled as a solid green
    pill (★ rating) with "By N+" stacked underneath, replacing the old
    plain star-icon-plus-text row.
  - New `detailLocationRow` (pin icon + distance/address) and
    `detailEtaRow` (lightning icon + ETA + "Schedule for later" + chevron)
    — both `gone` by default, shown from code once there's data.
  - `detailTagsGroup` kept (existing chip mechanism) but now expected to
    have `pure_veg` filtered out in code (not done yet — see handover).
  - New `detailOffersDivider` + `detailOffersStrip` ("N offers ⌄") — `gone`
    by default, shown when `restaurant.offers` is non-empty.

**❌ NOT done yet — exactly where the next session picks up:**
1. **`RestaurantDetailActivity.kt` is not updated at all yet.** None of the
   new views above are bound to real data — the activity still calls the
   old 2-arg-shaped `bindRestaurantDetail()` and doesn't fetch GPS. Needs:
   - A `resolveLocationThenLoad()` flow mirroring `HomeActivity`'s existing
     `fetchCurrentLocation()`/`onLocationResolved()` pattern (permission
     check via `ActivityResultContracts.RequestPermission`,
     `LocationManager` last-known-location first, single-update fallback)
     — but adapted so it does **not** block the initial menu load: call
     `loadMenu()` immediately without lat/lng so the screen renders fast,
     then once a location resolves, re-call `getMenu(id, lat, lng)` and
     rebind (the existing `applyFiltersAndSubmit()` re-submit is already
     idempotent, so a second silent fetch is low-risk/cheap — same
     "just re-fetch, it's cheap" spirit as `onStop()`'s cart sync).
   - `bindRestaurantDetail()` needs new code to:
     - Show/hide `detailPureVegRow` off `restaurant.isVegOnly`.
     - Fill `detailLocationRow`/`detailLocationText` using
       `getString(R.string.detail_distance_format, ...)` — hide the row
       entirely while `distanceKm` is null (don't show address without
       distance per the screenshot's combined line; fall back to plain
       `restaurant.address` some other way if that reads better — flag for
       user preference if ambiguous).
     - Fill `detailEtaRow`/`detailEta` using
       `getString(R.string.detail_eta_format, etaMinutes)` — hide row while
       both the old `EXTRA_ETA_MINUTES` intent extra AND the new
       `restaurant.etaMinutes` are unavailable. Decide which ETA source
       wins if both are present (intent extra was Home's old pre-GPS value;
       probably prefer `restaurant.etaMinutes` once resolved).
     - Wire `detailEtaRow`'s click → `InAppNotifier.show(this,
       getString(R.string.coming_soon), InAppNotifier.Type.INFO)` (the
       "Schedule for later" stub — not a real feature, see this session's
       Q&A).
     - Filter `restaurant.tags` to exclude `slug == "pure_veg"` before
       building `detailTagsGroup`'s chips (avoid double-showing with the
       new dedicated badge). Consider restyling those chips with a
       checkmark icon (`ic_check_circle`, tinted `success_fg`, on
       `bg_pill_highly_reordered`'s light-green pill) to match the
       screenshot's "✓ Frequently reordered" look — not done yet, current
       chip style is the old plain-chip one.
     - Show/hide `detailOffersDivider` + `detailOffersStrip` off
       `restaurant.offers.isNullOrEmpty()`; fill `detailOffersCount` via
       `getString(R.string.detail_offers_count_format, count, if (count ==
       1) "" else "s")`; wire its click to open the new
       `OffersBottomSheetFragment` (see next item — doesn't exist yet).
2. **`OffersBottomSheetFragment.kt` + `fragment_restaurant_offers.xml` don't
   exist yet.** Needs building from scratch — same lightweight pattern as
   `MenuFiltersBottomSheet`/`fragment_menu_filters.xml` (Gson-serialized
   `List<RestaurantOffer>` through the args `Bundle`, title + close icon at
   top, simple vertical list of title-bold/description-grey rows, no
   footer needed since there's nothing to "apply" here — just a viewer).
3. **AndroidManifest** already has `ACCESS_FINE_LOCATION`/
   `ACCESS_COARSE_LOCATION` (confirmed, no manifest change needed).
4. Not yet cross-checked: every new `binding.<id>` reference the eventual
   `RestaurantDetailActivity.kt` changes will need, against the actual ids
   now in `activity_restaurant_detail.xml` (same script-assisted method used
   in other sessions) — can't do this until step 1 above is actually
   written.
5. **Not built/run on a device or even PHP-linted** — no `php`/Android SDK
   available in this environment, same caveat as every other session. XML
   was hand-validated (well-formed, every `@id`/`@string`/`@color`/
   `@drawable` reference traced to something that now exists) but not
   compiled.

**Migration order for whoever deploys this mid-session:** run
`14_migration_restaurant_offers_and_tags.sql` before re-running
`auto-update-bestseller-discount.php`, same dependency-ordering note as
part 4's spicy/kids-choice migration.

---

## Session update (2026-08-09, part 4) — Apply button visibility bug fix + spicy/kid's-choice demo data

**Reported:** user screenshots showed the Filters sheet's "Apply (N)" button
text essentially invisible (blank in one screenshot, low-contrast in the
other) whenever the result count was 0 (i.e. whenever no items were flagged
`is_spicy`/`is_kids_choice` yet — expected right after part 3's migration,
before any items get flagged).

**Root cause:** `btnApplyFilters` (`fragment_menu_filters.xml`) had no
explicit `android:textColor`, and `binding.btnApplyFilters.isEnabled =
resultCount > 0` (in `MenuFiltersBottomSheet.kt`) triggered MaterialButton's
default disabled-state styling, which resolved to a text color too close to
the background to read.

**Fix:**
- **New:** `res/color/btn_apply_filters_bg.xml` — explicit background color
  state list (grey when disabled, `anydrop_primary` when enabled).
- **`fragment_menu_filters.xml`** — `btnApplyFilters` now uses that state
  list for `app:backgroundTint` and a constant `android:textColor="@color/
  white"` that no longer shifts with enabled/disabled state, so "Apply (N)"
  stays readable in both states instead of only showing correctly once N > 0.

**Also requested:** some demo items flagged spicy/kid's-choice so the
sheet's dietary chips have something to actually filter (both currently
default to 0 on every row per part 3's migration, no UI to set them yet —
see Known Limitations).

**What changed:** extended `backend/scripts/auto-update-bestseller-discount.php`
(already the established "safe to re-run, demo-placeholder" script for
`is_bestseller`/`discount_percent` — same reasoning applies here, no real
signal in the schema for "this dish is spicy" either) — Step 4 now randomly
flags a `spicy_ratio` (default 25%) / `kids_ratio` (default 15%) slice of
each restaurant's items as `is_spicy`/`is_kids_choice`, drawn independently
of the bestseller/discount picks (a dish can realistically be both). Script
header + summary output updated accordingly.

**To apply:** re-run the script the same way as before —
`http://localhost:8080/anydrop/scripts/auto-update-bestseller-discount.php?key=SEED_ME`
— **but only after `backend/sql/13_migration_menu_item_dietary_flags.sql`
has actually run**, since the script now unconditionally writes to
`is_spicy`/`is_kids_choice`, which will SQL-error on those two columns if
the migration hasn't been applied yet.

---

## Session update (2026-08-09, part 3) — features.md §1: "Filters and Sorting" bottom sheet, Customer App

**Scope:** features.md's own suggested session order — §1 was next after §3+§4
(the badge/tag fields it depends on) shipped in part 1 below.
**Not yet deployed or tested on a device** — source-only change, same as every
other undeployed phase noted elsewhere in this file. User confirmed part 1/2
are now deployed+tested, so this is the first untested change on top of that.

**What changed:**
- **New migration:** `backend/sql/13_migration_menu_item_dietary_flags.sql` —
  adds `menu_items.is_spicy` / `is_kids_choice` (both `TINYINT(1) DEFAULT 0`).
  Needed because, unlike "Highly reordered" (`is_bestseller`, already
  existed), there was no real field for the sheet's "Spicy" / "Kid's choice"
  dietary chips. **Same known-limitation pattern as `is_bestseller`/
  `discount_percent`:** no UI to set these yet, manual
  `UPDATE menu_items SET is_spicy=1 WHERE id=...` via phpMyAdmin for now —
  added to Known Limitations below.
- **`backend/api/v1/restaurants/menu.php`** — returns the two new fields per
  item (defaults to `false` if the migration hasn't run yet, so this is
  backward-compatible if deployed before the SQL migration).
- **`Models.kt`** — `MenuItem` gets `isSpicy` / `isKidsChoice` (both default
  `false` client-side too, for old cached responses).
- **New:** `ui/restaurant/MenuFiltersBottomSheet.kt` + layout
  `fragment_menu_filters.xml` — the sheet itself: sort pills (price low↔high,
  single-select, tap again to clear), pure-veg note (shown when
  `restaurant.isVegOnly`), "Highly reordered" top-pick chip, "Spicy"/"Kid's
  choice" dietary chips (multi-select), sticky footer with Clear All +
  live "Apply (N)" count. Menu items passed in via Gson JSON in the args
  Bundle — same pattern `ItemDetailBottomSheetFragment` already uses, not a
  new convention.
- **New:** `ic_filter.xml` — Material "tune" icon for the Filters pill (no
  emoji, per the standing UI/UX requirement below).
- **`activity_restaurant_detail.xml` / `RestaurantDetailActivity.kt`** —
  new "Filters" pill row above the category tab bar; opens the sheet with
  the currently active selection. Applying: filters items within each
  category (drops categories left empty), sorts items within each category
  by price when a sort is chosen — **deliberately kept the existing
  category-header structure intact** rather than flattening the whole menu
  into one sorted list, to avoid a bigger restructure this pass. Category
  tab bar / jump-to-category positions are rebuilt from the *filtered* list
  every time, so they never drift from what the adapter is actually
  showing. Filters pill itself highlights (same bg_chip_selected/unselected
  toggle convention as HomeActivity's chips) while any non-default
  filter/sort is active.
- **`strings.xml`** — all sheet copy (`filters_*` keys).

**Deliberately not touched this session (kept scope tight, matches
features.md's own per-feature file lists):**
- `MenuAdapter.kt` / `item_menu_item.xml` — unchanged; filtering/sorting
  happens one layer up in the Activity, the adapter just renders whatever
  category list it's given, same as before.
- Empty-state copy when a filter combination matches zero items reuses the
  existing generic "menu_empty" string rather than a dedicated "no items
  match your filters" message — flag as a follow-up if it reads oddly in
  practice.

**To test once deployed:** on a restaurant's menu, tap the new "Filters"
pill — sheet should open showing the pure-veg note only for pure-veg
restaurants, and Apply's count should update live as chips/sort are
toggled. After Apply, confirm the menu list reflects the filter (categories
with no matching items disappear entirely) and the Filters pill itself
shows highlighted. Re-opening the sheet should show the same selection
still checked. Since `is_spicy`/`is_kids_choice` default to 0 for every
existing row, those two chips will show 0 matches until at least one item
is manually flagged via phpMyAdmin per the note above — that's expected,
not a bug.

---

## Session update (2026-08-09, part 2) — Auto bestseller/discount script + git push cheat-sheet doc

**New file:** `backend/scripts/auto-update-bestseller-discount.php` — see
`docs/09_Auto_Bestseller_Discount_And_Git_Push.md` for full behavior.
Short version: bestseller uses real delivered-order history when it
exists, falls back to "first N items by id" when a restaurant has no
order history yet (flagged in its own output so it's never silently
mistaken for real data); discount is an explicit demo/test placeholder
(random slice of items, flat %) since there's no real signal for pricing
decisions in the schema — matches the 2026-08-09 (part 1) decision below
to defer a real discount-control feature. Safe to re-run repeatedly
(full recompute each time), unlike the one-time `seed-*.php` scripts.

**New file:** `docs/09_Auto_Bestseller_Discount_And_Git_Push.md` — also
holds a reusable git add/commit/push cheat-sheet (clone is `~/anydrop`,
remote `prosmmpanel12-cmd/anydrop`, branch `main`) so this doesn't need
re-explaining each session.

---

## Session update (2026-08-09, part 1) — features.md §3 + §4: "Highly reordered" pill + discount corner badge, Customer App

**Scope:** Started `docs/features.md`'s Zomato-parity plan, doing §3 and §4
together per that doc's own suggested session order (same adapters).
**Not yet deployed or tested on a device** — source-only change, same as
every other undeployed phase noted elsewhere in this file.

**Confirmed before writing code:** both `MenuItem` and `PopularItem`
(`network/Models.kt`) already carry `isBestseller` and `discountPercent`
fields from the backend — so this was pure client-side UI work, no
`Models.kt`, PHP, or SQL migration needed for this pass.

**What changed:**
- **New drawables:** `bg_pill_highly_reordered.xml` (light green pill,
  reuses existing `success_bg`/`success_fg` colors — no new colors added),
  `bg_discount_badge_corner.xml` (dark pill, corner-radius mirrored to sit
  in the bottom-end/"right-down" corner per the user's explicit wording,
  same technique `bg_carousel_overlay_pill.xml` already uses for its own
  top-start placement).
- **`res/values/strings.xml`** — added `highly_reordered` and `percent_off`
  (format string, shared by both adapters below).
- **`item_menu_item.xml` + `MenuAdapter.kt`** — new `itemHighlyReordered`
  pill under the description (visible when `isBestseller`), new
  `itemDiscountBadge` pinned to the image's bottom-end corner (visible
  when `discountPercent > 0`).
- **`item_popular_dish.xml` + `PopularItemsAdapter.kt`** — same two
  additions (`dishHighlyReordered`, `dishDiscountBadge`) on the Home
  "Popular dishes near you" row, for consistency with the menu screen.

**Deliberately not touched this session (kept scope tight):**
- `item_restaurant.xml` / `RestaurantAdapter.kt` — the restaurant list
  card already has its own top-left offer badge (`restaurantOfferBadge`)
  and tag chips (`restaurantTagsGroup`, includes a static "Near & Fast"
  tag) from an earlier phase; features.md §4's bottom-right corner ask was
  aimed at dish-level cards that had nothing yet, not at replacing this
  restaurant-level badge that already works.
- `item_search_dish.xml` / `SearchResultsAdapter.kt` — same visual gap
  exists here (search results have no highly-reordered pill or discount
  badge either) but wasn't in this session's two-file scope; flag for a
  follow-up pass if wanted.
- Feature 1 (filters bottom sheet) — per features.md's own suggested
  order, this depends on the badge/tag fields touched in this session and
  is the natural next session.

**To test once deployed:** open a restaurant's menu — any item with
`is_bestseller = true` should show a green "Highly reordered" pill, any
item with `discount_percent > 0` should show a dark "N% OFF" badge in the
image's bottom-right corner. Same two checks on Home's "Popular dishes
near you" row.

---


## Session update (2026-08-08, part 19) — Fixed the real carousel-load bug (RecyclerView-in-NestedScrollView anti-pattern was starting every card's timer at once), fixed the back-to-top button's wrong interaction model (was mirrored 1:1 to filters, now driven by distance-from-top, per live screenshot 3), switched it to a pill-with-text per that same screenshot, and replaced the carousel's dot indicators with the originally-requested Instagram/WhatsApp-Stories-style animated progress segments. **Packaging step (id cross-check, zip, git push) done this session — see below. Still not built/run on a device.**

**Screenshot mismatch flagged and resolved before writing any code:** the
first "screenshot 3" file you attached (`1000402505.jpg`) did not match
this app/build at all — different top bar ("Delivering to your location"
vs. this app's "Home ⌄ / <address>" + GOLD badge), different cart icon,
different card layout (tag pills below the title vs. this app's rating
badge in the photo corner). Flagged this explicitly rather than guessing
which button in that screenshot was "the" back-to-top control. You then
sent the correct screenshot (`1000402541.jpg`), which matched this app's
actual UI exactly (search bar, veg toggle, category/filter rows,
restaurant cards with rating badges) and clearly showed a black pill
"↑ Back to top" button floating over the restaurant list while the filter
row was simultaneously visible — that's the one every fix below is
built against. Also confirmed (via the two other screenshots,
`1000402542.jpg` and `1000402539.jpg`) that the handover doc's own
"screenshot 1 vs screenshot 2" labels were swapped relative to actual
upload order — didn't matter for the fix itself, but flagging the
mismatch for the record, same "confirm against the actual thing, not the
description" discipline as every other session.

### ✅ Bug 1 — Carousel auto-advancing on every card at once, confirmed root cause
**Confirmed, not a guess, before touching anything:** `activity_home.xml`'s
`restaurantList` RecyclerView (line ~497) has `android:layout_height="wrap_content"`
and `android:nestedScrollingEnabled="false"`, sitting inside `homeNestedScroll`
(a plain `NestedScrollView`) — the well-known RecyclerView-in-NestedScrollView
anti-pattern. This forces every row to measure/lay out (i.e. attach) at
once regardless of scroll position, so `DishPhotoCarouselView.onAttachedToWindow()`
fired for every card the moment the list loaded, and every card's 2.5s
auto-advance timer started simultaneously — not synchronized, just all
running at once. `DishPhotoCarouselView`'s own attach/detach-scoped timer
logic was already correct in isolation; the bug was one layer up, exactly
as diagnosed pre-session.

**What changed (visibility-based pause/resume added on top, not a
restructure of the scroll model — deliberately did not touch the
NestedScrollView/RecyclerView relationship itself, since that's this
screen's entire scroll model including the collapsing header from part 18):**
- **`DishPhotoCarouselView.kt`** — `start()`/`stop()` are now idempotent
  (redundant calls are no-ops, tracked via a new `isRunning` flag) so
  callers never need to track "did I already start this" themselves. New
  public `setVisibleToUser(Boolean)` lets an owner that manages its own
  on-screen visibility (because attach/detach won't fire again once
  everything's laid out up front) pause/resume the timer independent of
  attach state. `onAttachedToWindow()`/`setPhotos()` now check
  `isVisibleToUser` before calling `start()` (previously unconditional) —
  defaults to `true` so a view actually inside a normal recycling
  RecyclerView elsewhere in the app (if any exist) behaves exactly as
  before; only an owner that explicitly calls `setVisibleToUser(false)`
  changes this.
- **`RestaurantAdapter.kt`** — constructor now takes
  `isCarouselVisible: (View) -> Boolean`, defaulting to `{ false }`.
  `VH.bind()` calls `binding.restaurantCarousel.setVisibleToUser(isCarouselVisible(binding.root))`
  right after `setPhotos()` — a freshly-bound card checks real visibility
  at bind-time rather than starting-then-immediately-stopping a frame
  later, per your explicit instruction (item 4 in your diagnosis).
  `VH`'s `binding` property changed from `private val` to `val` so
  `HomeActivity` can reach `holder.binding.restaurantCarousel` directly
  for the scroll-driven check below, without adding a second way to
  reach the same view.
- **`HomeActivity.kt`** — `homeNestedScroll`'s existing scroll listener
  (same one driving the part-18 collapsing header) now also runs
  `updateCarouselVisibility()`, which walks every currently-attached
  `restaurantList` child, gets its `VH`, and calls
  `setVisibleToUser(isViewWithinScrollBounds(child))` — using
  `getLocalVisibleRect` on `homeNestedScroll` plus each card's Y position
  relative to the scroll container (via a small `getYRelativeToScrollContainer`
  walk up the view tree) to determine real on-screen overlap. **Given its
  own separate throttle** (`carouselCheckThrottlePx` = 40dp), not reusing
  the header's direction-change gating — that gating exists specifically
  to suppress false triggers on direction *flips*, which is the wrong
  shape for this: a long steady one-directional fling down the list still
  needs to periodically re-check visibility, which direction-gating alone
  wouldn't do once the direction stops changing. Also called once via
  `binding.restaurantList.post { updateCarouselVisibility() }` right
  after `loadRestaurants()`'s `restaurantAdapter.submit(list)` — the
  scroll listener only fires on an actual scroll event, but every row is
  already laid out (and thus "on screen" for the first screenful) the
  instant data lands, before the user has scrolled at all; without this
  initial pass those first cards would sit inert until the first scroll.
- **Not touched, per your explicit instruction:** the
  RecyclerView-in-NestedScrollView structure itself. A real fix (giving
  `restaurantList` a bounded height so it can actually recycle) is a
  bigger, riskier change this session deliberately avoided, same
  reasoning you gave in the handover.

### ✅ Bug 2 — Back-to-top button's wrong interaction model, fixed per screenshot 3
**Confirmed the actual bug, not the described one, before changing code:**
`animateFilters()` called `animateBackToTop(collapse)` directly — a hard
1:1 mirror to `filtersCollapsed`. Screenshot 3 (`1000402541.jpg`) shows
the filter row already restored (small-scroll-up rule, unchanged and
correct) while the back-to-top pill is still visible at the same time —
something the 1:1 mirror architecturally cannot produce.

**What changed:**
- **`HomeActivity.kt`** — new `isFarFromTop: Boolean` field, completely
  independent of `filtersCollapsed`. Driven directly in the scroll
  listener on every callback (cheap boolean flip, no separate throttle
  needed): hides once `scrollY <= nearTopThresholdPx` (same near-top
  condition the banner already restores on), shows once
  `scrollY > nearTopThresholdPx + collapseTriggerPx` (reuses the
  existing collapse-trigger distance as "how far past near-top counts as
  far enough" — no new tunable introduced). `animateFilters()` no longer
  calls `animateBackToTop()` at all — the small-scroll-up branch that
  restores filters has a comment now explicitly noting `btnBackToTop` is
  deliberately left untouched there.
- **`activity_home.xml`** — `btnBackToTop` changed from a plain
  `FloatingActionButton` (`app:fabSize="mini"`, icon-only `ic_arrow_up`,
  end-aligned) to a Material `ExtendedFloatingActionButton` — icon +
  `@string/back_to_top` text ("Back to top"), centered
  (`layout_constraintStart_toStartOf`/`EndOf="parent"`) rather than
  end-aligned, matching screenshot 3's pill exactly. No new dependency —
  `com.google.android.material:material:1.11.0` (already in
  `build.gradle`) has `ExtendedFloatingActionButton` built in.
  `animateBackToTop()`'s existing translationY slide animation logic
  needed zero changes — `ExtendedFloatingActionButton` is still a `View`
  subtype, so `.visibility`/`.translationY`/`.animate()` all still apply
  exactly as before.
- **`strings.xml`** — new `back_to_top` string ("Back to top", the
  visible label) added alongside the existing `cd_back_to_top` (the
  content-description string, unchanged, still used for a11y).
- **Confirmed unaffected, as you asked me to check:** `categoryList` — still
  a plain unwrapped sibling, still never hides, still visually ends up
  pinned under the search bar purely because `collapsibleBanner` collapses
  above it. Nothing about this fix touches it.

### ✅ Refinement — Stories-style progress segments, replacing dot indicators (you confirmed scope this session)
Per your reply this session ("include the progress-bar refinement too"),
built the originally-requested §2.7 behavior instead of deferring it
again:
- **`view_dish_photo_carousel.xml`** — `carouselDots` (`LinearLayout`,
  bottom-end) removed entirely, replaced with `carouselProgressSegments`
  (`LinearLayout`, top-edge, `match_parent` width) — segments span the
  full card width like Instagram/WhatsApp Stories, not a small dot
  cluster in the corner. `carouselOverlayText`'s top margin increased
  (10dp → 18dp) so the dish-name/price pill doesn't collide with the new
  top-mounted segment row.
- **New drawables** — `bg_carousel_progress_track.xml` (translucent white,
  2dp corner radius — the unfilled portion) and
  `bg_carousel_progress_fill.xml` (solid white, same corner radius — the
  filled portion), same color palette as the dot drawables they replace
  (kept `dot_carousel_selected.xml`/`dot_carousel_unselected.xml` on disk,
  now unreferenced anywhere in the project — didn't force-delete unused
  resources, consistent with how past sessions have left things).
- **`DishPhotoCarouselView.kt`** — `buildSegments(count)` builds one
  `FrameLayout` "track" per photo (equal-width via `layout_weight`),
  each containing a `View` "fill" that starts at width 0.
  `animateCurrentSegmentFill()` animates only the *current* photo's fill
  from 0 to the track's full width using a `ValueAnimator` with
  `LinearInterpolator` over `INTERVAL_MS` — real elapsed-time progress,
  not a static state, per your original ask. `resetSegmentFillsUpTo(index)`
  instantly sets already-viewed segments (index < current) to full and
  not-yet-viewed ones to empty, called from `showPhoto()`. `stop()` now
  also cancels the fill animator (an off-screen/paused card shouldn't keep
  animating a fill in the background); `start()` re-triggers
  `animateCurrentSegmentFill()` each time it actually starts (not
  suppressed by the idempotency guard, since `stop()` always resets
  `isRunning = false` first).
- **`INTERVAL_MS`: 2500L → 4500L** — your bug-tracker's own §2.7 wording
  flagged "~4-5s, not 2.5s" once this became a visibly-filling bar rather
  than an instant dot-swap; picked the middle of that stated range.

### Packaging / verification done this session
- Cross-checked every `binding.<id>` reference in `HomeActivity.kt`
  against `activity_home.xml`'s actual ids (same script-assisted method as
  part 18) — all resolve; the one apparent miss (`restaurantCarousel`) is
  `item_restaurant.xml`'s id, correctly reached via
  `holder.binding.restaurantCarousel` (`ItemRestaurantBinding`), not
  `ActivityHomeBinding` — expected, not a bug.
- Same cross-check for `RestaurantAdapter.kt` against `item_restaurant.xml`,
  and for `DishPhotoCarouselView.kt`'s `findViewById(R.id....)` calls
  against `view_dish_photo_carousel.xml` — both clean.
- Confirmed `carouselDots` (the removed id) and both old dot drawables
  have zero remaining functional references anywhere in the project
  (only explanatory comments in the two new drawable files mention them
  by name).
- Brace/paren counts balanced in all three touched Kotlin files; all
  touched/new XML parses cleanly with a parser.
- **Still not a real Gradle build or a device test** — same caveat as
  every session. The three carousel/back-to-top tunables introduced or
  reused this session (`carouselCheckThrottlePx` = 40dp,
  `INTERVAL_MS` = 4500L, and the reused `nearTopThresholdPx`/`collapseTriggerPx`
  for `isFarFromTop`) are reasonable starting values, not device-tuned —
  adjust if the feel is off once actually run, same spirit as every prior
  session's tunables note.

### Not built this session, deliberately
Nothing new deferred — all three items you asked for (carousel fix,
back-to-top fix, progress-bar refinement) are done. Migration 11 remains
the only DB thing outstanding from part 18 (see below, unchanged this
session — no DB work was in scope this pass).

## Session update (2026-08-08, part 18) — Fixed 2 real bugs from live-screenshot report: part-17's collapsing header (oversensitivity + wrong interaction model) rebuilt, and restaurant-card carousel gap fixed via new gallery auto-seed migration. Migration 11 still the only DB thing outstanding.

**Both bugs confirmed against the actual files before changing anything**
(per your instructions) — the diagnosis you supplied matched what's
actually in the zip in both cases, nothing was assumed.

### ✅ Bug 1 — Home collapsing header, rebuilt to match the Zomato reference screenshot
**Root cause of oversensitivity, confirmed:** part 17's single
`NestedScrollView.OnScrollChangeListener` compared each callback's
`scrollY` only to the immediately-previous `oldScrollY` — a couple of
pixels of touch jitter, or the collapse animation's own content reflow,
was enough to flip "direction" and retrigger a height animation, which
generated more scroll-position churn, compounding.

**Root cause of wrong interaction model, confirmed:** `activity_home.xml`
wrapped promo banner + `categoryList` + `filterScroll` in one single
`collapsibleHeader` `LinearLayout` that collapsed/expanded as one unit —
there was no way for `categoryList` to end up "pinned" under the search
bar while the banner and filters did different things.

**What changed:**
- **`res/layout/activity_home.xml`** — `collapsibleHeader` split into two
  independent wrappers: `collapsibleBanner` (promo carousel/static banner
  only) and `collapsibleFilters` (filter chip row only). `categoryList` is
  now a plain, unwrapped sibling sitting between them — it needs no
  animation of its own; it visually "becomes pinned" directly under the
  search bar purely because `collapsibleBanner`'s height collapses to 0
  above it, matching screenshot 2's reference exactly.
- New `btnBackToTop` `FloatingActionButton` (constrained just below
  `searchBarContainer`, end-aligned) — `GONE` by default, mirrors
  `collapsibleFilters`'s collapsed state exactly (visible whenever filters
  are collapsed), animated in/out via `translationY` ("slides down to
  appear / up to disappear" per your spec, not a plain visibility swap).
  Tap → `homeNestedScroll.smoothScrollTo(0, 0)`.
- **`ui/home/HomeActivity.kt`** — `setupCollapsingHeader()` rebuilt:
  - Real touch-delta gating: instead of comparing consecutive callbacks,
    it now accumulates scroll distance from `scrollDirectionAnchorY`,
    which only resets when the scroll direction actually flips (tracked
    via `scrollLastDirection`) — a threshold (`collapseTriggerPx` = 24dp
    down, `expandTriggerPx` = 12dp up) must clear before anything
    animates, so a few px of jitter can't trigger a false collapse/expand.
  - Animation-reflow isolation: while `isHeaderAnimating` is true (either
    `bannerAnimator` or `filtersAnimator` running), the listener updates
    its position bookkeeping but performs no direction/threshold logic at
    all — the animation's own reflow can't retrigger itself or pollute the
    accumulator, and tracking resumes cleanly once it settles.
  - Staged behavior implemented exactly per your bullet list:
    `collapsibleBanner` collapses on scroll-down past threshold, restores
    ONLY once back near the top (`nearTopThresholdPx` = 24dp);
    `collapsibleFilters` collapses on the same scroll-down trigger, but
    restores on ANY small scroll-up (not just near-top) — this is what
    makes "small scroll up → filter chips slide back down immediately"
    happen while the banner stays collapsed. `btnBackToTop` is driven
    directly from `animateFilters()`, so it always mirrors the filter
    row's state 1:1.
  - `remeasureCollapsibleHeaderHeight()` renamed
    `remeasureCollapsibleBannerHeight()` and now only re-measures
    `collapsibleBanner` (the piece whose height actually changes when
    async promo content loads) — both call sites
    (`loadPromoBanners()`/`showPromoBanner()`) updated to match.
  - `onDestroy()` now cancels both `bannerAnimator` and `filtersAnimator`.
- Diffed every `binding.<id>` reference in the touched files against
  `activity_home.xml`'s actual ids (same method as every prior session) —
  `collapsibleBanner`, `collapsibleFilters`, `btnBackToTop` all resolve,
  and confirmed `collapsibleHeader` (the old id) has zero remaining
  references anywhere in the project. Added `cd_back_to_top` to
  `strings.xml` for the new FAB's `contentDescription`. XML parses cleanly
  (checked with a parser); Kotlin brace/paren counts balanced. **Still not
  a real Gradle build or a device test** — same caveat as every session,
  called out explicitly per your standing rules. The three tunables
  (`collapseTriggerPx`, `expandTriggerPx`, `nearTopThresholdPx`, all in dp)
  are reasonable starting values, not device-tuned — adjust these three if
  the feel is off once you actually run it, same spirit as part 17's
  now-superseded note about its (different, buggier) three tunables.

### ✅ Bug 2 — Restaurant card carousel, fixed via auto-seed (you chose option (a))
**Confirmed, not guessed:** `RestaurantAdapter.kt`'s
`binding.restaurantCarousel.setPhotos(...)` and
`DishPhotoCarouselView.kt`'s own 0/1-photo fallback are both already
correct — this was purely `restaurant_gallery_photos` being empty for
every restaurant except the 5 hardcoded test ones from migration 10.
Re-read `07_Phase_3.7_Bug_Tracker.md` §2.7 before assuming which option you
wanted — its own decision table already recorded "Auto from `menu_items`"
as the chosen approach, which you then explicitly confirmed again this
session before I wrote anything.

**What was built — `backend/sql/12_seed_gallery_from_menu_items.sql` (new,
not yet run against any database):**
- For every restaurant NOT in migration 10's 5-restaurant test set, deletes
  any existing auto-seeded gallery rows (idempotent — safe to re-run after
  new restaurants/menu items are added) and inserts up to 5 gallery rows
  per restaurant, sourced from that restaurant's own `menu_items`
  (`is_bestseller DESC, is_recommended DESC, price DESC` ranking, only
  `is_available=1`/`deleted_at IS NULL`/non-empty `image_url` rows
  eligible) — so the "Dish Name · ₹price" overlay shows a real dish at a
  real price from that specific restaurant, not a placeholder.
- Uses `ROW_NUMBER() OVER (PARTITION BY ...)` — **assumes MySQL 8+**; no
  other migration in this project explicitly states a minimum MySQL
  version, so flagging this assumption honestly rather than silently
  picking it. If your DB is older than 8.0, tell me next session and I'll
  rewrite it with a session-variable-based ranking instead (same technique
  migration 10 already uses for its restaurant lookups, just extended).
- Deliberately excludes the 5 migration-10 test restaurants so their
  curated Unsplash demo photos aren't overwritten by their own (likely
  lower-quality placeholder) `menu_items.image_url` values.
- A restaurant with no eligible menu items (nothing available with an
  `image_url` set) simply gets no gallery rows — same fallback-to-cover_url
  behavior as before this migration, not a regression.
- **No app code touched for this bug** — `DishPhotoCarouselView.kt` and
  `RestaurantAdapter.kt` are unchanged, per your explicit instruction, and
  `backend/api/v1/restaurants/list.php`'s existing gallery query (batched,
  grouped in PHP, already reads from `restaurant_gallery_photos`) needs no
  changes either — it already returns whatever rows exist for a given
  restaurant, real or seeded.
- **Still open, deliberately out of scope this pass:** no restaurant-panel
  upload UI (bug-tracker §2.7's option (b), not chosen) — a real
  restaurant owner still can't curate/reorder their own gallery. If you
  want that built later, it's a new endpoint + Restaurant App screen +
  storage handling, same estimate as flagged in your original request.

### ⚠️ Migration 11 hit "Duplicate column name 'special_instructions'" when actually run
User ran migration 11, then 12, and got this error — meaning migration 11
(or part of it) had already been applied at some earlier point (outside
this project's own tracked sessions — Status.md had said "still not run"
every session up to part 17, so this happened either manually or in an
untracked attempt). The plain `ALTER TABLE ... ADD COLUMN` statements in
11 aren't idempotent, so re-running them against a DB where the column
already exists fails outright.

**Fix — `backend/sql/11b_fix_item_customization_idempotent.sql` (new):**
checks `information_schema.columns` for each of the three columns
migration 11 was meant to add (`order_items.special_instructions`,
`customer_cart_items.addon_ids`, `customer_cart_items.special_instructions`)
and only adds whichever ones are actually missing, via dynamic SQL
(`PREPARE`/`EXECUTE`). Safe to run any number of times regardless of
partial prior state. Ends with a `SELECT` confirming which of the three
columns exist afterward — use that to sanity-check the final state.
**Run this INSTEAD OF re-running `11_migration_item_customization.sql`
directly** — that original file is left as-is/unchanged since some
environments' migration tooling may expect it to stay exactly as
originally written; `11b` is the one to actually execute now.

### ⚠️ 11b failed too — `information_schema` access denied (error 1044), superseded by 11c
User's `root`@`localhost` account isn't granted access to
`information_schema` on this environment (unusual, but happens with some
restricted MariaDB/Termux grant setups) — so 11b's `information_schema.columns`
checks failed outright before fixing anything, and the underlying
duplicate-column problem was still unresolved.

**Fix — `backend/sql/11c_fix_item_customization_safe.sql` (new,
supersedes 11b):** wraps the three `ALTER TABLE` statements in a stored
procedure with a `CONTINUE HANDLER FOR 1060` (MySQL's "duplicate column
name" error code) — a column that already exists is silently skipped,
anything else still surfaces as a real error. No `information_schema`
access needed at all, only the same `ALTER`/`CREATE ROUTINE` privileges
the account already has. Ends with three `SHOW COLUMNS ... LIKE` checks
(not `information_schema`-based) to confirm the final state.
**Use 11c, not 11 and not 11b, going forward.**

Also worth flagging: `backend/sql/12_seed_gallery_from_menu_items.sql`
has zero `ALTER TABLE` statements and zero `information_schema` usage —
it's pure `DELETE`/`INSERT` against `restaurant_gallery_photos`. If a
"duplicate column" error appears while running something labeled "12",
it almost certainly means the plain `11_migration_item_customization.sql`
is what actually executed (e.g. an old copy still in that slot, or a
copy/paste mix-up) — double check the exact file being run.

### ⚠️ Migration 12 also failed — window functions unsupported, superseded by 12b
User's MySQL/MariaDB version doesn't support `ROW_NUMBER() OVER (...)`
(syntax error near `PARTITION BY`), meaning it's older than the MySQL 8+
this project's other migrations had been assuming. 11c's fix (stored
procedure + error handler) needed no version-specific features and ran
fine — confirmed by the user.

**Fix — `backend/sql/12b_seed_gallery_from_menu_items_no_window.sql`
(new, supersedes 12):** identical intent/output (top 5 eligible menu
items per restaurant, `is_bestseller DESC, is_recommended DESC, price
DESC`, same 5-test-restaurant exclusion, same idempotent delete-then-
insert), but ranks rows using the classic MySQL user-variable trick
(`@rn := IF(@prev_r = restaurant_id, @rn + 1, 1)` over a pre-sorted
result) instead of a window function — works on any MySQL/MariaDB
version. **Use 12b, not 12, going forward.**

### ❌ Still not done
- **`backend/sql/12b_seed_gallery_from_menu_items_no_window.sql` — not
  yet confirmed run.** This is now the one to actually execute (not 12).
- **11c confirmed run by user — done, no longer outstanding.**
- **`backend/sql/12_seed_gallery_from_menu_items.sql`** — user reported
  running this too in the same session as the migration-11 error; not
  confirmed whether it succeeded or was blocked by the same session/script
  aborting on 11's error first. Re-run after 11b succeeds, if not already
  confirmed successful. Needs `restaurant_gallery_photos` from migration 09
  and reads `menu_items`/`restaurants`, both from migration 01.

### Exact next step
1. Run migration 11, then migration 12, against your database (confirm
   05–10 already ran first, same as every prior caveat).
2. Real device test, in order: (a) Home scroll feel — confirm the staged
   collapse/expand/back-to-top behavior matches screenshot 2's reference
   and doesn't feel oversensitive anymore, adjust the three tunables in
   `HomeActivity.kt` if not; (b) restaurant cards on Home now show a real
   per-restaurant dish carousel instead of a repeated generic image —
   check a few of your real restaurants (Hive Board Games, Jai Shyam Ri
   Hotel, Sweet Treats, New Lucky Namkeen) specifically, since those are
   the ones from your screenshots; (c) dish-customization sheet, same
   as part 17's still-outstanding device-test item.
3. A real Gradle build hasn't happened across parts 13–18 — worth doing
   before more feature work stacks on top un-compiled, same standing note.
4. Once confirmed, say so and I'll give the git push commands per your
   standard request format.

## Session update (2026-08-08, part 17) — §2.4 collapsing header BUILT, on your explicit go-ahead. Migration 11 still the only thing left.

User confirmed "kiya hoga isme" (do it) in response to part 16's flagged
conflict — proceeding overrides `07_Phase_3.7_Bug_Tracker.md` §3's earlier
"defer" call for 2.4, logged here so that doc and this one don't
contradict each other going forward.

### ✅ DONE this session
- **`res/layout/activity_home.xml`** — wrapped the promo banner +
  `categoryList` + `filterScroll` block in a new `collapsibleHeader`
  `LinearLayout` (inside the existing `NestedScrollView`, which itself
  gained an id, `homeNestedScroll`, since it had none before). Nothing
  inside the wrapper was moved, renamed, or restructured — same children,
  same ids, just one new parent.
- **`ui/home/HomeActivity.kt`** — new `setupCollapsingHeader()` (called
  from `onCreate`, right after `setupExploreTiles()`):
  - Manual `NestedScrollView.OnScrollChangeListener`, not
    `AppBarLayout`/`CollapsingToolbarLayout` — picked per the bug-tracker
    doc's own flagged fallback, since `AppBarLayout` needs a
    `CoordinatorLayout` and this screen's `NestedScrollView` is already
    wrapped in a `SwipeRefreshLayout`; a second nested-scroll-consuming
    container risked fighting pull-to-refresh's touch handling.
  - Scrolling down past a small threshold collapses `collapsibleHeader`'s
    height to 0 (200ms, decelerate interpolator); scrolling up — or
    landing back near the top, e.g. after a pull-to-refresh reset —
    snaps it back to its measured expanded height immediately (150ms,
    deliberately a touch snappier than the collapse).
  - `collapsibleHeaderExpandedHeight` is captured once via
    `header.post {}` after initial layout, **and re-measured** whenever
    promo content changes (`loadPromoBanners()`'s carousel path and
    `showPromoBanner()`'s static-banner path both now call
    `remeasureCollapsibleHeaderHeight()`) — promo content loads
    asynchronously and can change the header's natural height after the
    first measurement; the re-measure is guarded to only run while
    currently expanded and not mid-animation, so it can't stomp an
    in-progress collapse.
  - `collapsibleHeaderAnimator` is cancelled in `onDestroy()` alongside
    the existing promo-auto-advance cleanup.
- Diffed every `binding.<id>` reference across the whole file against
  `activity_home.xml`'s ids again (same method as part 16's
  `fragment_item_detail.xml` check) — everything resolves, including the
  two new ones (`collapsibleHeader`, `homeNestedScroll`).

### Known limitation, flagging honestly
- **Not verified on a device.** The collapse threshold (`scrollY > 24`),
  durations, and interpolator are reasonable starting values, not
  tuned against a real screen — if the snap feels too abrupt or the
  threshold triggers too eagerly/late once you actually run it, those
  are the three numbers to adjust (`animateCollapsibleHeader`'s
  `duration`/interpolator, and `setupCollapsingHeader`'s `scrollY > 24`
  check).
- Fast, repeated up/down flicks near the threshold could in principle
  retrigger the animator mid-flight — it's cancelled and restarted
  cleanly each time (`collapsibleHeaderAnimator?.cancel()` before every
  new animation), so this shouldn't visually glitch, but it's called out
  since it's exactly the kind of thing that only really shows up on a
  real device with real touch input, not on a read-through.

### ❌ Still not done — the only thing left
- **`backend/sql/11_migration_item_customization.sql` — still not run
  against any database.** Same as every prior session's caveat: no DB
  client and no network egress in this build environment, so this has to
  be run by you directly. Confirm migrations 05–10 already ran first.

### Exact next step
1. Run migration 11 against your database.
2. Real device test, in order: (a) dish-customization sheet from all
   three entry points (Restaurant Detail menu, Home Popular row, Home
   Search results), (b) customized cart line survives app kill/restart,
   (c) collapsing header — scroll down/up on Home, confirm it feels right,
   adjust the three tunables above if not.
3. A real Gradle build hasn't happened on any of this across parts 13–17
   — worth doing before more feature work stacks on top un-compiled.
4. Once you've confirmed the migration ran and you're happy with the
   header feel, say so and I'll give you the git push commands per your
   standard request format.

## Session update (2026-08-08, part 16) — Tap-wiring DONE, closing the part-15 blocker. §2.4 collapsing header intentionally NOT done — flagging a conflict, see below. Migration 11 still not run.

**⚠️ Flagging a conflict before the "done" list — read this first:** part
15's own "Exact next step" told the next session to do §2.4 (collapsing
header) right after tap-wiring, in this same session. But
`07_Phase_3.7_Bug_Tracker.md` §3's decision table already has an explicit,
reasoned call on 2.4: **"Defer — keep the current Phase-3.6 permanent-pin
behavior,"** specifically because it's pure cosmetic polish that "goes
last, after every functional gap is closed" — and tap-wiring (this
session's actual work) was the last functional gap. Since the bug-tracker
doc is the recorded decision-of-record and nothing in this session's
instructions overrode it, §2.4 was **not** started — doing it would mean
silently reversing a documented decision on a guess. `activity_home.xml`
was confirmed unchanged (still the Phase-3.6 permanent `NestedScrollView`
pin, no `AppBarLayout`/`CoordinatorLayout`, no scroll-listener). **Say
explicitly "do 2.4 now" or "the defer decision is stale, proceed" next
session** if you want it started — otherwise it stays parked where §3 put
it.

### ✅ DONE this session — tap-wiring (part 15's actual blocker)
1. **`ui/restaurant/MenuAdapter.kt`** — added `onDishClick: (MenuItem) ->
   Unit` constructor param; `ItemVH.bind()` now sets
   `binding.root.setOnClickListener { onDishClick(item) }` as the last
   listener registered, same "card body opens, ADD/stepper/bookmark clicks
   stop here first" pattern already used by `PopularItemsAdapter`/
   `SearchResultsAdapter`.
2. **`ui/restaurant/RestaurantDetailActivity.kt`** — `MenuAdapter(...)`
   construction now passes `onDishClick`, which builds
   `ItemDetailBottomSheetFragment.newInstance(restaurantId, restaurantName,
   item)`, sets `onAdded = { updateCartButton() }`, and shows it via
   `supportFragmentManager`.
3. **`ui/home/HomeActivity.kt`** — both `onDishClick` lines (Search results,
   Popular row) no longer call `openRestaurantById(...)`. Both now call a
   new private `openItemDetailSheet(restaurantId, restaurantName, item)`
   helper (added next to `openRestaurantById`) that builds the sheet via
   `item.toMenuItem()` (both `SearchItem` and `PopularItem` already had this
   extension in `Models.kt` — no new conversion code needed) and sets
   `onAdded = { updateCartBadge() }`. `openRestaurantById` itself is
   untouched and still used by the deep-link handler further down the file
   — not dead code.
4. **Sanity-checked `fragment_item_detail.xml` against
   `FragmentItemDetailBinding`'s actual usage in
   `ItemDetailBottomSheetFragment.kt`** (part 15 flagged this as
   hand-written/unverified) — diffed every `binding.<id>` reference in the
   Kotlin file against every `android:id` in the layout: **all resolve,
   nothing missing.** Also checked `item_addon_row.xml` against
   `ItemAddonRowBinding` usage (`addonCheckbox`/`addonPrice` — both
   present), and confirmed every `@string/item_*`/`@drawable/*` the layout
   references actually exists in `strings.xml`/`drawable/`. Still not a
   real Gradle build — this was a manual reference-by-reference check, not
   a compiler run — but no gaps found.
- **Net effect:** dish-card taps across Restaurant Detail's own menu,
  Home's Popular row, and Search results now all open the customization
  sheet instead of navigating to the restaurant. This is the change that
  actually makes addons/cooking-notes reachable from the UI — **not
  verified on a device or via a real build**, same as everything else this
  session, only hand-traced against the existing working patterns
  (`PopularItemsAdapter` et al.) and the binding-reference diff above.

### ❌ Still not done
- **Bug-tracker §2.4, collapsing header animation — not started, and not
  going to be unless you say so.** See the conflict note at the top.
- **`backend/sql/11_migration_item_customization.sql` — still not run
  against any database.** No DB client and no network egress in this
  environment, so this has to be run by you directly, same as every prior
  session's caveat. Confirm migrations 05–10 already ran first.
- Nothing in this session touched a device or a real build — every claim
  above is a source-level/reference check, not a compiled-and-run
  verification.

### Exact next step (read this first in the next session)
1. **Decide §2.4** — say "do it now" (and which approach:
   `AppBarLayout`/`CollapsingToolbarLayout` vs. the manual scroll-listener
   fallback the bug-tracker doc suggests) or "stays deferred."
2. Run migration 11 against your database, then do a real end-to-end test:
   open a dish from Restaurant Detail's menu (should show addons), from
   Home's Popular row and Search results (should show qty + note only, no
   addons — that's expected per `toMenuItem()`'s scope), add a customized
   line, kill/restart the app, confirm it restores with addons/note intact
   (part 14/15's original priority).
3. A real Gradle build hasn't happened on any of this — part 13 through 16
   of work — worth doing before more feature work stacks on top un-compiled.

## Session update (2026-08-08, part 15) — CUT SHORT AGAIN. Fixed the part-14 CartSyncManager gap + a newly-found backend bug, and built the ItemDetailBottomSheet itself. Tap-wiring + collapsing header still not done.

**⚠️ THIS SESSION WAS CUT SHORT BEFORE FINISHING — read "Exact next step"
at the bottom of this entry before doing anything else.** Nothing in this
session has been deployed, compiled, or tested on a device or against a
real database. Source-only, same as every other session.

### ✅ DONE this session

**1. Backend bug fix, found (not introduced) this session:**
`backend/api/v1/customer/cart-sync.php`'s POST handler already had
`addon_ids`/`special_instructions` in its INSERT SQL's column list and
`:addons`/`:instructions` placeholders (from part 14), but the `execute()`
call right below it never actually bound those two params. Every POST to
`/customer/cart-sync.php` — **not just customized lines, every cart sync,
period** — would have thrown `SQLSTATE[HY093]: Invalid parameter number`
and failed outright. Fixed: now reads `addon_ids` (dedup'd, cast to int
array) and `special_instructions` (trimmed, capped at 200 chars via
`mb_substr`) off each item and binds both. Also updated the endpoint's
top-of-file docblock example to show the new fields. **Still not run
against any database** — this is a source-only fix, verify it actually
works once migration 11 is applied and a real POST is fired.

**2. Part-14's flagged gap, now fixed — `CartSyncManager.kt` +
`network/Models.kt`:**
- `CartSyncItem` gained `addons: List<MenuAddon>? = null`,
  `addonIds: List<Int>? = null`, `specialInstructions: String? = null`
  (`addons` is GET-only/restore, same convention as the existing
  name/description/price fields on that class; `addonIds`/
  `specialInstructions` are used on both GET and POST).
- `CartSyncManager.pushToServer()` now sends `addonIds`/
  `specialInstructions` per line (`it.addonIds.ifEmpty { null }` so an
  uncustomized line's JSON stays minimal, matching the existing
  omit-when-absent convention).
- `CartSyncManager.restoreFromServer()` now rebuilds `MenuItem.addons`
  from the synced item's `addons` list (needed so `CartLine.unitPrice`/
  `addonSummary` can still compute correctly after a restore) and passes
  `addonIds`/`specialInstructions` into the rebuilt `CartLine`.
- **Net effect:** a customized cart line should now survive an app
  kill/restart, which was the part-14 handover's #1 priority — **not
  verified against a real build or a real sync round-trip**, only
  hand-checked for type/shape consistency against what cart-sync.php's
  GET response actually returns.

**3. Built `ui/itemdetail/ItemDetailBottomSheetFragment.kt`** (+
`res/layout/fragment_item_detail.xml` + `res/layout/item_addon_row.xml`)
— the actual sheet UI that's been the main missing piece since part 14:
- Dish image/name/description/price header, veg badge, "View full menu —
  ⟨restaurant⟩" link (tappable → opens `RestaurantDetailActivity`,
  dismisses the sheet).
- Addon checkboxes built from `item.addons` — **flat list, no
  groups/max-select cap**, matches the accepted v1 simplification already
  documented in `CartManager.kt`'s kdoc, NOT the full §2.6 scope. Shows
  `noAddonsNote` instead when `item.addons` is empty (i.e. sheet opened
  from Home/Search rows, whose `MenuItem`s don't carry addons — see
  `toMenuItem()` converters in `Models.kt`).
- Quantity stepper (min 1).
- Cooking-request `EditText`, 200-char cap, live counter, plus 4 static
  quick-select chips ("No onion or garlic", "Less spicy", "Extra spicy",
  "No cutlery") that append into the field rather than replace it, and
  won't duplicate a preset that's already present in the text.
- Sticky "Add item ₹X" button — live total = `(item.price + selected
  addon prices) × quantity`, recomputed on every checkbox/qty change. On
  tap: `CartManager.setCustomized(...)` → `CartSyncManager.scheduleSync(...)`
  → `onAdded?.invoke()` (exposed var, same pattern as
  `AddressEditorBottomSheet.onSaved`) → `dismiss()`.
- Re-opening the sheet on a dish already in that restaurant's cart
  pre-fills its existing quantity/addons/note instead of resetting to
  qty=1 with nothing selected.
- **Not done:** the sheet exists but **nothing opens it yet** — see
  "Exact next step" below. Also not built: `MenuAdapter`'s new
  `onDishClick` param (doesn't exist today, still needs adding).

### ❌ NOT STARTED / NOT FINISHED — same items as part 14, still open
- **Wiring dish-card taps to the sheet** — the actual remaining blocker on
  §2.6/1.9. Today: `MenuAdapter` still has no card-body click listener at
  all (only ADD/stepper buttons), and `HomeActivity.kt`'s two
  `onDishClick = { item -> openRestaurantById(...) }` lines (Popular row +
  Search results, lines ~153 and ~169) still open the restaurant instead
  of the new sheet. **None of this was touched this session** — only the
  sheet itself was built, not its wiring. This is why the app still
  behaves exactly as before if you build it right now: the sheet is dead
  code until this step happens.
- Bug-tracker Phase E item **2.4**, collapsing header animation — still
  not started.
- `backend/sql/11_migration_item_customization.sql` — still not run
  against any database.
- `backend/api/v1/cart/validate.php` — confirmed (read fully this
  session) that it forwards `items[]` straight into `price_cart()`
  unchanged, so `special_instructions`/`addon_ids` passing through it
  should already work with no changes needed. This was flagged as
  unverified in part 14; now verified by reading, not by testing.

### Exact next step (read this first in the next session)
1. Add an `onDishClick: (MenuItem) -> Unit` constructor param to
   `MenuAdapter` (`ui/restaurant/MenuAdapter.kt`) — set it on
   `binding.root`'s click listener in `ItemVH.bind()`, same "card body
   opens the sheet, ADD/stepper buttons stop the click from bubbling up"
   pattern `PopularItemsAdapter`/`SearchResultsAdapter` already use.
2. In `RestaurantDetailActivity.kt`, pass that new param when constructing
   `MenuAdapter(...)` (currently `MenuAdapter(restaurantId, restaurantName,
   lifecycleScope) { updateCartButton() }`) — on tap, build
   `ItemDetailBottomSheetFragment.newInstance(restaurantId, restaurantName,
   item)`, set `sheet.onAdded = { updateCartButton() }`, then
   `sheet.show(supportFragmentManager, "item_detail")`.
3. In `HomeActivity.kt`, change both existing `onDishClick = { item ->
   openRestaurantById(item.restaurantId, item.restaurantName) }` lines
   (Popular row ~169, Search results ~153) to instead build
   `ItemDetailBottomSheetFragment.newInstance(item.restaurantId,
   item.restaurantName, item.toMenuItem())`, set `onAdded = {
   updateCartBadge() }`, and show it. Both `PopularItem` and `SearchItem`
   already have a `toMenuItem()` extension in `Models.kt` — no new
   conversion code needed, just call it.
4. Manually sanity-check `fragment_item_detail.xml` layout inflates
   correctly (view IDs referenced from `ItemDetailBottomSheetFragment.kt`
   should all resolve via `FragmentItemDetailBinding` — this was
   hand-written this session, not run through a real Gradle build to
   confirm the generated binding class matches every reference).
5. THEN move to bug-tracker Phase E item 2.4 (collapsing header) — per
   §3's decision this is deliberately last, after every functional gap
   closes. The bug-tracker doc itself suggests a manual scroll-listener +
   height-animation approach on the existing `NestedScrollView` (wrap the
   promo/category/filter block in `activity_home.xml` in a new
   `LinearLayout` container, animate its height 0↔wrap-content on
   scroll-down/scroll-up) as a fallback if `AppBarLayout` +
   `CollapsingToolbarLayout` conflicts with the existing
   `SwipeRefreshLayout` — flagging this as the likely path since an
   `AppBarLayout`/`CoordinatorLayout` rework is a bigger, riskier change to
   a screen that already works.
6. Run `backend/sql/11_migration_item_customization.sql` (after confirming
   migrations 05–10 already ran) before testing any of this end to end.
7. Update this Status.md file again once tap-wiring + collapsing header
   are both done, then zip and give the git push commands per the user's
   standard request format.

## Session update (2026-08-08, part 14) — IN PROGRESS, Bug-tracker Phase C item 2.3 DONE, Phase D item 2.6/1.9 PARTIALLY DONE (backend + cart data layer only — sheet UI not built yet)

**⚠️ THIS SESSION WAS CUT SHORT — read "Exact next step" at the bottom of
this entry before doing anything else.** Nothing in this session has been
deployed or tested on a device. Source-only, same as every other session.

### ✅ DONE — Phase C item 2.3, service-area "not available" screen
Matches `07_Phase_3.7_Bug_Tracker.md` §2.3 exactly (message-only, no
"notify me" capture, per that doc's own §3 decision).
- **`res/layout/activity_home.xml`** — new `serviceAreaUnavailable`
  full-screen `LinearLayout` (icon + title + message + retry button),
  inserted between `vegToggleContainer`'s sibling block and `emptyState`,
  constrained the same way `emptyState` is (`top_toBottomOf=searchBarContainer`,
  fills the rest of the screen). `GONE` by default.
- **`res/values/strings.xml`** — `service_area_unavailable_title`,
  `service_area_unavailable_message`, `service_area_retry`.
- **`ui/home/HomeActivity.kt`**:
  - `loadRestaurants()` now treats an empty result as "unserved area" ONLY
    when `isBrowsingDefaultHome()` is true AND veg-only toggle is off (i.e.
    truly the plain, nothing-narrowed-down feed) — a filtered/categorised/
    veg-only empty result still shows the ordinary `emptyState` message,
    since that's a real "no matches", not an unserved area.
  - New `setServiceAreaUnavailable(Boolean)` — toggles the new full-screen
    view AND hides/shows `swipeRefresh` (the whole scrolling banner/
    categories/filters/list block) together, so the unserved-area state
    doesn't show a dead scrollable area behind it.
  - `btnServiceAreaRetry` wired to just call `loadRestaurants()` again.
- **Not done / deliberately out of scope this pass:** doesn't distinguish
  "no restaurants in the DB at all" vs. "restaurants exist but none deliver
  to this exact lat/lng" — both currently produce the same message, which
  matches the bug-tracker doc's scope (message-only, no radius-specific
  copy was requested).

### 🟡 PARTIALLY DONE — Phase D item 2.6/1.9, dish customization
**What exists now:** every backend + cart-data-layer piece needed to store
and price a customized line (addons + a per-item cooking note). **What does
NOT exist yet: the actual bottom-sheet UI the customer taps through, and
the wiring that opens it from a dish card.** Today, tapping a dish card
body still calls the OLD `openRestaurantById(...)` everywhere (Home
Popular row, Search results, Category results) and MenuAdapter's item rows
still have no card-body tap listener at all — **none of that was changed
this session**, only the data layer underneath it was prepared. Placing an
order today therefore still can't attach addons/notes from the UI (the
ADD button still does a plain add with no customization, exactly like
before this session) — the wiring described in "Exact next step" below is
what turns this into an actually-usable feature.

**Backend (done, not deployed):**
- `backend/sql/11_migration_item_customization.sql` (new) —
  `order_items.special_instructions TEXT NULL`, plus
  `customer_cart_items.addon_ids TEXT NULL` and
  `customer_cart_items.special_instructions TEXT NULL` (extends migration
  07's table). **Not run against any database yet.**
- `backend/lib/orders.php` — `price_cart()` now reads `special_instructions`
  per line (trims, caps at 200 chars via `mb_substr`, null if blank) and
  includes it in each `line_items[]` entry. Addon pricing itself
  (`addon_ids` → `menu_item_addons` lookup) was **already fully built**
  from an earlier phase — nothing changed there.
- `backend/api/v1/orders/create.php` — `order_items` INSERT gains the
  `special_instructions` column + bound param, pulled from
  `$line['special_instructions'] ?? null`.
- `backend/api/v1/customer/cart-sync.php` — GET now batch-fetches each
  cached item's full addon list (`menu_item_addons`, same grouped-query
  pattern used elsewhere for tags/gallery, avoids N+1) and returns
  `addons`, `addon_ids`, `special_instructions` per synced item, so a
  restored cart line can correctly recompute its priced total. POST now
  also inserts `addon_ids` (JSON-encoded — **not yet actually JSON-encoded
  client-side, see Android gap below**) and `special_instructions` into
  `customer_cart_items`.
- **NOT done:** `backend/api/v1/cart/validate.php` was not touched — it
  already forwards whatever `items[]` shape it's given straight into
  `price_cart()`, so `special_instructions` passing through it should
  already work without changes, but this was not explicitly verified by
  reading that file this session — check it first in the next session
  before assuming it's fine.

**Android — cart data layer (done):**
- `data/CartManager.kt` — `CartLine` gains `addonIds: List<Int>`,
  `specialInstructions: String?`, computed `unitPrice` (item.price + sum of
  matching `item.addons` prices) and `addonSummary` (comma-joined addon
  names). `RestaurantCart.totalPrice()` now sums `unitPrice * quantity`
  instead of `item.price * quantity`. New `CartManager.setCustomized(...)`
  — sets a line's quantity/addons/notes directly (not an increment), meant
  to be called once from the (not-yet-built) sheet's sticky Add button.
  **Known, accepted simplification:** a cart line is still keyed by
  `menu_item_id` alone — customizing the same dish twice overwrites the
  first customization rather than creating a second line. Not the
  "addon groups with max-select cap" system the original request
  described either — addons are still flat checkboxes, any combination, no
  cap, no grouping. Flagging clearly so this isn't mistaken for the full
  scope of the original ask.
- `network/Models.kt` — `CartItemLine` gains `special_instructions`.
- `ui/cart/CartItemAdapter.kt` + `res/layout/item_cart_line.xml` — new
  `cartLineCustomization` `TextView` (GONE unless a line actually has an
  addon summary and/or note), price now reads `line.unitPrice`.
- `ui/checkout/CheckoutActivity.kt` — `cartLines()` now sends
  `addonIds`/`specialInstructions` per line to `/cart/validate` and
  `/orders`.
- **Gap found but not fixed this session:** `data/CartSyncManager.kt`'s
  `pushToServer()` still builds `CartSyncItem(menuItemId=..., quantity=...)`
  only — it does NOT yet send `addon_ids`/`special_instructions` to the
  backend despite the backend now accepting them, and `restoreFromServer()`
  does NOT yet read the new `addons`/`addon_ids`/`special_instructions`
  fields back off `CartSyncItem` into the restored `CartLine`. Net effect:
  a customized line's addons/notes will NOT currently survive an app
  kill/restart even once the sheet UI exists — this must be fixed together
  with the sheet, or customizations will silently vanish on restore. Also
  `network/Models.kt`'s `CartSyncItem`/`CartSyncRestaurant` data classes
  need the matching new fields added (`addons: List<MenuAddon>?`,
  `addonIds: List<Int>?`, `specialInstructions: String?`) — not done yet.

### ❌ NOT STARTED
- **`ItemDetailBottomSheetFragment`** — the actual sheet UI: dish image/
  name/description/price, addon checkboxes (flat list from
  `item.addons`), quantity stepper, cooking-request `EditText` + quick-
  select chips, sticky "Add item ₹X" button showing a live total. This is
  the main remaining piece of Phase D.
- Wiring dish-card taps to open that sheet instead of the restaurant:
  `MenuAdapter` (no card-body click listener exists at all today — only
  ADD/stepper buttons), `PopularItemsAdapter`/`SearchResultsAdapter`
  (currently call `onDishClick` → `openRestaurantById(...)`, need to
  redirect to the new sheet, with a "View full menu" link inside the sheet
  for restaurant access, resolving bug 1.9 as originally scoped).
  `SearchItem`/`PopularItem` don't carry an `addons` field from their
  APIs today, so sheets opened from Search/Popular rows will show
  qty+notes only, no addon checkboxes — full addon support only for
  taps originating from Restaurant Detail's own menu (`MenuItem` already
  has `addons`). Flag this limitation to the user if it matters enough to
  extend `search.php`/`popular-items.php` later.
- Bug-tracker Phase D item **2.5** (floating Menu jump button) — already
  done in an earlier session (see part 11 below), not part of this gap.
- Bug-tracker Phase E item **2.4**, collapsing header animation — not
  started, and per the bug-tracker doc's own §3 decision this was already
  explicitly deferred to go last after every functional gap closes, so
  this is correctly still last in the queue, not a regression.

### Exact next step (read this first in the next session)
1. Fix `CartSyncManager.kt` + `network/Models.kt`'s `CartSyncItem`/
   `CartSyncRestaurant` to actually send/restore `addon_ids` +
   `special_instructions` (see "Gap found but not fixed" above) — do this
   BEFORE building the sheet, not after, or it's easy to forget once the
   sheet works and ship a restart-loses-your-customization bug.
2. Build `ui/itemdetail/ItemDetailBottomSheetFragment.kt` +
   `res/layout/fragment_item_detail.xml` (+ a small addon-row layout) —
   accept restaurantId/restaurantName + a `MenuItem` (serialize via Gson to
   a JSON string arg, same as any other Bundle-friendly approach already
   used in this codebase — `MenuItem` is a plain data class, not
   Parcelable). On its sticky Add button, call
   `CartManager.setCustomized(...)` with the chosen quantity/addonIds/
   notes, then `CartSyncManager.scheduleSync(...)`, then dismiss.
3. Wire it in as the card-body tap target: add an `onDishClick` callback
   to `MenuAdapter` (new — doesn't have one today) and change
   `PopularItemsAdapter`/`SearchResultsAdapter`/`MenuAdapter`'s existing
   `onDishClick` wiring in `HomeActivity.kt`/`RestaurantDetailActivity.kt`/
   the search screen to open the new sheet instead of
   `openRestaurantById(...)`. Put a "View full menu — ⟨Restaurant⟩" link
   inside the sheet so restaurant access isn't lost (this is what actually
   resolves bug 1.9, not just the sheet existing on its own).
4. Run `backend/sql/11_migration_item_customization.sql` (after confirming
   migrations 05–10 already ran) before testing any of this.
5. THEN move to bug-tracker Phase E item 2.4 (collapsing header) — last
   remaining item on the user's current 4-item punch list.
6. Update this Status.md file again once the sheet + wiring are done, then
   zip and deliver per the user's standard request format.

## Session update (2026-08-07, part 13) — Phase E §2.7, auto-advancing dish-photo carousel on restaurant cards

**Built this session:** restaurant cards (Home list + Search results) now
show an auto-advancing photo carousel instead of a single static cover
image, when the restaurant has 2+ gallery photos — screenshot reference:
cover cycles through dish photos every ~2.5s with a "Dish Name · ₹price"
tag overlay and dot indicators, similar to a status-style auto-advance.
Restaurants with 0 or 1 gallery photo (i.e. haven't uploaded any yet)
automatically fall back to the old single static image — nothing breaks,
nothing looks broken, no extra setup needed for them.

**New files:**
- `ui/common/DishPhotoCarouselView.kt` — reusable custom `FrameLayout`.
  Auto-advance timer starts/stops itself via `onAttachedToWindow`/
  `onDetachedFromWindow`, which `RecyclerView` already calls on recycle —
  no adapter-side lifecycle wiring needed, and a recycled row calling
  `setPhotos()` always stops any previous timer first so two timers can
  never stack on one recycled view.
- `res/layout/view_dish_photo_carousel.xml` — the view's internal layout
  (image + overlay tag + dot row), `<merge>`d into the FrameLayout.
- `res/drawable/dot_carousel_selected.xml` / `dot_carousel_unselected.xml`
  — white/semi-transparent-white dots (not `dot_promo_selected/
  unselected.xml`'s primary-color dots — those are for a plain white
  banner background, these sit on top of a photo and need to read on any
  background).
- `res/drawable/bg_carousel_overlay_pill.xml` — dark pill background for
  the dish-name+price tag.

**Files changed:**
- `res/layout/item_restaurant.xml` — `restaurantCover` `ImageView` swapped
  for `restaurantCarousel` (`DishPhotoCarouselView`), same position.
- `ui/home/RestaurantAdapter.kt` / `ui/search/SearchResultsAdapter.kt` —
  both now build a `List<DishPhotoCarouselView.Photo>` from
  `restaurant.gallery` and call `setPhotos(photos, restaurant.coverUrl)`
  instead of the old direct `binding.restaurantCover.load(...)` calls.
- `network/Models.kt` — `Restaurant` gained a `gallery:
  List<RestaurantGalleryPhoto>?` field (new `RestaurantGalleryPhoto` data
  class: `imageUrl`, `dishName`, `price`), same nullable-despite-
  always-present convention as the existing `tags` field on the same
  class (Gson + a missing JSON key = real null at runtime regardless of
  the Kotlin default) — always read via `.orEmpty()`.
- `backend/api/v1/restaurants/list.php` / `backend/api/v1/search/
  search.php` — both now also batch-fetch `restaurant_gallery_photos` per
  restaurant (same grouped-in-PHP pattern already used for tags, avoids
  N+1 queries) and emit it as `gallery` in the response.

**New backend migration:** `backend/sql/09_migration_restaurant_gallery.sql`
— `restaurant_gallery_photos` table (`restaurant_id`, `image_url`,
`dish_name`, `price`, `sort_order`). No restaurant-panel UI to upload
these yet — that's a separate not-yet-built task; for now the only way to
add gallery photos is a direct SQL insert into this table (see the seed
file below for the pattern).

**New seed data:** `backend/sql/10_seed_restaurant_gallery_photos.sql` —
3 gallery photos each for the 5 test restaurants from
`08_seed_multi_category_test_restaurants.sql`, so the carousel has
something to actually demo. Run 08 before 10. **⚠️ The Unsplash photo URLs
in this seed file are unverified** — written following the same
`images.unsplash.com/photo-<id>?w=...&q=...` convention already used
elsewhere in this repo's seed data (`03_migration_splash_login_settings
.sql`, `06_migration_phase36.sql`), but each specific photo ID was not
individually checked to confirm it resolves/matches the dish name. If any
image looks wrong or 404s after running the seed, swap that row's
`image_url` — the app just reads whatever URL is in the table, no app
update needed.

**⚠️ Not verified against a real build** — hand-written; brace/paren
balance checked across every touched Kotlin file, `item_restaurant.xml`
and `view_dish_photo_carousel.xml` both parse cleanly as XML, PHP brace
balance checked in both touched endpoint files. Coil's `crossfade()` has
separate `Boolean` and `Int` overloads — the carousel's `loadImage()`
calls one or the other via an `if/else` rather than a single expression,
specifically to avoid a branch-type-mismatch compile error.

## Session update (2026-08-07, part 12) — seed data: 5 test restaurants, multiple categories each

**Added:** `backend/sql/08_seed_multi_category_test_restaurants.sql` —
seeds 5 restaurants (Spice Junction, Urban Pizza Co, Dragon Wok, Burger
Barn, South Spice Express), each with 3-4 menu categories and 2-3 items
per category. Purpose: the category chip tab bar (§2.1) and the floating
Menu jump button (§2.5) are both hidden when a restaurant has only 1
category — this seed exists purely so there's data to actually see and
test both features against, since the existing test data (e.g. "Sweet
Treats Dessert House") is single-category.

Each restaurant is inserted with `status='approved'`, `operational_status
='open'`, and an all-day/all-week open window, so all 5 show up in listing
immediately as "Open" with no extra admin-panel setup. Safe to re-run —
deletes any earlier run of this same seed (matched by `owner_email`)
before re-inserting. Owner login for all 5: password `Test@1234` (bcrypt
hash generated locally, not from the live DB — untested against this
project's actual PHP `password_verify()` call, but `$2b$`-prefixed bcrypt
hashes are handled interchangeably with PHP's own `$2y$` hashes by
`password_verify()` in all currently supported PHP versions).

**⚠️ Not run against a real database** — hand-written against
`01_schema.sql`'s column list, multi-row-insert `LAST_INSERT_ID()`
contiguous-id assumption (holds for a single-writer seed run, not
guaranteed under concurrent inserts), parenthesis/quote balance checked.

## Session update (2026-08-07, part 11) — Phase E §2.5, floating Menu jump button

**Built this session:** Restaurant Detail screen now has a small floating
"Menu" button (mini FAB, bottom-end, sitting just above the View Cart
button) that opens a popup list of every menu category. Tapping a category
in the popup jumps the scroll position straight to that category's header
row — same jump behaviour as the existing horizontal chip tab bar (§2.1),
just reachable without scrolling the chip row first on a long menu.

**Files changed:**
- `res/drawable/ic_menu_list.xml` — new vector icon (bulleted-list glyph).
- `res/values/strings.xml` — added `cd_menu_jump` content-description string.
- `res/layout/activity_restaurant_detail.xml` — added `btnMenuJump`
  (`FloatingActionButton`), constrained `bottom_toTopOf="@id/btnViewCart"`
  with `app:layout_goneMarginBottom` so it sits right above View Cart when
  that's showing, or closer to the screen edge when the cart is empty and
  View Cart is `gone`.
- `ui/restaurant/RestaurantDetailActivity.kt` — `buildCategoryTabs()` now
  also stores the category-name -> header-position list in a
  `categoryPositions` field and shows/wires `btnMenuJump`; new
  `showCategoryJumpMenu()` opens a `PopupMenu` built from that same list
  and reuses the existing `jumpToCategory()`, keeping the chip tab row's
  checked-state in sync with whichever entry point was used. Same
  single-category hide rule as the chip bar (§2.1) — both are hidden
  together when there's nothing to jump between.

**⚠️ Not verified against a real build** — hand-written, brace/paren
balance checked and layout XML parses cleanly. `FloatingActionButton` and
`PopupMenu` are both already-used-elsewhere-in-the-codebase/stock Android
APIs, no new library dependency added.

## Session update (2026-08-07, part 10) — Phase E, ADD button ↔ qty-stepper animation

**Fixed this session:** the ADD button and qty stepper (+/- with count)
used to swap with a hard `View.GONE`/`View.VISIBLE` jump-cut. Added a
shared 180ms fade+scale cross-fade so ADD visibly shrinks/fades out while
the stepper grows/fades in (and the reverse when qty drops back to 0),
matching the Zomato/Swiggy-style "morph" feel from the Phase E polish list.

**New file:** `ui/common/QtyStepperTransition.kt` — a small shared
animation helper (`show()`/`hide()` for animated user-triggered toggles,
`setImmediate()` for `RecyclerView` bind so a recycled row never flashes a
stale mid-animation frame from whatever it was previously bound to).

**Wired into all three ADD/stepper locations** (every place a dish can be
added, per `CartAddHelper`'s own kdoc): `ui/restaurant/MenuAdapter.kt`
(Restaurant Detail menu), `ui/home/PopularItemsAdapter.kt` (Home's
Popular row), `ui/search/SearchResultsAdapter.kt` (Search dish results).
Each file's old single `refreshQtyUi()` (used for both initial bind *and*
post-click updates) is now split into `setQtyUiImmediate()` (bind-only, no
animation) and `refreshQtyUi()` (click-callback-only, now animated via
`QtyStepperTransition`) — same split, same naming, in all three files.

**⚠️ Not verified against a real build** — hand-written, brace/paren
balance checked (0 mismatches across all 4 touched/new files). No new
layout or binding IDs — reuses each layout's existing `btnAdd`/
`qtyStepper` views (already stacked in the same `FrameLayout` position per
`item_menu_item.xml` / `item_popular_dish.xml` / `item_search_dish.xml`,
confirmed before writing the cross-fade so it doesn't need any layout
changes).

## Session update (2026-08-07, part 9) — Phase E, closed-restaurant card dimming

**Fixed this session:** Phase 3.7 §5 Phase E item — closed restaurant
cards now visually dim (`alpha = 0.5f`) instead of relying solely on the
small red "Closed" status label, which was easy to miss at a glance.

**Files changed:** `ui/home/RestaurantAdapter.kt` (Home restaurant list)
and `ui/search/SearchResultsAdapter.kt` (search results — reuses the same
`item_restaurant.xml`/`ItemRestaurantBinding`, kept both in sync so a
closed restaurant looks the same whether found via Home or Search).
Tapping a dimmed/closed card still opens the restaurant page — dimming is
visual-only, not a disabled state, per the original ask (dimming, not
blocking).

**⚠️ Not verified against a real build** — hand-written, brace/paren
balance checked (both files balanced), no new binding IDs added (reused
`binding.root.alpha`, a property on every `View`).

## Session update (2026-08-07, part 8) — Phase 3.7, bug 1.6 fixed
**Confirmed working on device (Phase 3.6 + cart persistence deploy):** all
of Phase 3.6's manual checklist, plus bugs 1.1 (filter revert), 1.3 (search
crash) — both already marked ✅ FIXED in the tracker from an earlier
session and now device-confirmed too.

**Fixed this session:** 1.6 — "Delivering to your location" bar now opens
`AddressEditorBottomSheet` with a working "Use current location" GPS fix,
instead of doing nothing. See `07_Phase_3.7_Bug_Tracker.md` §1.6 for full
detail. **Files changed:** `customer/.../ui/home/HomeActivity.kt` only —
no layout/manifest/backend changes.

**⚠️ Not verified against a real build** — hand-written, brace/paren
balance checked (139/139, 415/415), binding ID cross-checked against
`activity_home.xml` (`deliveryLocationText` exists there). Needs a real
Android build + device test before moving to the next bug.

**Still pending in the Phase 3.7 queue:** 1.9 (real item-detail view on
tap, Phase D — bigger feature block, not started).

## Session update (2026-08-07, part 6) — Cart server-persistence added

**Problem it fixes:** `CartManager` was in-memory only — app kill/restart
emptied the cart completely, with no record of what was in it or which
restaurant(s). Customer explicitly asked: cart contents *and* where it was
left should live server-side, and restore automatically on next app open.

**Approach:** "Replace-all snapshot" sync, not granular per-item CRUD — the
app POSTs its *entire* current multi-restaurant cart state after every
local change (debounced 1s), and restores the same way on next app open.
Simpler and safer than row-level add/remove endpoints for something that
isn't the order-of-record (checkout still re-validates everything via the
existing `POST /cart/validate` before anything is charged).

**✅ Done:**
- `backend/sql/07_migration_cart_persistence.sql` — new `customer_cart_items`
  table (customer_id, restaurant_id, menu_item_id, quantity, coupon_code),
  unique per (customer_id, restaurant_id, menu_item_id).
- `backend/api/v1/customer/cart-sync.php` — `GET` restores the saved cart
  (joined with live restaurant/menu_item data so the app can rebuild full
  `MenuItem` objects without a second call; unavailable items are silently
  dropped, same pattern as `/cart/validate`'s `invalid_items`); `POST`
  replaces the customer's entire saved cart in one transaction (delete +
  reinsert), skipping malformed lines rather than failing the whole sync.
- `customer/.../data/CartSyncManager.kt` (new) — `scheduleSync()` (debounced
  1s, call after every cart mutation) and `syncNow()` (immediate, call from
  `onStop()` on any cart-mutating screen) push to the server; silent on
  failure since this isn't the order-of-record. `restoreFromServer()` pulls
  the snapshot and rebuilds `CartManager` state, called once from
  `HomeActivity.onCreate()`.
- `data/CartManager.kt` — added `restoreFromServer(List<RestaurantCart>)`.
- Wired `CartSyncManager.scheduleSync()`/`syncNow()` into every cart
  mutation point: `CartAddHelper`, `SearchResultsAdapter`, `MenuAdapter`,
  `CartItemAdapter`, `PopularItemsAdapter`, and `CheckoutActivity`'s coupon
  apply/remove + post-order cart clear (uses `syncNow()` there since order
  placement is a hard exit point).
- `HomeActivity`, `RestaurantDetailActivity`, `CartBottomSheetFragment` —
  added `onStop()` force-sync so "add item then immediately swipe the app
  away" isn't lost waiting on the debounce timer.

**Files changed this session:** `backend/sql/07_migration_cart_persistence.sql`
(new), `backend/api/v1/customer/cart-sync.php` (new),
`data/CartSyncManager.kt` (new), `data/CartManager.kt`, `network/Models.kt`,
`network/ApiService.kt`, `ui/common/CartAddHelper.kt`,
`ui/search/SearchResultsAdapter.kt`, `ui/restaurant/MenuAdapter.kt`,
`ui/cart/CartItemAdapter.kt`, `ui/home/PopularItemsAdapter.kt`,
`ui/checkout/CheckoutActivity.kt`, `ui/cart/CartBottomSheetFragment.kt`,
`ui/restaurant/RestaurantDetailActivity.kt`, `ui/home/HomeActivity.kt`.

**⚠️ Not verified against a real build.** Same caveat as every other
session in this project — hand-written and cross-checked (brace/paren
balance checked on every touched file, every new binding/import/field
traced back to something that exists), but this environment has no Android
SDK / PHP CLI to actually compile or lint. **Needs a real build + `php -l`
on `cart-sync.php` before anything else touches Cart/Checkout.**

**Still needs after the build passes — apply the migration first:**
1. Run `07_migration_cart_persistence.sql` against the `anydrop` DB.
2. Add items across 2+ restaurants → force-kill the app (not just
   background) → reopen → confirm both restaurant-carts restore with
   correct quantities.
3. Apply a coupon → kill → reopen → confirm the applied coupon state
   restores too (re-shows in Checkout's coupon field).
4. Add item → immediately swipe the app away (not wait 1s) → reopen →
   confirm the `onStop`-triggered immediate sync caught it.
5. Place an order → confirm that restaurant's cart clears both locally
   *and* on the server (reopen app, confirm it doesn't come back).
6. Two devices, same login → add on device A → open fresh on device B →
   confirm B pulls A's cart (last-write-wins is expected here).
7. A menu item gets deleted/unavailable server-side while sitting in a
   saved cart → reopen → confirm it's silently dropped, not shown broken.

**⚠️ Known gaps still open (flagged in part 5, still not started):**
- **Qty +/- button occasionally unresponsive.** Root cause found: every tap
  on a cart line's +/- triggers a full `notifyDataSetChanged()` cascade
  through nested RecyclerViews (`RestaurantCartAdapter` → rebuilds every
  `CartItemAdapter` from scratch, not just the touched row), causing a
  visible rebuild right under the user's finger — occasional missed taps.
  Fix direction: `notifyItemChanged(position)` at the line level instead of
  parent-level `refresh()` on every tick. Not yet implemented.
- **Cart-abandonment notifications.** Nothing exists — only the two
  unrelated fixed-time daily meal reminders (`MealReminderScheduler`).
  Needs a ~15-min-after-abandon trigger plus a longer 30-40 day
  re-engagement series (5+ touchpoints requested) — cadence/copy needs a
  product decision before engineering starts. Not yet designed or built.

## Session update (2026-08-07, part 5) — Coupon-in-checkout gap (from part 4) closed
Part 4's known gap: the multi-restaurant cart rework removed the cart
sheet's coupon field and never rebuilt it. **Fixed this session** — coupon
entry now lives in `CheckoutActivity`, matching what part 4's plan said.

**✅ Done:**
- `res/layout/activity_checkout.xml` — new "Coupon" section between
  delivery-instructions and bill-details: `inputCouponCode` EditText +
  `btnApplyCoupon` (entry state), a `couponAppliedRow` (green card, shows
  "CODE applied — you saved ₹X" + a `btnRemoveCoupon` text action), and a
  `couponErrorText` line for invalid/ineligible codes. Reused the
  `hint_coupon_code`/`btn_apply_coupon`/`coupon_applied`/`coupon_invalid`
  strings that were already sitting unused in `strings.xml` since the old
  cart sheet was gutted in part 4. Added 3 new strings:
  `coupon_min_order_not_met`, `coupon_usage_limit_reached`, `remove_coupon`,
  `lbl_coupon`.
- `ui/checkout/CheckoutActivity.kt` — `applyCoupon()` sends the typed code
  through the existing `POST /cart/validate` call (same endpoint `loadBill()`
  already uses) — this both validates the code and gets the discounted
  total back in one round trip, no new endpoint needed. Checks the
  response's `warning` field against the three coupon-specific error codes
  `price_cart()` (`backend/lib/orders.php`) already returns
  (`invalid_coupon`, `coupon_min_order_not_met`, `coupon_usage_limit_reached`)
  — on one of those, shows the matching message and does **not** set
  `CartManager`'s `appliedCouponCode` (so a bad code can't silently ride
  along into order placement); otherwise sets it and re-renders the bill.
  `removeCoupon()` clears the field and reloads the bill without it.
  `renderCouponState()` toggles between the entry row and the applied row
  based on `discountAmount > 0`, not just whether a code string is set —
  trusts the server-computed number, not local state. Pre-fills the input
  on open in case a coupon was already applied earlier in the same
  checkout session (`CartManager` is an in-memory singleton, so it survives
  backing out and returning to Checkout without a process restart).
- **No backend changes needed** — `price_cart()` already had all three
  coupon-error codes and already put them in the `warning` field; this was
  purely wiring the Android side up to something the backend already did.

**Files changed this session:** `res/layout/activity_checkout.xml`,
`res/values/strings.xml`, `ui/checkout/CheckoutActivity.kt`.

**⚠️ Not verified against a real build.** Same caveat as always in this
doc — written and cross-checked by hand (XML validated well-formed,
brace/paren-balance checked, every binding ID/string/color/drawable
reference traced back to something that exists), but this environment
still has no Android SDK. Needs a real `build-customer.yml` run before
anything else touches Checkout.

**Still needs a real device test even after the build passes:**
- Applying a valid coupon end-to-end (does the discount row/grand total
  actually update, does the applied-state card render right)
- Applying an invalid/expired code — confirm the right message shows for
  each of the 3 backend error cases, not just "invalid" for all of them
- Removing an applied coupon, then placing the order — confirm the coupon
  really isn't sent (check the order's discount is ₹0 / no coupon on the
  resulting order)
- Backing out of Checkout after applying a coupon, then reopening it for
  the same restaurant — confirm the pre-fill + applied-state restore works
- The interaction with the still-not-yet-verified part-4 multi-restaurant
  cart rework itself (build hasn't been independently re-confirmed by me
  this session — only the coupon-specific files above)

---

## Session update (2026-08-07, part 4) — Cart reworked to multi-restaurant (Zomato-style). IN PROGRESS, not fully done — see handover.
User rejected bug 1.7's original fix (option A: confirm-before-clearing
dialog) and asked for **option B instead**: real multiple independent
carts, one per restaurant, exactly like Zomato/Swiggy — add items from
Restaurant A and Restaurant B and both sit in the cart at once, checked
out separately. Same message also asked to start **1.9** (dish-detail
screen) and **2.3** (service-area check).

**✅ Done this session — multi-restaurant cart:**
- `data/CartManager.kt` — full rewrite. Was a single global cart
  (`restaurantId: Int?`, one flat `lines` map). Now holds
  `LinkedHashMap<Int, RestaurantCart>` — each `RestaurantCart` has its own
  `restaurantName`, `lines`, `appliedCouponCode`. `add()` no longer clears
  anything or returns a Conflict result — adding from a new restaurant just
  starts that restaurant's own cart. `AddResult`/`wouldConflict()`/
  `clearAndAdd()` all removed (no longer needed — see verification note
  below).
- `ui/common/CartAddHelper.kt` — simplified, the AlertDialog "Start a new
  cart?" confirmation is gone (no longer applicable, there's nothing to
  conflict with).
- `ui/cart/CartItemAdapter.kt` — now takes `restaurantId` in its
  constructor (item rows always belong to one restaurant's section now).
- `ui/cart/RestaurantCartAdapter.kt` — **new file**. Renders one card per
  active restaurant-cart (name, item count, nested item list via
  `CartItemAdapter`, subtotal, clear icon, its own "Checkout" button).
- `ui/cart/CartBottomSheetFragment.kt` — rewritten. Was a single flat
  `cartLineList` + one coupon field + one "Proceed to Checkout" button.
  Now shows `RestaurantCartAdapter`'s list of restaurant cards; a hint
  line appears when 2+ restaurants have active carts
  ("You have items from N restaurants — checkout each separately").
  **Coupon entry field was removed from this sheet** (see known gap below).
- `res/layout/fragment_cart.xml` — rewritten to match (removed
  `cartLineList`/coupon views, added `restaurantCartList` +
  `multiCartHint`).
- `res/layout/item_restaurant_cart_section.xml` — **new file**, one
  restaurant's card layout (`MaterialCardView` with a nested
  `RecyclerView`, `nestedScrollingEnabled="false"`).
- `ui/checkout/CheckoutActivity.kt` — now requires a new
  `EXTRA_RESTAURANT_ID` intent extra (the cart sheet's per-card "Checkout"
  button passes it) instead of reading `CartManager.restaurantId` off a
  single global cart. On successful order placement it now calls
  `CartManager.removeCart(restaurantId)` — **only that restaurant's cart
  clears**, any other restaurant's cart the customer also has going is
  left untouched. This is the actual point of the whole rework — placing
  an order from Restaurant A's cart must not wipe Restaurant B's cart.
- `ui/restaurant/MenuAdapter.kt`, `ui/home/PopularItemsAdapter.kt`,
  `ui/search/SearchResultsAdapter.kt` — `CartManager.decrease(item)` →
  `CartManager.decrease(restaurantId, item)`, same for `quantityOf()`, at
  every call site (each adapter already had a `restaurantId` in scope).
- `ui/restaurant/RestaurantDetailActivity.kt` — `updateCartButton()` now
  reads `CartManager.getCart(restaurantId)?.totalItemCount()` instead of
  the old `CartManager.totalItemCount()` + `CartManager.restaurantId ==
  restaurantId` pair.
- `res/values/strings.xml` — removed the now-unused
  `cart_switch_restaurant_*` / `cart_other_restaurant_fallback` strings
  (the confirm-dialog is gone), added `clear_cart`,
  `cart_multiple_restaurants_hint`, `cart_other_restaurant_fallback_generic`,
  a `cart_items_count` plural. `btn_checkout` text changed from "Proceed
  to Checkout" to "Checkout" (fits the smaller per-card button).
- **Verified no leftover calls to the old single-cart API anywhere in
  `customer/app/`** — grepped for `CartManager.restaurantId`,
  `CartManager.getLines()`, `CartManager.appliedCouponCode`,
  `CartManager.totalPrice()`, `AddResult`, `wouldConflict`,
  `clearAndAdd`, and single-argument `decrease()`/`quantityOf()` calls;
  all clear.

**⚠️ Known gap opened by this rework — coupon entry has no home yet.**
The old cart sheet's coupon input field was removed (it doesn't make
sense on a screen with N independent carts — a coupon applies to one
restaurant's order). `CheckoutActivity` still *reads*
`CartManager.getCart(restaurantId)?.appliedCouponCode` when building the
bill and placing the order, but **nothing sets that field anymore** — no
crash, coupons just silently don't apply right now. `CheckoutActivity`
needs its own coupon input UI (a small addition: one `EditText` + apply
button in `activity_checkout.xml`, wired the same way the old cart
sheet's `applyCoupon()` was). Not done this session — flagging clearly so
it isn't mistaken for "coupons still work."

**⚠️ Not verified against a real build.** This environment has no Android
SDK/Gradle — every change above was written and cross-checked by hand
(including a full grep pass for stale API usage) but never compiled. Push
from Termux and confirm `build-customer.yml` passes before testing on
device — treat this as source-only, same caveat as every other phase in
this doc.

**❌ Not started this session, still queued (same message asked for
these too):**
- **1.9** — dedicated item-detail screen (image/name/description/price,
  tappable restaurant name, "More from this restaurant" row) for taps on
  Popular-row / Search / Category-browse dish cards. Currently those still
  open `RestaurantDetailActivity` directly (unchanged from before this
  session). Planned target: `ItemDetailActivity` (new), reusing
  `getMenu(restaurantId)` for the "more from this restaurant" row and the
  existing `item_popular_dish.xml`/`ItemPopularDishBinding` layout for
  that row's cells (no new small layout needed there).
  `HomeActivity`'s `onDishClick` lambdas for `searchAdapter` and
  `popularItemsAdapter` need to launch it (with id/name/description/
  price/imageUrl/isVeg/restaurantId/restaurantName as Intent extras)
  instead of calling `openRestaurantById()`.
- **2.3** — service-area "not available in your area yet" check. Planned
  design (not built): new `app_settings` rows (`service_area_enabled`,
  `service_area_hub_lat`, `service_area_hub_lng`, `service_area_radius_km`,
  `service_area_message`) via a new
  `backend/sql/07_migration_service_area.sql`; new
  `backend/api/v1/home/service-area-check.php` (haversine distance from
  hub, same helper function pattern as `category-items.php`;
  `service_area_enabled` defaults to `0` so this is a no-op until an admin
  turns it on — "nothing hardcoded" principle, matches how every other
  `app_settings` flag in this project behaves); `HomeActivity` requests
  location permission on create (fails open — doesn't block the app if
  permission is denied), calls the new endpoint, and shows a full-screen
  "Sorry, we're not available in your area yet — coming soon!" overlay
  over Home's content when `serviceable=false`. Per the existing §3
  decision in `07_Phase_3.7_Bug_Tracker.md`: **message only, no
  email/waitlist capture** for v1.

**Files changed/added this session:** `data/CartManager.kt`,
`ui/common/CartAddHelper.kt`, `ui/cart/CartItemAdapter.kt`,
`ui/cart/RestaurantCartAdapter.kt` (new), `ui/cart/CartBottomSheetFragment.kt`,
`ui/checkout/CheckoutActivity.kt`, `ui/restaurant/MenuAdapter.kt`,
`ui/restaurant/RestaurantDetailActivity.kt`, `ui/home/PopularItemsAdapter.kt`,
`ui/search/SearchResultsAdapter.kt`, `res/layout/fragment_cart.xml`,
`res/layout/item_restaurant_cart_section.xml` (new), `res/values/strings.xml`.
**No backend files touched this session** — this part was Android-only.

---


## Session update (2026-08-07, part 3) — category + filter chip combo did nothing
User reported (with screenshot): tapped "Thali" category, then tapped
"Under ₹200" — the chip highlighted with its × like it applied, section
title stayed "Thali", but the dish list still showed items over ₹200
(e.g. "Dal Makhani ₹210"). The filter chip and the category row were two
completely independent systems that were never wired to combine:
- `home/category-items.php` never accepted a `filter` query param at all
  — `loadCategoryItems()` (Android) never sent `activeFilter`, so a price/
  tag/open-now/rating filter chip was pure decoration once a category was
  selected.
- The filter-chip tap handler always forced `binding.restaurantList.adapter
  = restaurantAdapter` and called the plain `loadRestaurants()`, ignoring
  `activeCategorySlug` entirely — in isolation this would have visibly
  kicked the user out of category-browsing back to the restaurant list,
  which is why the bug looked like "the chip just does nothing" rather
  than "it switches views" (the category tap's own `loadCategoryItems()`
  call, fired a moment earlier, kept `searchAdapter` bound and the section
  title as "Thali" — see part 1/2 fixes above for why category taps no
  longer trigger stray reloads — so the later, filter-only `loadRestaurants()`
  call's *results* never got a chance to land in a visibly different way
  before the next interaction).

**Fix:**
- Backend: `home/category-items.php` now accepts the same `filter` values
  `restaurants/list.php` already supports (`near_fast` / `pure_veg` /
  `under_200` / `open_now` / `rating_4` / `has_offer` / `veg`) — price/
  offer/veg as SQL `WHERE` conditions on the joined `restaurants` row,
  tag-based filters via the same `restaurant_tag_map` join
  `restaurants/list.php` uses, `open_now`/`rating_4` applied in the PHP
  loop (mirrors the existing pattern there).
- Android: `loadCategoryItems()` now sends `filter = activeFilter`.
  `setupFilterChips()`'s click listener now checks `activeCategorySlug`
  first — if a category is active, tapping a filter chip re-runs
  `loadCategoryItems()` (keeping the category view, now filtered);
  otherwise it falls back to the original `loadRestaurants()` path.
  Selecting a category while a filter chip is already active also now
  carries the filter forward automatically, since `loadCategoryItems()`
  always reads the current `activeFilter` field rather than needing it
  passed in explicitly.

**Files changed:** `backend/api/v1/home/category-items.php`,
`customer/app/src/main/java/com/anydrop/customer/ui/home/HomeActivity.kt`,
`customer/app/src/main/java/com/anydrop/customer/network/ApiService.kt`
(`getCategoryItems()` gained a `filter` query param).

**⚠️ Backend change needs redeploying** — copy the updated
`backend/api/v1/home/category-items.php` to the server, same as any other
backend file change in this project (no SQL migration needed, no new
columns — `min_order_amount`/`offer_badge_text`/`is_veg_only` on
`restaurants` and the `restaurant_tag_map` join already existed).

---

## Session update (2026-08-07, part 2) — category filter revert bug: first fix was incomplete, now actually fixed
User re-tested and confirmed the revert was still happening ("milliseconds
ke liye filter lagta hai, phir old, phir refresh se theek") even after the
part-1 fix below. Root cause of *why the fix didn't work*:
`clearSearchInputProgrammatically()` cancelled the previously-pending
`searchRunnable`, but `searchInput.setText("")` still fires the
`TextWatcher`, which immediately schedules a **brand-new** debounced
runnable 400ms out regardless — cancelling the old one didn't stop the new
one from being created. **Real fix:** an `isProgrammaticSearchClear`
boolean flag the `TextWatcher` checks first; while true, `afterTextChanged`
returns immediately and never reschedules anything. See
`07_Phase_3.7_Bug_Tracker.md` §1.1 for the full corrected writeup.
**File changed:** `ui/home/HomeActivity.kt`.

---

## Session update (2026-08-07) — Category filter revert bug (real fix), category chooser UI, promo dots removed
User reported (with screenshots): tapping a category icon (e.g. "Pizza")
filters correctly for a moment, then reverts back to the unfiltered Home
feed on its own — only a manual pull-to-refresh brings the filtered view
back. This is the **same class of bug** as `07_Phase_3.7_Bug_Tracker.md`
§1.1, but a fresh occurrence: the 3.6 fix only guarded the filter-chip row's
click listener, not the category icon row, the promo-banner "category"
tap-through, or the Explore tiles' Offers/Top 10 taps — each of those calls
`searchInput.setText("")` to reset the search box, which still fires the
debounced search TextWatcher and silently reverts the view ~400ms later.
See `07_Phase_3.7_Bug_Tracker.md` §1.1 for the full root cause — fixed via
a new `clearSearchInputProgrammatically()` helper used at every one of
those call sites.

Also this session (same conversation, screenshots-driven):
- **Category chooser is now visually clear** — the selected category's icon
  gets a colored ring + tinted background (not just the label text turning
  orange), plus a small × badge on the selected icon to undo/deselect it
  (tapping it clears back to "All", same as tapping the category again).
  `item_food_category.xml` rebuilt on `MaterialCardView` (stroke support)
  + a badge `ImageView`; `FoodCategoryAdapter.kt` wires both.
- **Promo carousel dot indicators removed** — the 3 dots under the ad
  banner (added in Phase 3.6 §2.2 via `TabLayoutMediator`) are gone per
  request. The carousel still auto-advances and is swipeable; the
  `TabLayout` view stays in the layout (unused, always `gone`) rather than
  being deleted outright, in case dots are wanted back later.

**Files changed this session:** `ui/home/HomeActivity.kt` (new
`clearSearchInputProgrammatically()`, used in `onCategoryTapped()`,
`onPromoBannerTapped()`'s category branch, `onExploreTileTapped()`'s
offers/top10 branches; `TabLayoutMediator` import + dot-attach call
removed), `ui/home/FoodCategoryAdapter.kt` (stroke/background/badge
selection UI), `layout/item_food_category.xml` (rebuilt on
`MaterialCardView` + badge), `layout/activity_home.xml`
(`promoCarouselDots` defaults to `gone`), `values/strings.xml`
(`clear_category_filter`).

**Not yet deployed/tested on a device** — same as every other pending
Phase 3.6/3.7 Android work; needs a fresh build + install to verify.

---

## Confirmed Decision: Maps & Live Delivery Animation (free stack)

> **⚠️ PLAN CHANGED (2026-08-11):** Superseded by a later decision —
> **Google Maps is now the planned provider**, not OSM/osmdroid/OSRM. See
> `docs/12_Handover_H6_Map_PinDrop_Photo.md` → "Google Maps SDK migration
> plan." The section below is kept as historical record of the reasoning
> at the time; don't build against it.

You asked "ab kaunsa map use karenge" — **already decided and documented in `03_Live_Tracking.md`, unchanged:**
- **Map rendering:** OpenStreetMap tiles via **osmdroid** (Android library) — 100% free, no API key, no billing account, no Google Play Services dependency.
- **Routing / road-snapped route line + ETA:** **OSRM** public demo server (free), with a documented one-line swap to a self-hosted OSRM Docker container later if you outgrow the free demo server's fair-use limits.
- **Live motion animation:** rider sends GPS every 5-7s (adaptive interval, see table in `03_Live_Tracking.md`); customer app polls `/orders/{id}/track` every 4-5s and **animates the marker smoothly between old/new points using a `ValueAnimator`** (~4s interpolation, 60fps) instead of teleporting the dot — this is the actual trick that makes it look "live" without needing real-time sockets (which InfinityFree can't run anyway).
- This is all still **Phase 4** scope (Rider App + Live Tracking) — nothing to build differently, just confirming the plan stands as written since you asked directly.

## Interim UI/UX Polish (2026-08-04, ahead of Phase 4)
Outside strict phase order, at your request, the following was built directly into the already-shipped Phase 2/3 Customer App screens (does not block or change Phase 4 scope):
- **Search bar wired up** — was UI-only before; added `GET /search` backend endpoint (matches restaurant name, cuisine, and dish name) + debounced live search in `HomeActivity`.
- **Veg/Non-veg toggle** — Zomato-style animated pill switch, **default ON**, persisted via SharedPreferences (`VegModeManager`). Filters the restaurant list (`veg_only` param, backend) and the menu screen (client-side) consistently.
- **Notifications:**
  - In-app banner (`InAppNotifier`) now supports an optional image and a new `OFFER` style for discounts/promos.
  - Real system (status-bar) notifications added (`NotificationHelper`) — offer/discount notifications use `BigPictureStyle` when an image is available, plain text otherwise ("with image / without image" requirement).
  - **Daily meal reminder** — WorkManager-scheduled local notifications at 1:30 PM and 8:30 PM ("your meal is waiting for you"), survives app kill/reboot. `POST_NOTIFICATIONS` runtime permission requested on Android 13+.
- **Coupon field — admin-toggleable:** new `coupon_field_enabled` setting (via `app_settings` table, read through `splash-config.php`, same "nothing hardcoded" pattern as `home_promo_enabled`). When ON, the Cart bottom sheet shows a coupon-code input; applying it calls the existing `/cart/validate` endpoint and carries the code through to checkout and order placement. When OFF, the field is hidden entirely — no app update needed to toggle it, just an `UPDATE app_settings` row (Admin Panel UI for this is still Phase 5 — for now it's set directly via phpMyAdmin).
- **Cart/Checkout UI polish** — veg/non-veg dot on cart lines, cleaner spacing and dividers, "Use current location" button on Checkout (plain `LocationManager` + `Geocoder`, no Play Services needed, consistent with the rest of the free stack) to auto-fill the delivery address, labeled delivery-note field.

**New/changed permissions:** `POST_NOTIFICATIONS`, `ACCESS_FINE_LOCATION`, `ACCESS_COARSE_LOCATION` added to the Customer App manifest. **New dependency:** `androidx.work:work-runtime-ktx` (WorkManager) for the daily reminders.

**Not yet done (flag if you want these next):** no admin UI to flip `coupon_field_enabled` (phpMyAdmin only, until Phase 5); no per-restaurant coupon eligibility UI beyond what `/cart/validate` already enforces server-side; veg toggle doesn't yet have its own switch inside the Restaurant Detail screen (it inherits the global Home toggle, which is simpler but means you can't view non-veg items without turning the global toggle off).

## Current Phase
**Phase 3 — Ordering + Restaurant App: code generated, awaiting deployment + test.**

**Confirmed working before this phase:** Phase 0 (build pipeline) and Phase 1 (backend/DB) confirmed. Phase 2 (Customer App: splash, update-check, login, home, restaurant detail, cart) confirmed working end-to-end by you on 2026-08-03.

**Now: run the new SQL migration, deploy the updated backend, push both app codebases, build, and test the full order loop (customer places order -> restaurant sees it -> restaurant accepts/preps/marks ready -> customer sees status update).**

## Completed
- [x] Phase 0 — build pipeline confirmed
- [x] Phase 1 — backend + DB confirmed
- [x] Phase 2 — Customer App confirmed working (splash -> update-check -> login -> home -> restaurant detail -> cart)
- [x] **Phase 3 — Ordering + Restaurant App (this update):**
  - **Backend (`backend/lib/orders.php` + new endpoints):**
    - `lib/orders.php` — single shared pricing/validation function (`price_cart`) used by both preview and real order placement, so totals can never drift between the two. Re-validates every item/variant/addon against the DB — never trusts client-sent prices.
    - `POST /cart/validate` — price preview for the checkout screen
    - `POST /orders` — places the order (transaction: order + order_items + status_history + coupon_usage), generates a unique `order_code`, generates a delivery OTP when payment is UPI (or when `otp_required_for_cod` is on)
    - `GET /orders/{id}`, `GET /orders/{id}/track` (polled), `POST /orders/{id}/cancel` (only within `order_cancel_window_minutes` and while pending/accepted)
    - `GET /restaurant/orders` (paginated, filterable by status), `GET /restaurant/orders/{id}`, `POST /restaurant/orders/{id}/accept`, `.../reject`, `.../status` (accepted->preparing->ready, transitions enforced server-side), `GET /restaurant/dashboard` (today's order count/earnings, pending/active counts, current due)
    - `POST/GET /customer/addresses` — minimal address book (Phase 2 never built this; checkout needs somewhere to deliver to). No map-picker UI yet, just label + free-text address.
    - `backend/sql/04_migration_order_settings.sql` — adds `tax_percent`, `delivery_charge_flat`, `packing_charge_flat`, `order_cancel_window_minutes` to `app_settings` (safe to re-run)
    - Commission uses each restaurant's own `commission_percent` if set, falling back to the global `commission_default_percent` setting
  - **Customer App additions:**
    - `CheckoutActivity` — pick/add a delivery address, COD or UPI, live server-computed bill via `/cart/validate`, place order via `/orders`
    - `OrderStatusActivity` — polls `/orders/{id}/track` every 5s, shows status/ETA/rider contact/delivery OTP, cancel button (shown only while cancellable)
    - Cart bottom sheet's checkout button now actually opens Checkout instead of the Phase-2 "coming soon" toast
  - **Restaurant App — new, built from scratch (`restaurant/`), mirrors the Customer app's structure:**
    - `LoginActivity` (email+password against `POST /auth/restaurant-login.php`, already existed from Phase 1) -> `DashboardActivity` -> `OrderDetailActivity`
    - Dashboard: New / Active / History tabs (backed by `GET /restaurant/orders?status=...`), today's order count + earnings summary, polls every 10s, pull-to-refresh
    - Order detail: shows items/bill/delivery instructions, one contextual action button per status (Accept+Reject when pending, Mark Preparing when accepted, Mark Ready when preparing, no action once ready+ — rider assignment is Phase 4)
    - Own `build.gradle`/`settings.gradle`/`gradle.properties` (self-contained project, same pattern as `customer/`), own `.github/workflows/build-restaurant.yml`
    - Notifications kept to simple Toasts (not the full custom banner the customer app has) — reasonable simplification for an internal staff-facing app

## In Progress — deployment checklist for this phase
- [ ] Run `backend/sql/04_migration_order_settings.sql` in phpMyAdmin
- [ ] Copy the updated `backend/` folder (new `cart/`, `orders/`, `restaurant/`, `customer/addresses.php`, updated `.htaccess`, `lib/orders.php`) into the KS Web `anydrop` folder
- [ ] Push `customer/` changes and the new `restaurant/` folder from Termux
- [ ] Confirm both GitHub Actions builds pass (`build-customer.yml` and the new `build-restaurant.yml`)
- [ ] Install both APKs on the same test phone (or two phones on the same network as the backend)
- [ ] Walk through the full loop: customer adds items -> checkout (add address, pick COD/UPI) -> place order -> restaurant app shows it under "New" -> accept -> mark preparing -> mark ready -> customer's order-status screen reflects each change -> try cancelling an order within the cancel window from the customer side, and try rejecting a pending order from the restaurant side
- [ ] Confirm Phase 3 working end-to-end before Phase 4 starts

## Pending (Not Started)
- [ ] Phase 4 — Rider App + Live Tracking + OTP Delivery (rider assignment, live map, OSRM-based ETA — the placeholder ETA/otp plumbing from Phase 3 is designed to slot into this). **Confirmed with user (this session): the full location module — Home screen GPS location bar, map-based saved-address picker, "device location not enabled" prompt — is intentionally deferred to Phase 4, built together with rider routing/live tracking rather than bolted on separately now.** Until then, Checkout's plain `LocationManager`/`Geocoder` "Use current location" button (Phase 3) is the only location entry point.
- [ ] Phase 5 — Admin Panel (Web) — will include the category/restaurant-tag icon upload UI (see Phase 3.5 note below)
- [ ] Phase 6 — Notifications, Reviews, Polish
- [ ] Phase 7 — Hardening & Launch Readiness

## Phase 3.5 — Home Screen Catalog, Categories & Cross-Restaurant Search (this session)
Scope: match the Home screen to the reference screenshots (category chip row,
tag/offer badges on restaurant cards, animated notification popup) and add
enough demo data to see it all populated, plus make search show cross-
restaurant results for the same dish.

**Backend**
- `backend/sql/05_migration_categories_and_tags.sql` — new tables:
  `food_categories` (Pizza/Rolls/Burger/Biryani/Thali/Chinese/Desserts/
  Sandwich/South Indian/Beverages, admin-manageable later), `menu_item_categories`
  (many-to-many item↔category), `restaurant_tags` + `restaurant_tag_map`
  (Near & Fast / Pure Veg / Under ₹200 / Gold Extra 10%), and a new
  `restaurants.offer_badge_text` column. Run this in phpMyAdmin.
- `backend/scripts/seed-demo-catalog.php` — **15 restaurants, 37 menu items**,
  free Unsplash-hosted image URLs, Jodhpur coordinates, tags + categories
  mapped. Run once via browser (`?key=SEED_ME`), then delete the file
  (same convention as `seed-test-data.php`).
- `search/search.php` — rewritten. Now returns BOTH `restaurants` (name/cuisine/
  dish matches) AND `items` — every menu item matching the query BY NAME from
  ANY restaurant, each tagged with `restaurant_id`/`restaurant_name`, plus an
  `is_cross_restaurant_match` flag so the app can group a searched
  restaurant's own dishes separately from the same dish at other restaurants
  ("Also available at").
- `home/categories.php` (new) — the category chip row data.
- `home/category-items.php` (new) — tapping a category (e.g. "Pizza") returns
  that dish from every restaurant, each tagged the same way as search.
- `restaurants/list.php` — now returns `tags[]` and `offer_badge_text` per
  restaurant; `filter=near_fast|pure_veg|under_200` now supported.
- `.htaccess` — routes added for the two new `home/` endpoints.

**Customer App**
- `Models.kt` — `RestaurantTag`, `FoodCategory`, `SearchItem`,
  `CategoryItemsResult`; `Restaurant` gained `tags`/`offerBadgeText`;
  `SearchResponse` reshaped to `{restaurants, items, meta}`.
- `ApiService.kt` — `getHomeCategories()`, `getCategoryItems()` added.
- `item_restaurant.xml` / `RestaurantAdapter.kt` — offer badge overlay on the
  cover image + a `ChipGroup` for restaurant tags.
- `FoodCategoryAdapter.kt` + `item_food_category.xml` — the category icon row.
- `activity_home.xml` — category row + expanded filter-chip row (All / Near &
  Fast / Under ₹200 / Pure Veg / Open now / Rating) inserted between the promo
  banner and the restaurant list.
- `ui/search/SearchResultsAdapter.kt` + `item_search_dish.xml` +
  `item_search_section_header.xml` (new) — combined results list: matching
  restaurant card(s), then a "Dishes" section for that restaurant's own
  items, then an "Also available at" section for the same dish from other
  restaurants — every dish card shows a "from &lt;Restaurant Name&gt;" tag.
- `HomeActivity.kt` — rewritten to wire the category row, new filter chips,
  and the new search flow (swaps the RecyclerView's adapter between
  `RestaurantAdapter` and `SearchResultsAdapter` depending on mode).
- `ui/common/NotificationPermissionDialog.kt` + `dialog_notification_permission.xml`
  + `res/raw/anim_bell_ring.json` (new) — the animated "Want to stay updated
  about offers, order status and more?" bottom-sheet popup (screenshot
  reference), shown once per app open on Home. "Yes" triggers the real
  `NotificationHelper.requestPermissionIfNeeded()` system prompt; "Not now"
  just dismisses. Uses a small hand-authored Lottie JSON (no external asset
  download needed) for the ringing-bell animation — `com.airbnb.android:lottie:6.4.0`
  added to `build.gradle`.

**Flagged for Phase 5 (Admin Panel)**
- Category icons and restaurant logos/covers currently come from hardcoded
  Unsplash URLs in the seed script. Admin Panel needs an image-upload flow
  for `food_categories.icon_url`, `restaurant_tags.icon_url`, and per-
  restaurant logo/cover, so these can be changed without editing SQL.

## Phase 3.6 — UI Fixes & New Features (ANDROID COMPLETE — awaiting deployment/test)
Full scope in `docs/06_Phase_3.6_UI_Fixes_And_New_Features.md`. User said
"start" — work proceeded in implementation order (§5). **Backend is fully
done. Android is now fully done (steps 1–11, plus step 12 — all 7 of 7
Profile pieces built and verified, including `ProfileActivity` itself,
this session — see part 7 below).** Nothing has been deployed or tested on
a device yet.

### ✅ Done — Backend (fully complete, not yet deployed)
- `backend/sql/06_migration_phase36.sql` — new tables `promo_banners`,
  `customer_favorites`, `faqs`, `feedback`; `restaurants.rating_count`
  column; structured columns on `customer_addresses` (`address_type`,
  `house_flat_no`, `floor`, `landmark`, `receiver_name`, `receiver_phone`);
  `rate_us_url` app_setting; seed data for banners + FAQs. Safe to re-run.
- `backend/lib/favorites.php` — shared `get_saved_restaurant_ids()` /
  `get_saved_item_ids()` helpers (avoid duplicating the query per endpoint).
- New endpoints: `home/promo-banners.php`, `home/popular-items.php`,
  `customer/favorites.php` (GET list / POST add / DELETE remove, idempotent),
  `customer/faqs.php` (read-only), `customer/feedback.php` (submit).
- Updated `restaurants/list.php`, `search.php` (both restaurants + items
  blocks), `home/category-items.php` to stamp `is_saved` per row.
- **`restaurants/menu.php` rewritten** — now returns a `restaurant` block
  (id, name, address, logo/cover, cuisine_tags, is_veg_only,
  min_order_amount, rating_avg, **rating_count**, offer_badge_text, tags,
  is_saved) alongside `categories`. This is the fix for bug **1.1**
  (restaurant detail header never showed rating/cuisine/badge) — one round
  trip instead of a separate detail endpoint. **Response shape changed**:
  old shape was `{categories: [...]}`, new shape is
  `{restaurant: {...}, categories: [...]}` — any client reading this
  endpoint must be updated to match (see Android section below).
- `customer/addresses.php` rewritten — same endpoint now handles GET (list,
  with all structured fields), POST (add), **PUT** `?id=` (edit), **DELETE**
  `?id=` (remove). `full_address` is now a computed/concatenated display
  string built server-side from the structured fields on every write
  (house_flat_no, floor, full_address, landmark), kept for backward compat.
- `.htaccess` — new routes added for all of the above, plus
  `PUT/DELETE /customer/addresses/{id}`.

**⚠️ Not yet run anywhere.** Before any Android testing: run
`06_migration_phase36.sql` in phpMyAdmin, then redeploy the whole `backend/`
folder (every file above changed or is new).

### ✅ Done — Android (partial)
- `Models.kt`: added `RestaurantDetail`, `MenuResponse` now carries
  `restaurant: RestaurantDetail?` alongside `categories` (matches the new
  backend shape above), `PromoBanner`/`PromoBannersResult`,
  `PopularItem`/`PopularItemsResult`, `ToggleFavoriteBody/Result`,
  `FavoriteRestaurant`/`FavoriteItem`/`FavoritesResult`,
  `FaqEntry`/`FaqsResult`, `SubmitFeedbackBody`/`Result`. `Address` and
  `AddAddressBody` now carry the structured fields
  (addressType/houseFlatNo/floor/landmark/receiverName/receiverPhone).
  `Restaurant`, `SearchItem`, `MenuItem` all gained `isSaved: Boolean`;
  `Restaurant` also gained `ratingCount: Int`.
- `ApiService.kt`: added `getPromoBanners()`, `getPopularItems()`,
  `getFavorites()`, `addFavorite()`, `removeFavorite()` (DELETE with body —
  uses `@HTTP(method="DELETE", hasBody=true)`), `getFaqs()`,
  `submitFeedback()`, `updateAddress()` (PUT), `deleteAddress()` (DELETE).
- `data/FavoritesManager.kt` (new) — shared helper
  `FavoritesManager.toggle(context, scope, favoriteType, favoriteId,
  currentlySaved, onResult)`. Flips the bookmark icon instantly
  (optimistic UI), calls add/removeFavorite, rolls back + shows a toast
  only on failure. **This is the intended wiring point for every bookmark
  icon** (RestaurantAdapter, SearchResultsAdapter dish cards, MenuAdapter,
  a future PopularItemsAdapter) — none of those adapters call it yet.
- Confirmed `item_restaurant.xml` already has `restaurantBookmark`
  (ImageView) + `bg_bookmark_circle` + `ic_bookmark_outline` from Phase
  3.5 — **`ic_bookmark_filled.xml` does not exist yet**, needs creating
  (same vector shape as `ic_bookmark_outline.xml` but `fillColor` solid
  instead of stroke-only) before the icon can visually toggle state.

### ✅ Done — Android, this session (Phase 3.6 continued, part 1)
1. **`ic_bookmark_filled.xml`** — created (`customer/app/src/main/res/drawable/`),
   same vector path as `ic_bookmark_outline.xml` but solid `fillColor` instead
   of stroke-only, per spec.
2. **`RestaurantDetailActivity.kt` — bug 1.1 fixed.** Now reads
   `response.body()?.data?.restaurant` (the `RestaurantDetail` block) via a
   new `bindRestaurantDetail()` function and populates `detailRating`,
   `detailCuisines`, a new `detailRatingCount` "By Xk+" label (formats e.g.
   1500 → "By 1.5K+", 800 → "By 800+"), the offer badge, tag chips, cover
   image, and the restaurant-level bookmark fill-state — all from one
   `getMenu()` call.
   - `activity_restaurant_detail.xml`: `coverImage` is now wrapped in a
     `FrameLayout` (`coverFrame`) with an offer-badge overlay
     (`detailOfferBadge`) and a bookmark icon (`detailBookmark`) — same
     visual pattern as `item_restaurant.xml`. Info card gained a
     `detailTagsGroup` `ChipGroup` below the rating row.
   - Added `EXTRA_ETA_MINUTES` to the activity's companion object — ETA/
     distance are computed by the list endpoints against the customer's
     location and are **not** part of the `menu.php` restaurant block, so
     `HomeActivity.openRestaurant()` still passes ETA through as an intent
     extra; everything else now self-fetches from `getMenu()` rather than
     relying on extras as the source of truth, per the spec's instruction.
   - Restaurant-level bookmark tap wired through `FavoritesManager.toggle()`.
3. **In-menu category tab bar** (§2.1) — added `categoryTabsScroll` /
   `categoryTabsGroup` (`HorizontalScrollView` + `ChipGroup`) above
   `menuList` in `activity_restaurant_detail.xml`. `RestaurantDetailActivity.
   buildCategoryTabs()` builds one chip per non-empty category (using the
   existing `sort_order`-driven order — no backend change needed) and
   `jumpToCategory()` smooth-scrolls `scrollContainer` (the `NestedScrollView`)
   to that category's header row, found via
   `menuList.findViewHolderForAdapterPosition(...)` — this works because
   `menuList` has `nestedScrollingEnabled="false"` inside a
   `NestedScrollView`, so it lays out all its children up front rather than
   virtualizing them. Tab bar auto-hides when there's only one category.
4. **Bonus — dish-level bookmark wired ahead of schedule** (partial §2.5/
   step 8, restaurant-detail menu only): added an `itemBookmark` `ImageView`
   to `item_menu_item.xml` (top-end corner of the dish image, same
   `bg_bookmark_circle` pattern as the restaurant card). `MenuAdapter.kt`
   constructor now takes a `LifecycleCoroutineScope` param (passed from
   `RestaurantDetailActivity`'s `lifecycleScope`) and wires each dish's
   bookmark through `FavoritesManager.toggle(favoriteType = "menu_item", ...)`,
   tracking optimistic state per-item-id in a local `savedOverrides` map
   (since `MenuItem` itself is an immutable data class).
   **Still NOT wired:** `RestaurantAdapter` (Home restaurant cards),
   `SearchResultsAdapter` (search dish cards), and the not-yet-built
   `PopularItemsAdapter` — these are still step 8's remaining scope.

**File changes this session (part 1):** `drawable/ic_bookmark_filled.xml` (new),
`layout/activity_restaurant_detail.xml`, `layout/item_menu_item.xml`,
`ui/restaurant/RestaurantDetailActivity.kt`, `ui/restaurant/MenuAdapter.kt`,
`ui/home/HomeActivity.kt` (only the one-line `EXTRA_ETA_MINUTES` addition to
`openRestaurant()`).

### ✅ Done — Android, this session (Phase 3.6 continued, part 2)
5. **Search bar + veg toggle redesign** (§1.3). `searchLayout`
   (`TextInputLayout`) replaced with `searchBarContainer`, a plain
   `LinearLayout` pill (`bg_search_pill.xml`, new — `999dp` corners + 1dp
   `outline` stroke, same solid-surface pattern as `bg_card_rounded.xml`)
   containing the search icon, a borderless `EditText` (`searchInput`, same
   id — no Kotlin changes needed for the text-watcher/debounce logic), and a
   new `btnVoiceSearch` mic button (`ic_mic.xml`, new — 24dp viewport,
   `?attr/colorControlNormal` tint, matches `ic_search.xml`'s style).
   `HomeActivity.setupVoiceSearch()` wires the mic button to
   `RecognizerIntent.ACTION_RECOGNIZE_SPEECH` via
   `registerForActivityResult`, drops the recognized text straight into
   `searchInput` (existing `TextWatcher` picks it up and searches
   normally), and catches `ActivityNotFoundException` with an
   `InAppNotifier` error toast on devices with no speech-recognition app.
   Added a `<queries>` block to `AndroidManifest.xml` for
   `android.speech.action.RECOGNIZE_SPEECH` (Android 11+ package-visibility
   requirement for resolving this implicit intent) — no `RECORD_AUDIO`
   permission needed, that's on the resolving app. Veg toggle: same
   `vegToggleContainer`/`Track`/`Thumb`/`Dot`/`Label` ids and logic,
   restyled smaller (46dp→42dp track, 20dp→18dp thumb) to sit flush against
   the shorter pill bar — `HomeActivity`'s pixel-based translateX math in
   `applyVegToggleUi()` already reads the width in code, not hardcoded
   dp-for-dp against XML, so it didn't need touching.
6. **Home scroll restructure** (§1.4) — confirmed real gap from this
   session's research (see prior note) and fixed: `activity_home.xml` now
   wraps `promoBannerContainer` + `categoryList` + `filterScroll` +
   `sectionTitle` + `restaurantList` inside one `NestedScrollView`, itself
   wrapped by `swipeRefresh` (same id, same `SwipeRefreshLayout`) — same
   pattern proven in `activity_restaurant_detail.xml` earlier this session.
   `restaurantList` and `categoryList` both got
   `android:nestedScrollingEnabled="false"` (same trade-off already
   accepted for `menuList`: all rows laid out up front, fine at current
   catalog sizes). `emptyState` stays a fixed sibling overlay, now
   constrained under `searchBarContainer` instead of `sectionTitle` (it no
   longer lives inside the scroll container, so it can't anchor to a view
   that does) — visually identical since it was always centered full-bleed
   below the search row anyway. No `HomeActivity.kt` changes needed for
   this step — every `binding.*` id referenced from Kotlin (`categoryList`,
   `filterScroll`, `sectionTitle`, `restaurantList`, `swipeRefresh`,
   `emptyState`, `promoBannerContainer` + its children) still exists
   unchanged, only their XML nesting moved.

**File changes this session (part 2):** `drawable/bg_search_pill.xml` (new),
`drawable/ic_mic.xml` (new), `layout/activity_home.xml` (search bar + veg
toggle redesign, scroll restructure), `AndroidManifest.xml` (`<queries>` for
speech recognition), `values/strings.xml` (`voice_search`,
`voice_search_prompt`, `voice_search_unavailable`), `ui/home/HomeActivity.kt`
(`setupVoiceSearch()`, `voiceSearchLauncher`).

### ✅ Done — Android, this session (Phase 3.6 continued, part 3)
7. **Promo carousel** (§2.2) — done. Static `FrameLayout` banner
   (`promoBannerContainer`) kept **unchanged** as the fallback; a new sibling
   `promoCarouselContainer` (`ViewPager2` + Material `TabLayout` dot
   indicators) was added above it in `activity_home.xml`. `HomeActivity.
   loadPromoBanners()` calls `getPromoBanners()`; if the list is non-empty it
   shows the carousel and hides the static banner, wires
   `TabLayoutMediator` for the dots, and starts a ~4s auto-advance loop
   (`startPromoAutoAdvance()`, cancelled in `onDestroy()`). If the list is
   empty **or the call fails**, it falls back to the pre-existing
   `showPromoBanner()` (splash-config `home_promo_*` fields) exactly as
   before — untouched. Tap-through per `target_type` is handled in
   `onPromoBannerTapped()`: `restaurant` → opens `RestaurantDetailActivity`
   by id (reuses `openRestaurantById()`), `category` → reuses the same
   category-items flow `reloadCurrentView()` already uses (activates the
   chip, sets section title, calls `loadCategoryItems()`), `url` → plain
   `ACTION_VIEW` browser intent, `none` → no-op (visual-only slide).

**File changes this session (part 3):** `layout/item_promo_banner.xml` (new),
`ui/home/PromoBannerAdapter.kt` (new), `drawable/dot_promo_selected.xml` (new),
`drawable/dot_promo_unselected.xml` (new), `drawable/tab_dot_selector.xml`
(new), `layout/activity_home.xml` (added `promoCarouselContainer` /
`promoCarousel` / `promoCarouselDots`, `promoBannerContainer` itself
untouched), `app/build.gradle` (added `androidx.viewpager2:viewpager2:1.1.0`
— `material:1.11.0` already present covers `TabLayout`/`TabLayoutMediator`),
`ui/home/HomeActivity.kt` (`loadPromoBanners()`, `startPromoAutoAdvance()`,
`onPromoBannerTapped()`, `onDestroy()` added for handler cleanup, call site
changed from `showPromoBanner()` to `loadPromoBanners()` in `onCreate()`).

### ✅ Done — Android, this session (Phase 3.6 continued, part 4)
8. **Popular dishes row + inline ADD button everywhere** (§1.6 + §2.4) —
   done. **Blocker resolved first:** `CartManager.add()`/`decrease()` stayed
   typed to `MenuItem` only (not widened) — instead added
   `SearchItem.toMenuItem()` and `PopularItem.toMenuItem()` extension
   functions in `Models.kt` that convert at the call site (variants/addons
   default empty, prepTime defaults 0 — neither read by cart/UI for
   no-variant inline adds). New `item_popular_dish.xml` +
   `PopularItemsAdapter.kt` — horizontal row on Home between the filter
   chips and the restaurant list, reads `getPopularItems()`, hides itself
   entirely if the list is empty or if search/category-browse is active,
   reloads on veg-toggle flip. `item_search_dish.xml` got the same
   ADD/qty-stepper overlay as `item_menu_item.xml`'s existing pattern
   (`btnAdd` ↔ `qtyStepper`/`btnIncrease`/`btnDecrease`/`itemQuantity`,
   `refreshQtyUi()`). **Bug 1.6 fixed** in `SearchResultsAdapter.kt`'s
   `DishVH`: card body (image/name/price/tag) still opens the restaurant via
   `onDishClick`, but the new ADD/qty-stepper buttons have their own click
   listeners that consume the tap and never bubble up to the card's
   `onClickListener`. Category-items browsing already reuses
   `SearchResultsAdapter`, so this single fix covers search results *and*
   category-browse results.
9. **Bookmark icons wired everywhere** (§2.5) — done. `RestaurantAdapter`
   (Home restaurant cards) and `SearchResultsAdapter`'s `RestaurantVH`
   (search-result restaurant cards) now call `FavoritesManager.toggle()`,
   optimistic flip with rollback on failure, same pattern `MenuAdapter` and
   `SearchResultsAdapter.DishVH` already used. All adapter types with a
   bookmark icon are now wired: `RestaurantAdapter`, `SearchResultsAdapter`
   (both dish and restaurant cards), `MenuAdapter`, `PopularItemsAdapter`.
10. **Explore More tile row** (§2.3) — done. Backend: added
    `filter=has_offer` branch to `restaurants/list.php`
    (`WHERE offer_badge_text IS NOT NULL`); added optional `per_page` query
    param to `ApiService.getRestaurants()` for "Top 10"
    (`sort=rating&per_page=10`, purely client-side otherwise, no backend
    change needed for that one). New `item_explore_tile.xml`,
    `bg_explore_tile_icon.xml`, 4 new vector icons (`ic_offer_tag`,
    `ic_top_ranked`, `ic_train`, `ic_collections` — no emoji, per project
    rule), `ExploreTileAdapter.kt`. Row added to `activity_home.xml` below
    `restaurantList`. Offers/Top 10 tiles reuse the same restaurant-list
    flow as a filter-chip tap; "Food on train"/"Collections" show a
    "Coming soon" `InAppNotifier` toast, exactly per the 3.6 doc's explicit
    out-of-scope call for those two.
11. **Structured Address Book form** (§2.6) — done. New shared
    `fragment_address_editor.xml` + `AddressEditorBottomSheet.kt` (type
    chips Home/Work/Other, house/flat no., floor, area/street/city,
    landmark, receiver name + phone, "Use current location"). Supports add
    mode and edit mode (pre-fills from an existing `Address`), validates
    required fields client-side, calls `addAddress`/`updateAddress` — both
    already accepted every one of these fields server-side, this closed a
    UI gap only, no backend change needed for step 11 itself.
    `CheckoutActivity.kt` rewired: removed the old single free-text
    `inputNewAddress` + inline `btnSaveAddress`/`btnUseCurrentLocation`
    flow entirely, replaced with a `btnAddAddress` button that opens the
    shared sheet. `CheckoutActivity` now implements
    `AddressEditorBottomSheet.LocationRequester` so the sheet's "Use
    current location" reuses Checkout's existing GPS/Geocoder logic (a
    bottom sheet has no Activity context of its own for permission
    prompts). Long-press on a saved address row in Checkout now opens the
    sheet in edit mode for that address. This is the shared editor step 12
    (Address Book list screen) will reuse for its own add/edit flows.

**File changes this session (part 4):** `network/Models.kt`
(`SearchItem.toMenuItem()`, `PopularItem.toMenuItem()` extension
functions), `layout/item_popular_dish.xml` (new),
`ui/home/PopularItemsAdapter.kt` (new), `layout/item_search_dish.xml`
(bookmark + ADD/qty-stepper overlay added), `ui/search/
SearchResultsAdapter.kt` (bug 1.6 fix, bookmark wiring on both `DishVH` and
`RestaurantVH`), `layout/activity_home.xml` (Popular row + Explore tile row
added), `ui/home/HomeActivity.kt` (`PopularItemsAdapter` wiring,
`loadPopularItems()`, `setupExploreTiles()`, `onExploreTileTapped()`,
`loadRestaurants()` gained optional `sort`/`perPage` params),
`ui/home/RestaurantAdapter.kt` (bookmark wiring, gained `lifecycleScope`
constructor param), `backend/api/v1/restaurants/list.php` (`has_offer`
filter branch), `network/ApiService.kt` (`getRestaurants()` gained
`per_page` param), `layout/item_explore_tile.xml` (new),
`drawable/bg_explore_tile_icon.xml` (new), `drawable/ic_offer_tag.xml`
(new), `drawable/ic_top_ranked.xml` (new), `drawable/ic_train.xml` (new),
`drawable/ic_collections.xml` (new), `ui/home/ExploreTileAdapter.kt` (new),
`layout/fragment_address_editor.xml` (new), `ui/address/
AddressEditorBottomSheet.kt` (new), `layout/activity_checkout.xml` (address
block replaced), `ui/checkout/CheckoutActivity.kt` (address flow rewired,
implements `LocationRequester`), `values/strings.xml` (all new
step 8-11 strings).

**Groundwork laid for step 12, not yet wired to any UI (safe, unused,
doesn't affect anything else):** `backend/api/v1/orders/list.php` (new —
paginated order history endpoint, joins `orders`+`restaurants`, verified
column names against `01_schema.sql`: `cover_url` not `cover_image_url` —
caught and fixed during this pass), `ApiService.getOrderHistory()` (new
method), `Models.kt`'s `OrderHistoryEntry`/`OrderHistoryResult` (new data
classes). These compile fine and don't break anything since nothing calls
them yet — next session can use them directly for the Order History screen.

**Reverted in a previous session — do not treat as done:** a first attempt
at the full Profile screen (`ProfileActivity.kt`, `activity_profile.xml`,
`item_profile_menu_row.xml`) was built but referenced 6 sub-screen classes
that didn't exist yet — that combination wouldn't compile, so all three
files were deleted. This session built those sub-screens for real, one at
a time, verifying compile-consistency after each before moving to the
next (see below) — the lesson from that revert.

### ✅ Done — Android, this session (Phase 3.6 continued, part 5 — step 12,
### Profile sub-screens 1-4 of 6)
Building step 12's Profile screen sub-screens first, `ProfileActivity`
itself last, so the entry point only gets wired once every reference it
needs actually exists (this is the direct lesson from last session's
revert). 4 of 6 sub-screens done this session:

- **AddressBookActivity** (`ui/profile/AddressBookActivity.kt` +
  `AddressAdapter.kt` + `layout/item_address_card.xml`) — list via
  `getAddresses()`, reuses step 11's `AddressEditorBottomSheet` as-is for
  both add (header + button) and edit (tap Edit on a card) — no new form
  code needed. Delete via `deleteAddress()` with a confirm dialog.
  Implements `AddressEditorBottomSheet.LocationRequester` (same
  GPS/Geocoder pattern as `CheckoutActivity`) so "Use current location"
  inside the sheet works here too.
- **OrderHistoryActivity** (`ui/profile/OrderHistoryActivity.kt` +
  `OrderHistoryAdapter.kt` + `layout/item_order_card.xml` +
  `drawable/bg_status_pill.xml`) — first real caller of the
  `orders/list.php`/`getOrderHistory()` groundwork laid last session.
  Infinite-scroll pagination (`has_more`/`page` drive whether another page
  loads), status-colored pill badges (delivered=green,
  cancelled/rejected/failed/refunded/expired=red, everything else=primary
  color), tapping a card opens the existing `OrderStatusActivity` by id.
- **SavedActivity** (`ui/profile/SavedActivity.kt` +
  `SavedRestaurantAdapter.kt` + `SavedDishAdapter.kt` +
  `layout/activity_saved.xml` + `layout/item_saved_restaurant.xml` +
  `layout/item_saved_dish.xml`) — Restaurants/Dishes tabs via a plain
  `TabLayout` (not ViewPager2/fragments — simpler for a fixed 2-tab
  screen, just toggles which of two RecyclerViews is visible). Single
  `getFavorites()` call feeds both tabs at once (the endpoint already
  returns `{restaurants: [...], items: [...]}` together). Every entry here
  is by definition currently-saved, so the bookmark icon only ever removes
  — reuses the existing `FavoritesManager.toggle()` with
  `currentlySaved = true` hardcoded, removing the item from the local list
  on success (no new favorites endpoint needed).
- **FaqsActivity** (`ui/profile/FaqsActivity.kt` + `FaqAdapter.kt` +
  `layout/item_faq_card.xml`) — expandable question/answer cards
  (tap question → toggles that card's answer + rotates a chevron, only one
  expanded at a time), backed by `getFaqs()`.

Shared layout `layout/activity_simple_list.xml` (new — header with back +
optional action icon, `SwipeRefreshLayout` + `RecyclerView` + empty state)
is reused by AddressBook, OrderHistory, and FAQs. Saved has its own layout
since it needs the tab bar + two RecyclerViews.

**Every one of these 4 screens is individually verified:** brace/paren
balance-checked, every layout id referenced in Kotlin confirmed to exist
in its XML, registered in `AndroidManifest.xml`. **None of them are
reachable from any UI yet** — nothing launches `AddressBookActivity`,
`OrderHistoryActivity`, `SavedActivity`, or `FaqsActivity` except this
zip's own manifest entries. `HomeActivity.kt`'s `btnProfile` still does
the original immediate-logout behavior — untouched. This is deliberate and
safe: confirmed via repo-wide grep that nothing references the (still
nonexistent) `ProfileActivity`, so there's no dangling-reference risk in
this state, same pattern as the previous session's checkpoint.

**File changes this session (part 5):** `layout/activity_simple_list.xml`
(new), `layout/item_address_card.xml` (new), `ui/profile/AddressAdapter.kt`
(new), `ui/profile/AddressBookActivity.kt` (new), `layout/item_order_card.xml`
(new), `drawable/bg_status_pill.xml` (new), `ui/profile/OrderHistoryAdapter.kt`
(new), `ui/profile/OrderHistoryActivity.kt` (new), `layout/activity_saved.xml`
(new), `layout/item_saved_restaurant.xml` (new), `layout/item_saved_dish.xml`
(new), `ui/profile/SavedRestaurantAdapter.kt` (new),
`ui/profile/SavedDishAdapter.kt` (new), `ui/profile/SavedActivity.kt` (new),
`layout/item_faq_card.xml` (new), `ui/profile/FaqAdapter.kt` (new),
`ui/profile/FaqsActivity.kt` (new), `AndroidManifest.xml` (4 new activity
registrations), `values/strings.xml` (`label_default`,
`receiver_line_format`, `order_meta_format`,
`dish_price_and_restaurant_format`, `empty_faqs`, plus the Profile-section
strings already added last session — `address_book_title`,
`order_history_title`, `saved_title`, `faqs_title`, `empty_addresses`,
`empty_orders`, `empty_saved_restaurants`, `empty_saved_dishes`,
`saved_restaurants_tab`, `saved_dishes_tab`, `btn_edit`, `btn_delete`,
`delete_address_confirm` — these were added last session in anticipation
but are only actually used starting this session).

### ✅ Done — Android, this session (Phase 3.6 continued, part 6 — step 12b/12c)
- **FeedbackActivity** (`ui/profile/FeedbackActivity.kt` +
  `layout/activity_feedback.xml`) — required multi-line message field
  (inline validation error, clears as soon as the user types something),
  optional 5-star row (tap a star to select, tap the same star again to
  clear it back to "no rating" since the field is optional), submit
  button disabled while the request is in flight, calls
  `submitFeedback()` with `rating = null` when no star was tapped (not
  `0` — matches the backend's "absent means no rating" handling).
- **RateUsDialog** (`ui/profile/RateUsDialog.kt` +
  `layout/dialog_rate_us.xml`) — `BottomSheetDialog`, star-rating only
  (no free-text field, that's what Feedback is for), requires picking at
  least one star before Submit is accepted (inline error otherwise),
  sends the fixed `rate_us_default_message` string alongside the rating
  since the backend rejects a blank `message`. "Maybe later" just
  dismisses. Takes an `AppCompatActivity` (not a bare `Activity`) so it
  can use `lifecycleScope` for the network call.

**Both individually verified:** brace/paren balance-checked, every layout
id referenced in Kotlin confirmed to exist in its XML, every string/color/
drawable they reference confirmed to already exist in the repo.
`FeedbackActivity` registered in `AndroidManifest.xml`; `RateUsDialog` is a
plain dialog object (no manifest entry needed, same as
`NotificationPermissionDialog`). **Neither is reachable from any UI yet** —
nothing calls `FeedbackActivity` or `RateUsDialog.show()` except this zip's
own manifest entry for the activity. `HomeActivity.kt`'s `btnProfile` still
does the original immediate-logout behavior — untouched, same
deliberate-checkpoint pattern as part 5.

Also started (not finished) `layout/item_profile_menu_row.xml` — the
per-row layout (icon + label + chevron, chevron = `ic_back` rotated 180°,
same reuse pattern as the FAQ card's expand chevron) that `ProfileActivity`
will inflate 7 times. This file is unused by anything yet, so it carries no
dangling-reference risk in this checkpoint.

**File changes this session (part 6):** `layout/activity_feedback.xml`
(new), `ui/profile/FeedbackActivity.kt` (new), `layout/dialog_rate_us.xml`
(new), `ui/profile/RateUsDialog.kt` (new), `layout/item_profile_menu_row.xml`
(new, unused so far), `AndroidManifest.xml` (1 new activity registration —
`FeedbackActivity`), `values/strings.xml` (`error_feedback_message_required`,
`feedback_submitted`, `rate_us_prompt_stars`, `error_select_rating`,
`rate_us_submitted`, `rate_us_default_message`, `rate_us_maybe_later`,
`account_basics_title`, `logout_confirm_positive`, `btn_cancel`,
`error_generic` — the rest of the Profile-section strings, e.g.
`feedback_title`/`rate_us_title`/`menu_*`/`profile_title`, already existed
from an earlier session).

**Icon reuse (used as-is, no purpose-built icons were requested):**
`ProfileActivity`'s 7 rows use: Address Book → `ic_location`, Order
History → `ic_restaurant`, Saved → `ic_bookmark_filled`, FAQs →
`ic_error` (a filled circle with a bar — reads close enough to a
question mark at 22dp, same "near-fit reuse" pattern already used for
the chevron and for Explore tiles' `ic_collections`/`ic_train`), Rate
Us → `ic_star`, Feedback → `ic_mail`, Logout → `ic_logout`. Swapping any
of these for a purpose-built icon later is a one-line change
(`rowIcon.setImageResource(...)` in `ProfileActivity.kt`).

### ✅ Done — Android, this session (Phase 3.6 continued, part 7 — step 12d,
### ProfileActivity — Phase 3.6 Android now fully complete)
- **`ProfileActivity.kt` + `activity_profile.xml`** — built last, only
  after confirming every sub-screen it references already existed and
  compiled on its own (this was the exact lesson from the earlier
  revert — see note above). Inflates `item_profile_menu_row.xml` 7
  times into a plain `LinearLayout` container (`profileMenuContainer`),
  with a 1dp `outline`-colored divider `View` added between rows in
  code (no new divider drawable needed).
  - Account basics header shows `TokenManager.getEmail()` only, per
    plan (no name/phone stored locally).
  - Row wiring: Address Book → `AddressBookActivity`, Order History →
    `OrderHistoryActivity`, Saved → `SavedActivity`, FAQs →
    `FaqsActivity`, Rate Us → `RateUsDialog.show(this)`, Feedback →
    `FeedbackActivity`, Logout → confirm `AlertDialog` (reusing
    `logout_confirm_title`/`logout_confirm_message`/
    `logout_confirm_positive`/`btn_cancel`) → on confirm,
    `TokenManager.clear()` + `CartManager.clear()` + navigate to
    `LoginActivity` + `finish()` — same effect as Home's old immediate
    logout, but now behind a confirmation, which Home's version never
    had.
- **`AndroidManifest.xml`** — `ProfileActivity` registered
  (`exported="false"`, same as every other Profile sub-screen).
- **`HomeActivity.kt` rewired** — `btnProfile` now launches
  `ProfileActivity` instead of doing the old immediate-logout directly.
  The now-unused `TokenManager` import was removed from this file
  (`CartManager` import stays — still used elsewhere for the cart
  badge count).
- **Repo-wide compile-consistency sweep** (same checks as prior
  sessions): Kotlin brace/paren balance — 0 mismatches across all `.kt`
  files. XML validity — 129 files parsed, 0 invalid. PHP brace/paren
  balance — 1 file flagged by a naive character count
  (`backend/api/v1/search/search.php`), investigated by hand: the
  "extra" `)` characters all sit inside `//` comments (e.g. "(Mapped
  from...)"), not in code — false positive, not a real imbalance, and
  this file wasn't touched this phase anyway. Every layout id
  `ProfileActivity.kt` references (`btnBack`, `screenTitle`,
  `profileEmail`, `profileMenuContainer`, `rowIcon`, `rowLabel`)
  cross-checked against `activity_profile.xml` and
  `item_profile_menu_row.xml` — all present, no dangling references.

**Phase 3.6 Android is now fully done (steps 1–11 + all of step 12).**
Nothing has been deployed or tested on a device yet — see deployment
checklist below.

**File changes this session (part 7):** `ui/profile/ProfileActivity.kt`
(new), `layout/activity_profile.xml` (new), `AndroidManifest.xml` (1 new
activity registration — `ProfileActivity`), `ui/home/HomeActivity.kt`
(`btnProfile` rewired to launch `ProfileActivity`; removed unused
`TokenManager` import).

**Known snag, still applies:** `SplashConfig.homePromoEnabled` etc. (the
old single-banner settings) must stay working as a fallback per the 3.6
doc §2.2 — confirmed still intact after this session's changes (nothing
in part 7 touched `HomeActivity`'s promo-banner code path or
`SplashConfig`).

**Reminder — nothing in Phase 3.6 has been deployed or tested on a device
yet** (backend or Android), even though both are now fully code-complete.
This zip is source-only. The migration file
`backend/sql/06_migration_phase36.sql` still needs to run before any of
Phase 3.6 can be tested — see deployment checklist below.

**Backend files touched or added across this phase that need deploying
together with the next Android build:** `backend/api/v1/restaurants/list.php`
(`has_offer` filter), `backend/api/v1/orders/list.php` (new), plus every
file listed under "Done — Backend (fully complete, not yet deployed)"
above (migration, `favorites.php`, the new `home/`/`customer/` endpoints,
the rewritten `restaurants/menu.php` and `customer/addresses.php`,
`.htaccess`).

## Deployment checklist (do this before testing anything in Phase 3.6)
1. Run `backend/sql/06_migration_phase36.sql` in phpMyAdmin (after
   confirming `05_migration_categories_and_tags.sql` already ran).
2. **Breaking change to check for:** `restaurants/menu.php`'s response
   shape changed from the old `{categories: [...]}` to the new
   `{restaurant: {...}, categories: [...]}`. Any client code (or manual
   testing / Postman collections) still assuming the old shape will break.
   Customer app's `MenuResponse` model already matches the new shape — this
   is only a concern for anything outside this repo that also calls
   `menu.php` directly.
3. Copy the updated `backend/` folder into the KS Web `anydrop` folder
   (includes the `has_offer` filter addition to `restaurants/list.php`, the
   new `orders/list.php`, and every Phase 3.6 backend file listed above —
   the whole folder, not a partial copy).
4. Push `customer/` changes from Termux, confirm `build-customer.yml`
   passes.
5. Install the APK and manually verify, in order: Home shows the promo
   carousel (or falls back to the static banner if `getPromoBanners()`
   returns empty), the "Popular dishes near you" row appears between the
   filter chips and the restaurant list (or hides itself if empty), tapping
   ADD on a Popular/Search dish card adds to cart without opening the
   restaurant, tapping the card body itself still opens the restaurant,
   bookmark icons toggle correctly on restaurant cards, search-result dish
   cards, and search-result restaurant cards, the Explore More row shows
   Offers/Top 10/Food on train/Collections and Offers+Top10 actually filter
   the restaurant list, Checkout's "Add new address" opens the new
   structured form (not the old free-text field) and successfully saves an
   address with all fields, tapping the Home profile icon opens the new
   Profile screen (not an immediate logout), Profile's email line shows the
   logged-in account, each of the 7 rows opens the right destination
   (Address Book / Order History / Saved / FAQs / Rate Us dialog /
   Feedback form / Logout confirm dialog), and Logout only clears the
   session after confirming (not immediately on tap).
6. **GitHub personal access token** — if not already revoked, revoke it now
   at github.com/settings/tokens.

## Next Step (historical — this was the actionable step for the *previous*
Phase 3.5 catalog migration, kept here for history; superseded by the
"Deployment checklist" above for Phase 3.6)
1. Run `backend/sql/05_migration_categories_and_tags.sql` in phpMyAdmin
   (after confirming `04_migration_order_settings.sql` already ran).
2. Copy the updated `backend/` folder into the KS Web `anydrop` folder.
3. Visit `https://yourdomain/anydrop/scripts/seed-demo-catalog.php?key=SEED_ME`
   once in a browser — confirm it reports 15 restaurants seeded, then delete
   the file the same way `seed-test-data.php` gets deleted after use.
4. Push `customer/` changes from Termux, confirm `build-customer.yml` passes.
5. Install the APK, open Home: category row should show 10 icons, restaurant
   cards should show offer badges + tag chips, and the notification popup
   should appear once with the animated bell. Search "pizza" or a restaurant
   name and confirm both the restaurant's own items and "Also available at"
   items from other restaurants appear, each tagged with its restaurant name.

Report back what works / what breaks.

## UI/UX Requirements (noted for all future phases)
- **No emoji anywhere in the UI.** Use proper vector icons.
- If a specific icon isn't cleanly available as a Material icon, flag it instead of substituting an emoji.
- **In-app popup notifications** required in the Customer app (has the full banner system). Restaurant app currently uses simple Toasts — revisit if that's not enough in practice.
- **In-app update check** required — customer app has it; restaurant app doesn't have its own version-gate popup yet (uses the same `app-version.php` endpoint pattern, just not wired into the restaurant app's splash — restaurant app has no splash screen at all yet).
- **Skeleton/shimmer loaders, progress bars, and loading spinners — build LAST, once the rest of the app is functionally complete.** Explicit instruction from the app owner (2026-08-07): don't build loading-state polish per-screen as each screen is built; do one consistent pass across every screen (Home, Search, Restaurant Detail, Checkout, Orders, etc.) at the very end, after all other features/screens are done. Reason: loading-state UI depends on knowing the final shape/layout of each screen's content, and doing it once at the end keeps the loading style consistent app-wide instead of ad-hoc per screen. Phase E's existing "shimmer/skeleton loading placeholders" item (§5) is this same task — tracked there, just re-noted here so it isn't picked up early by mistake.

## Known Limitations / Flagged Risks
- **`is_bestseller` / `discount_percent` (menu_items) have no UI to set them yet** — the columns exist and are already fetched/rendered everywhere (features.md §3/§4's badges, popular-items.php, cart-sync.php), but nothing writes to them. No admin/restaurant-side toggle, no automatic order-count-based logic. **Decision (2026-08-09, user's call):** leave as manual `UPDATE menu_items SET is_bestseller=1, discount_percent=20 WHERE id=...` via phpMyAdmin for now; build a real control (restaurant-side toggle, or automatic, or both — undecided) as its own future feature, not bundled into features.md's UI-only pass.
- **`is_spicy` / `is_kids_choice` (menu_items, added 2026-08-09 part 3 for features.md §1's dietary chips) — same situation as above, no UI to set them yet.** Manual `UPDATE menu_items SET is_spicy=1, is_kids_choice=1 WHERE id=...` via phpMyAdmin for now; folds into the same future "real control for these manual flags" feature as `is_bestseller`/`discount_percent` above rather than a separate one-off.
- **Backend currently runs locally on-device (KS Web)** — must migrate to InfinityFree before real use. Only `config/config.php` and each app's `ApiClient.kt` base URL need to change.
- Address book has no map picker — free-text address only; lat/lng optional and unused until Phase 4 needs them for rider routing
- Restaurant app has no splash/update-check screen (Customer app does) — not built this phase, flag if wanted before Phase 4
- Restaurant app notifications are simple Toasts, not the custom in-app banner the Customer app uses
- Delivery charge and packing charge are flat settings, not distance-based — real distance-based delivery pricing is Phase 4 scope (needs OSRM)
- Rider assignment doesn't exist yet — once an order is "ready" there's no restaurant-side or automatic next step; that's explicitly Phase 4
- Email OTP delivery still stubbed (`debug_otp`) — unchanged from Phase 2, still a near-term follow-up
- `POST /auth/customer/google` still not implemented — unchanged from Phase 2
- KS Web's rewrite-rule support is unconfirmed — direct `.php` file paths (with `?id=`) used throughout Phase 3 endpoints specifically so they work either way, same convention the Phase 1/2 endpoints already used
- No signing/release pipeline yet — debug builds only until Phase 7
- A GitHub personal access token was pasted directly into chat earlier in the project; should be revoked/regenerated at https://github.com/settings/tokens if not already done

## Phase 3 Next Step (order-loop testing — do after the Phase 3.5 steps above)
1. Run `backend/sql/04_migration_order_settings.sql` in phpMyAdmin
2. Copy the updated `backend/` folder into the KS Web `anydrop` folder
3. Push `customer/` and the new `restaurant/` folder from Termux
4. Confirm both GitHub Actions builds pass, install both APKs
5. Walk through the full order loop described above on both apps

Report back what works / what breaks, then move to Phase 4 (Rider App + Live Tracking) or fix anything broken first.

---

## Part 13 — Rating System (Customer app only, 2026-08-10)

**Scope decided with app owner:** Restaurant + Food + Delivery split (matches
the original `reviews` table design), no restaurant-side reply/view yet
(future feature), prompted both automatically right when an order hits
`delivered` and manually via a "Rate Order" button in Order History.

**What was built:**
- `reviews` table already existed in `01_schema.sql` (designed early, never
  wired up) — `backend/sql/15_migration_rating_system.sql` adds a UNIQUE key
  on `order_id` (one review per order) and an index on `restaurant_id`.
- `backend/lib/reviews.php` — `recalc_restaurant_rating()` (full recompute
  of `restaurants.rating_avg`/`rating_count` from `reviews.restaurant_rating`
  after each insert) and `require_ratable_order()` (order must belong to the
  caller and be `delivered`).
- `backend/api/v1/customer/reviews.php` — `POST` (submit; `restaurant_rating`
  required 1-5, `food_rating`/`delivery_rating` optional 1-5, `comment`
  optional; 409 `already_reviewed` if the order's already been rated) and
  `GET ?order_id=` (fetch the existing review, or `null` — lets the app show
  "Rate Order" vs "Rated" without guessing from order status alone).
- `orders/list.php` gained `is_rated` and `has_rider` per order (drives the
  Order History card's button state and whether the dialog shows a delivery
  star row at all).
- `format_order()` (used by `orders/detail.php` and the track flow) gained
  `restaurant_name` and `rider_id` so the auto-prompt can build its label
  without a second network round-trip.
- Customer app: `RateOrderDialog` (new, `ui/orders/`) — bottom sheet with
  3 star rows built programmatically (restaurant required, food + delivery
  optional, delivery row hidden entirely if the order had no rider) plus an
  optional comment field. Reused the existing `ic_star`/`rating_gold`/
  `outline` pattern from `RateUsDialog` rather than inventing a new one.
- `OrderStatusActivity` — when polling first sees `status == "delivered"`,
  checks `GET reviews?order_id=` and shows the dialog once if not already
  rated (silently skips on any network error — not worth interrupting the
  delivered-order screen for).
- `OrderHistoryAdapter`/`item_order_card.xml` — delivered + unrated order
  cards show a "Rate Order" chip; delivered + rated cards show a small
  "★ Rated" label instead. Submitting from here updates that one card in
  place (`adapter.markRated()`) rather than reloading the whole list.

**Not built (explicitly deferred, app owner's call):** restaurant app
reviews list / reply UI, and any customer-facing "see all reviews for this
restaurant" screen on Restaurant Detail — `GET /reviews.php` only supports
lookup by `order_id` right now, not by `restaurant_id`. `idx_reviews_restaurant`
was still added in the migration so that listing endpoint is cheap to add
later without another schema change.

**Deployment checklist:**
1. Run `backend/sql/15_migration_rating_system.sql` in phpMyAdmin.
2. Copy the updated `backend/` folder into the KS Web `anydrop` folder (new
   `lib/reviews.php`, `api/v1/customer/reviews.php`, updated
   `orders/list.php`, `lib/orders.php`, `.htaccess`).
3. Push `customer/` changes from Termux, confirm `build-customer.yml` passes.
4. Install the APK and manually verify: place an order through to
   `delivered` (or flip an existing test order's status via phpMyAdmin) and
   confirm the rating sheet pops up automatically on the order-status
   screen; submit a rating and confirm it doesn't pop up again on revisit;
   in Order History, confirm delivered+unrated orders show "Rate Order" and
   tapping it, submitting, updates that card to "★ Rated" in place; confirm
   a restaurant's star rating on Home/Search/Restaurant-list actually moves
   after a few test ratings (rating_avg/rating_count recalculated).

Report back what works / what breaks.

---

## H6 part 2 — Map pin-drop + photo (backend + Android scaffolding, 2026-08-10)

Backend for the door/building photo feature is done (migration, new
`address-photo.php` upload endpoint, `addresses.php` updated to
read/write `photo_url`). Android scaffolding is done (models, API method,
osmdroid dependency, manifest entry, strings, drawables, full layout for
`activity_map_pin_drop.xml`). **`MapPinDropActivity.kt` itself — the
osmdroid/geocoding/save wiring — is not written yet**, and neither part 1
nor part 2 has been built or run. Full detail in
`docs/12_Handover_H6_Map_PinDrop_Photo.md`; say "continue H6" to resume.

---

## H6 — Google Maps migration + app rename to Anydrop (2026-08-12)

Two things happened this session, in order:

1. **App renamed Qorix → Anydrop.** `com.qorix.customer` →
   `com.anydrop.food`, `com.qorix.restaurant` → `com.anydrop.restaurant`.
   Every `.kt`/`.xml`/`.gradle`/`.php`/`.md`/`.sql` reference to
   "Qorix"/"qorix" updated project-wide (package dirs physically moved,
   not just text-replaced). Rider app package will be `com.anydrop.rider`
   whenever that app gets built (still an empty folder, Phase 4 not
   started).
2. **`MapPinDropActivity.kt` migrated from osmdroid to Google Maps SDK**,
   along with `activity_map_pin_drop.xml` (MapView tag swapped, search bar
   removed entirely — see below), `customer/app/build.gradle` (osmdroid
   dependency removed, `play-services-maps` added), and
   `AndroidManifest.xml` (new `com.google.android.geo.API_KEY` meta-data
   tag, currently pointed at a placeholder string). Also: **search bar cut
   entirely** for this update (not just hidden behind an "Order for
   other" button as earlier planned) — current-location and manual
   pin-drop are the only two address-entry paths now. Code is structurally
   complete and should compile, but **the map will render blank/grey at
   runtime** until a real Android-restricted Maps key replaces the
   placeholder in `strings.xml`'s `google_maps_key` — Google Cloud billing
   still isn't set up. Full detail, including what's still interim
   (on-device Geocoder instead of backend-proxied Geocoding API) and what
   wasn't done (Live Tracking screen, backend Geocoding endpoint), in
   `docs/12_Handover_H6_Map_PinDrop_Photo.md`'s "Google Maps SDK migration
   plan" section.

Also recorded in that same doc (not yet built — Rider app doesn't exist):
the full rider navigation → background-GPS-tracking → manual return →
drop-off-OTP sequence, for whenever Phase 4 starts.

**Next actual step:** either (a) get the Google Cloud billing card set up
and generate the two real keys (Android-restricted for Maps SDK,
server-only for Geocoding/Directions) so this screen can actually be
tested end to end, or (b) do a build-only sanity pass now (gradle
sync/compile) to catch any migration mistakes before billing is sorted,
since the map rendering blank is expected/fine for that kind of check.
Say which.

## I4 — Scheduled orders (2026-08-13) — ✅ DONE, confirmed working on-device

Started I4 ("Schedule for later") per app owner's explicit scope call:
**same-day only** — a time-slot picker bounded to today's remaining open
hours, not a date+time picker.

Backend, Android models/cart storage/slot-picker sheet, restaurant-detail
entry point, and finally `CheckoutActivity.kt` (delivery-time row,
sheet wiring, `scheduledFor` sent with the order, 422 error handling) are
all done. **Confirmed working end-to-end by the app owner** — schedule
pick → checkout → order placed successfully.

Full detail in `docs/15_Handover_I4_Scheduled_Orders.md`.

**Still open, lower priority (not blockers):**
- `OrderStatusActivity`/order-history doesn't show `scheduled_for` yet,
  even though the API already returns it.
- Restaurant App's order list/detail doesn't surface `scheduled_for` —
  restaurant staff currently can't tell a scheduled order apart from an
  ASAP one.
- No Gradle build log was captured this session (app owner tested the
  installed APK directly) — worth a clean build pass at some point as a
  sanity check, not urgent since it's confirmed working live.

## Next up — I4 follow-ups + new "pause taking orders" toggle (2026-08-13, planned only)

Two small I4 leftovers (above) plus a **new feature request**: restaurant
should be able to mark itself as not accepting orders right now, and
resume whenever ready, independent of its fixed opening/closing hours.
Turns out the DB already has an unused `operational_status` column and
`is_open_now` already reads it — the read side is done, just no
restaurant-facing endpoint/UI to write it, and `orders/create.php` doesn't
enforce it yet either (needs to, so the pause actually blocks orders, not
just hides the restaurant from the list). Full plan, scope questions, and
suggested build order in `docs/16_Handover_I4_Followups_And_Order_Toggle.md`.
Nothing built yet — say "start toggle" or "start I4 followups" to begin.

**Note (2026-08-13, later):** this section is stale — Part A and Part B
from `docs/16` were in fact built later the same day (restaurant-facing
`status-update.php` endpoint, `DashboardActivity`'s "Accepting orders"
switch, `scheduled_for` surfaced in both apps). See the bug-fix entry
below for what was still missing even after that.

---

## Bug fix pass — closed restaurant could still receive orders (2026-08-13)

**Reported by app owner:** orders were getting placed against restaurants
that were closed (outside their `opening_time`/`closing_time`/
`working_days` window).

**Root cause, confirmed by reading `lib/orders.php`'s `price_cart()`:**
the Part B pause-toggle work added a check for
`restaurants.operational_status !== 'open'`, but that only covers the
on-demand pause (busy/temp_closed/etc.) — it never re-checked the
restaurant's fixed hours the way `restaurants/list.php`'s `is_open_now`
does. A restaurant sitting at its default `operational_status = 'open'`
(i.e. never paused) but simply outside its opening hours could still have
a "Deliver Now" order placed against it, because nothing on the order path
looked at `opening_time`/`closing_time`/`working_days` at all.

**Fix:**
- `backend/lib/orders.php` — `price_cart()` now takes an optional
  `$scheduledForRaw` param. For a "Deliver Now" order (no scheduled slot),
  it now runs the same open-hours/working-day check `restaurants/list.php`
  uses, and fails fast with a new `restaurant_closed` error if the
  restaurant is outside its window. Scheduled orders skip this right-now
  check (their own target slot is already checked by
  `validate_scheduled_for()`), so scheduling ahead for a restaurant that's
  currently closed still works correctly.
- `backend/api/v1/orders/create.php` and `backend/api/v1/cart/validate.php`
  — both now pass `scheduled_for` through to `price_cart()` so the actual
  order-placement call and the checkout bill-preview call agree.
- Customer App — `CartValidateBody` gained `scheduled_for` (sent from both
  `loadBill()` and `applyCoupon()` in `CheckoutActivity`), and the
  place-order error handling now shows a real message for
  `restaurant_closed` / `restaurant_not_accepting_orders` instead of
  falling through to the raw error code string (`R.string.restaurant_closed_error`,
  `R.string.restaurant_paused_error` — new strings in `strings.xml`).

**Not yet done:** a Gradle build/sanity pass on the Customer App changes,
and a live device retest placing an order against a restaurant that's
currently outside its hours (should now fail with a clear message) and
one that's within hours (should still succeed as before).

---

## Full re-audit — corrections + new scope (2026-08-13, later)

App owner asked for a ground-truth re-check of the codebase (not just doc
claims) before adding new scope. Findings below.

### Corrections to earlier claims in this doc
- **§2.5 Floating "Menu" jump button** — built, but **simplified vs original
  spec**: uses a plain `FloatingActionButton` + `PopupMenu`
  (`showCategoryJumpMenu()` in `RestaurantDetailActivity.kt`), not the
  planned `ExtendedFloatingActionButton` + `BottomSheetDialog`. **Missing:
  item counts per category** ("Pizza — 17") — `categoryPositions` only
  stores `(name, headerPosition)`, no count. Functionally usable, visually/
  informationally short of spec.
- **Closed-restaurant card dimming** — ✅ confirmed done. `RestaurantAdapter.kt`
  sets `binding.root.alpha = 0.5f` for both `Closed` and `isPaused` states.
- **§2.7 Dish-photo carousel + story-style progress bars** — ✅ confirmed
  done. `DishPhotoCarouselView` wired in `RestaurantAdapter.kt` via
  `restaurant.gallery`, with attach/detach-gated timers
  (`isCarouselVisible`) as originally specced.
- **§2.3 Service-area "not available yet" state** — ✅ confirmed done
  (this doc previously listed it as not built — that was wrong).
  `HomeActivity.kt`'s `setServiceAreaUnavailable()` shows a full-screen
  state when the **plain, unfiltered** Home feed returns zero restaurants.
  **Gap:** this is a blanket "no restaurants at all" check, not a
  per-address/per-coordinate "does this specific point have delivery
  coverage" check — see new scope below.
- **Qorix naming** — ✅ clean. Searched entire repo; only remaining
  mentions are historical/documentation text in this file (the rename
  changelog itself). No source, resource, package, or config references
  `qorix` anywhere.

### Newly confirmed NOT built
- **Admin Panel** — the `admin/` folder doesn't exist at all yet (Phase 5
  untouched). Any admin-side coupon/banner-approval work depends on this.
- **Rider App** — folder doesn't exist. `orders/track.php` (customer-facing
  poll endpoint) exists and reads `riders.last_lat/last_lng`, but there is
  **no rider-side endpoint that writes those columns**, no background
  location service, no accept/reject/pickup flow, and no Customer-App live
  map screen consuming `track.php` yet. Live tracking is 0% wired beyond
  the read-side placeholder.
- **Cart-abandonment reminder** — doesn't exist. Only two fixed local
  notifications exist today (`MealReminderScheduler`/`MealReminderWorker`
  — a lunch reminder at 13:30 and dinner at 20:30, same message every day,
  WorkManager-based). No notification template pool, no cart-add-then-
  leave detection, no 15-minute delayed trigger.
- **Restaurant/Admin coupon creation** — only `coupons/list.php` exists
  (customer-facing read/eligibility check). No write endpoint anywhere,
  no `is_public`/visibility column on `coupons` table, no Restaurant App
  or Admin Panel screen.

### Bugs / financial loopholes found this pass
1. **`menu_items.discount_percent` has no upper-bound validation.**
   `price_cart()` computes `unitPrice = unitPrice * (1 - discount_percent/100)`
   with no `min(discount_percent, 100)` clamp anywhere (not in the DB
   column, not at read time). Since this field is currently only ever set
   via a manual `UPDATE ... SET discount_percent=X` in phpMyAdmin (no UI
   exists — flagged as a known limitation earlier in this doc), a typo
   like `discount_percent=150` silently produces a **negative unit price**,
   which flows straight into `item_total` and `grand_total`. No server-side
   floor (`max(0, unitPrice)`) exists to catch this. **Real money-loss risk
   once a restaurant-side discount UI ships** (§ new scope item 1 below) —
   needs a `CHECK (discount_percent BETWEEN 0 AND 100)` or equivalent
   application-level clamp before that UI goes live.
2. **Delivery OTP is only returned for `payment_method === 'upi'` orders**
   (`orders/track.php`). Since UPI isn't even wired yet (COD is the only
   real path today per this doc's Known Limitations), **no live order
   currently gets a delivery OTP at all** — the OTP-verification safety
   step effectively doesn't run for any real order right now. Worth
   deciding: should COD orders also get a delivery OTP (most platforms do,
   to prevent wrong-address/wrong-person drops)? Flagging as a decision
   needed, not fixing unilaterally.
3. No other rounding/negative-total issues found — `price_cart()`'s coupon
   discount is correctly capped at `min(discount, item_total)` and again at
   `max_discount_amount`, and `quantity` is floored at `max(1, qty)`, so
   those two paths are safe.

### New scope requested this session (not started — planning only)

**1. Restaurant + Admin coupon creation, with public visibility + min-cart-value**
- `min_order_amount` **already exists** in the `coupons` schema and is
  already enforced in `price_cart()` and shown in `coupons/list.php` — no
  schema change needed for that part.
- **Needed:** new `coupons.is_public` (or `visibility` ENUM) column,
  DEFAULT 0/private. When a restaurant or admin creates a coupon, it can
  toggle public on/off. `coupons/list.php` (the customer-facing "suggest
  a coupon" screen) needs a `WHERE is_public = 1` filter added so only
  public coupons get suggested there — private ones still work if a
  customer types the code manually at Checkout (existing `cart/validate`
  path), they just don't get surfaced/suggested.
- **Needed:** `POST/GET/PUT /restaurant/coupons` (restaurant scoped to own
  `restaurant_id`, same ownership pattern as `/restaurant/menu`) **and**
  an Admin Panel equivalent for platform-wide coupons (`restaurant_id IS
  NULL`) — but Admin Panel doesn't exist yet, so the admin half is blocked
  on Phase 5 starting.
- **Needed:** Restaurant App screen — "My Coupons" (create/edit/toggle
  active + toggle public, discount type/value, min order, max discount,
  validity dates).

**2. GPS-off / service-area address flow (Add Address + Home)**
Three distinct states, all around "does this location have delivery
coverage":
- **GPS off entirely:** Add Address screen should open with two clear
  choices — "Use live location" (triggers the OS location-permission/
  enable-GPS prompt) or "Choose a saved address" — instead of just
  silently falling back to network-provider location the way
  `MapPinDropActivity`/`LocationPickerActivity` do today.
- **Live location resolved, but zero delivery coverage there:** show the
  existing §2.3 "sorry, not available in your area yet" state — this part
  already works for the blanket empty-Home-feed case, but needs to be
  reachable from address-resolution specifically (right now it only fires
  from the plain Home-feed API response, not from a location/address pick
  event).
- **A saved/selected address has coverage:** once picked, re-run the same
  restaurant-list fetch scoped to that address's lat/lng and show results
  normally — this is close to already working (Home already fetches by
  lat/lng) but needs to be confirmed it's the address's coordinates driving
  the fetch, not just whatever the last GPS fix was.
- **Backend:** current `restaurants/list.php` already computes
  `distance_km` per point — no new endpoint strictly needed, just needs to
  be called with the chosen address's coordinates instead of assuming
  live GPS every time.

**3. Cart-abandonment + notification-template pool**
- New table needed, e.g. `notification_templates` (id, title, body,
  category) seeded with ~40-50 varied "chatpata" copy variants — rotated
  so the same 2 fixed lines don't repeat daily forever.
- **Daily engagement notifications:** pick ~4-5 templates/day per customer
  (random or rotating, avoiding immediate repeats) — needs a scheduler.
  Since this is customer-app-local today (`MealReminderScheduler` uses
  WorkManager, no backend/FCM involved), either extend that same
  local-WorkManager pattern with a bigger template pool, or move to real
  FCM (ties into `features.md` I6, already flagged as not built) so the
  same push can arrive even if the app isn't foregrounded.
- **Cart-abandonment (15 min):** needs to detect "item added to cart" +
  "app backgrounded/left" as a pair of events, then a one-shot 15-minute
  delayed local notification ("items in your cart" style) that
  self-cancels if the user places the order or empties the cart before it
  fires. `CartManager` already tracks cart state client-side — this is a
  new `WorkManager` one-shot job keyed off cart-non-empty + app-background,
  cancelled on order-placed/cart-cleared.

**Nothing in this "new scope" section has been built yet — planning only,
per this session's request. Say which item to start with.**

---

## Updated priority roadmap (2026-08-13, final re-order per app owner)

App owner's explicit re-priority this session: **Admin Panel + Restaurant
coupon creation move up now** (both can be built together — coupon
creation needs an admin half and a restaurant half anyway). **Rider App
pushed to dead last** — do not start until everything else below is done.
40-50 notification templates + cart-abandonment reminder added as its own
item. Full bug list moved to `docs/bugs.md` (new file, this session) —
two items from it (1.1, 2.2) are called out below as blockers on other
work, not standalone fixes.

### Phase H — Coupon system (Admin Panel + Restaurant App, build together)
1. **Bug 1.1 first** (`docs/bugs.md`) — clamp `discount_percent` to 0-100
   in `price_cart()` before any UI that sets discounts goes live. Small,
   isolated, must land before item 4 below.
2. Schema: `coupons.is_public` (default 0), `coupons.created_by_type`
   ENUM(`admin`,`restaurant`), `coupons.created_by_id`.
3. Backend: `POST/GET/PUT /restaurant/coupons` (scoped to caller's own
   `restaurant_id`, same ownership pattern as `/restaurant/menu`).
   `coupons/list.php` (customer "suggest a coupon" screen) gets a
   `WHERE is_public = 1` filter — private coupons still redeemable by
   typed code at Checkout, just not suggested.
4. **Admin Panel (Phase 5, starting now instead of later):**
   - Admin login (session-based)
   - Dashboard (basic stats — can be minimal for v1)
   - Restaurant approval/suspension screen (closes bug 3.1's
     "restaurants have no approval workflow" gap)
   - Coupon creation screen (platform-wide, `restaurant_id IS NULL`)
   - Settings page — editor for `app_settings` (delivery charge, platform
     fee, tax %, OTP rules, `otp_required_for_cod`, etc.) — closes
     another piece of bug 3.1
5. Restaurant App: "My Coupons" screen — create/edit code, discount
   type/value, **min order amount** (field already exists in schema,
   just needs a form field), max discount, validity dates, active
   toggle, **public/private toggle**.
6. Test both creation paths end-to-end, confirm a restaurant-created
   coupon and an admin-created coupon both show correctly (public ones
   suggested, private ones only work by typed code) on Checkout.

### Phase I — GPS-off / address-resolution flow
7. Add Address screen: explicit "Use live location" vs "Choose a saved
   address" choice when GPS is off, instead of silently falling back to
   network-provider location.
8. Wire the existing §2.3 "not available in your area yet" state to also
   fire from address-pick events (currently only fires from the plain
   Home-feed empty response).
9. Confirm restaurant-list fetch uses the selected/saved address's
   lat/lng (not last-GPS-fix) once an address is chosen and has coverage.

### Phase J — Notifications (40-50 templates + cart abandonment)
10. **Bug 2.2 first** (`docs/bugs.md`) — remove/gate `debug_otp` from the
    live API response before notification/engagement work risks drawing
    more real users into a still-open auth hole. Small, isolated.
11. New `notification_templates` table, seeded with 40-50 varied
    "chatpata" copy variants across a few categories (hunger/craving,
    offer-style, re-engagement, etc.).
12. Daily engagement: pick ~4-5 templates/customer/day, rotating so nothing
    repeats within a set window (closes bugs.md §4.2's dedup gap from day 1).
    Extend the existing `MealReminderScheduler`/WorkManager pattern, or
    move to real FCM if push-when-backgrounded matters (ties into
    `features.md`'s I6, still not built either way).
13. Cart-abandonment: `CartManager` cart-non-empty + app-backgrounded pair
    of events → one-shot 15-minute delayed local notification,
    self-cancels on order-placed or cart-cleared.
14. Along the way, fix bug 1.3 (coupon usage race — add a unique
    constraint on `coupon_usages(coupon_id, customer_id)`) and bug 2.1
    (OTP request rate limit) and bug 2.4 (order-creation idempotency) —
    small, isolated, no reason to leave them for later once touching
    adjacent code.

### Phase K — Rider App + Live Tracking (explicitly LAST, per app owner)
15. Everything in the old Phase 4 (`04_Phase_Plan.md`) — rider login,
    background location service, `POST /rider/location` (doesn't exist
    yet — `track.php` currently only reads `riders.last_lat/lng`, nothing
    writes them), Customer App live map screen, delivery-OTP verify flow.
    Bug 1.2 (OTP generation/display mismatch) gets fixed as part of this
    phase, since it's meaningless to fix in isolation before a rider flow
    exists to actually use the OTP.

**Everything above is planning only as of this message — nothing in
Phase H/I/J/K has been built yet.** Say which phase/item to start with.

---

## Restaurant App full scope + pre-order rating (2026-08-13, later)

App owner supplied a full restaurant-app feature wishlist (Dashboard,
Menu Management, Order Management, Restaurant Management, Delivery
Management, Payments, Offers, Analytics, Reviews, Notifications, Staff
Management, Settings) plus a new customer-facing feature request: let a
customer star-rate a restaurant **without ordering** (a lighter-weight
"impression" rating, dampened so it can't out-rank real order-based
reviews just from taps). Full prioritized breakdown, what already exists
vs what's new, what stays deferred to Phase K (Rider App), and the
proposed rating-weight formula are all in
`docs/18_Restaurant_App_Full_Scope_And_Rating_System.md` — say "start
menu management" (top of that doc's recommended order) to begin, or name
any other item from it directly.

**Nothing in that doc has been built yet — planning only.**

---

## Bug 1.1 + Phase H (item 6, list-only) + Phase I items 7-9 (2026-08-14)

Picked up from the phase list above. Done this session:

**Bug 1.1 — `discount_percent` not clamped (`docs/bugs.md`)**
- `lib/orders.php`'s `price_cart()` now clamps `discount_percent` to
  `[0, 100]` at read time (`min(100, max(0, ...))`) before applying it to
  `unitPrice`, so a bad value can no longer push price negative or
  discount above 100%. Checked every other write path
  (`auto-update-bestseller-discount.php` already clamps to 0-90,
  `seed-demo-catalog.php` is fixed seed data, no restaurant/admin write
  UI exists yet) — the read-time clamp is the actual fix given there's no
  live write path to also guard yet. Re-add a write-time clamp once
  Phase H item 5 (restaurant "My Coupons"/menu-discount form) ships.

**Phase H item 6 (partial — public/private list filtering only, not the
full item)**
- Added migration `18_migration_coupon_is_public.sql` — `coupons.is_public
  TINYINT(1) DEFAULT 1` (idempotent conditional-ALTER, same pattern as
  06/16/17). Existing coupons default to public (today's behaviour,
  unchanged) since nothing creates private ones yet.
- `coupons/list.php` (the Checkout "view all offers" endpoint) now filters
  `is_public = 1` — private coupons won't be suggested there. Confirmed
  `price_cart()`'s coupon lookup (`lib/orders.php`) matches by `code` only
  with no `is_public` filter, so a private coupon still redeems correctly
  by typed code at Checkout.
- **Not done**: nothing yet sets `is_public = 0` on any coupon — that's
  items 1-5 of Phase H (schema fields beyond this one, the "public vs
  private" input on both admin and restaurant coupon-creation forms).
  This session only made the list endpoint respect the flag once
  something eventually sets it.

**Phase I — items 7-9, all done**
- Item 9 (confirm restaurant list uses selected address, not last GPS
  fix) — turned out to already be correct. `HomeActivity.loadRestaurants()`
  reads lat/lng from `ActiveAddressManager.get()` only;
  `ApiService.getRestaurants()` has exactly one caller in the whole
  customer app (`HomeActivity`) and no other code path feeds it a raw
  `LocationManager` fix. No change needed, verified only.
- Item 8 (service-area-unavailable should also fire from an address pick,
  not just the plain Home-feed response) — also turned out to already be
  wired correctly as of H6: `deliveryLocationText`'s tap →
  `LocationPickerActivity` → `locationPickerLauncher` callback →
  `resolveActiveAddressThenLoad(forceRefresh = true)` → `loadRestaurants()`,
  the exact same function whose empty-result branch already calls
  `setServiceAreaUnavailable(true)` when `isBrowsingDefaultHome()` is
  true. An address-pick that returns zero restaurants on the plain feed
  now shows the same unavailable state a blank first-open does. No change
  needed, verified only.
- Item 7 (explicit "Use live location" vs "Choose a saved address" when
  GPS is off) — this one had a real gap, now fixed in
  `LocationPickerActivity.kt`:
  - The "Use current location" row previously never actually set current
    location as the deliverable address at all — `onLocationResolved()`
    only filled the subtitle/distance-line UI. Added
    `ActiveAddressManager.setLiveLocation(lat, lng, addressLine)` (new
    method, `ActiveAddressManager.kt`) and wired the row tap
    (`explicitRowTap` flag, only true for a genuine tap — not the silent
    on-open distance-line resolve) to call it and close the picker with
    `RESULT_OK`, same as picking a saved address does.
  - `ActiveAddressManager.ActiveAddress` gained `isLiveLocation: Boolean`
    and a sentinel `id = -2` for a live-location selection (no backing
    saved-address row), distinct from -1 ("unset"). `set()` (saved
    address) and the new `setLiveLocation()` both funnel through a shared
    `persist()`. Confirmed nothing in `HomeActivity` uses
    `ActiveAddressManager.get().id` as a real address id for any API call
    — no collision risk from the sentinel.
  - GPS-off case: was a dead-end toast ("Turn on location services to use
    this") with nothing else offered. Now shows an explicit
    `AlertDialog` (`showLocationServicesOffChoice()`) — "Turn on
    location" (deep-links to `Settings.ACTION_LOCATION_SOURCE_SETTINGS`)
    vs "Choose saved address" (dismisses back onto the picker's own
    saved-addresses list) when the account has saved addresses, or just
    "Turn on location" / Cancel when it doesn't. Only triggers for a real
    row tap, not the silent on-open auto-resolve.
  - New strings: `location_services_off_title`,
    `location_services_off_message_with_saved`,
    `location_services_off_message_no_saved`,
    `location_services_off_turn_on`, `location_services_off_choose_saved`.

**Not touched this session**: Phase H items 1-5 (schema + both
creation-form UIs), Phase J, Phase K, and bugs 1.2/1.3/2.1/2.2/2.4.

---

## Phase J — Notifications + bugs 1.3/2.1/2.2/2.4 (2026-08-14, later)

Continued straight from the session above. **Not fully finished — see
"Left for next session" at the bottom.** Everything below has been
written but **not build-verified** (no Android SDK / Gradle available in
this environment to actually compile and run it) — treat as needing a
real build + device/emulator smoke-test as the very next step.

### Security bugs fixed first (per the roadmap's explicit ordering)

**Bug 2.2 — `debug_otp` exposed in every API response (🔴 pre-launch
blocker, now fixed)**
- `customer-request-otp.php` only includes `debug_otp` in its response
  when `app_settings.debug_otp_enabled = '1'`. Defaults to `'0'` (off) —
  seeded by migration `19_migration_otp_security_settings.sql`.
- **Action needed before any real launch**: confirm this row is `'0'` (or
  absent) on whatever DB actually goes live. It's safe by default, but
  worth a manual check since it's exactly the kind of setting a stray
  `UPDATE` during testing could flip and forget.

**Bug 2.1 — OTP request had no rate limit (fixed)**
- Same file: 60-second per-email cooldown before a new OTP row is
  inserted (`app_settings.otp_request_cooldown_seconds`, also seeded by
  migration 19). Returns `429 otp_request_cooldown` with
  `retry_after_seconds` when hit.

**Bug 1.3 — coupon usage-limit TOCTOU race (fixed)**
- `orders/create.php`: right before the `coupon_usages` insert, inside
  the same transaction, now does `SELECT ... FOR UPDATE` on the coupon
  row and re-checks `usage_limit_per_user`/`usage_limit_total` against a
  fresh count. A losing concurrent request throws and the whole
  transaction (order + items + status history, not just the coupon
  usage) rolls back — the customer sees `coupon_usage_limit_reached`
  instead of a duplicate redemption slipping through. Deliberately not a
  blanket `UNIQUE KEY (coupon_id, customer_id)` — that would break
  legitimately-multi-use coupons (`usage_limit_per_user` can be `> 1` or
  `NULL`).

**Bug 2.4 — no idempotency protection on `POST /orders` (fixed, client +
server)**
- Server: `orders.idempotency_key` column + unique
  `(customer_id, idempotency_key)` constraint (migration
  `20_migration_order_idempotency_key.sql`, nullable — older requests
  without a key are unaffected). A repeated request with the same key
  returns the original order instead of creating a duplicate; the
  concurrent-race version of the same thing (two requests, same key,
  genuinely simultaneous) is caught in `create.php`'s catch block by
  matching the constraint-violation message and handing back the winner.
- Client: `CheckoutActivity` already disabled the Place Order button
  on tap (pre-existing, not new this session) — what was missing was the
  idempotency key itself. Now generates one UUID per place-order attempt,
  sends it as `idempotency_key`, and **keeps** the same key across a
  network-exception retry (the request may have actually landed) but
  **clears** it on a clean error response (validation/coupon rejection —
  nothing was created, safe to mint a fresh key next time).

### Template pool + rotation (bugs.md §4.1 scope gap, §4.2)

- `notifications/NotificationTemplates.kt` — 45 templates (within the
  40-50 ask), split into three categories the roadmap called for:
  HUNGER (craving copy, 15), OFFER (generic discount-style copy, 15),
  REENGAGEMENT ("miss you" copy, 15). Kept **on-device**, not a backend
  table — there's no real push channel yet to deliver a server-picked
  template through (`features.md` §I6 is still "not started"), so a
  backend table would have nothing to drive. Move this pool server-side
  whenever I6/FCM actually lands.
- `notifications/EngagementNotificationHistory.kt` — bugs.md #4.2 fix.
  SharedPreferences log of which template ids were shown on which day,
  7-day no-repeat rotation window (45 templates / ~5 per day ≈ 9 days of
  inventory, comfortably more than the window), self-prunes anything
  older than the window. Uses plain millis-since-epoch day-bucketing, not
  `java.time.LocalDate` — minSdk is 24 and no desugaring is configured,
  so the API-26+ `java.time` classes weren't safe to reach for here.

### Daily engagement (4-5/customer/day)

- `notifications/DailyEngagementWorker.kt` — picks a template not in the
  rotation window (falls back to `all.random()` in the — currently
  unreachable at this pool size — case every template is within-window),
  shows it via a new `NotificationHelper.showMealReminder` call, then
  records it shown.
- `notifications/DailyEngagementScheduler.kt` — **5** separate daily
  `PeriodicWorkRequest`s at fixed times (9:30, 12:45, 16:00, 19:30,
  21:45), same one-job-per-slot shape the old `MealReminderScheduler`
  already used for its 2 slots (WorkManager periodic work has a 15-min
  floor and isn't built for "fire N times a day" as a single job).
  `HomeActivity.onCreate()` now calls this instead of the old
  `MealReminderScheduler.scheduleDailyReminders()`.
- `MealReminderScheduler.kt`/`MealReminderWorker.kt` (the old 2-slot,
  2-hardcoded-string setup) are **left in the codebase but no longer
  called from anywhere** — harmless dead code, not deleted in case the
  old copy is wanted back for some reason. Safe to delete in a later
  cleanup pass once the new system's been confirmed working.

### Cart-abandonment (15-min delayed, self-cancelling)

- **`AnydropApplication.kt` is a new file** — the app had no custom
  `Application` subclass at all before this. Registered in
  `AndroidManifest.xml` (`android:name=".AnydropApplication"`). Observes
  `ProcessLifecycleOwner` (needs the new `androidx.lifecycle:lifecycle-process:2.7.0`
  dependency, added to `app/build.gradle` — wasn't already present) for a
  real "the whole app was backgrounded/foregrounded" signal, deliberately
  not any single Activity's onPause/onStop (which also fires on ordinary
  in-app screen navigation and would false-trigger constantly).
- `notifications/CartAbandonmentScheduler.kt` +
  `notifications/CartAbandonmentWorker.kt` — on backgrounding, if
  `CartManager.hasAnyItems()`, schedules a one-shot 15-minute delayed
  notification (`ExistingWorkPolicy.REPLACE` — a fresh backgrounding
  restarts the 15-minute clock rather than an old near-expired timer
  winning). On foregrounding, cancels it. The worker itself **also**
  re-checks `CartManager.hasAnyItems()` live at fire time (rather than
  trusting the 15-minute-old snapshot) — covers "order placed while still
  backgrounded" without needing every single cart-clearing call site in
  the app to remember to call `.cancel()`. `CheckoutActivity`'s
  order-success path calls `.cancel()` explicitly too, belt-and-suspenders.
- `NotificationHelper.kt` gained a separate `CHANNEL_CART_ABANDONMENT`
  channel + `NOTIF_ID_CART_ABANDONMENT` (3001) and a
  `showCartAbandonmentReminder()` function — kept distinct from the meal
  reminder channel/id so the two notification types can't silently
  overwrite each other in the status bar and so a user can mute one
  without muting the other.

### Left for next session

1. **No build/compile verification done** — this was all written and
   reasoned through file-by-file, but there's no Android SDK in this
   environment to actually run Gradle. First thing next session: build
   the customer app, fix whatever the compiler catches (import ordering,
   any typo), and smoke-test on an emulator — specifically (a) the 5
   daily engagement notifications actually fire and don't repeat within a
   week, (b) backgrounding the app with a non-empty cart and waiting 15
   min shows the cart-abandonment notification, (c) reopening the app
   within 15 min cancels it, (d) placing an order also cancels it.
2. **Migrations 18/19/20 need to actually be run** against whatever DB is
   in use — nothing in this session executed them, they were only
   written to `backend/sql/`.
3. **Old `MealReminderScheduler`/`MealReminderWorker` cleanup** — dead
   code now, fine to delete once the new 5-slot system is confirmed
   working end-to-end.
4. **No logout wiring exists for either scheduler** (pre-existing gap,
   not introduced this session — the old `MealReminderScheduler.cancelAll()`
   was never called from anywhere either). Worth fixing alongside
   whichever future session touches logout, so a signed-out account
   doesn't keep getting engagement pushes.
5. Still entirely untouched: **Phase H items 1-5** (coupon schema fields
   beyond `is_public`, admin coupon-creation form, restaurant "My
   Coupons" screen), **Phase K** (Rider App + Live Tracking, explicitly
   last per app owner), and **bug 1.2** (COD OTP generated but never
   shown to the customer — deferred to Phase K on purpose, since fixing
   it in isolation before a rider flow exists has nothing to verify
   against).
6. Bug 2.3 (GitHub PAT pasted in chat) is still just a "confirm revoked"
   action item, not a code fix — nobody has confirmed that yet as far as
   this doc knows.

---

## Bugs §6.1/6.2/6.3/6.4 + out-of-stock (2026-08-14, two sessions)

Full detail in `docs/17_Handover_Bugs_6.1_6.2_2026-08-14.md` (session 1:
6.1 GPS-off banner, 6.2 address "set as default") and
`docs/18_Handover_Bugs_6.3_Verify_And_OutOfStock_2026-08-14.md`
(session 2: 6.3 turned out already fixed elsewhere — just badge-gap on
the restaurant detail page remained and got fixed; out-of-stock built
customer-side, restaurant-app toggle explicitly deferred since that app
isn't built out yet). **Nothing from either session is build-verified**
— same no-SDK/no-network limitation as Phase J above. See doc 18's
own "Left for next session" for the full list.

---

## Admin Panel — full spec + Payment/Email-OTP architecture + Analytics (2026-08-14, later)

Planning-only session per app owner's full requirement dump (Roles &
Permissions, Area Management, every Admin Panel module, Payout system,
Email OTP multi-provider failover, Payment Provider architecture with
UPIPE as launch provider, and a large Analytics & Reports module). Full
detail in `docs/19_Admin_Panel_Full_Spec_And_Payment_Email_Architecture_2026-08-14.md`
— cross-checked against the existing schema so nothing already-built
gets re-specified; every genuinely new table/column is flagged there.
**Referral system explicitly excluded from all planning per app owner's
instruction.** Nothing in that doc has been built yet — say which item
to start with (doc's own §14 has a recommended build order).


## Combo/Bundle Offer Type — Steps 1-3b/6 done (2026-08-25/26)

Step 1: migration `backend/sql/50_migration_combo_offers.sql` written —
`promo_offers.offer_type` ENUM +'combo', new `offer_combo_items` table
(offer_id, menu_item_id, required_qty). Not run against a live DB (no
MySQL CLI in sandbox) — balance-checked only.

Step 2: `backend/lib/offers.php` — new `get_offer_combo_items()`
(fetches a combo's item/required_qty rows) + a new `'combo'` case in
`compute_offer_discount()`. A combo bypasses `get_offer_scoped_lines()`
entirely (its `scope` stays unused/'restaurant' per migration 50) —
matching is all-or-nothing against every `offer_combo_items` row's
`required_qty`, discount is (sum of each item's cart unit_price ×
required_qty) − `offer_price` floored at 0, and multiple simultaneous
bundles use the same `intdiv()` "how many full sets fit" approach
`quantity_deal` already uses, capped by whichever ingredient runs out
first. `select_best_auto_offer()` needed no change, per plan.
No PHP CLI in sandbox — balance-checked (braces/parens/brackets), not
`php -l`-verified.

Step 3: verification only, no code change. Confirmed both
`cart/validate.php` (preview) and `orders/create.php` (placement) call
`price_cart()` fresh against the actual client-submitted cart, and
`create.php` has no client-supplied `offer_id` — the applied offer is
always server-selected. That's the *only* re-validation any existing
offer_type gets (no separate scope-recheck exists anywhere else in
`create.php`), so Step 2's combo case — which re-derives cart-item
quantities from whatever `$lineItems` it's called with — is
automatically re-validated at placement the same way every other type
already is. `recordOfferUsage()`'s daily/total/per_customer limit
re-check (under `FOR UPDATE`) also already covers combo generically.

Step 3b (2026-08-26) — bug found while prepping for Step 4, not part
of the original plan: `backend/api/v1/restaurant/offers-create.php`
(the real creation endpoint) had never been touched by Steps 1-3 and
still rejected `offer_type: "combo"` outright (`'combo'` missing from
`$validTypes`), with no code path to write `offer_combo_items` even if
it had been accepted, and `format_offer()` didn't return a combo's
items either — so nothing built on top of this (Step 4's Android
dialog) could have actually worked end-to-end. Fixed:
- `offers-create.php`: `'combo'` added to `$validTypes`; `scope`
  forced server-side to `'restaurant'` for combo (per migration 50);
  `offer_price` validated as the bundle price; new `combo_items` field
  (array of `{menu_item_id, required_qty}`, min 2 distinct items,
  each ownership-checked against the caller's own menu items in one
  batched query, de-duplicated before insert); the `promo_offers` +
  `offer_combo_items` inserts now run inside one transaction so a
  combo can never end up half-written.
- `format_offer()`: new optional `?PDO $db = null` param; returns
  `combo_items` (`[]` for non-combo, or a combo fetched without `$db`)
  so no caller needs a null-check. `offers-create.php`,
  `offers-list.php`, `offers-update.php` all updated to pass `$db`.
  `offers-update.php` needed no other change — combo items correctly
  stay non-editable there, "delete and recreate" same as every other
  type's mechanic fields.
- Small bonus fix: `backend/admin/offers.php`'s `$offerTypeLabels` was
  missing a `'combo'` entry (would've shown the raw enum string) —
  added `'Combo/Bundle'`.
- Swept the rest of `backend/` for the same class of bug (string-
  concatenated SQL / unbound `$_GET`/`$body` interpolation into
  queries) — every other partial-update endpoint builds its `SET
  field = :placeholder` list dynamically but always binds via prepared
  statements; no new injection findings.
- Balance-checked only, not `php -l`-verified — same sandbox
  limitation as every other step.

Next: Step 4, Restaurant App create/edit dialog (Android) — now safe
to build against a backend that actually accepts combo. Full plan +
per-step tracking in `docs/40_Plan_Combo_Bundle_Offer_Type_2026-08-25.md`.


## Combo/Bundle Offer Type — Step 4/6 done (2026-08-26)

Restaurant App create/edit dialog (Android), built against the now-fixed
`offers-create.php`:

- `dialog_add_offer.xml`: new `chipTypeCombo` chip; new
  `mechanicComboGroup` (a `comboItemsContainer` LinearLayout that rows
  get inflated into at runtime, a `btnAddComboItem` button, a dedicated
  `inputComboPrice` field — separate View from `inputOfferPrice` since
  the two mechanic groups can't share one). Scope chips + item/category
  pickers are hidden entirely when Combo is selected (matching
  migration 50's "scope forced to restaurant, unused for matching"),
  not just left visible-but-meaningless. New `comboItemsLockedLabel`
  for edit/view mode (combo_items is create-only).
- New `item_offer_combo_row.xml` — one row (item dropdown + qty +
  remove button); `showAddOfferDialog()` starts with 2 pre-added rows
  (docs/40's 2+ item minimum).
- `OfferManagerActivity.kt`: `addComboItemRow()` +
  `comboMenuItemsCache` (reuses the existing `getMenuItems()` fetch,
  no extra round trip); `submitNewOffer()` collects the rows into
  `ComboItemBody`, de-dupes by menu_item_id client-side, validates 2+
  items + bundle price > 0 before ever calling the API (mirrors
  `offers-create.php`'s own validation); `applyComboItemsLockedLabel()`
  (shared by edit/view) shows the combo's items immediately from
  `PromoOffer.comboItems` using id placeholders, then upgrades to real
  names once a `getMenuItems()` lookup resolves — `format_offer()`
  only returns ids, not names.
- `Models.kt`: `PromoOffer.comboItems: List<ComboItem>`,
  `OfferCreateBody.comboItems: List<ComboItemBody>?`. `OfferUpdateBody`
  untouched (combo items aren't editable post-creation).
- `OfferAdapter`/`item_offer_card.xml`: no change needed — the offer
  card's rendering (title/usage/validity/status + fire/delivery icon
  split) is already generic across every offer_type.
- Balance-checked only (braces/parens for the .kt, tag-count for both
  .xml files) — no Gradle/Kotlin compiler in this sandbox, not build-
  verified.

Next: Step 5, admin panel per-combo item-list visibility (the type-
label fix already landed in Step 3b; showing the actual item set is
still open). Full plan + per-step tracking in
`docs/40_Plan_Combo_Bundle_Offer_Type_2026-08-25.md`.


## Combo/Bundle Offer Type — Step 5 done (2026-08-26)

`backend/admin/offers.php` — per-combo item-set visibility:

- New batched query (one `IN()` against just the combo offer ids on
  the current page — same batching style as `offers-create.php`'s
  Step 3b ownership check) joins `offer_combo_items` to `menu_items`
  for display names. `lib/offers.php`'s `get_offer_combo_items()`
  stays id-only on purpose (it's the matching-path helper, not a
  display one), so this page queries `offer_combo_items` directly
  instead of calling it.
- Type column now shows each combo's item list (`Name ×qty`,
  comma-joined) under the type label. A combo somehow saved with zero
  `offer_combo_items` rows (the same "not impossible" case that
  function's own kdoc already calls out) renders `(no items on file)`
  instead of silently showing nothing, so a half-written combo stays
  visible to admin.
- Bundle price (`offer_price`) intentionally NOT added to this table —
  no other offer type's mechanic value (percent/flat amount, X/Y
  quantities, etc.) is shown here either, so leaving it out matches
  this table's existing scope rather than being a gap.
- Balance-checked (`<?php`/`<?=`/`?>` tag counts, braces/parens/
  brackets) — no PHP CLI in this sandbox, not `php -l`-verified, same
  standing limitation as every prior step.

Next: Step 6, Customer App display (combo item list + bundle price on
the offer card, distinct from a plain percent/flat badge) — the last
step in docs/40's plan. Full plan + per-step tracking in
`docs/40_Plan_Combo_Bundle_Offer_Type_2026-08-25.md`.


## Combo/Bundle Offer Type — Step 6 done (2026-08-26) — closes docs/40's plan, Steps 1-6 all done

Found while starting this step, not part of the original plan: browse-time
item badging had a real correctness bug, not just a missing feature.
`pick_item_badge_offer()` matches by `scope`, and migration 50 forces a
combo's `scope` to `'restaurant'` (unused for matching, per that
migration's own contract). That meant the function's existing
restaurant-wide fallback tier would match a live combo and badge
**every menu item in the restaurant** with the combo's tag — not just
the combo's own required items — across all four browse-time badge
endpoints (`restaurants/menu.php`, `home/popular-items.php`,
`search/search.php`, `home/offers-browse.php`).

`backend/lib/offers.php`:
- New `index_combo_offers(PDO $db, array $offers): array` — one
  batched query (skipped entirely when the offer set has no combo
  row) building two maps from a restaurant's already-fetched
  `$browsableOffers`: `menu_item_id => combo offer id` (for matching)
  and `offer_id => [menu_item_id => name]` (for labeling). Built once
  per restaurant, same "batch once, not N+1" discipline Step 5's admin
  query already used.
- `pick_item_badge_offer()` gained a new combo tier — checked after
  item-scope and category-scope, before the restaurant-wide fallback
  — using that index; the restaurant-wide fallback itself now
  explicitly excludes `offer_type === 'combo'`, closing the bug above
  at its root so all four endpoints are fixed by one shared change.
- `offer_badge_label()` gained a `'combo'` case: instead of falling to
  the generic `default` (which just echoes the offer's own title), it
  now names the *other* items in the bundle plus the bundle price —
  e.g. `"Combo w/ Fries, Coke — ₹199"` on the Burger's own badge —
  capped at 3 named items (`+N more`) since the pill TextView has no
  `maxLines`/`ellipsize` in any of its 5 layout uses (hand-checked all
  5: `item_menu_item.xml`, `item_popular_dish.xml`,
  `item_search_dish.xml`, `item_offer_browse_dish.xml`, plus
  `item_cart_line.xml` which reads the same cached field but isn't one
  of the 4 endpoints changed here). This delivers docs/40's own "item
  list + bundle price on the offer card" ask through the existing
  generic `offer_tag` string field — no new field needed.

All 4 badge-producing endpoints (`restaurants/menu.php`,
`home/popular-items.php`, `search/search.php`,
`home/offers-browse.php`) updated to build the combo index once per
restaurant and thread it through both the matching and labeling calls.

**Android side needed zero changes** — `MenuItem.offerTag`/
`PopularItem.offerTag`/`SearchItem.offerTag`/`OfferBrowseItem.offerTag`
are all plain `String?`, already wired end-to-end to an unconstrained
pill `TextView`, so the richer combo text renders through the existing
pipeline with a normal backend deploy, no client rebuild required. The
checkout offer strip (`CheckoutActivity.renderBill()`, already generic
over `offerTitle`/`offerDiscountAmount` since Step 2) and the Offers
screen (`OfferScreenActivity`/`OfferBrowseAdapter`, docs/36) both pick
up correct combo behavior automatically as a result.

Balance-checked with a comment-stripped brace/paren/bracket count
(several of this change's own explanatory comments contain unbalanced
parens in prose, which would otherwise false-positive a naive count) —
no PHP CLI in this sandbox, not `php -l`-verified, same standing
limitation as every prior step.

**This closes docs/40's plan — Steps 1-6 all done.** Nothing in this
feature is device/build-verified anywhere (no PHP CLI, Kotlin
compiler, Gradle, or live DB in this sandbox). A real `php -l` pass +
migration 50 run + live click-through is still required before this is
production-ready — checklist:
1. Run migration 50 against a real DB.
2. `php -l` every file this feature touched across docs/40's Steps
   1-6 (lib/offers.php, offers-create.php, offers-list.php,
   offers-update.php, admin/offers.php, restaurants/menu.php,
   home/popular-items.php, search/search.php, home/offers-browse.php).
3. Create a combo via the Restaurant App (2+ items, a bundle price) →
   confirm it saves and shows correctly in admin/offers.php's item
   list (Step 5) and the Restaurant App's own edit/view mode (Step 4).
4. Browse Home/Search/the restaurant's menu/the Offers screen →
   confirm ONLY the combo's own items badge (e.g. "Combo w/ Fries,
   Coke — ₹199"), NOT every item on that restaurant's menu — this is
   the specific regression Step 6 fixed and the one most worth a real
   device check.
5. Add the combo's required items to cart, checkout → confirm the
   offer strip shows the combo's title + correct discount, and that
   removing a required item before placing drops the discount to 0
   (Step 3's re-validation claim, still never actually run against a
   live DB).

Per `PENDING.md` item 31's existing "full build/device/live DB
regression" requirement — this feature is one more item on that same
standing list, not a special case.


## Restaurant Insights Tab — built end-to-end (2026-08-27) — closes PENDING.md item 3

Full detail: `docs/49_Handover_2026-08-27_Restaurant_Insights_Tab_Built.md`.

Picked as this session's target after confirming (by reading actual
code, not old docs) that review moderation and the Support Ticket
System admin side were already both done — Restaurant Insights was
the genuine remaining placeholder (`InsightsFragment.kt` was an empty
shell, no `restaurant/insights.php` existed).

Built: `backend/api/v1/restaurant/insights.php` (new, no migration
needed), `ui/insights/OrdersBarChartView.kt` (new custom bar chart —
no charting library exists in this project), `InsightsFragment.kt`
rewritten from placeholder, `fragment_insights.xml` rewritten,
`skeleton_insights.xml` + `item_insight_top_item.xml` +
`divider_line.xml` new, `Models.kt`/`ApiService.kt` extended.

Deliberately out of scope (flagged in doc 49): Peak hours (not in
§6's actual spec, only in PENDING.md's broader wishlist), Export
PDF/Excel (no existing pattern to extend).

Not build/device-verified — no PHP CLI/Android SDK/live DB/device in
this sandbox, same standing limitation as every prior session. Full
verification checklist in doc 49.

**Next session:** Admin Analytics remaining filters
(State/District/Restaurant/Category) + Rider/Payment/Coupon analytics
+ Export, per the app owner's own framing and doc 49's reasoning.


## Admin Analytics — remaining scope built (2026-08-27, session 11) — closes PENDING.md item 2's written scope

Full detail: `docs/50_Handover_2026-08-27_Admin_Analytics_Filters_Riders_Payments_Coupons_Export_Built.md`.
(Entry added retroactively during a later session's doc-audit — this
was missed from Status.md when it happened.)

Extended `backend/admin/analytics.php` with State/District/Restaurant/
Category filters (AND-combined with the existing Area filter, one
shared query-extension pattern), a new Riders section (delivered
count/revenue/avg delivery time per rider — `orders.rider_id` is a
real, populated column, confirmed directly against `01_schema.sql`,
overriding doc 44's older "no Rider App data" framing), a Payments
section (UPI vs COD), a Coupons section (usage/unique customers/
discount, off the order's own `discount_amount` snapshot, not the
coupon's current live value), and a CSV Export gated on the
pre-existing `reports_export` permission — first export pattern in
this codebase.

Not build/device-verified — same standing sandbox limitation. Full
10-item checklist in doc 50.


## Doc-accuracy correction (2026-08-27, session 12) — PENDING.md item 4 was stale

`PENDING.md` item 4 ("Full Restaurant Offers Engine") was still marked
`PENDING` with every checklist box unchecked. This was wrong — this
file's own "Combo/Bundle Offer Type — Step 6 done ... closes docs/40's
plan, Steps 1-6 all done" entry above already documents that every
offer type, the full rule engine, the Restaurant App UI, the Home/
Search/menu badge pills, the Offers browse screen, and the checkout
offer strip are built. Re-confirmed directly against current code
(`lib/offers.php`, `OfferManagerActivity.kt`, `CheckoutActivity.kt`,
`OfferScreenActivity.kt`) before writing this correction. PENDING.md
item 4 updated to 🟡 BUILT — NOT build/device-verified, same status
pattern as items 2/3. Item 1 (Admin Order Control) had the same
problem (stale PENDING despite `docs/42` confirming it built) and was
corrected too. No feature code changed for these corrections.


## Admin panel: Settlements CSV Export built (2026-08-27, session 12)

Full detail: `docs/51_Handover_2026-08-27_Doc_Audit_And_Settlements_Export_Built.md`.

After the doc-audit corrections above, checked Admin panel side
specifically for a genuine remaining gap (app owner's own ask). Found
`admin/settlements.php` (Payout Analytics + Ledger Statement) had no
CSV export — same gap `analytics.php` had before doc 50, confirmed by
grep (`Content-Disposition` — zero results in this file before this
session).

Built, reusing doc 50's exact pattern: gated on the same
`reports_export` permission (migration 29, no new permission needed),
`fputcsv`/`Content-Disposition` streaming to `php://output`. Exports
Payout Analytics stat-card figures + the full Ledger Statement (200
rows) + Settlement History (50 rows), per-restaurant, for whichever
`payout_range` is selected. Writes a `settlement_exported` audit log
entry. No new migration.

Not build/device-verified — same standing sandbox limitation. Full
5-item checklist in doc 51.


## Admin Customer-Feedback View built; Customer Complete-Profile backend built (2026-08-27, session 13)

Full detail: `docs/52_Handover_2026-08-27_Admin_Feedback_View_And_Customer_Complete_Profile_Built.md`.

App owner asked for two things: (1) an admin screen for customer
feedback, (2) ask name + mobile from the customer right after
email-OTP login, in the customer app.

(1) is fully built: migration 55 (`feedback_view` permission) +
`admin/customer-feedback.php` (read-only list, star-rating filter
chips, message search) + sidebar nav entry. Closes the TODO that's
been sitting in `api/v1/customer/feedback.php`'s own kdoc since Phase
3.6 ("Reviewable directly in the `feedback` table, or a future Admin
Panel screen").

(2) is backend-only this session: `api/v1/customer/complete-profile.php`
(auth'd, validates name + 10-digit mobile, rejects duplicate mobile)
plus the `.htaccess` route. The Android side — new
`CompleteProfileActivity`, model/API wiring, and routing it in after
`LoginActivity.onVerifyOtp()` when `customer.name`/`mobile` come back
null — is NOT started. Full step-by-step for next session is in doc
52 and `PENDING.md` item 11b.

Not build/device-verified — same standing sandbox limitation.
