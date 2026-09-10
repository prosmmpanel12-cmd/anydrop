# Handover — 2026-09-07 — Broadcast Base URL Consolidated Into admin_base_url; Rider Targeting Added

**App owner asked:** does the notification/FCM settings pages still
need their own base URL now that `admin_base_url` exists, and add a
Rider option to the push-notification broadcast targeting.

## Base URL — where it's actually needed

- **`fcm-settings.php`** (Firebase service-account credential page) —
  **never needed a base URL, still doesn't.** It only stores/reads a
  JSON credential; no image, link, or upload path exists on that page.
- **`broadcast.php`** (Push Notification Broadcast) — **did** have its
  own separate `app_base_url` app_settings key, used only to build an
  absolute, publicly-fetchable image URL for FCM (Google's servers
  fetch the image directly, not through the app). This predated
  today's `admin_base_url` and served the same underlying purpose
  ("this backend's own public root URL") under a different name — two
  settings meaning the same thing is exactly the drift risk
  `admin_base_url` exists to prevent.

**Consolidated:** removed `broadcast.php`'s own `save_base_url` form
action and its dedicated "Base URL" card entirely. Image URLs are now
built via `admin_base_url() . '/' . $imagePath` (same helper
`riders.php`/`banners.php`/`settlements.php`/`support.php` already use
after this morning's earlier session). The page now just links out to
`base-url-settings.php` with a one-line note instead of duplicating the
input field. **The old `app_base_url` app_settings row, if one was ever
saved, is now unused** — it isn't read anywhere anymore.

## Rider targeting added to the broadcast page

`target_type` gained `all_riders` / `area_riders`, alongside the
existing `all_customers`/`all_restaurants`/`area_customers`/
`area_restaurants`:

- **New migration 78** — `notification_broadcasts.target_type` was a
  strict ENUM (migration 61) that didn't include the new values;
  without this migration the very first rider-targeted broadcast would
  fail its INSERT outright. Widened to include both new values, same
  `MODIFY COLUMN ENUM(...)` pattern migrations 74/76 already used for
  `notifications.type`.
- **No other schema change needed** — riders already had an
  `fcm_token` column (`01_schema.sql`, seeded ahead of the Rider App
  itself) and `notifications.recipient_type` already included `'rider'`
  from the start, so `create_notification('rider', ...)` needed zero
  changes; it already resolves the rider's own `fcm_token` and sends
  the real push (`lib/notifications.php`, unchanged).
- **One naming trap avoided:** riders' area column is
  `service_area_id` (migration 69), **not** `area_id` like
  restaurants/`customer_addresses` use. The new `area_riders` recipient
  query uses the correct column name — checked directly against the
  migration rather than assumed from the restaurant/customer pattern.
- Recipient-type derivation (`str_ends_with($targetType, ...)`), the
  token-table lookup for the delivered-count approximation, and the
  recent-broadcasts target-label display map were all widened from a
  two-way (customer/restaurant) to a three-way (customer/restaurant/
  rider) branch — same shape, one more case each.

## Not done / caveats

- **Migration 78 needs to actually run** on the live DB before the
  first rider-targeted broadcast is sent — same as every migration in
  this project, nothing runs itself.
- **No live PHP/DB test** — same standing sandbox limitation as every
  session here. Verified via brace/paren balance on `broadcast.php`,
  and by reading `create_notification()`'s FCM-send branch directly to
  confirm `'rider'` was already handled with zero changes needed there.
- Any restaurant/admin who had previously saved a value under the now-
  unused `app_base_url` key should re-save the same value at
  `base-url-settings.php` if they haven't already — it isn't migrated
  automatically, since there's no live DB in this sandbox to read an
  existing value from in the first place.
