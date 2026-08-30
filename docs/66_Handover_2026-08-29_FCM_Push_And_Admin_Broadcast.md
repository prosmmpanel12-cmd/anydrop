# Handover — 2026-08-29 (session 18, doc 66): FCM Push Notifications + Admin Broadcast

## What was asked

Continuing the same conversation as doc 65 (Insights CSV Export). App
owner reviewed the remaining priority list, decided:

1. **Per-category restaurant notification toggle** (New Orders/
   Payments/Settlement/Marketing/System) — investigated first. Only 2
   of those 5 categories (`order`, `review`) have any real notification
   writer for restaurant recipients anywhere in the backend —
   Payments/Settlement/Marketing don't exist as a concept for
   restaurants yet. **Deliberately not built** — a 5-way toggle with 3
   permanently no-op switches would confuse more than help. App owner
   agreed to skip it entirely. Revisit only once broadcast/admin-driven
   notifications (built this session, see below) add real
   restaurant-facing volume worth toggling.

2. **Self Delivery vs Rider App priority** — discussed, no code
   change. Recommended deferring Self Delivery to after (or alongside)
   the Rider App, since both share the same delivery-mode/rider-
   assignment-routing dependency.

3. **FCM push notifications — all 3 apps (Customer/Restaurant/Rider),
   Type 1 (existing order/review notifications) AND admin broadcast
   (image/link/area-wise targeting) in the same session.** This is
   what this doc covers.

## Firebase Console setup (app owner did this, this session)

- Created Firebase project "Anydrop" (project id `anydrop-2d917`), Spark
  (free) plan — sufficient for FCM, which is free regardless of plan.
- Registered all 3 planned apps in the console: `com.anydrop.food`
  (Customer), `com.anydrop.restaurant` (Restaurant), and a Rider app
  package (Rider App itself is still Phase 4/unbuilt — registered
  ahead of it, same as `riders.fcm_token` already existing in schema
  ahead of the app).
- Enabled Google Analytics (= Firebase Analytics under a newer brand
  name — same underlying GA4 engine) for all 3.
- Generated a Firebase Admin SDK service account private key (Project
  settings → Service accounts → Generate new private key).
- Downloaded `google-services.json` (same file content for all 3 apps
  — Firebase always bundles every registered app's config into one
  file; which Gradle module it sits in is what scopes it, not the file
  itself).

## What was built this session

### Repo hygiene

- **`.gitignore`** (new, repo root) — protects
  `backend/config/firebase-service-account.json` from ever being
  committed, with a rotation procedure noted in the comment for if it
  ever leaks.
- **`backend/config/firebase-service-account.json`** (gitignored) —
  the live credential, placed here.
- **`customer/app/google-services.json`**,
  **`restaurant/app/google-services.json`** — placed in each app
  module (not gitignored — this file is non-secret, ships inside the
  APK anyway). A third copy is stashed at
  `docs/firebase/google-services-for-rider-app-when-built.json` for
  whenever the Rider App project scaffold exists.

### Gradle wiring (both apps)

- Root `build.gradle` (both) — added
  `com.google.gms.google-services` plugin (`apply false`), version
  4.4.1.
- App-level `build.gradle` (both) — applied the plugin, added
  `firebase-bom:33.1.2` + `firebase-messaging-ktx` +
  `firebase-analytics`.

### Backend — core FCM infra

- **`backend/sql/60_migration_fcm_tokens.sql`** — adds `fcm_token` to
  `customers` and `restaurants` (nullable, no default — `riders`
  already had this column from before the Rider App itself existed).
  Same idempotent CONTINUE-HANDLER-for-1060 pattern every other ADD
  COLUMN migration in this project uses.
- **`backend/lib/fcm.php`** (new) — the FCM HTTP v1 API sender.
  **Hand-rolled, not the official `kreait/firebase-php` or
  `google/apiclient` library** — this codebase has no Composer/vendor
  directory anywhere (checked; every other external integration,
  e.g. `PaytmStatusClient.php`, hand-rolls its own `curl` calls) and no
  network access exists in the dev sandbox to add one anyway. FCM v1
  needs nothing beyond: sign a JWT with the service account's RSA key
  (`openssl_sign`, RS256), exchange it for an OAuth2 access token
  (cached in `app_settings` as `fcm_access_token` /
  `fcm_access_token_expires_at` — tokens last 1 hour, so most sends in
  a busy period skip the mint-and-exchange round trip), then POST the
  actual message. Two public functions: `fcm_get_access_token()` and
  `fcm_send_to_token($token, $title, $body, $data, $imageUrl)`. Never
  throws outward — every failure path logs and returns
  `null`/`false`, same "push is best-effort, never blocks the real
  action" philosophy `create_notification()` already had for its own
  bell-row write.
