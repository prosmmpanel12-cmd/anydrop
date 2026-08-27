# Handover — H5 "View all offers & coupons" page (Checkout)

**Status: ✅ Done, confirmed working on-device (2026-08-10).** Backend
endpoint, Android UI, and `CheckoutActivity.kt` wiring are all built,
built with Gradle, and tested on a real device. See `docs/Status.md`'s
"H5 build+test" session entry for the confirmed checklist, and
`docs/features.md` §H5 for the final status.

This doc is kept for historical reference (how the feature was built,
the two build-fix commits along the way) — no further action needed.

---

## What this feature is

From `docs/07_Phase_3.7_Bug_Tracker.md` §H5: a tappable **"View all
offers & coupons"** row on Checkout that opens a full list of every
coupon usable for that order (restaurant-specific + platform-wide),
each showing its discount, min-order line, and a tap-to-apply action —
instead of the user having to already know a code to type into the
existing coupon box.

## What's built (this session)

**Backend:**
- `backend/api/v1/coupons/list.php` — new endpoint, `GET
  ?restaurant_id=&item_total=`. Auth: customer token. Lists active,
  in-date coupons where `restaurant_id IS NULL OR = :rid` (same query
  shape `lib/orders.php`'s `price_cart()` already uses), and flags each
  one `is_eligible` (false if usage-limit exhausted, or — when
  `item_total` is sent — the cart is still below that coupon's
  `min_order_amount`, with `amount_needed_to_unlock` for the UI).
- `backend/.htaccess` — added `RewriteRule ^api/v1/coupons/?$
  api/v1/coupons/list.php`. (Not strictly required — the Android app
  calls the direct `.php` path like every other endpoint — added only
  for convention/consistency with the rest of the file.)
- `docs/02_API_Contract.md` — documented the new endpoint under §3.

**Android (Customer app):**
- `network/Models.kt` — added `CouponListResult` / `CouponListItem`.
- `network/ApiService.kt` — added `getCoupons(restaurantId, itemTotal)`.
- `res/layout/item_coupon_row.xml` — one coupon row (dashed "voucher"
  card, discount headline, min-order/unlock sub-line, tap-to-apply /
  applied states).
- `res/layout/fragment_coupons_list.xml` — the bottom sheet shell
  (drag handle + title + close icon, same pattern as
  `OffersBottomSheetFragment`'s `fragment_restaurant_offers.xml`) with a
  loading spinner / empty state / RecyclerView.
- `res/drawable/bg_coupon_card.xml` — new dashed-border card background.
- `res/values/strings.xml` — new strings: `view_all_offers`,
  `coupons_sheet_title`, `coupons_empty_state`, `coupon_tap_to_apply`,
  `coupon_applied_label`, `coupon_flat_off`, `coupon_percent_off`,
  `coupon_percent_off_capped`, `coupon_min_order_line`,
  `coupon_add_more_to_unlock`, `coupon_used_up`.
- `ui/checkout/CouponsAdapter.kt` — new RecyclerView adapter; dims +
  disables ineligible rows (same "don't invite a tap that'll just fail"
  stance as `btnPlaceOrder`'s H4 disabled state).
- `ui/checkout/CouponsListBottomSheetFragment.kt` — new
  `BottomSheetDialogFragment`. Takes `restaurantId`, optional
  `itemTotal`, and the currently-applied code; loads via
  `api.getCoupons()`; exposes `var onCouponSelected: ((CouponListItem)
  -> Unit)?` for the host to set before calling `.show(...)`.
- `res/layout/activity_checkout.xml` — added a new clickable row,
  `id/rowViewAllOffers` ("View all offers & coupons", offer-tag icon +
  chevron), positioned above the existing coupon code box.

## What's left to do

**1. ✅ Done — `rowViewAllOffers` wired in `CheckoutActivity.kt`.**
`binding.rowViewAllOffers.setOnClickListener { openCouponsSheet() }` added
in `onCreate()`; `openCouponsSheet()` added next to `openAddressEditor()`;
`lastKnownItemTotal` field added and set at the top of `renderBill()`.
No import needed — `CouponsListBottomSheetFragment` is same-package.

**2. ~~Import~~** — confirmed not needed (same package).

**3. Build + test** — no Gradle build or on-device test has happened yet
for anything in this doc. Once wired:
- Push `customer/` changes, confirm `build-customer.yml` passes.
- Install the APK, open Checkout with items in cart, tap "View all
  offers & coupons".
- Confirm the sheet loads and lists coupons (needs at least one seeded
  `coupons` row with `restaurant_id IS NULL` or matching the test
  restaurant — check via phpMyAdmin if the list comes back empty).
- Confirm an eligible coupon's row is tappable, fills the code box, and
  actually applies (bill updates, `couponAppliedRow` shows).
- Confirm a coupon below its `min_order_amount` renders dimmed with the
  "Add ₹X more to unlock" sub-line and does **not** respond to taps.
- Confirm the already-applied coupon (if any) shows the check-circle
  icon instead of "Tap to apply" and isn't re-tappable.
- Confirm the empty state shows correctly if no coupons exist for that
  restaurant/platform.

**4. After it's confirmed working** — update `docs/features.md`'s H5
status line to ✅ Done (currently marked 🟡 in progress), and
`docs/Status.md` gets a normal "✅ confirmed working" session entry same
as every other completed item in that file.

## Files touched this session (for a quick diff scan)

```
backend/api/v1/coupons/list.php                                    (new)
backend/.htaccess                                                  (edited)
docs/02_API_Contract.md                                            (edited)
docs/Status.md                                                     (edited)
docs/features.md                                                   (edited)
customer/.../network/Models.kt                                     (edited)
customer/.../network/ApiService.kt                                 (edited)
customer/.../res/layout/item_coupon_row.xml                        (new)
customer/.../res/layout/fragment_coupons_list.xml                  (new)
customer/.../res/drawable/bg_coupon_card.xml                       (new)
customer/.../res/values/strings.xml                                (edited)
customer/.../ui/checkout/CouponsAdapter.kt                         (new)
customer/.../ui/checkout/CouponsListBottomSheetFragment.kt         (new)
customer/.../res/layout/activity_checkout.xml                      (edited)
customer/.../ui/checkout/CheckoutActivity.kt                       (NOT YET EDITED — see "What's left to do")
```
