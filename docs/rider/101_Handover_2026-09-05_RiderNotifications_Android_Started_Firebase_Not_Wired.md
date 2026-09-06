# Handover — Rider Notifications Android side, STARTED (models/endpoints/gradle only — no service, no UI, Firebase not wired into this app at all)

Session date: 05 Sep 2026 (continues doc 100, same day). Picks up doc
100's "Still open → Android side — this is now the actual blocker, not
backend" section. This session did NOT finish that section — it went
partway through the groundwork and stopped. Nothing in this session was
built/tested against a live backend, a live Firebase project, or a real
device — same standing caveat as every prior handover in this project,
restated because this session touches Gradle config that genuinely
cannot be verified to compile here (no Gradle in this sandbox, and see
the new blocker below which would fail a real build too).

## New blocker found this session, not previously flagged

Doc 100's "Still open" list treated the Android gap as "no
`FirebaseMessagingService` exists yet" — implying Firebase itself was
already wired into the Rider app and only the service class was
missing. That's not what's actually there. Checked directly this
session (`rider/build.gradle`, `rider/app/build.gradle`, and a search
for `google-services.json` anywhere under `rider/`):

- No `com.google.gms.google-services` plugin in either Rider gradle file.
- No `rider/app/google-services.json` anywhere in the repo (the
  customer and restaurant apps each have their own, checked for
  contrast).
- No `firebase-messaging` (or any `firebase-*`) dependency in
  `rider/app/build.gradle`.

So the Rider app was never onboarded to the Firebase project at all —
not "service class missing," but "this Android app doesn't exist yet
as far as Firebase is concerned." That's a harder blocker than doc 100
described: it needs someone with Firebase console access to register
`com.anydrop.rider` as a new Android app under the same Firebase
project the customer/restaurant apps use, download that app's
`google-services.json`, and commit it to `rider/app/`. Nothing in this
sandbox can do that (no network access, no console credentials, and
it's a one-time human action against a real Firebase project either
way).

## What was built this session

### `rider/build.gradle` (edited)
Added the `com.google.gms.google-services` classpath plugin
(`apply false`, version 4.4.1 — same version the customer app's
top-level `build.gradle` already pins) alongside the existing
`com.android.application`/`org.jetbrains.kotlin.android` plugins. Comment
added noting the missing `google-services.json` explicitly, so this
doesn't read as an oversight to whoever hits the build failure next.

### `rider/app/build.gradle` (edited)
- Applied the `com.google.gms.google-services` plugin (comment repeats
  the same blocker note, right where the failure will actually surface).
- Added `implementation platform('com.google.firebase:firebase-bom:33.1.2')`
  and `implementation 'com.google.firebase:firebase-messaging-ktx'` —
  same BOM version and artifact the customer app's own `build.gradle`
  uses (checked directly, not assumed). Did NOT add
  `firebase-analytics` — customer/restaurant both have it but nothing
  in doc 100's backend or this session's Android work needs it, and
  adding an unused dependency isn't this session's call to make.

### `rider/app/src/main/java/com/anydrop/rider/network/Models.kt` (edited)
Appended six data classes at the end of the file: `FcmTokenBody`,
`FcmTokenResult`, `NotificationItem`, `NotificationsResult`,
`MarkReadResult`, `MarkAllReadResult`. Field-for-field copies of the
customer app's own classes of the same names (`SerializedName`
annotations included) — confirmed by reading
`customer/app/src/main/java/com/anydrop/food/network/Models.kt`
directly rather than guessing the shape from the backend docs alone.
Re-declared here rather than shared/imported because the Rider app is
a separate Gradle module with no shared code module in this project's
structure (same reasoning `EarningsLedgerAdapter`'s own kdoc already
gives for duplicating its date-formatting helper instead of sharing
it).

### `rider/app/src/main/java/com/anydrop/rider/network/ApiService.kt` (edited)
Added four Retrofit methods, grouped under a new `// ---- FCM push +
notification bell ----` comment block at the end of the interface:

- `updateFcmToken(FcmTokenBody)` → `POST rider/fcm-token-update.php`
- `getNotifications(page, perPage, unreadOnly)` → `GET
  rider/notifications-list.php`
- `markNotificationRead(id)` → `POST rider/notifications-read.php`
- `markAllNotificationsRead()` → `POST rider/notifications-read-all.php`

Matches doc 100's backend exactly (four flat files, not one
`?action=`-routed file) — confirmed against the actual `.php` files
under `backend/api/v1/rider/` this session, not assumed from the
kdoc summary alone.

## Verification done this session

1. **Read all four of doc 100's new backend files directly** (not just
   its handover doc) to confirm exact query params, body shapes, and
   response envelopes before writing the matching Retrofit signatures —
   `?page=&per_page=&unread_only=` on the GET, `?id=` query param (not
   body) on the read endpoint, `{marked_read: int}` on read-all.
2. **Read `backend/lib/notifications.php` in full** to confirm the
   `data` payload's actual keys (`screen`, plus whatever else a given
   call site adds) — needed for the still-unbuilt deep-link handling,
   so the next session doesn't have to re-derive it.
3. **Read both `CustomerFirebaseMessagingService.kt` and
   `RestaurantFirebaseMessagingService.kt` in full** as templates for
   the still-unbuilt `RiderFirebaseMessagingService` — confirmed
   neither one actually handles `data.screen`-based deep-linking either
   (both only branch on `notification_type`/`order_id`), so a rider
   version handling `screen` values will be new ground, not a copy of
   an existing pattern.
