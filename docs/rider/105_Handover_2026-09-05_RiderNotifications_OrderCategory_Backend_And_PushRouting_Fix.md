# Handover — Rider Notifications "Order" category built (Assignment expired/cancelled, Admin cancellation, Delivery issue), plus a pre-existing FCM push-routing bug fixed (account/order/payout now go to the right channel/screen)

Session date: 05 Sep 2026 (continues doc 104, same day). Picked up
doc 103/104's explicitly left-open option: deep-plan §23's Order
category, "still entirely unbuilt." Same standing caveat as every
prior handover in this project: nothing this session was compiled,
run, or checked against a live backend/Gradle/real device (no PHP
CLI, no Gradle, no Android SDK in this sandbox).

## What was verified before writing anything

- Re-read Rider_Deep_Plan.md §23 in full (`Assignment`: new offer/
  accepted/expired/cancelled; `Order`: restaurant ready/customer
  cancellation/admin cancellation/delivery issue; `Finance`: earning
  posted/payout initiated/completed/COD settlement warning; `Account`:
  approved/rejected/suspended/reactivated) to scope exactly which
  sub-events were still missing, rather than re-building the whole
  section from scratch.
- Grepped every `create_notification('rider', ...)` call site in
  `backend/` (not just `admin/riders.php`, which is all doc 103
  specifically re-checked) to build an honest current-state picture:
  - `backend/admin/riders.php` — Account category, all 4 events. Built
    (docs 99-103).
  - `backend/lib/dispatch.php` — Assignment category, "New delivery
    offer" only. Built (doc 85).
  - `backend/api/v1/rider/orders-deliver.php`,
    `backend/lib/rider_payout.php` — Finance category, "Earning
    posted"/"Payout approved"/"Payout completed"/"Payout rejected".
    Built (docs 90-96), but see the push-routing bug below.
  - Nothing anywhere sent Assignment-expired, Assignment-cancelled,
    Order/Admin-cancellation, or Delivery-issue. These four were the
    actual remaining gap, not the whole Order category naively assumed
    unbuilt.
- Re-read `backend/lib/dispatch.php`'s `expire_stale_offers()` in full
  before touching it, to confirm the opportunistic-sweep model (no
  cron) and exactly where a per-rider notification could be added
  without changing its cheap "only do real work when something
  expired" shape.
- Re-read `backend/admin/orders.php`'s Force-Cancel block in full,
  including the `$nonTerminalStatuses` list, to confirm force-cancel
  can land on an order that already has an assigned rider
  (`rider_assigned`/`picked_up`/`out_for_delivery`) as well as one that
  only has an open, unresponded offer (`ready` with a live `offered`
  row) — and confirmed via `orders-accept.php`'s own transaction that
  an order can only ever be in one of those two states, never both, so
  the two notification branches added are mutually exclusive by
  construction, not by a runtime check.
