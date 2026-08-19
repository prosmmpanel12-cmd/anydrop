# UI/UX Overhaul — App Owner Feedback (2026-08-18)

**Status: feedback captured, NOT started.** This is a scoping/backlog doc,
not a session's changelog — nothing below has been built yet. Written
after the app owner reviewed the Restaurant app's current UI and gave
direct feedback. Applies to **both** apps (Customer + Restaurant) wherever
the same pattern repeats — see each item.

Raw feedback, translated/organized below (original was in Hinglish):
overall UI and icons feel outdated/generic, category icons shouldn't
require an upload every time, dialogs across both apps look dated
("2012-style"), the coupon create flow needs a "show on coupon screen"
enable/disable option at creation time (not just after, via the row
toggle), the existing enable/disable toggle itself looks bad, coupon
`valid_until` should use a real date-time picker instead of a raw text
field, and dialogs/fields generally need icons for visual polish.

---

## 1. Category icons — stop requiring an upload for every category

**Current state:** every menu category needs its own uploaded image;
nothing built-in.

**Ask:** ship a pre-set library of common food-category icons (e.g.
biryani, chinese, desserts, beverages, south indian, etc.) that a
restaurant can just *pick* from — upload should be optional, not
required.

**Two ways to source the icon set — needs the app owner's decision
before building, not a code question:**
1. **Bundled, fixed icon set** — a curated set of vector/PNG icons
   shipped inside the app itself (drawable resources), restaurant picks
   from a grid. Zero network dependency, works offline, no rate limits,
   no ongoing cost — but finite/curated, needs someone to actually
   pick/design the set once.
2. **Free icon/image search API at runtime** (e.g. a stock-icon or
   stock-photo API) — restaurant searches "biryani" and picks a result.
   More variety, but adds a network dependency to a flow that should
   probably work reliably even on a bad connection, plus whatever that
   API's rate-limit/licensing terms are (needs checking — most free
   tiers cap daily requests and/or restrict commercial use without
   attribution).

**Recommendation for the ask session:** default to option 1 (bundled
set) as the baseline — reliable, no external dependency, matches this
project's general "nothing hardcoded but also nothing fragile" pattern
— and treat option 2 as an optional "search for more" addition on top,
not a replacement. Confirm with the app owner before either is built.

---

## 2. Dialogs across both apps look outdated

**Current state:** dialogs in both apps are plain
`android.app.AlertDialog.Builder(...).setView(...)` wrapping a custom
`TextInputLayout`/`ChipGroup` form — functional but visually plain,
no icons, dated system-dialog chrome (title bar, buttons) rather than
a styled Material dialog.

**Every dialog XML in the project, both apps, that this applies to:**
- Customer: `dialog_notification_permission.xml`, `dialog_rate_order.xml`,
  `dialog_rate_us.xml`, `dialog_update.xml`
- Restaurant: `dialog_add_coupon.xml`, `dialog_add_menu_item.xml`,
  `dialog_add_category.xml`

