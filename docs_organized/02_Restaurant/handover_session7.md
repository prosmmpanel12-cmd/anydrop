# Handover — continue from here (2026-08-24, session 7)

Continues session 6's handover (below, kept as history at the bottom of
this file isn't included — see the previous doc in this folder for the
full session 6 recap). Session 6 finished only the network layer
(Models.kt, ApiService.kt, 5 new drawables). This session built the
screen's layouts and list adapter, and added every string resource the
new layouts reference — but **stopped before the Activity (the actual
screen logic) was written.** Nothing below is wired into anything a
user can tap yet: there is no way to reach this screen from the app.

Same standing limitation as every prior session: no Android SDK/
Gradle/PHP CLI/network access for apt or composer in this sandbox —
everything below is manually re-read for correctness, not built or
run. XML files were checked for well-formedness with Python's
`xml.etree.ElementTree` (catches malformed tags, not Android resource
errors — a real `./gradlew assembleDebug` is still the only thing that
can confirm this compiles).

---

## ✅ Done this session

### `restaurant/.../ui/account/OfferAdapter.kt` (new)
Plain `submitList()`/`updateOne()` adapter, same shape as
`CouponAdapter` (no DiffUtil — this screen's list is small and rarely
churns, same reasoning). Renders `item_offer_card.xml` per offer:
- Fire/delivery icon chosen by `offer_type == "free_delivery"`.
- "Used: X / Y" line, Y omitted (falls back to "Used: X") when
  `total_limit` is null (unlimited) — matches the backend's own
  null-is-unlimited convention.
- "Valid: start – end" line, gracefully degrading to "Valid from X" /
  "Valid until X" / blank depending on which of `start_date`/`end_date`
  are set.
- Status badge colored via `background.setTint()` — copied this exact
  idiom from `OrderAdapter.statusStyle()` (the only existing precedent
  for status-colored badges in this app) rather than inventing a new
  pattern. Uses the existing `success_bg/fg`, `status_pending_bg/fg`,
  `error_bg/fg` colors — active=success, paused=pending, disabled=error.
- Pause/Resume button hidden entirely (not shown-then-erroring) when
  `status == "disabled"`, since `offers-update.php` rejects a
  restaurant trying to resume an admin-disabled offer with 403
  `offer_disabled_by_admin` — the card can already rule this out
  client-side, so it doesn't invite the tap.
- Does **not** own the Active/Scheduled/Expired/Paused bucketing
  itself — expects whoever calls `submitList()` (the not-yet-written
  Activity) to pre-filter by tab and hand it one bucket at a time, same
  division of responsibility `BannerAdapter` uses elsewhere.

### `restaurant/.../res/layout/activity_offer_manager.xml` (new)
Same header/SwipeRefreshLayout/RecyclerView/+Create shell as
`activity_coupon_manager.xml`, plus a `TabLayout` (`offerTabs`) with 4
fixed `TabItem`s (Active/Scheduled/Expired/Paused) for doc 20 §14's ask.
Confirmed `com.google.android.material.tabs.TabLayout` is available —
it ships in the `material:1.11.0` dependency this app already has, even
though no other screen in this app happens to use it yet.

### `restaurant/.../res/layout/item_offer_card.xml` (new)
Card content matches doc 20 §14's mock exactly: icon badge, title,
status pill, "Used: X / Y" line, "Valid: ..." line, and an
Edit/Pause-Resume/View button row. `ic_fire`/`ic_delivery` (session 6's
drawables) sit in a circular backdrop using the existing
`bg_icon_circle` drawable, tinted with `anydrop_primary` at low alpha
(there's no `anydrop_primary_light` color in this app's palette —
caught and fixed this session; used the existing primary color with
`android:alpha="0.12"` on the backdrop View instead of inventing a new
color resource).