- Re-read `backend/lib/support.php`'s `create_ticket()` in full,
  including its own kdoc's note that it's deliberately raiser-type-
  generic so any future app's "Help & Support" screen can call it
  without this file changing — confirmed the delivery-issue
  notification hook added here fires the same way regardless of who
  eventually raises the ticket (today: admin only, per PENDING.md #9).
- Confirmed `notifications.type` ENUM (`backend/sql/76_migration_
  notification_type_account.sql`) already includes `'order'` —
  `ENUM('order','promo','system','security','review','wallet','payout',
  'account')` — so none of this session's new `create_notification(...,
  'order', ...)` calls need a new migration, unlike doc 76's own
  'account' addition.
- Re-read `backend/lib/notifications.php`'s `create_notification()` in
  full, specifically the FCM-push branch, and confirmed it always
  stamps the caller's `$type` onto the outgoing FCM payload as
  `data.notification_type` (`$fcmData['notification_type'] = $type;`).
  This is what made the Android-side bug below findable — the payload
  already carries the real type, the Android client just wasn't
  reading it.
- Re-read `RiderFirebaseMessagingService.kt` and
  `RiderNotificationHelper.kt` in full while adding the new backend
  pushes, and found `onMessageReceived()` unconditionally called
  `showAccountNotification()` regardless of `data.notification_type`.
  This is a **pre-existing bug, not something this session's backend
  changes introduced** — `dispatch.php`'s "New delivery available"
  offer push and `rider_payout.php`'s payout pushes were already being
  silently mis-routed (wrong channel, and any tap not matching
  `dashboard`/`submit_documents` already fell back to
  `ApplicationStatusActivity`, an odd landing spot for an order/payout
  push). Fixed properly this session rather than left for whoever
  eventually noticed a rider complaining that delivery-offer taps open
  their application-status screen.
- Confirmed no dedicated Rider Order Detail screen exists yet
  (deep-plan §9, Android side not built) before deciding
  `order_offer`/`order_status` pushes should land on
  `RiderDashboardActivity` — the honest current "what's happening with
  my delivery" screen, not a guessed detail screen that doesn't exist.
- Confirmed `EarningsActivity` exists at
  `rider/app/.../ui/earnings/EarningsActivity.kt` before wiring the
  `earnings` screen value to it — this screen value was already being
  sent by `rider_payout.php`/`orders-deliver.php` before this session
  but, per the bug above, was never actually reachable via a correct
  channel/routing path until now.

## What was built this session

### `backend/lib/dispatch.php` (edited)
`expire_stale_offers()` now sends "Delivery offer expired" (`type:
'order'`, `screen: 'order_offer'`) to the rider whose offer just timed
out, before moving the order to the next candidate — deep-plan §23
Assignment category, "Assignment expired". Same opportunistic-sweep
timing as the expiry itself (fires whenever the next dispatch-adjacent
endpoint call happens to sweep it, not the instant the timer hits
zero) — not changed, just extended. Query changed from a plain
two-column SELECT to a join against `orders` so the notification has
an `order_code` to reference.

### `backend/admin/orders.php` (edited)
Force-Cancel now sends one of two mutually-exclusive notifications
(deep-plan §23 Order category "Admin cancellation" / Assignment
category "Assignment cancelled"):
- If the order already has an assigned rider (`rider_id` set): "Order
  cancelled" to that rider (`screen: 'order_status'`).
- Else, if the order has a live open offer (`rider_order_assignments`
  row with `status = 'offered'`): that row is marked `'cancelled'` and
  the offered rider gets "Delivery offer cancelled" (`screen:
  'order_offer'`).
Both branches only apply to the 7 non-terminal statuses already gated
by `$nonTerminalStatuses`; an order still in
`pending`/`accepted`/`preparing` (no rider involved yet, dispatch
hasn't started) triggers neither branch, correctly.

### `backend/lib/support.php` (edited)
`create_ticket()` now sends "Delivery issue reported" to the order's
assigned rider (deep-plan §23 Order category "Delivery issue") when
both `category === 'delivery_issue'` and the linked order actually has
a `rider_id`. An order-linked delivery-issue ticket logged before any
rider ever touched the order (or with no `order_id` at all) sends
nothing — there's no one to notify. Fires after the existing audit-log
write, same "notify after the real write, never inside its
transaction" pattern this file already used for the ticket-creation
audit log itself.

### `rider/app/src/main/java/com/anydrop/rider/notifications/RiderNotificationHelper.kt` (edited)
- Added `CHANNEL_ORDER` ("Delivery updates", `IMPORTANCE_HIGH`) and
  `CHANNEL_PAYOUT` ("Earnings & payouts", `IMPORTANCE_DEFAULT`)
  alongside the existing `CHANNEL_ACCOUNT` — same "don't let a rider
  mute delivery offers while muting account alerts" reasoning the
  customer app's own channel split already follows; payout pushes get
  their own (calmer) channel rather than folding into `CHANNEL_ORDER`,
  since urgency for "a new delivery is waiting" and "your payout was
  approved" are genuinely different.
- Extended `contentIntentFor()`'s `when` block:
  `order_offer`/`order_status` → `RiderDashboardActivity`, `earnings`
  → `EarningsActivity`. `dashboard`/`submit_documents` and the
  `ApplicationStatusActivity` fallback are unchanged.
- Split the single `showAccountNotification()` body into a shared
  private `show()` plus three public entry points —
  `showAccountNotification()` (unchanged behavior/signature),
  `showOrderNotification()`, `showPayoutNotification()` (new) — so
  each type gets its own channel/notification-id without duplicating
  the builder logic three times.

### `rider/app/src/main/java/com/anydrop/rider/notifications/RiderFirebaseMessagingService.kt` (edited)
`onMessageReceived()` now branches on `data["notification_type"]`:
`"order"` → `showOrderNotification()`, `"payout"` →
`showPayoutNotification()`, anything else (including `"account"` and
any future/unrecognized type) → `showAccountNotification()`, exactly
preserving the original behavior for every case that isn't one of the
two newly-distinguished types. This is the actual fix for the
pre-existing bug described above.

### `rider/app/src/main/java/com/anydrop/rider/notifications/NotificationListActivity.kt` (edited)
Its own inline tap-routing `when` block (kept separate from
`RiderNotificationHelper`'s private `contentIntentFor()` since this
screen calls `startActivity` directly — see doc 103) updated to match
exactly: added the same `order_offer`/`order_status` →
`RiderDashboardActivity`, `earnings` → `EarningsActivity` cases, plus
the `EarningsActivity` import. Kept in sync deliberately rather than
letting the system-tray push and the in-app bell list disagree about
where the same notification's `screen` value should navigate.

## Deliberately not built this session (investigated, not guessed away)

- **"Restaurant ready" (Order category)** — not built as a separate
  notification. `dispatch_next_candidate()` only ever fires once an
  order's status becomes `'ready'` (that's its own trigger condition —
  see `dispatch.php`'s file header), so the existing "New delivery
  available" Assignment-category push already fires at the exact same
  moment a standalone "Restaurant ready" push would. Building both
  would be two notifications for one event under this codebase's
  actual dispatch design — a deliberate simplification, not a missed
  item.
- **"Customer cancellation" (Order category)** — investigated and
  found structurally impossible under this codebase's current rules,
  not merely unbuilt. `backend/api/v1/orders/cancel.php` (customer
  self-cancel) only allows `status IN ('pending', 'accepted')`, and
  `dispatch_next_candidate()` only ever offers an order to a rider once
  it reaches `'ready'` — a customer can never reach this endpoint's
  success path while any rider is assigned or has an open offer, so
  there is no rider to notify. If a future session widens the
  customer-cancel window to cover `'preparing'`/`'ready'`, this gap
  should be revisited then, not before.
- **"Assignment accepted" (Assignment category)** — not built.
  Notifying the same rider who just tapped Accept, about their own
  accept, is redundant with the app's own immediate in-UI response to
  that action (`orders-accept.php`'s `200` response already tells the
  accepting rider's own app the order is theirs). A future session
  could still add this if a background-accept path is ever built that
  doesn't go through the app's own UI, but nothing like that exists
  today.
- **COD settlement warning (Finance category)** — unchanged from every
  prior doc in this chain; still blocked on the same product decision
  (what threshold, what message, who configures it) docs 90-96 already
  left open.

## Still open

- **Nothing this session was compiled or device-tested** — no PHP CLI,
  no Gradle, no Android SDK in this sandbox. Manual brace/paren-balance
  checks were run on all 6 touched files (3 PHP, 3 Kotlin); no syntax
  tool beyond that was available.
- **Recommended first live check, backend**: trigger each of the three
  new PHP paths against a live DB — (1) let a dispatched offer's
  `expires_at` pass and confirm the offered rider's bell shows
  "Delivery offer expired" and the order actually moves to the next
  candidate; (2) force-cancel an order that already has `rider_id` set
  and confirm that rider's bell shows "Order cancelled", then
  force-cancel a `'ready'` order with a live open offer and confirm
  the assignment row flips to `'cancelled'` and the offered rider's
  bell shows "Delivery offer cancelled"; (3) log a `delivery_issue`
  ticket against an order with a rider assigned from
  `admin/support.php` and confirm that rider's bell shows "Delivery
  issue reported".
- **Recommended first live check, Android**: once a build is possible
  (Firebase is registered as of doc 104), trigger each of `'account'`,
  `'order'`, and `'payout'` pushes and confirm each lands on its own
  channel (three separate channels should be visible in Android
  notification settings for this app) and that tapping each lands on
  the right screen — this is the first real exercise of the
  channel/routing split added this session, none of it has ever run.
- Everything doc 99-104 already carried over unchanged and untouched
  this session: `php -l` still never run, migrations 75/76 still never
  run (though neither is required for this session's own changes — see
  above), assignment timeout/expiry sweep itself (not the new
  notification on top of it) unchanged, COD settlement warning still
  uninvestigated, PENDING.md/recall.md still not re-audited (flagged
  stale since doc 97 — not done this session either).

## Next step, owner's choice

Deep-plan §23's Order/Assignment categories are now built; only
"Restaurant ready" and "Customer cancellation" remain deliberately
unbuilt for the structural reasons above, and Finance's COD settlement
warning remains blocked on a product decision. That closes out §23 as
a thread — the remaining open options are: a real Gradle build +
device test of the Rider app (Firebase is registered as of doc 104, so
this is now possible for the first time — the natural next step before
adding anything further on top of this notifications work), or moving
on to a different still-fully-unbuilt deep-plan section (Rider Order
Detail §9, Live Location System §12-15, Delivery OTP §16, COD
Settlement Limit UI §18, Admin Rider Command Center §25).
