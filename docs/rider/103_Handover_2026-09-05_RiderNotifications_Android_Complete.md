# Handover — Rider Notifications Android side, COMPLETE (list screen, dashboard bell/badge, manifest, strings, post-login FCM registration, POST_NOTIFICATIONS runtime request — all built; Firebase still not registered)

Session date: 05 Sep 2026 (continues doc 102, same day). Closes out doc
102's "Still open" list item by item. Same standing caveat as every
prior handover in this project: nothing this session was compiled,
run, or checked against a live backend/Gradle/real device (no Gradle
in this sandbox).

## Blocker carried over unchanged

Doc 99-102's Firebase-registration blocker is still fully open and
untouched this session — `rider/app/google-services.json` still does
not exist, and nothing in this sandbox can create it (needs a human
with Firebase console access to register `com.anydrop.rider` under the
project's existing Firebase app and commit the downloaded file). Every
file this session added or edited is source that will not compile into
a working push path until that happens — same as every prior doc in
this chain has stated.

## What was verified before writing anything

- Re-read the customer app's `NotificationListActivity.kt` and
  `NotificationAdapter.kt` in full as templates (doc 102 had already
  used these as templates for the layouts/helper/service; this session
  re-read them specifically for the list-screen's submit/appendPage/
  markRead/markAllRead/infinite-scroll logic, not yet ported).
- Re-read `rider/app/.../ui/earnings/EarningsActivity.kt` in full to
  confirm its `response.isSuccessful && response.body()?.success ==
  true` response-checking convention, and used that shape for the new
  list activity's network calls instead of the customer app's more
  lenient `.body()?.data` shortcut — this app's own established
  convention, not the customer app's.
- Re-read `RiderNotificationHelper.kt`'s `contentIntentFor()` in full
  to confirm the exact three `screen` values and fallback target
  (`ApplicationStatusActivity`) before writing the list screen's own
  tap-routing `when` block.
- Re-read `restaurant/app/.../ui/main/MainActivity.kt`'s
  `notificationPermissionLauncher` + `startOrderPollingService()` in
  full (doc 102 had already flagged this as the confirmed template but
  hadn't yet ported it) for the exact API-33-gated
  `registerForActivityResult` shape.
- Re-read `restaurant/app/src/main/AndroidManifest.xml`'s FCM
  `<service>` + `default_notification_icon` `<meta-data>` block in full
  before adding the rider app's own equivalents, rather than guessing
  the meta-data key name.
- Re-read `rider/app/.../ui/login/OtpVerifyActivity.kt`'s
  `attemptVerify()` in full to confirm the exact `accountExists=true`
  branch and where `tokenManager.saveSession(...)` is called, before
  inserting the new FCM-registration call right after it.
- Re-read `activity_rider_dashboard.xml`'s header row in full (between
  `btnDocumentsAlert` and `btnLogout`) to place the new bell in the one
  open slot without disturbing either existing view's position.
- Confirmed `@color/white` already exists in this app's `colors.xml`
  before reusing it for the badge text color (same color the
  already-built `bg_notification_badge.xml` pairs with).

## What was built this session

### `rider/app/src/main/java/com/anydrop/rider/notifications/NotificationAdapter.kt` (new)
Same submit/appendPage/markRead/markAllRead shape as the customer
app's own adapter. One deliberate simplification: no `type`-based icon
`when` block — every notification this app's backend sends today is
`type: "account"` (confirmed again this session by re-reading
`backend/admin/riders.php`'s call sites, same conclusion doc 102
already reached), so `ic_notification` is used unconditionally.

### `rider/app/src/main/java/com/anydrop/rider/notifications/NotificationListActivity.kt` (new)
List screen wired to `activity_notifications.xml`'s existing IDs
(`notificationsSwipeRefresh`, `notificationsList`,
`notificationsEmptyState`, `btnMarkAllRead`, `btnBack`). Infinite
scroll + pull-to-refresh, same shape as the customer app's list
activity. Tap-to-navigate reimplements
`RiderNotificationHelper.contentIntentFor()`'s three-value `when`
inline (rather than exposing that private method) since this screen
calls `startActivity` directly instead of building a `PendingIntent`.
Auto-marks everything read on open, same as the customer app's bell.
All network calls use this app's `isSuccessful && success == true`
convention, not the customer app's shortcut.

### `activity_rider_dashboard.xml` (edited)
Added a bell+badge `FrameLayout` (`btnNotifications` +
`notificationBadge`) in the header row, between `btnDocumentsAlert` and
`btnLogout` — same visual shape as the customer app's Home bell, using
the already-built `bg_notification_badge.xml` and this app's own
`@color/text_primary`/`@color/white`. No existing view IDs touched.

### `RiderDashboardActivity.kt` (edited)
- Wired `btnNotifications`'s click to launch
  `NotificationListActivity`.
- Added `updateNotificationBadge()` — same cheap
  `unread_only=1&per_page=1`, unread-count-only call as the customer
  app's `HomeActivity.updateNotificationBadge()` (no scale-in
  animation added here, unlike the customer app's version — kept to a
  plain visibility toggle since this dashboard already has enough
  motion from the online/offline switch and offer-countdown UI; a
  no-op simplification, not a missing feature). Called from both
  `onCreate` and `onResume` — the `onResume` call specifically so
  coming back from `NotificationListActivity` (which auto-marks
  everything read) clears the badge without waiting for a poll cycle.
