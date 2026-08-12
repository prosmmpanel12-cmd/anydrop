# Handover — Item Detail Sync + Share Link (Part 12)

Paste this whole file as your first message to Claude in the next session,
along with the attached zip (`anydrop-part12-bugfixes.zip`).

## Context

This is the **Anydrop** food-delivery app (customer/restaurant/rider apps +
PHP backend). This session fixed 3 bugs in the **customer** app's dish
detail sheet (`ItemDetailBottomSheetFragment`) and the list adapters that
open it (`MenuAdapter` on Restaurant Detail, `PopularItemsAdapter` and
`SearchResultsAdapter` on Home). **Nothing else in the repo was touched.**

## What was fixed this session

1. **Save/bookmark button out of sync (inner sheet vs. outer card)**
   Toggling the bookmark inside the item-detail sheet never told the card
   that opened it, so closing the sheet showed a stale "unsaved" icon on
   the card, and vice versa.
   - Added `ItemDetailBottomSheetFragment.onSaveStateChanged: ((Boolean) -> Unit)?`,
     fired from `bindBookmarkAndShare()`'s `FavoritesManager.toggle` result.
   - Added `newInstance(..., currentSavedOverride: Boolean? = null)` so the
     sheet opens showing whatever the caller's adapter currently has, not
     a stale `item.isSaved` snapshot.
   - Added `currentSavedState(itemId)` / `setSavedState(itemId, saved)` to
     `MenuAdapter`, `PopularItemsAdapter`, `SearchResultsAdapter`.
   - Wired both directions in `RestaurantDetailActivity.onDishClick` and
     `HomeActivity.openItemDetailSheet` (now takes `currentSaved` +
     `onSaveStateChanged` params).

2. **Cart quantity mismatch / couldn't unselect (1 → 0) inside the sheet**
   - `CartManager.setCustomized()` used to silently no-op on
     `quantity <= 0` — now routes to a new `CartManager.removeLine()`.
   - The sheet's qty stepper was clamped at a minimum of 1 — now allows
     going down to 0, and the sticky button relabels to "Remove item"
     (new string `item_remove_button`) at 0.
   - Separately: the sheet's `onAdded` callback only refreshed the
     floating "View Cart" button/badge, never the specific card's own qty
     stepper in the RecyclerView — that's why the two qty displays could
     disagree. Added `refreshCartUi(itemId)` to all three adapters
     (`notifyItemChanged` on that row) and call it from `onAdded` in both
     `RestaurantDetailActivity` and `HomeActivity`.

3. **No per-dish share link**
   - Added `ItemDetailBottomSheetFragment.buildShareLink(restaurantId, itemId)`
     → `anydrop://item?rid=<id>&iid=<id>`. Included in the share text
     (`item_share_text_format` now takes a 3rd `%3$s` arg for the link).
   - Registered an intent-filter on `RestaurantDetailActivity` in
     `AndroidManifest.xml` (now `exported="true"`) for
     `ACTION_VIEW` + scheme `anydrop` host `item`.
   - `RestaurantDetailActivity.onCreate` now parses `intent.data` when
     launched via that link (`rid`/`iid` query params) into
     `restaurantId` + `pendingHighlightItemId`.
   - Added `MenuAdapter.findItemPosition(itemId)` and
     `RestaurantDetailActivity.scrollToItemAndGlow(itemId)` — consumed
     once at the end of `applyFiltersAndSubmit()` after the first menu
     load: smooth-scrolls to the dish's row (same offset approach as
     `jumpToCategory()`) then shows a ~1.1s fading warm-orange `foreground`
     overlay (`R.color.anydrop_primary_container`) as the "glow."
   - **This is an app-only deep link, not a verified web/App Link** — no
     real domain/Digital Asset Links behind it. If Anydrop isn't installed
     on the recipient's device, the link just does nothing when tapped.
     That matches what was asked, but flag it if the user later wants a
     real `https://` universal link (needs a real domain +
     `assetlinks.json` hosted on the backend, plus `autoVerify="true"`).

## Files touched (customer app only)

- `app/src/main/java/com/anydrop/customer/data/CartManager.kt`
- `app/src/main/java/com/anydrop/customer/ui/itemdetail/ItemDetailBottomSheetFragment.kt`
- `app/src/main/java/com/anydrop/customer/ui/restaurant/MenuAdapter.kt`
- `app/src/main/java/com/anydrop/customer/ui/restaurant/RestaurantDetailActivity.kt`
- `app/src/main/java/com/anydrop/customer/ui/home/HomeActivity.kt`
- `app/src/main/java/com/anydrop/customer/ui/home/PopularItemsAdapter.kt`
- `app/src/main/java/com/anydrop/customer/ui/search/SearchResultsAdapter.kt`
- `app/src/main/res/values/strings.xml` (`item_remove_button` added,
  `item_share_text_format` gained a 3rd `%3$s` link arg)
- `app/src/main/AndroidManifest.xml` (RestaurantDetailActivity: added
  intent-filter, `exported` false → true)

## ⚠️ Not verified — do this first next session

**No Gradle/network access was available in this sandbox, so none of this
was actually compiled.** Before doing anything else:

1. `cd customer && ./gradlew :app:assembleDebug` (or open in Android
   Studio) and fix any compile errors.
2. Sanity-check `item_share_text_format`'s new 3rd arg doesn't break any
   other caller — search the whole customer module for
   `item_share_text_format` to confirm `ItemDetailBottomSheetFragment` is
   the only user.
3. Manually test all three bugs on a device/emulator:
   - Toggle save inside the sheet → close → card icon matches. Toggle on
     card → open sheet → sheet icon matches.
   - Add/change qty from the card directly vs. from inside the sheet →
     both stay in sync. Open sheet on a cart item, drag qty to 0, tap
     "Remove item" → item leaves the cart and the card goes back to
     "ADD".
   - Share a dish (system share sheet) → the shared text contains a
     `anydrop://item?rid=..&iid=..` link. With the app installed, tapping
     that link (e.g. from Notes/Messages) opens Restaurant Detail,
     scrolls to that dish, and it visibly glows for ~1s.
4. `MenuAdapter.refreshCartUi()` / `setSavedState()` use
   `notifyItemChanged(position)`, which re-binds the whole row (loses any
   in-flight row animation, minor image reload flicker via Coil's cache —
   should be instant/invisible in practice, but keep an eye out). If this
   turns out visually janky, a more surgical fix would be exposing a
   public per-ViewHolder update method instead of going through
   `notifyItemChanged`.
5. Double check `getColor(R.color.anydrop_primary_container)` glow color
   actually reads well against both light dish-card rows and any dark-mode
   theme, if the app has one — screenshots weren't reviewed for this pass.

## Known deliberate simplifications (carried over from before, unchanged)

- Addon groups still don't exist server-side — sheet's addons are a flat
  checkbox list (see file's own kdoc).
- A cart line is still keyed by `menu_item_id` alone — re-customizing the
  same dish overwrites the previous customization rather than creating a
  second line.