**Ask:** modernize all of these — proper Material styling (rounded
corners, elevation, consistent spacing/typography matching the rest of
the app), icons on fields where it helps scannability (see item 5), and
ideally move off the plain `AlertDialog.Builder` chrome toward a styled
`MaterialAlertDialogBuilder` (Material Components already a dependency
in both apps per the coupon session's `ChipGroup` usage) or a
`BottomSheetDialogFragment` for the more complex ones (add-coupon,
add-menu-item) — bottom sheets read as more modern on mobile and give
more room than a centered dialog.

**Scope note:** this is a real design pass, not a one-line style tweak —
needs actual mockup/direction from the app owner (or Claude proposing a
direction and getting it confirmed) before touching seven-plus dialog
layouts across two apps. Don't start rewriting these blind.

---

## 3. Coupon create dialog needs a "show on coupon screen" toggle at creation time

**Current state:** `is_public` (whether the coupon is auto-suggested on
the customer app's "view all offers" screen — see
`coupons-create.php`'s kdoc for the `is_public` vs `is_active`
distinction) defaults to `0` and is **not** exposed anywhere in the
create dialog. It can only be set indirectly, and there's currently no
UI path to flip `is_public` at all — the existing row switch only
flips `is_active` (the on/off toggle), not `is_public`.

**Ask:** add a toggle to the add-coupon dialog — "Show on coupon
screen" (or similar wording) — that sets `is_public` at creation time.

**What this needs:**
- `CouponCreateBody`/`coupons-create.php` already default `is_public`
  to `0` server-side — need to confirm whether the backend already
  accepts an explicit `is_public` value in the create request body, or
  whether `coupons-create.php` needs a small addition to read and
  honor it. **Check this first** before assuming it's UI-only, unlike
  the usage-limit fields which turned out to be already fully
  supported server-side.
- New toggle UI in `dialog_add_coupon.xml` + wiring in
  `CouponManagerActivity.submitNewCoupon()`.
- Probably also needs to become editable later via
  `showEditCouponDialog()`/`coupons-update.php` for consistency, though
  the ask as given was specifically about *creation* time — confirm
  with the app owner whether edit-time toggling is also wanted or
  out of scope for now.

---

## 4. The enable/disable toggle (switch) itself looks bad

**Current state:** `item_coupon_manage_row.xml` uses a plain
`SwitchMaterial` (`switchActive`) with `app:thumbTint`/`app:trackTint`
set to the brand primary color — functional but visually plain.

**Ask:** improve the visual design of this toggle specifically. Needs
actual direction (a reference image, a specific style, or Claude
proposing 2-3 concrete options) rather than blind guessing — could mean
a custom-styled switch, a segmented on/off control, a colored pill/chip
toggle, etc. Don't rebuild this without agreeing on a direction first.

---

## 5. `valid_until` should use a real date-time picker, not a text field

**Current state:** `dialog_add_coupon.xml`'s `inputValidUntil` is a
plain `TextInputEditText` with `android:inputType="date"` — this only
changes the soft-keyboard layout hint, it does **not** open any picker
UI. The user has to type `yyyy-MM-dd` by hand; `CouponManagerActivity`
then appends `" 23:59:59"` itself.

**Ask:** wire up a real Android date picker (`MaterialDatePicker` from
Material Components, or the framework `DatePickerDialog`) that opens on
tap and writes the formatted date into the field, instead of requiring
manual typing. The app owner specifically said "date time picker" —
worth clarifying whether they want date-only (current behavior is
effectively date-only, since time is always forced to 23:59:59) or a
real date **and** time picker where the restaurant can pick a specific
cutoff time instead of always end-of-day. Confirm before building —
changes whether this needs `MaterialDatePicker` alone or
`MaterialDatePicker` + `MaterialTimePicker` chained together.

---

## 6. Icons in dialogs and fields generally

**Ask:** add icons throughout dialog/field UI — e.g. a leading icon on
`TextInputLayout` fields (via `app:startIconDrawable`), icons next to
dialog titles, etc. — for visual polish and easier scanning. Overlaps
with item 1's icon-sourcing question (bundled set vs. API) for anything
beyond simple, generic UI icons (calendar, percent sign, rupee, tag,
clock) — those generic ones can just be normal bundled vector drawables,
no sourcing debate needed, same as the rest of this app's existing
`ic_*.xml` icon set.

---

## Suggested approach for whoever picks this up next

This is a coordinated design pass across two apps, not a quick patch —
recommend:
1. Get the icon-sourcing decision (item 1) and toggle-style direction
   (item 4) from the app owner first — both are genuine design
   decisions, not implementation details.
2. Check whether `coupons-create.php` already accepts `is_public` in
   the request body (item 3) before scoping that as UI-only.
3. Confirm date-only vs date+time (item 5).
4. Then do the dialog modernization (item 2) as one coordinated pass
   across both apps' dialog layouts, applying the icon-field pattern
   (item 6) and the resolved decisions from 1/3/4/5 as part of the same
   pass, rather than touching each dialog file repeatedly across
   separate sessions.
5. As always in this sandbox: no Android SDK/Gradle available, so this
   entire pass will be unverified-by-compiler like everything else —
   flag it clearly in Status.md same as prior sessions, and prioritize
   it in the "run a real build" queue once a toolchain is available.