- **`backend/lib/notifications.php`** (edited) — `create_notification()`
  now calls `fcm_send_to_token()` as its last step, independent try/
  catch from the bell-row insert (a push failure must never look like
  a bell-write failure and vice versa). Looks up the recipient's
  `fcm_token` from `customers`/`restaurants`/`riders` based on
  `recipientType`, no-ops silently for `admin` (no device token
  concept) or a null token. **This is why every existing Type 1 call
  site — order accept/reject, new order, review reply, wallet credit,
  etc — got real push delivery this session with zero call-site
  changes.** The fan-out point was always this one function.
- **`backend/api/v1/customer/fcm-token-update.php`**,
  **`backend/api/v1/restaurant/fcm-token-update.php`** (new) — plain
  POST endpoints, `{fcm_token: "..."}` → overwrites the column. No
  format validation beyond non-empty (FCM tokens have no stable public
  format contract to validate against; an invalid one just fails
  silently at send time).

### Android — Restaurant app

- **`RestaurantFirebaseMessagingService.kt`** (new) — deliberately
  thin. Does NOT build its own "new order" notification UI — routes a
  `notification_type=order` push (with an `order_id`) through the
  *existing*, real-device-tested `OrderNotificationHelper
  .showNewOrderAlert()` (fetching the one order via the existing
  `getOrder()` endpoint first, since that function needs a full
  `Order` object). Every other push type builds a `NotificationItem`
  straight from the FCM payload and routes to the existing
  `showBellNotification()`. `onNewToken()` registers the token if
  logged in, silently skips otherwise (a token minted pre-login has
  nothing to attach to).
- **`LoginActivity.kt`** (edited) — added
  `registerFcmTokenAfterLogin()`, called right after
  `tokenManager.saveSession()`. Covers the case `onNewToken()` can't:
  a token FCM already minted before login. Plain
  `FirebaseMessaging.getInstance().token.addOnSuccessListener{}`
  callback, not the coroutine `.await()` extension — that needs
  `kotlinx-coroutines-play-services`, not already a dependency, not
  worth adding for one call.
- **`ApiService.kt`** / **`Models.kt`** (edited) — `updateFcmToken()`
  call, `FcmTokenBody`/`FcmTokenResult` models.
- **`AndroidManifest.xml`** (edited) — `<service>` registration +
  intent-filter for the messaging service, `default_notification_icon`
  meta-data (for a push arriving while the app is fully killed, no
  code running to build a notification manually).
- **`OrderPollingService`'s own polling loop is left running as-is** —
  FCM is additive this session, not a replacement. Removing polling
  once push is confirmed reliable on real devices is reasonable future
  cleanup, but doing that in the same pass as standing up FCM for the
  first time (zero device verification possible here) would risk
  losing the one delivery path already proven to work.

### Android — Customer app

- **`CustomerFirebaseMessagingService.kt`** (new) — same "route to
  existing UI, don't duplicate it" approach. This app was in an even
  better position than Restaurant: `NotificationHelper
  .showOfferNotification()` **already had real `BigPictureStyle` image
  support built and tested** (the "with image / without image"
  requirement was already solved here, for the pre-existing offer-
  notification path) — this session's admin broadcast reuses that
  exact function rather than building a second image pipeline.
  `notification_type=order` routes to the existing
  `showOrderUpdateNotification()` (which already does `order_id`-based
  deep-linking); everything else (including admin broadcasts) routes
  to `showOfferNotification()` with `image_url` passed through if
  present.
