# Handover — continue from here (2026-08-24, session 7)

Continues docs/30 (Restaurant Offers screen finished after that doc was
written — `OfferManagerActivity.kt`/`OfferAdapter.kt`/
`dialog_add_offer.xml`/`item_offer_card.xml`/`activity_offer_manager.xml`
all exist now, no separate handover was written for that session).

This session responded to three things the app owner reported while
using the built screen:
1. "Couldn't create offer" error when creating an offer.
2. Wanted a notice on Free Delivery offers that the restaurant pays the
   delivery fee, not the platform.
3. In the create-offer form, scrolling down then trying to scroll back
   up doesn't work.

Same standing limitation as every prior session: no Android SDK/
Gradle/PHP CLI/live DB in this sandbox — nothing below is
build/device/DB verified.

---

## 1. "Couldn't create offer" — root cause not yet confirmed; error is no longer hidden

Migration 47 has since been run against the live DB (confirmed by app
owner), so the missing-table theory is ruled out. Audited
`offers-create.php`'s INSERT column list against migration 47's
`promo_offers` schema again — still matches exactly, column for
column. Owner reports: fails for **every** offer type, always with
**Item** scope, and the failure comes back **after a delay** (i.e. it
is reaching the server and coming back, not a client-side field
check firing instantly).

Checked the ownership path this implies (`offers-create.php` rejects
`menu_item_id` unless it belongs to `restaurant_id = owner_id` from
the auth token) against `menu-items-list.php` (the same endpoint the
create-offer dialog's item picker calls) — both use the exact same
`$owner['owner_id']` as the scoping value, so a picked item should
always pass. No mismatch found there either.

**Real problem found: the app was swallowing the actual server error.**
`OfferManagerActivity.submitNewOffer()`/`submitOfferEdit()` only ever
checked `response.isSuccessful` and showed one generic string no
matter *why* the call failed — a `422 validation_error` on
`menu_item_id`, a `403 account_suspended`, and a `500` all looked
identical on screen. Retrofit only populates `response.body()` for a
2xx; anything else's JSON lives in `response.errorBody()`, which
nothing was reading.

**Fixed this session:** added `serverErrorDetail()`, which reads
`response.errorBody()` once and extracts the `error` code (+ `fields`
list when present) from the backend's own `{success,data,error}`
envelope. Both `submitNewOffer()`'s and `submitOfferEdit()`'s failure
branches now show it — e.g. `Couldn't create offer
(validation_error: menu_item_id)` instead of just `Couldn't create
offer`. New strings: `offer_create_failed_detail`,
`offer_update_failed_detail`.

**Next step for the app owner:** retry creating an offer with Item
scope. The toast will now say *why* it failed. Whatever field/error
code it names is the real next thing to fix — report it back rather
than guessing further, since this sandbox has no live DB/device to
reproduce the failure directly.

---

## 2. Free-delivery notice — done

`dialog_add_offer.xml`: new `freeDeliveryNoticeBanner` `TextView`
(reuses the existing, previously-unused `bg_banner_info` drawable +
`info_fg` color already defined in `colors.xml` for this purpose),
placed right after the mechanic-fields section, before the scope
section. Bilingual (English + Hindi) in a single string,
`offer_free_delivery_notice`, since the app has no separate Hindi
locale resources to route through.

Wired to show only when `offer_type == free_delivery`, in all three
places the dialog is used:
- Add mode — `applyOfferTypeVisibility()`, alongside the existing
  mechanic-group visibility toggles.
- Edit mode — `showEditOfferDialog()`, since `offer_type` is
  create-only/locked and free_delivery offers stay free_delivery
  forever once created.
- View (read-only) mode — same reasoning as edit mode.

## 3. Create-offer form scroll bug — fixed

Root cause: `dialog_add_offer.xml`'s root was a plain `<ScrollView>`
inside a `BottomSheetDialog`. Plain `ScrollView` doesn't implement
`NestedScrollingChild`, so `BottomSheetBehavior` can't correctly
coordinate who owns a scroll/drag gesture — this is what produced the
"scrolls down fine, can't scroll back up" symptom (the gesture gets
handed to the sheet's own drag behavior instead of the form's scroll
content once you're partway down).

Fix: root changed to `androidx.core.widget.NestedScrollView`, which
implements nested scrolling correctly and is the standard fix for this
exact BottomSheetDialog+ScrollView interaction bug. No other structural
change — same `maxHeight`, same single child `LinearLayout`, same ids.

**Note for later:** `dialog_add_coupon.xml` uses the same plain
`ScrollView` pattern inside its own `BottomSheetDialog`. It's shorter
so the bug is less likely to be hit in practice, but it's the same
latent issue — worth the same swap next time that file is touched.

---

## Needs a real machine, not this sandbox

1. `php -l` the offer-engine PHP files (docs/29's list) — still never
   run.
2. Gradle build of the Restaurant app to confirm the XML/Kotlin
   changes above compile (view-binding IDs were hand-matched between
   `dialog_add_offer.xml` and `OfferManagerActivity.kt` and
   cross-checked with `grep`, but never compiler-checked).
3. Device test: open Create Offer → select Free Delivery → confirm the
   notice appears; scroll the form down then back up → confirm it now
   scrolls both directions; retry the failing Item-scope create → read
   the now-specific error toast and report back what it says.
