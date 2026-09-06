# Handover — Route Recalculation Admin Settings Page, BUILT

Session date: 05 Sep 2026. Closes the fast-follow gap doc 92 explicitly
flagged: "No admin-panel UI added yet for these three keys" (the
deviation threshold / sustain / max-recalc-interval trio added by
doc 91/92's route-line work).

> **Not built/tested against a live backend or device.** Same standing
> caveat as every other handover in this project — no PHP CLI, Android
> SDK, or DB in this sandbox. Checked by hand for brace/paren balance
> only. Treat as `🟡 IMPLEMENTED — TEST PENDING` per `done.md`'s own
> rule until it's actually run.

## What changed

### Backend — new file: `backend/admin/route-recalc-settings.php`
New admin page, modelled directly on `directions-settings.php`'s
existing "one small page for a platform-wide key" shape (chosen over
cramming these into `app-settings.php`'s per-app-suffixed `$fields`
array, since these three keys are shared across all apps, not
customer/restaurant/rider-specific — same reasoning
`directions-settings.php`'s own kdoc already gives for
`google_directions_api_key`).

- Edits the three keys `route.php` already reads via `get_setting()`:
  `rider_route_deviation_threshold_m` (default 70, allowed 5–2000m),
  `rider_route_deviation_sustain_seconds` (default 60, allowed 5–600s),
  `rider_route_max_recalc_interval_seconds` (default 90, allowed
  15–1800s).
- Bounds are sanity rails only, not tuned "correct" values — doc 92
  item 1 already says the defaults themselves are TBD until watched
  against a real rider's GPS drift. The min/max here just stop an
  admin from saving something that breaks the feature outright (e.g.
  a 0s sustain that fires on every GPS jitter sample).
- Save validates all three as numeric and in-range before writing any
  of them (all-or-nothing — doesn't partially save two fields then
  reject the third, to avoid an inconsistent trio).
- "Reset to defaults" button (own confirm dialog, own form, same
  two-form pattern `directions-settings.php` uses for its clear-key
  action).
- `write_audit_log()` on both save and reset, same convention as every
  other settings page in `backend/admin/`.
- Gated on `settings_manage`, already seeded by migration 29 — no new
  RBAC migration.

### `backend/admin/_layout_head.php`
- Added `'route_recalc_settings'` to the `$activeNav` doc-comment enum.
- New sidebar entry, `group => 'settings'`, placed alongside
  `directions_settings`/`fcm_settings` (same permission, same group,
  refresh-icon SVG path to visually distinguish it from Directions
  Settings' route-line icon).

## Why this shape, not another one

Considered folding these three fields into `directions-settings.php`
itself (same page, since both are route-line-related settings) instead
of a new page. Kept them separate because: (a) `directions-settings.php`
already has a distinct, focused job — the API key — and its masked-key
UI pattern doesn't fit plain numeric fields; (b) the sidebar entry
naming stays clearer as two small pages than one page doing two
unrelated jobs. This can be revisited/merged later if the person
prefers fewer settings pages — flagging the option rather than
guessing which they'd want.

## Open items / things to verify on a real device

1. **Build/PHP-CLI verification** — no `php -l`, no live DB, no admin
   login in this sandbox. Needs one real page load + one real save +
   one real reset, same as every other settings page's standing gap.
2. **The three numbers' real-world tuning** — unchanged from doc 92's
   own item 1; this page just makes them editable, it doesn't answer
   what the right values are.
3. Confirm the sidebar entry renders correctly and doesn't collide
   visually with the adjacent Directions Settings entry (icon reuse
   risk — different path chosen but not visually confirmed here).

Next step: log into the live admin panel, open **Settings → Route
Recalc Settings**, confirm the three current values match what
`route.php` is actually reading, save a changed value, and confirm the
customer app's next route fetch reflects it.
