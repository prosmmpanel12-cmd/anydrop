# Handover — continue from here (2026-08-24, session 6)

Continues docs/29 (Offers Engine — backend + admin built, backend
fully wired end to end). This session started the one piece docs/29
flagged as "not built" item 1 — the Restaurant App "Offers" screen
(Kotlin/XML) — but **stopped partway through, on the user's own
request to package up what exists so far.** Nothing below is broken
or half-wired into a screen that doesn't compile; it's foundation
pieces only, no Activity/Adapter/layout yet exists.

Same standing limitation as every prior session: no Android SDK/
Gradle/PHP CLI/network access for apt or composer in this sandbox —
everything below is manually re-read for correctness, not built or
run.

---

## ✅ Done this session (small slice — network layer only)

### `restaurant/.../network/Models.kt`
Added, mirroring `backend/lib/offers.php`'s `format_offer()`
field-for-field (same pairing discipline `Coupon`/`CouponCreateBody`/
`CouponUpdateBody` already establish):
- `PromoOffer` — full read model. Two fields (`times_used`,
  `is_currently_active`) are `offers-list.php`-only extras, defaulted
  in the data class so deserializing `offers-create.php`/
  `offers-update.php`'s response (which don't compute either) doesn't
  need a different model.
- `OffersListResult`, `OfferResult` — response wrappers.
- `OfferCreateBody` — every field `offers-create.php` accepts.
- `OfferUpdateBody` — **deliberately excludes** `offer_type`/`scope`/
  `menu_item_id`/`food_category_id`/`required_qty`/`get_qty`/
  `offer_price`/`discount_percent`/`discount_flat` — same "delete and
  recreate instead" boundary `offers-update.php`'s own kdoc documents
  (an offer's mechanic can't change once `offer_usages` history exists
  against it). Includes `is_deleted` for the soft-delete action and
  `status` for the restaurant's own active↔paused toggle (server
  rejects setting `status` back to `active` if it's currently
  `disabled` — 403 `offer_disabled_by_admin` — this class doesn't
  enforce that client-side, the Activity that doesn't exist yet will
  need to handle that error code same as any other).

### `restaurant/.../network/ApiService.kt`
Added three endpoints, same annotation style as the coupon block right
above them in the file:
- `GET restaurant/offers-list.php` → `getOffers()`
- `POST restaurant/offers-create.php` → `createOffer(OfferCreateBody)`
- `POST restaurant/offers-update.php?id=` → `updateOffer(id, OfferUpdateBody)`

### New drawables (`restaurant/.../res/drawable/`)
None of these existed before; the offer card design (doc 20 §14) and
create-form dialog will need them:
- `ic_pause.xml` — pause action on an active offer's card
- `ic_play.xml` — resume action on a paused offer's card
- `ic_add.xml` — "+ Create Offer" button
- `ic_fire.xml` — stands in for doc 20 §1.1's 🔥 emoji on item/percent/
  flat offer cards
- `ic_delivery.xml` — stands in for doc 20 §2's 🚚 emoji on
  free_delivery offer cards

---

## 🔴 Not built yet — this is most of the work

Nothing below exists as a file yet. This is the actual plan, worked
out by reading the codebase this session but not yet written:

### 1. `OfferManagerActivity.kt` + `OfferAdapter.kt`
Same shell shape as `CouponManagerActivity.kt`/`CouponAdapter.kt`
(reviewed in full this session — see that file for the header-bar +
SwipeRefreshLayout + RecyclerView + bottom "+ Create" button pattern
to copy).

Doc 20 §14 asks for **Active / Scheduled / Expired / Paused** tabs.
`offers-list.php`'s own kdoc already anticipates this exact ask —
comment says "return everything, let the client bucket it" (same
pattern `coupons-list.php` uses for active-vs-archived) — so this
should be **one API call, one RecyclerView, filtered client-side by a
`TabLayout`** (not four separate calls, not `ViewPager2` — simpler,
matches the "single small list, rarely churns" nature of this screen
same as coupons). Bucketing logic (decided this session, not yet
coded):

```kotlin
fun bucketFor(offer: PromoOffer, today: String /* yyyy-MM-dd */): OfferBucket = when {
    offer.status == "paused" || offer.status == "disabled" -> OfferBucket.PAUSED
    offer.endDate != null && offer.endDate < today -> OfferBucket.EXPIRED
    offer.startDate != null && offer.startDate > today -> OfferBucket.SCHEDULED
    else -> OfferBucket.ACTIVE
}
```

