# Handover — continue from here (2026-08-25, session 14)

Picked up the one loose end docs/35 (the "Checkout_Items_And_CouponBased_
Offers" doc) flagged and nothing since — docs/35b/36/37 all continued a
*different* thread (the Offers browse screen, then the
`allow_coupon_stacking` toggle) and never touched this one. Backend
support for `apply_mode`/`code`/`is_public` (migration 49,
`offers-create.php`, `offers-update.php`, `lib/offers.php`,
`coupons/list.php`) has existed since docs/35's coupon-based-offers
session — **only the Restaurant App's own create/edit screen was still
missing the UI to actually set any of it.** That's what this session
built.

Same sandbox limitation as every session before it: no Kotlin compiler/
Gradle/Android SDK here. Balance-checked (brace/paren/bracket,
comment/string-aware, script-checked) on every edited Kotlin file — all
balanced. Every new view-binding id was cross-checked by hand against
`dialog_add_offer.xml`'s actual `android:id` values — all matched, no
typos. `dialog_add_offer.xml` and `strings.xml` were both parsed with
Python's `xml.dom.minidom` to confirm well-formed. None of this is a
substitute for a real Gradle build — see "Needs a real machine" below.

---

## ✅ Done this session

### `network/Models.kt` (Restaurant app)

- `PromoOffer` — added `applyMode: String = "default"`, `code: String? =
  null`, `isPublic: Boolean = true` (mirrors `format_offer()`'s own
  field set, same defaults the backend falls back to for a pre-
  migration-49 row).
- `OfferCreateBody` — added `applyMode`, `code`, `isPublic` (all
  nullable, sent only when the dialog's Coupon Based branch is active —
  `offers-create.php` already defaults an omitted `apply_mode` to
  `"default"`).
- `OfferUpdateBody` — added `isPublic` only. `applyMode`/`code` are
  **not** in this class — both are create-only server-side
  (`offers-update.php`'s own kdoc, same "delete and recreate" boundary
  as `offer_type`/`scope`), so there was nothing to add there for edit.

### `res/layout/dialog_add_offer.xml`

New block inserted right after the Title field, before the (add-mode-
only) offer-type chips:

- `applyModeHint` + `applyModeGroup` (`chipApplyModeDefault` /
  `chipApplyModeCouponBased`, single-select, Default checked by
  default) — add-mode only.
- `offerCodeLayout` (`inputOfferCode`) — hidden by default, revealed
  only when Coupon Based is picked. Same styling as
  `dialog_add_coupon.xml`'s own `inputCode`.
- `offerPublicRow` (`switchOfferPublic`) — same icon+label/hint+switch
  row shape as `dialog_add_coupon.xml`'s `switchPublic` and this
  dialog's own `switchAllowCouponStacking`, for visual consistency
  across all three. Hidden unless Coupon Based is active; defaults
  checked (matches `offers-create.php`'s own omitted-means-true
  default for `is_public`).
- `editApplyModeLabel` — edit/view-mode-only locked label (apply_mode +
  code are create-only), same pattern `editOfferTypeLabel` already
  uses for `offer_type`. Shows the code inline for a coupon_based offer
  since the input field itself is hidden in edit/view mode.

New strings: `offer_hint_apply_mode`, `offer_apply_mode_default_label`,
`offer_apply_mode_coupon_based_label`, `offer_hint_code`,
`offer_apply_mode_locked_default_fmt`,
`offer_apply_mode_locked_coupon_based_fmt`, `offer_public_toggle_label`,
`offer_public_toggle_hint`, `offer_code_required_error`.

### `OfferManagerActivity.kt`

- **Add mode** — `setUpApplyModeToggle()` (new) wires the chip group to
  `applyApplyModeVisibility()`, which shows/hides `offerCodeLayout` +
  `offerPublicRow` together. Triggered once up front in
  `showAddOfferDialog()` for the default-checked state, same pattern
  `applyOfferTypeVisibility()`/`applyScopeVisibility()` already use.
  `submitNewOffer()` reads the checked chip into `"default"` |
  `"coupon_based"`; when coupon_based, validates the code field is
  non-empty (`offer_code_required_error` on failure) and reads the
  switch state into `isPublic`; both stay `null` for a default offer.
  All three threaded into `OfferCreateBody`.
- **Edit mode** — `showEditOfferDialog()` hides the add-mode chips/code
  field, shows `editApplyModeLabel` with the locked-mode text (code
  included inline for a coupon_based offer), and shows/hides
  `offerPublicRow` based on the offer's own `applyMode` — `switchOfferPublic`
  pre-filled from `offer.isPublic` when shown. `submitOfferEdit()` reads
  `isPublic` back out **only when `offerPublicRow` is visible** (i.e.
  only for an offer that's already coupon_based) — a default offer has
  nothing here to send, so its `OfferUpdateBody.isPublic` stays `null`.
- **View mode** — `showViewOfferDialog()` gets the identical locked-label
  + conditional-public-row treatment as edit mode, then adds
  `switchOfferPublic` to the dialog's existing "disable every remaining
  field" list so it's genuinely read-only when shown, not just
  visually implied.

---

## What this does NOT change

- **Offer type mechanics** (B1G1, % off, flat off, quantity deal, free
  delivery) are completely unaffected — apply_mode is an orthogonal
  toggle on top of whichever mechanic is picked, exactly as docs/35
  originally specified ("usme B1G1 rakh diya to usme 2 cheez hongi").
- **Customer-app checkout coupon-input UX** — docs/35 flagged that
  `CheckoutActivity.placeOrder()`'s `COUPON_ERROR_CODES`/`when` block
  hadn't been reviewed for whether a coupon-based-offer failure produces
  a sensible message. Still not reviewed this session — untouched,
  still open.
- **Admin visibility** — `admin/offers.php` still doesn't show
  `apply_mode`/`code`/`is_public` for any offer (docs/35's own "known
  rough edge", unchanged).

---

## Needs a real machine, not this sandbox

1. Migration 49 against the live DB — docs/35 flagged it as "not yet
   run against any real DB"; nothing in this session or since re-
   confirmed that status.
2. `php -l` on the offer-engine backend files — still never run (same
   standing gap every session note in this repo already flags).
3. **Gradle build of the Restaurant app** — first real chance to
   compile the new `DialogAddOfferBinding` fields
   (`applyModeGroup`, `chipApplyModeDefault`, `chipApplyModeCouponBased`,
   `offerCodeLayout`, `inputOfferCode`, `editApplyModeLabel`,
   `offerPublicRow`, `switchOfferPublic`) — hand cross-checked against
   the XML, not compiler-checked.
4. Device test, specifically for this session's change:
   - Create Offer → default is "Default" (no code/public row visible)
     → switch to "Coupon Based" → code field + public switch appear →
     leave code blank → Save → confirm the inline validation error
     fires and nothing is submitted.
   - Same flow with a real code typed → Save → confirm it's created,
     then reopen via Edit → confirm the locked label shows "Coupon
     Based — code X" and the public switch shows the right state and
     is still editable.
   - Toggle the public switch off in Edit → Save → reopen → confirm it
     round-trips through the server (not just held in memory).
   - Open the same offer via View → confirm the public switch shows the
     right state and can't be toggled.
   - Create/Edit a plain "Default" offer → confirm the locked label
     (edit mode) reads "Default — applies automatically, no code" and
     the public row never appears at all.
   - Customer-app side: type that offer's code at Checkout → confirm it
     applies the same discount a real coupon would (this path is
     backend-only from docs/35, already flagged as untested there too).
5. Everything else docs/29 through docs/37 already accumulated (Admin
   Order Control, Analytics, Restaurant Insights, admin-side apply_mode
   visibility, etc.) — unrelated to this session, still open per
   `PENDING.md`.

## Suggested order for next session

1. Whatever machine access unblocks §1–4 above.
2. Customer-app `CheckoutActivity`'s coupon-error-message review (docs/35's
   own flagged gap, still open).
3. `admin/offers.php` — surface `apply_mode`/`code`/`is_public` for
   admins browsing offers (docs/35's "known rough edge").
4. `PENDING.md` / `docs/21_Production_Feature_Gap_Plan.md` pass — same
   "update once verified, not before" rule doc 36/37 both note.