"View" (flagged as an undefined destination in session 6's handover)
is wired here as a button (`btnOfferView`) but **not yet implemented**
in any Kotlin — the recommendation stands: open the same edit dialog
read-only, or drop it for v1. Flag for the app owner, don't just
invent a detail screen (unchanged from session 6's note).

### `restaurant/.../res/layout/dialog_add_offer.xml` (new)
The big one — built per the field table in session 6's handover,
mirroring `offers-create.php`'s own per-type validation field-for-field:

| offer_type | fields shown |
|---|---|
| quantity_deal / buy_x_for_y | `inputRequiredQty1`, `inputOfferPrice` (`mechanicQtyPriceGroup`) |
| buy_x_get_y | `inputRequiredQty2`, `inputGetQty` (`mechanicQtyGetGroup`) |
| percent_discount | `inputDiscountPercent` (`mechanicPercentGroup`) + `maxDiscountLayout` (shared section) |
| flat_discount | `inputDiscountFlat` (`mechanicFlatGroup`) |
| free_delivery | none of the above — `inputOfferMinOrder` only |

Every group except the one for the currently-checked chip starts
`visibility="gone"`; **no Kotlin listener wires this yet** — that's the
main piece left (see below). Two `required_qty` fields exist
(`inputRequiredQty1` for the price-mechanic group,
`inputRequiredQty2` for the get-mechanic group) rather than one shared
field moved between layouts, since both groups can't be visible at
once and a single view can't live in two `ViewGroup`s simultaneously —
whichever group is visible is the one the not-yet-written submit logic
should read from.

Also built, all shared across types:
- Scope chips (`offerScopeGroup`: item/category/restaurant) — **not
  yet wired** to remove the "restaurant" chip for quantity-mechanic
  types (session 6's plan called for this; the chip exists in the XML
  but nothing hides it yet).
- `inputMenuItemPicker` / `inputFoodCategoryPicker` — plain
  `AutoCompleteTextView` dropdowns (`ExposedDropdownMenu` style, first
  use of this style in the app — confirmed safe since the app's base
  theme is `Theme.Material3.Light.NoActionBar`). **Not yet populated**
  — needs an `ArrayAdapter` fed by `getMenuItems()`/`getFoodTags()` in
  the Activity. Confirmed this session that `getFoodTags()`/`FoodTag`
  is the correct source for `food_category_id` — its `id` field maps
  directly to the `food_categories` table `offers-create.php` checks
  against (re-verified by reading that file's own scope-validation
  block, not just assumed from the handover).
- `inputOfferMinOrder`, `maxDiscountLayout`/`inputOfferMaxDiscount`.
- Eligibility chips (`eligibilityGroup`: all/new/existing).
- `inputStartDate`/`inputEndDate` — date-only fields (focusable=false,
  click-only, same pattern as coupon's `inputValidUntil` but without
  the chained time picker, since these are DATE not DATETIME columns).
  **Not yet wired to a `MaterialDatePicker`.**
- `inputStartTime`/`inputEndTime` — same click-only pattern, for the
  happy-hour window. **Not yet wired to a `MaterialTimePicker`.**
- `weekdaysChipGroup` — empty `ChipGroup` container in the XML;
  **chips themselves are built in Kotlin at runtime**, same pattern
  `EditProfileActivity.buildDayChips()` already uses for
  `working_days` (confirmed by reading that function this session —
  same `1=Mon..7=Sun` convention, same `day_short_mon`..`day_short_sun`
  string resources, reused rather than duplicated).
- `inputDailyLimit`/`inputTotalLimit`/`inputPerCustomerLimit`.
- `editOfferTypeLabel` — edit-mode-only plain label (hidden in
  add-mode), same "create-only field becomes a label instead of a
  picker" pattern as `dialog_add_coupon.xml`'s `editDiscountTypeLabel`.

### `restaurant/.../res/values/strings.xml`
Added every `offer_*` string key the three new layout files and
`OfferAdapter.kt` reference (~75 keys — titles, hints, type/scope/
eligibility chip labels, status labels, action labels, format strings,
toast messages). Added **before** writing the Activity specifically so
the layouts that already reference these keys wouldn't be left
pointing at nonexistent resources in this handed-off state. Also added
`account_row_offers` for the not-yet-created `AccountFragment` row.

No other `values-*` locale directories exist in this project, so
there's nothing else to keep in sync.

---

## 🔴 Not built yet

### 1. `OfferManagerActivity.kt` — the actual screen logic. Nothing
exists yet. This is genuinely the entire remaining piece; every layout
and every string it needs is now in place. Needs, in the order session
6's handover laid out:
- `onCreate()`: wire `offerTabs`' `addOnTabSelectedListener` to
  re-filter and `adapter.submitList()` the current bucket; wire
  `btnAddOffer`/`btnBack`/`swipeRefresh` same as
  `CouponManagerActivity`.
- `loadOffers()`: call `api.getOffers()`, keep the full unfiltered list
  in a field, bucket + submit to the adapter for whichever tab is
  currently selected.
- `bucketFor(offer, today)` — session 6's handover already worked out
  the exact logic (paused/disabled → Paused tab; end_date < today →
  Expired; start_date > today → Scheduled; else → Active; deliberately
  ignoring `is_currently_active` for bucketing, see that doc's
  reasoning). Copy it in verbatim.
- `showAddOfferDialog()` / `showEditOfferDialog()`: inflate
  `DialogAddOfferBinding`, show as `BottomSheetDialog` (not
  `MaterialAlertDialogBuilder` — same reasoning as the coupon dialog).
- `setUpOfferTypeToggle()`: chip listener on `offerTypeGroup` that
  shows exactly one of `mechanicQtyPriceGroup` /
  `mechanicQtyGetGroup` / `mechanicPercentGroup` (+ `maxDiscountLayout`)
  / `mechanicFlatGroup` / (none, for free_delivery), **and** calls into
  scope-chip refresh below.
- Scope-chip refresh: when a quantity-mechanic type
  (`quantity_deal`/`buy_x_for_y`/`buy_x_get_y`) is selected, remove
  `chipScopeRestaurant` from `offerScopeGroup` (and re-select
  `chipScopeItem` if `chipScopeRestaurant` was the checked one);
  restore it for the other three types.
- Scope-chip listener: toggle `menuItemPickerLayout` /
  `foodCategoryPickerLayout` visibility (item→item picker,
  category→category picker, restaurant→neither).
- Populate `inputMenuItemPicker`/`inputFoodCategoryPicker`: call
  `api.getMenuItems()`/`api.getFoodTags()` once per dialog open, feed
  an `ArrayAdapter<String>` (item/tag names) to each
  `AutoCompleteTextView`, and track the selected item's/category's
  `id` (e.g. via the view's `tag`, same convention
  `applyValidUntilValue()` uses for the coupon dialog's date field) —
  `OfferCreateBody.menuItemId`/`foodCategoryId` need the numeric id,
  not the display name.
- Date pickers: `MaterialDatePicker` on `inputStartDate`/`inputEndDate`
  tap, no chained time picker (unlike coupon's `valid_until`) since
  these are DATE columns. Store `yyyy-MM-dd` in the field's tag, same
  tag-holds-wire-value convention as the coupon dialog.
- Time pickers: `MaterialTimePicker` on `inputStartTime`/`inputEndTime`
  tap, `HH:mm:ss` in the tag.
- `buildWeekdayChips()`: same loop as
  `EditProfileActivity.buildDayChips()`, multi-checkable, added to
  `weekdaysChipGroup`; read back as CSV `1,2,3` at submit time.
- `submitNewOffer()` / `submitOfferEdit()`: read every field per the
  currently-visible mechanic group, build `OfferCreateBody`/
  `OfferUpdateBody`, call `api.createOffer()`/`api.updateOffer()`, same
  true/false-on-validation-passed contract as
  `CouponManagerActivity.submitNewCoupon()` so the bottom sheet only
  dismisses on success.
- `togglePauseResume(offer)`: `api.updateOffer(offer.id,
  OfferUpdateBody(status = if paused "active" else "paused"))`; handle
  the 403 `offer_disabled_by_admin` response code specifically (show
  `offer_disabled_by_admin_message`, not the generic failure toast) —
  though per the adapter's own guard this response code should now be
  unreachable from the UI (button hidden for disabled offers), so
  treat it as defense-in-depth, not the primary guard.
- `showViewOfferDialog(offer)`: open `showEditOfferDialog(offer)` with
  every field disabled/read-only and the Save button hidden — simplest
  implementation matching session 6's recommendation, until the app
  owner says otherwise.
- Confirm-before-delete isn't in this session's or session 6's scope
  (doc 20 §14's mock only shows Edit/Pause/View, no Delete button on
  the card) — `OfferUpdateBody.isDeleted` exists in the model for
  later if the app owner asks for it, but nothing calls it yet.

### 2. Wiring
- `AccountFragment.kt` + `fragment_account.xml` — new "Offers" row
  (`account_row_offers` string already added), copy the
  `btnCouponsRow` block exactly, launching `OfferManagerActivity`
  instead of `CouponManagerActivity`. Placed next to `btnCouponsRow`.
- `AndroidManifest.xml` — register `OfferManagerActivity`, copy the
  `CouponManagerActivity` `<activity>` block verbatim (same
  `android:exported="false"`).

### 3. Customer App offer display (docs/29 item 2) — untouched, still
not started. Unchanged from session 6.

---

## Needs a real machine, not this sandbox (unchanged from session 6)

1. Run migration 47 against the live DB — still not run.
2. `php -l` every backend file docs/29 listed — still not run.
3. Live click-through per docs/29's checklist — still not done.
4. A real Gradle build — none of this session's or session 6's
   Kotlin/XML has been compiled. XML files were only checked for
   well-formedness (valid tags/nesting), not validated against Android
   resource/attribute rules or actual view-binding generation — a
   typo'd `app:` attribute or a wrong style parent would only surface
   in a real build.

## Suggested order for next session

1. Write `OfferManagerActivity.kt` — every layout and string it needs
   already exists (this session's work), so this is now the single
   remaining file standing between "no screen exists" and "screen
   compiles and is navigable." Follow the bullet list under "Not built
   yet, item 1" above in order — `bucketFor()` + `loadOffers()` +
   basic list rendering first (gets a visible, if create-disabled,
   screen working end to end against the already-built backend), then
   the offer-type toggle + mechanic fields (the long pole), then
   pickers/date/time/weekday last.
2. Wire into `AccountFragment`/manifest.
3. Only then: migration 47 + `php -l` + live click-through (docs/29's
   own checklist) + a real Gradle build of the Restaurant app.