4. **Confirmed the FCM-token-after-login pattern** by reading
   `customer/app/.../ui/login/LoginActivity.kt`'s
   `registerFcmTokenAfterLogin()` and `restaurant/app/.../ui/login/
   LoginActivity.kt`'s identically-named twin — both call
   `FirebaseMessaging.getInstance().token.addOnSuccessListener {
   ... api.updateFcmToken(...) }` once right after a successful login,
   non-fatal on failure. Rider's `OtpVerifyActivity.attemptVerify()`
   (the login-success call site) still needs this added — not done
   this session.
5. **Confirmed `EarningsActivity`/`activity_earnings.xml` is the right
   template** for the still-unbuilt notification-list screen (header
   row + `SwipeRefreshLayout` + `RecyclerView` + empty state, all
   already present in the Rider app's own established list-screen
   pattern) rather than trying to port the customer app's
   `activity_simple_list.xml`, which doesn't exist in this app.
6. **Confirmed `bg_icon_circle.xml` and `bg_card_rounded.xml` already
   exist** and can be reused as-is for the still-unbuilt notification
   row/icon styling — no new card-background drawable needed, only a
   bell icon and an unread-dot drawable.
7. **Confirmed the POST_NOTIFICATIONS runtime-permission pattern** by
   reading `restaurant/app/.../ui/main/MainActivity.kt`'s
   `notificationPermissionLauncher` — plain
   `registerForActivityResult(RequestPermission())`, requested once,
   no re-prompt on denial, no custom dialog (simpler than the customer
   app's `NotificationPermissionDialog`). This is the pattern the
   still-unbuilt dashboard wiring should follow, not the customer
   app's fancier one.

None of this was compiled, run, or checked with `php -l`/a live
DB/Gradle/a real device — same standing limitation as every prior
handover.

## Still open (carried over + updated)

- **Firebase project registration for `com.anydrop.rider`** (new this
  session, see blocker above) — needs a human with Firebase console
  access; nothing else in this list can be verified to actually build
  until `google-services.json` exists at `rider/app/`.
- **`RiderFirebaseMessagingService.kt`** — not created. Needs:
  `onNewToken()` calling `updateFcmToken()` (guarded by
  `TokenManager.isLoggedIn()`, same as customer/restaurant); `onMessageReceived()`
  reading `data["screen"]` and routing to `RiderDashboardActivity` /
  `ApplicationStatusActivity` / `SubmitDocumentsActivity` per doc 99's
  five `admin/riders.php` call sites' `screen` values
  (`dashboard`, `application_status`, `submit_documents`) — this is new
  deep-link logic, not a copy of the customer/restaurant services,
  which don't handle `screen` at all (see verification #3).
- **A rider-side notification-display helper** (equivalent to
  `NotificationHelper`/`OrderNotificationHelper`) — doesn't exist yet
  in this app at all; needs a notification channel created
  (`NotificationHelper.ensureChannels()`-equivalent) before any push
  can show on Android 8+.
- **`NotificationListActivity` + `NotificationAdapter` + two layouts**
  (`activity_notifications.xml`, `item_notification.xml`) + two
  drawables (bell icon, unread-dot) — not created. Template confirmed
  (verification #5/#6 above) but no files written.
- **Bell icon + unread badge on `RiderDashboardActivity`** — not
  wired. Needs a button in `activity_rider_dashboard.xml`'s header row
  (next to `btnDocumentsAlert`/`btnLogout`) plus an
  `updateNotificationBadge()` call in `onCreate`/`onResume`, same
  shape as the customer app's `HomeActivity.updateNotificationBadge()`
  (verification not yet done against `activity_rider_dashboard.xml`'s
  exact header markup for where the badge view itself would sit).
- **`OtpVerifyActivity` post-login FCM registration** — not added.
  Pattern confirmed (verification #4) but no code written; belongs in
  `attemptVerify()`'s `accountExists=true` branch, right after
  `tokenManager.saveSession(...)`.
- **`AndroidManifest.xml`** — not edited. Still needs: the new
  `RiderFirebaseMessagingService` `<service>` entry (with the standard
  `com.google.firebase.MESSAGING_EVENT` intent-filter), the new
  `NotificationListActivity` `<activity>` entry, and a
  `POST_NOTIFICATIONS` `<uses-permission>` (not present anywhere in
  this manifest currently — checked this session, restaurant/customer
  both have it, rider doesn't).
- **`strings.xml`** — not edited. Needs a `notifications_*` block
  (title, empty state, load-error, mark-all-read content description)
  — no attempt made to guess these ahead of the screens that would use
  them.
- Everything doc 100 already carried over unchanged and untouched this
  session: migrations 75/76 still never run, `php -l` still never run,
  Order category (deep-plan §23) still entirely unbuilt, assignment
  expired/cancelled still blocked on the same product decision, COD
  settlement warning still uninvestigated, and the full live
  click-through sequence doc 100 laid out — none of it possible without
  a live DB + a real Android build + (now) a registered Firebase app,
  which is one more precondition than doc 100 knew about.
- PENDING.md/recall.md still not re-audited (flagged stale since doc
  97) — not done this session either.
