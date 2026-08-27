# Handover — Bugs §6.1 and §6.2 (2026-08-14 session)

Session scope was `docs/bugs.md` Section 6 (3 items, all spec-level going
in, no code written for any of them yet at session start). **6.1 and 6.2
are now implemented. 6.3 is untouched — still spec-only, start there next
session.** Nothing in this session was build-verified (no Android SDK in
this environment) — see "Left for next session" below before assuming any
of this actually compiles.

## 6.1 — Home GPS-off banner (done)

Grepped `setServiceAreaUnavailable` / `empty_restaurants` first, per the
bug's own instruction, before writing anything.

**Files touched:**
- `customer/app/src/main/res/layout/activity_home.xml` — new
  `gpsOffBanner` `LinearLayout`, pinned between `topBar` and
  `searchBarContainer` (not inside `swipeRefresh`'s scrolling content).
  Icon + message TextView + a separate `btnGpsOffChangeAddress` tap
  target. `searchBarContainer`'s top constraint was retargeted from
  `topBar` to `gpsOffBanner`.
- `customer/app/src/main/res/values/strings.xml` — added
  `gps_off_banner_soft` and `change_address` only. The "unavailable" text
  variant intentionally reuses the existing `service_area_unavailable_title`
  string rather than a new one — this was the core "don't invent a second
  message" requirement in the spec.
- `customer/app/src/main/java/com/anydrop/food/ui/home/HomeActivity.kt`:
  - New field `lastAreaHasRestaurants: Boolean` (starts `true`,
    optimistic) — updated only from a genuine unfiltered/uncategorised/
    un-veg'd plain Home load (same `isUnfilteredDefaultView` gate
    `setServiceAreaUnavailable` already uses), so an active filter chip
    can't wrongly flip it.
  - `openLocationPicker()` — single shared launch site for
    `deliveryLocationText`, the banner body, and the "Change address"
    button, all opening `LocationPickerActivity`.
  - `updateGpsOffBanner()` — visibility: hidden if the active address is
    a saved (non-live) one; shown if GPS+network are both off (same
    `isProviderEnabled` check `LocationPickerActivity.fetchCurrentLocation()`
    uses) and the active address is live/unset. Text: soft nudge or the
    reused "unavailable" string, keyed off `lastAreaHasRestaurants`.
  - Called from `onResume()` (catches GPS toggled in system Settings and
    back) and from both the success and catch branches of
    `loadRestaurants()`.

**Not yet wired:** bug 6.3's future "closed/paused" badge work was called
out in the spec as a *third* place that must eventually share this same
`service_area_unavailable_title` string / `restaurants/list.php` result
set. Nothing to do here yet since 6.3 hasn't been touched, but whoever
builds 6.3's badge should check back against this file's comments.

## 6.2 — Address Book "Set as default" (done)

Confirmed via direct read that `backend/api/v1/customer/addresses.php`'s
`PUT` handler was already fully correct server-side (clears other
defaults, accepts `is_default`) — no backend changes made or needed.

**Files touched:**
- `customer/app/src/main/res/layout/item_address_card.xml` — new
  `btnSetDefaultAddress` text button in the action row, own tap target
  (separate from the card's existing tap-to-activate and from Edit/
  Delete). `visibility="gone"` when `address.isDefault` is already true.
- `customer/app/src/main/res/values/strings.xml` — added `btn_set_default`.
- `customer/app/src/main/java/com/anydrop/food/ui/profile/AddressAdapter.kt`
  — new `onSetDefault: (Address) -> Unit` constructor param, bound to the
  new button.
- `customer/app/src/main/java/com/anydrop/food/ui/profile/AddressBookActivity.kt`
  — new `setDefaultAddress(address)`: builds a **full** `AddAddressBody`
  from the existing `Address`'s fields (mirrors exactly what
  `AddressEditorBottomSheet`'s save already sends), flips only
  `isDefault = true`, calls the existing `api.updateAddress(...)` PUT.
  This is the one thing bugs.md specifically warned about — a bare
  `{"is_default": true}` body gets rejected by `require_fields(['full_address'])`
  server-side. On success, re-fetches the list (`loadAddresses()`) so the
  badge moves immediately, no stale state until next manual refresh.
  Deliberately does **not** touch `ActiveAddressManager` — setting the
  account default must not silently switch what's active on Home right
  now; that stays a separate action via the Location Picker, per the
  bug's "client-confusion note."

## 6.3 — Orders on closed/paused restaurant (NOT started)

Still exactly as bugs.md left it: `orders/create.php` needs to fetch
`operational_status`/`status` before pricing/inserting and reject with
`restaurant_not_accepting_orders` if not `open`/`approved`. The
"out-of-stock" schema question in that same bugs.md entry is a separate,
larger ask (schema + UI) — don't fold it into this fix unless the person
explicitly asks for it. There's also an open clarifying question in
bugs.md ("was the restaurant showing Open while unable to fulfil, or was
there no badge at all") that hasn't been answered — worth surfacing to
the person before or while doing this one, since the answer changes
whether `orders/create.php` alone is the whole fix.

## Left for next session

1. **No build/compile verification done for 6.1 or 6.2** — no Android SDK
   in this environment. First thing next session: build the customer
   app, fix whatever the compiler catches, then smoke-test:
   - 6.1: GPS off + no/live address → banner shows with correct text vs
     the unavailable-area screen; GPS off + saved address → banner stays
     hidden; tapping banner and "Change address" both open the Location
     Picker; toggling GPS in system Settings and returning to Home
     updates the banner without needing a manual refresh.
   - 6.2: tapping "Set as default" on a non-default address moves the
     DEFAULT badge immediately; the previously-default address's badge
     disappears; an address missing optional fields (e.g. no
     `receiver_name`) can still be set default without a
     `validation_error`; Home's active address is unaffected by setting a
     different address as default.
2. **6.3 not started** — pick this up next, per the plan already agreed
   with the person.
3. **Phase J notifications (cart-abandonment scheduler, daily engagement
   scheduler) and the earlier security fixes are still not build-verified**
   — carried over from `Status.md`'s existing "Left for next session"
   list, unchanged by this session. Check that list in `Status.md`
   directly rather than assuming it's covered here.
4. Everything else `Status.md` already listed as untouched (Phase H
   items 1-5, Phase K, bug 1.2, bug 2.3's "confirm PAT revoked" action
   item) is still untouched — this session was scoped to bugs.md §6 only.
