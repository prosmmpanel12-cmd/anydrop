# Handover — Rider Notifications Android side, CONTINUED (notification helper + FCM service + list-screen layouts + drawables built — adapter/activity/dashboard-wiring/manifest/strings still not done, Firebase still not registered)

Session date: 05 Sep 2026 (continues doc 101, same day). Picks up doc
101's "Still open" list exactly where it left off. Same standing
caveat as every prior handover in this project: nothing this session
was compiled, run, or checked against a live backend/Gradle/real
device (no Gradle in this sandbox).

## Blocker carried over unchanged

Doc 101's Firebase-registration blocker is still fully open and
untouched this session — `rider/app/google-services.json` still does
not exist, and nothing in this sandbox can create it (needs a human
with Firebase console access to register `com.anydrop.rider` under the
project's existing Firebase app and commit the downloaded file). Every
file this session added is source that will not compile into a
working push path until that happens — same as doc 101 stated.

## What was verified before writing anything (not re-assumed from doc 101 alone)

- Re-read `rider/build.gradle` and `rider/app/build.gradle` directly —
  confirmed doc 101's plugin/BOM/`firebase-messaging-ktx` additions are
  actually present, not just described in that doc's prose.
- Re-read `rider/app/src/main/java/.../network/Models.kt` and
  `ApiService.kt` in full — confirmed the six data classes and four
  Retrofit methods doc 101 describes match what's actually in the
  files, field for field (this matters because doc 101 was the first
  handover to touch Gradle config in this project, so its own claims
  got double-checked rather than taken on faith).
- Read `customer/app/.../notifications/CustomerFirebaseMessagingService.kt`
  and `NotificationHelper.kt` in full again as templates, this time
  specifically for the channel-creation and PendingIntent-building
  shape (doc 101 had already read these as templates but hadn't yet
  written the rider equivalents).
- Read `backend/admin/riders.php`'s four `create_notification(...)`
  call sites directly (not just doc 101's summary) to confirm the
  exact three `screen` values in use today — `dashboard`,
  `application_status`, `submit_documents` — and that the
  `documents_status === 'verified'` branch also sends `screen:
  'submit_documents'` (not `dashboard`), which is easy to miss reading
  the doc summary alone since it reads more like a "went back to
  normal" event than a documents-screen one. Handled as the backend
  actually sends it, not as it "should" read.
- Read `rider/app/src/main/res/values/colors.xml` in full to confirm
  `error_fg`/`surface`/`anydrop_green` before using them in new
  drawables, rather than guessing color-alias names from the customer
  app's differently-named palette.
- Read `rider/app/src/main/java/.../ui/pending/ApplicationStatusActivity.kt`,
  `SubmitDocumentsActivity.kt`, and `RiderDashboardActivity.kt` just
  far enough to confirm their exact class names/packages for the new
  helper's screen-routing `when` block.

## What was built this session

### `rider/app/src/main/res/drawable/ic_notification.xml` (new)
Bell icon — field-for-field copy of the customer app's own
`ic_notification.xml` vector path, so both apps' bells render
identically.

### `rider/app/src/main/res/drawable/bg_unread_dot.xml` (new)
Small oval + stroke, `error_fg` on `surface` — same shape as the
customer app's own unread-dot drawable, this app's own color aliases.
For the still-unbuilt notification row's icon-corner dot.

### `rider/app/src/main/res/drawable/bg_notification_badge.xml` (new)
Same oval+stroke shape, for the still-unwired dashboard bell's
unread-count badge. Deliberately kept as its own file rather than
reusing `bg_unread_dot.xml` for both — the row dot and the header
badge are conceptually different elements (one's a per-row flag,
one's an aggregate count) even though they render identically today;
keeping them separate means either can change shape later
independently without a "wait, which places does this affect" check.

### `rider/app/src/main/java/com/anydrop/rider/notifications/RiderNotificationHelper.kt` (new)
System (status-bar) notification helper — this app's first, mirroring
the customer/restaurant apps' own `NotificationHelper` but narrower:
one channel (`anydrop_rider_account`, `IMPORTANCE_HIGH`) since
`account` is the only `notification_type` this app's backend sends
today, and one show function (`showAccountNotification`) rather than
several. `ensureChannels()`, a `hasPermission()` check gated on API 33+
(same shape as the customer app's), and `contentIntentFor(screen)`
mapping the three known `screen` values to
`RiderDashboardActivity`/`SubmitDocumentsActivity`, with anything else
(including a null/unrecognized value) falling back to
`ApplicationStatusActivity` — chosen as the fallback specifically
because every entry point in this app already routes through that
screen to figure out where an account actually stands, so it's the
one safe default regardless of the rider's real status.

### `rider/app/src/main/java/com/anydrop/rider/notifications/RiderFirebaseMessagingService.kt` (new)
`onNewToken()` — guarded by `TokenManager.isLoggedIn()`, calls
`updateFcmToken()` on a background coroutine, non-fatal on failure.
Same shape as the customer/restaurant apps' equivalents.
`onMessageReceived()` — reads `data["screen"]` (not
`notification_type`/`order_id`, since this app's payloads don't carry
those) and calls `RiderNotificationHelper.showAccountNotification()`.
This is the new deep-link logic doc 101 flagged as "not a copy of an
existing pattern" — confirmed again this session that neither
customer- nor restaurant-app service handles `screen`, so there was no
existing branch to lift.

### `rider/app/src/main/res/layout/item_notification.xml` (new)
Notification-row layout — CardView + icon circle + unread dot +
title/body/time, same shape as the customer app's `item_notification.xml`,
but using this app's own `bg_icon_circle.xml` (confirmed reusable
as-is, doc 101 verification #6) instead of introducing a
`bg_notification_icon` drawable, and this session's own
`bg_unread_dot.xml`. Icon tinted `anydrop_green` rather than the
customer app's primary-color tint, matching this app's own palette.

### `rider/app/src/main/res/layout/activity_notifications.xml` (new)
List-screen layout — header (back arrow + title + a "mark all read"
check-circle button) + `SwipeRefreshLayout` + `RecyclerView` + empty
state, built directly off `activity_earnings.xml`'s structure (doc 101
verification #5's confirmed template) rather than the customer app's
`activity_simple_list.xml`, which doesn't exist in this app. IDs are
new/rider-specific (`notificationsSwipeRefresh`, `notificationsList`,
`notificationsEmptyState`, `btnMarkAllRead`) rather than reused from
`activity_earnings.xml`, since both screens will exist in the compiled
app at the same time and view-binding IDs must be unique per binding
class, not shared.

## Still open (carried over + updated)

- **Firebase project registration for `com.anydrop.rider`** — still
  the hard blocker, unchanged from doc 101. Nothing else below can be
  verified to actually build/run until it's done.
- **`NotificationListActivity.kt` + `NotificationAdapter.kt`** — not
  created. Layouts above are ready for them; the customer app's
  `NotificationListActivity`/`NotificationAdapter` are the confirmed
  templates (doc 101 + this session's re-reads), adjusted for:
  view-binding class name `ActivityNotificationsBinding`/
  `ItemNotificationBinding` (once generated), this app's `InAppNotifier`
  signature, and deep-link routing on `data.screen` (three rider values)
  rather than the customer app's single `order_status` check.
- **Bell icon + unread badge on `RiderDashboardActivity`** — not
  wired. Needs a button in `activity_rider_dashboard.xml`'s header row
  (`FrameLayout` wrapping an `ImageView` + a `notificationBadge`
  `TextView` using this session's new `bg_notification_badge.xml`,
  same shape as the customer app's `HomeActivity` bell) plus an
  `updateNotificationBadge()` call in `onCreate`/`onResume`, and the
  `POST_NOTIFICATIONS` permission request (restaurant `MainActivity`'s
  plain `registerForActivityResult` pattern, doc 101 verification #7)
  — none of this touched this session.
- **`OtpVerifyActivity` post-login FCM registration** — still not
  added. Belongs in `attemptVerify()`'s `accountExists=true` branch,
  right after `tokenManager.saveSession(...)`, same
  `FirebaseMessaging.getInstance().token.addOnSuccessListener { ... }`
  shape as the customer/restaurant apps' `registerFcmTokenAfterLogin()`.
- **`AndroidManifest.xml`** — still not edited. Needs: the
  `RiderFirebaseMessagingService` `<service>` entry (standard
  `com.google.firebase.MESSAGING_EVENT` intent-filter), the new
  `NotificationListActivity` `<activity>` entry, and a
  `POST_NOTIFICATIONS` `<uses-permission>` — confirmed again this
  session it's still absent from this manifest.
- **`strings.xml`** — still not edited. Needs a `notifications_*`
  block: `notifications_title`, `notifications_empty`,
  `notifications_mark_all_read_cd`, and a load-error string (e.g.
  `notifications_load_error`, same naming convention as
  `earnings_load_error`) — this session's new layouts already
  reference `@string/notifications_title`,
  `@string/notifications_mark_all_read_cd`, and
  `@string/notifications_empty`, so **the layouts will fail resource
  resolution until these are added** — flagging this explicitly since
  it's a harder failure than the other still-open items (those are
  simply unbuilt; this one is a broken reference already committed).
- Everything doc 100/101 already carried over unchanged and untouched
  this session: migrations 75/76 still never run, `php -l` still never
  run, Order category (deep-plan §23) still entirely unbuilt,
  assignment expired/cancelled still blocked on the same product
  decision, COD settlement warning still uninvestigated, the full live
  click-through sequence still not possible without a live DB + a real
  Android build + a registered Firebase app.
- PENDING.md/recall.md still not re-audited (flagged stale since doc
  97) — not done this session either.