- **`LoginActivity.kt`** (edited) — same
  `registerFcmTokenAfterLogin()` pattern, called after the one
  `tokenManager.saveSession()` call site (OTP-verify flow — this app
  only has one login path, unlike Restaurant's email/password).
- **`ApiService.kt`** / **`Models.kt`** (edited) — same
  `updateFcmToken()`/`FcmTokenBody`/`FcmTokenResult` additions.
- **`AndroidManifest.xml`** (edited) — same service registration +
  default-icon meta-data pattern.
- **`OrderUpdatePollingService`'s polling loop also left running** —
  same additive reasoning.

### Backend — Admin broadcast (Type 2, image/link/area-wise)

- **`backend/sql/61_migration_notification_broadcasts.sql`** (new) —
  `notification_broadcasts` table: one row per send, recording title/
  body/image_url/link_url/target_type/target_area_id/
  recipient_count/delivered_count. A receipt/history log, not a queue
  — sends fire synchronously from the admin page.
- **`backend/admin/broadcast.php`** (new) — the admin page. Key design
  points:
  - **No second delivery path.** Resolves the target audience into a
    list of recipient ids, then loops calling `create_notification()`
    once per recipient — the *exact same function* every other
    notification in this codebase already uses. This is also why a
    broadcast shows up in the recipient's in-app bell too, not just as
    a push.
  - **Targeting**: `all_customers` / `all_restaurants` /
    `area_customers` / `area_restaurants`. Area targeting resolves the
    chosen `service_areas` node **plus every descendant** (an
    iterative queue-based walk in PHP, `area_and_descendant_ids()` —
    not a MySQL `WITH RECURSIVE`, following this codebase's own
    established "walk the tree in PHP" convention from `areas.php`,
    itself chosen because some hosting setups' MariaDB doesn't support
    recursive CTEs, per `docs/Status.md`'s own note). Customers are
    matched via `customer_addresses.area_id` (a customer has no
    `area_id` of their own — only their addresses do), deduplicated
    with `DISTINCT` since one customer can have multiple addresses in
    the same targeted area/subtree. Restaurants matched directly via
    `restaurants.area_id`.
  - **Image handling**: reuses `banners.php`'s `save_banner_image()`
    validation pattern (5MB cap, real-content MIME sniff via `finfo`,
    JPG/PNG/WEBP only) via a new `save_broadcast_image()` — minus the
    crop-rect support banners.php has (not needed here, a broadcast
    image is used as-is).
  - **The one genuinely new problem this surfaced**: FCM needs an
    ABSOLUTE, publicly-fetchable image URL — Google's own servers
    fetch it directly, unlike every other `image_url`/`logo_url` in
    this schema, which stays relative and gets resolved client-side by
    each Android app's `baseUrlForStaticFiles()`. **This codebase has
    never needed an absolute base URL for anything before this**
    (checked — no email-sending feature, no other external-fetch
    feature exists anywhere in `backend/lib/`). Solved with a new
    `app_base_url` `app_settings` key, configurable right on the
    broadcast page (a small settings sub-section) rather than building
    a whole separate general Settings admin page for one value — a
    real gap, not a design choice, flagged plainly in the page's own
    UI when unset (a yellow-bordered card prompting for it, but only
    blocking sends that include an image — a text-only broadcast works
    without it).
  - **Link handling**: `link_url` rides in the FCM `data` payload as
    `data.link` — same as any other deep-link field
    `create_notification()`'s `$data` param already carries. Deciding
    what a *tap* on a broadcast notification does with that link
    (in-app webview? external browser? a specific screen?) is flagged
    as a real "still open" Android-side gap below — sending it now
    means no backend/database change is needed later, only the tap-
    handler needs to grow.
  - **`delivered_count`** is an honest approximation, documented as
    such in the page's own footnote: "had a device token to try," not
    a confirmed-delivery receipt — `create_notification()`'s push step
    is fire-and-forget by design, with no return value this loop could
    use to know whether FCM's actual response was a success.
  - Gated on `notifications_send` (migration 29's existing permission
    key — unused until now, no new key needed).
- **`backend/admin/_layout_head.php`** (edited) — added the "Push
  Notifications" sidebar entry (under the Catalog & Marketing group,
  alongside Banners/Offers) and updated the `$activeNav` doc-comment
  enum list.

## Verification done this session

Same standing constraint every session has: no PHP CLI, Android SDK,
or live Firebase send possible in this sandbox.

- Manual comment-aware (PHP `//`/`/* */` and SQL `--`) brace/paren
  balance check on every new/edited `.php`/`.kt` file this session —
  all balanced. (One false-positive caught and corrected: the first
  balance-check pass on migration 61 used PHP-style `//` comment
  detection instead of SQL's `--`, which misfired on a comment line —
  re-checked with a SQL-aware version and confirmed genuinely
  balanced.)
- XML well-formedness (`xml.dom.minidom`) on both apps'
  `AndroidManifest.xml` — both parse cleanly.
- Cross-referenced every new Kotlin call against its actual target
  signature before use — caught and fixed two real mismatches during
  this session (not left for a build to catch):
  - `NotificationItem`'s real field names (`data`, not `dataJson`) —
    fixed in `RestaurantFirebaseMessagingService.kt` before it was
    left in the file.
  - `write_audit_log()`'s real signature
    (`string $actorType, ?int $actorId, string $action, array
    $details`) vs. the `($adminId, $action, $entityType, $entityId,
    $details)` shape assumed while drafting — caught and fixed in
    `broadcast.php` before finalizing.
- Confirmed `androidx.core`/Firebase BoM version choices against what
  each app's `build.gradle` already had (no accidental duplicate/
  conflicting artifact versions introduced).

