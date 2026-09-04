# Handover — 2026-08-29 (doc 67): Generic Link-Tap Routing Built

## What was asked

Continuing from doc 66. Picked up the first "still open" item flagged
there: an admin broadcast's `link_url` rode in the FCM `data` payload
(`data.link`) but neither app did anything with it on tap — needed a
decision (in-app WebView vs external browser vs something else) and
the actual wiring.

## Decision

**External browser via `Intent.ACTION_VIEW`**, not an in-app WebView.
Neither app has a generic "open this arbitrary URL" screen, and
building one just for this is more than the feature needs — an
external browser tab is the standard, zero-new-UI way to open a link
from a push notification. Only `http://`/`https://` schemes are
honored; anything else (or a null/blank link) falls back to the
pre-existing behavior (Home / bell list).

## What was built

### Customer app
- `NotificationHelper.kt` — new private `openLinkOrHomeIntent()`,
  validates scheme, builds an `ACTION_VIEW` `PendingIntent` when valid,
  else falls back to the existing `openHomeIntent()`.
  `showOfferNotification()` gained an optional `linkUrl: String? = null`
  trailing param, wired to `.setContentIntent()`.
- `CustomerFirebaseMessagingService.kt` — reads `data["link"]`, passes
  it through to `showOfferNotification()`.

### Restaurant app
- `OrderNotificationHelper.kt` — `showBellNotification()` gained the
  same optional `linkUrl: String? = null` param; when valid, its
  content intent is `ACTION_VIEW` instead of opening
  `NotificationListActivity`.
- `RestaurantFirebaseMessagingService.kt` — reads `data["link"]`,
  passes it through.

No backend change needed — `broadcast.php` already sent `data.link`
since doc 66; this closes the tap-handler side only, matching what
doc 66 predicted ("sending it now means no backend/database change is
needed later, only the tap-handler needs to grow").

## Verification done this session

Same standing constraint: no PHP CLI, Android SDK, or live Firebase
send possible in this sandbox.

- Manual comment-aware brace/paren balance check on all 4 edited
  files — all balanced. (One false-positive during the check itself:
  a naive `//`-strip pass misfired on `"http://"` string literals in
  the new code, since `//` inside a string isn't a comment — re-checked
  ignoring that and confirmed genuinely balanced.)
- Cross-referenced every existing call site of the two modified
  functions (`HomeActivity.kt`'s `showOfferNotification()` call,
  `OrderPollingService.kt`'s `showBellNotification()` call) — both use
  positional args only and compile unchanged against the new trailing
  optional param.
- Confirmed `data["link"]`'s type (`String?`, from `RemoteMessage.data:
  Map<String, String>`) matches both functions' `linkUrl: String?`
  param exactly — no cast needed.

## Genuinely still open

- [ ] Real build + device verification — same as doc 66, this is new
      code path in both apps' messaging services, unverified by any
      compiler or device in this sandbox.
- [ ] Live click-through: send a broadcast with a `link_url` set,
      confirm tapping the resulting notification (both apps) opens the
      link in the device's browser rather than the app.
- [ ] Everything else from doc 66's "still open" list (stale FCM token
      cleanup, Rider App itself, `app_base_url` one-time setup) is
      unchanged by this session.

## Files touched this session

**Android — Customer:** `NotificationHelper.kt` (edited),
`CustomerFirebaseMessagingService.kt` (edited).

**Android — Restaurant:** `OrderNotificationHelper.kt` (edited),
`RestaurantFirebaseMessagingService.kt` (edited).

**Docs:** this file.

## Suggested next session

1. Real build + device verification pass (doc 66 + this doc combined —
   still the single highest-priority item, unchanged).
2. Stale FCM token cleanup (doc 66's other open item).
3. Peak-hours analytics — still needs its own design decision (doc 49).
4. Staff/RBAC, Self Delivery, Rider App — untouched, large separate
   phases.