- Added `notificationPermissionLauncher` +
  `requestNotificationPermissionIfNeeded()` — same API-33-gated
  `registerForActivityResult` shape as the restaurant app's
  `MainActivity`, fired once in `onCreate` right after the
  logged-in-session check, since this screen is this app's landing
  screen the same way `MainActivity` is the restaurant app's.

### `OtpVerifyActivity.kt` (edited)
Added `registerFcmTokenAfterLogin()`, called immediately after
`tokenManager.saveSession(...)` inside the `accountExists=true`
branch of `attemptVerify()` — covers a token minted before login (no
`rider_id` to attach to yet), same reasoning/shape as the customer/
restaurant apps' identically-named methods. Best-effort, silently
swallows failure — `RiderFirebaseMessagingService.onNewToken()` covers
every later token refresh regardless.

### `strings.xml` (edited)
Added the `notifications_*` block the layouts were already referencing
(`notifications_title`, `notifications_empty`,
`notifications_mark_all_read_cd`) plus `notifications_load_error`
(same naming convention as `earnings_load_error`) for the new
activity's toast-on-failure paths. This closes the broken-reference gap
doc 102 flagged as a harder failure than the rest of its "still open"
list.

### `AndroidManifest.xml` (edited)
- Added `<uses-permission android:name="android.permission.POST_NOTIFICATIONS" />`.
- Added `<activity android:name=".notifications.NotificationListActivity" android:exported="false" />`.
- Added the `RiderFirebaseMessagingService` `<service>` entry with the
  standard `com.google.firebase.MESSAGING_EVENT` intent-filter.
- Added the `default_notification_icon` `<meta-data>` entry (same key
  and drawable the restaurant app's manifest uses), for a cold-start
  push where no app code runs to build the notification itself.

## Still open

- **Firebase project registration for `com.anydrop.rider`** — still
  the hard blocker, unchanged from docs 99-102. Nothing above can be
  verified to actually build/run/deliver a push until it's done.
- **Full live click-through** — still not possible without a live DB +
  a real Android build + a registered Firebase app. Once Firebase is
  registered, the recommended first check is: log in, confirm the FCM
  token round-trips to `rider/fcm-token-update.php`, have an admin
  trigger one of the four `create_notification('rider', ...)` call
  sites in `backend/admin/riders.php`, and confirm both the system-tray
  push and the in-app bell/badge update.
- Everything doc 99-102 already carried over unchanged and untouched
  this session: migrations 75/76 still never run, `php -l` still never
  run, Order category (deep-plan §23) still entirely unbuilt,
  assignment expired/cancelled still blocked on the same product
  decision, COD settlement warning still uninvestigated.
- PENDING.md/recall.md still not re-audited (flagged stale since doc
  97) — not done this session either; this Rider Notifications thread
  has been tracked only through this doc chain + `Status.md`, same as
  doc 102.

## Next step, owner's choice

Rider Documents and Payout Requests (both already Android-complete per
docs 96-98) or Order category (deep-plan §23, still entirely unbuilt)
are the remaining open threads in the Rider app. Otherwise: get
Firebase registered for `com.anydrop.rider` so this notifications slice
can actually be device-tested end to end.