## Genuinely still open

- [ ] **Real build + device verification — flag this above the usual
      standing note**, since FCM plumbing is new to every one of the 3
      places it touches (backend live-send, Restaurant app, Customer
      app), unlike most recent sessions' Android work which extended
      an already-working screen. Recommended first real test: log in
      to the Restaurant app on a device, confirm a token gets
      registered (check `restaurants.fcm_token` isn't null), then
      trigger a real order from the Customer app and confirm the
      Restaurant app receives the loud ringing alert via push (not
      just the next poll tick) — kill the Restaurant app first to
      confirm it's really push, not the foreground service's own
      polling picking it up.
- [ ] **Generic link-tap routing** — `link_url` is sent in the FCM
      payload today, but neither app's messaging service does anything
      with a tapped notification's `data.link` beyond what
      `showOfferNotification()`/`showBellNotification()` already do
      (open Home / the bell list). Neither app has a generic "open
      this arbitrary URL" screen yet. Needs a decision: in-app WebView,
      external browser via `Intent.ACTION_VIEW`, or something else —
      then wiring in both messaging services' `onMessageReceived()`.
- [ ] **Stale FCM token cleanup** — `fcm_send_to_token()` logs (but
      doesn't act on) a `404`/`UNREGISTERED` FCM response, which means
      the token is dead (app uninstalled, data cleared, token
      rotated). Left un-auto-cleared this session since
      `fcm_send_to_token()` deliberately only ever sees a bare token
      string, not which table/row owns it (single-responsibility, see
      that file's own kdoc) — a future session could either widen that
      function's contract or have `create_notification()`'s own
      wrapper clear the column on that specific failure.
- [ ] **Rider App itself** — still Phase 4/unbuilt. Its
    `FirebaseMessagingService` equivalent, token-registration endpoint,
    and manifest wiring don't exist yet — only the `google-services.json`
    stash and the schema's pre-existing `riders.fcm_token` column are
    ready for when that project scaffold exists.
- [ ] **`app_base_url` must be set once, live**, before any broadcast
      with an image can send — the admin page blocks (with a clear
      message) rather than silently sending a broken image URL, but
      this is a manual one-time step someone needs to actually do.
- [ ] Live click-through once tooling exists: send a text-only
      broadcast to "All customers," confirm it lands in both the bell
      and as a push; send one with an image to a specific area,
      confirm `recipient_count`/`delivered_count` look sane and the
      image renders via `BigPictureStyle` on a real device.

## Files touched this session

**Repo hygiene:** `.gitignore` (new), `backend/config/
firebase-service-account.json` (new, gitignored),
`customer/app/google-services.json` (new), `restaurant/app/
google-services.json` (new), `docs/firebase/
google-services-for-rider-app-when-built.json` (new, stash).

**Gradle:** `customer/build.gradle`, `customer/app/build.gradle`,
`restaurant/build.gradle`, `restaurant/app/build.gradle` (all edited).

**Backend:** `backend/sql/60_migration_fcm_tokens.sql` (new),
`backend/sql/61_migration_notification_broadcasts.sql` (new),
`backend/lib/fcm.php` (new), `backend/lib/notifications.php` (edited),
`backend/api/v1/customer/fcm-token-update.php` (new),
`backend/api/v1/restaurant/fcm-token-update.php` (new),
`backend/admin/broadcast.php` (new), `backend/admin/_layout_head.php`
(edited).

**Android — Restaurant:** `RestaurantFirebaseMessagingService.kt`
(new), `LoginActivity.kt` (edited), `ApiService.kt` (edited),
`Models.kt` (edited), `AndroidManifest.xml` (edited).

**Android — Customer:** `CustomerFirebaseMessagingService.kt` (new),
`LoginActivity.kt` (edited), `ApiService.kt` (edited), `Models.kt`
(edited), `AndroidManifest.xml` (edited).

**Docs:** `today.md` (updated), `PENDING.md` (updated), this file.

## Suggested next session

1. Real build + device verification pass — this session's own work is
   the highest-priority candidate given how much of it is genuinely
   new plumbing (FCM end-to-end, admin broadcast, plus the still-
   unverified Insights CSV Export from doc 65).
2. Generic link-tap routing (see "still open" above) — small, but
   needed before an admin broadcast's link field does anything on tap.
3. Peak-hours analytics — still needs its own design decision first
   (doc 49).
4. Staff/RBAC, Self Delivery, Rider App — all still untouched, large
   separate phases; Self Delivery recommended to follow (not precede)
   the Rider App, per this session's discussion.