Deliberately **ignores** `is_currently_active` for bucketing (that
flag also folds in happy-hour time-window + weekday eligibility, which
answers "is this live in the next 60 seconds," not "which tab does
this belong in" — an offer paused for the day by its own weekday
restriction still belongs in the Active tab, just showing as
not-live-right-now on its card, same distinction doc 20 §14's own
mock implies by listing Scheduled/Expired as date-driven buckets
separate from the live-right-now flag).

### 2. `activity_offer_manager.xml` + `item_offer_card.xml`
Card content per doc 20 §14's own mock:
```text
🔥 3 Samosa @ ₹50
Used: 42 / 100
Valid: 18 Aug – 25 Aug
Status: ACTIVE
[Edit] [Pause] [View]
```
`times_used` / `total_limit` from `PromoOffer` for the "Used: X / Y"
line (Y blank/omitted when `total_limit` is null — unlimited).
`ic_fire`/`ic_delivery` (this session's new drawables) as the leading
badge icon, chosen by `offer_type == "free_delivery"` vs everything
else. "View" from the mock isn't a defined destination anywhere in
this codebase yet — recommend it opens the same edit dialog read-only,
or is dropped for v1 (flag for the app owner, don't just invent a
detail screen).

### 3. `dialog_add_offer.xml` — the hard part
Six offer types, each needing different fields visible. Reviewed
`dialog_add_coupon.xml` + `CouponManagerActivity`'s
`setUpDiscountTypeToggle()` this session as the pattern: a chip group
whose `setOnCheckedStateChangeListener` toggles other fields' visibility,
same idea but with 6 branches instead of 2, matching
`offers-create.php`'s own per-type validation exactly:

| offer_type | fields shown |
|---|---|
| quantity_deal / buy_x_for_y | required_qty, offer_price |
| buy_x_get_y | required_qty, get_qty |
| percent_discount | discount_percent, max_discount_amount |
| flat_discount | discount_flat |
| free_delivery | (none of the above — only min_order_amount, which every type shares) |

Plus, shared across all types (doc 20 §12): scope chips (item/
category/restaurant — **hide "restaurant" chip entirely when a
quantity-mechanic type is selected**, mirroring
`offers-create.php`'s own `scope === 'restaurant'` rejection for
those three types, so the form can never construct a request the
server will 422 on), an item/category picker (reuse the already-wired
`getMenuItems()` / `getFoodTags()` calls — no new endpoint needed),
min_order_amount, customer_eligibility chips (all/new/existing),
start/end date pickers (plain `MaterialDatePicker`, date-only — these
are DATE columns, not DATETIME like coupon's `valid_until`, so no
chained time picker needed here), start/end time pickers
(`MaterialTimePicker`, for the happy-hour window), a weekday
multi-select chip row (Mon–Sun, CSV `1-7` same convention
`offers-create.php`'s own weekday-cleaning logic expects), and the
three usage-limit number fields (daily/total/per-customer).

Given the size, this will likely end up as its own bottom sheet with
several distinct `LinearLayout` sections whose visibility is toggled
in Kotlin — plan for a bigger file than `dialog_add_coupon.xml`, not a
copy-paste of it.

### 4. Wiring
- `AccountFragment.kt` + `fragment_account.xml` — new "Offers" row,
  copy the `btnCouponsRow` block exactly (same style, same
  `?attr/selectableItemBackground` row pattern), placed logically next
  to `btnCouponsRow` since they're sibling concepts.
- `AndroidManifest.xml` — register `OfferManagerActivity`, copy the
  `CouponManagerActivity` `<activity>` block.
- `strings.xml` — new `offer_*` string keys (title, hints, empty
  state, success/error toasts, tab labels) — none added yet.

### 5. Customer App offer display (docs/29 item 2) — untouched, still
not started. Lower priority than finishing the Restaurant App screen
first, per docs/29's own suggested order.

---

## Needs a real machine, not this sandbox (unchanged from docs/29)

1. Run migration 47 against the live DB — still not run.
2. `php -l` every backend file docs/29 listed — still not run.
3. Live click-through per docs/29's checklist — still not done.
4. Once the Restaurant App screen above is actually built: a real
   Gradle build, since none of this session's Kotlin/XML plan has been
   compiled or even fully written yet.

## Suggested order for next session

1. Finish `OfferManagerActivity.kt`/`OfferAdapter.kt` + the two simpler
   layouts (`activity_offer_manager.xml`, `item_offer_card.xml`) first
   — gets a visible (if create-button-disabled) screen working end to
   end against the already-built backend.
2. Build `dialog_add_offer.xml` + its create/edit submit logic — the
   long pole, per the per-type field table above.
3. Wire into `AccountFragment`/manifest/strings.
4. Only then: migration 47 + `php -l` + live click-through (docs/29's
   own checklist) + a real Gradle build of the Restaurant app.
