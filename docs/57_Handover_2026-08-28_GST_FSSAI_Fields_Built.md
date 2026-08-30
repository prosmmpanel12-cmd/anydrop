# Handover — 2026-08-28 (cont'd): GST/FSSAI Fields Built

## What was asked

Continue `today.md`'s priority queue after doc 56's session: §3 GST/FSSAI
fields (small, columns already existed).

## What was built

`gst_number`/`fssai_number` columns already existed on `restaurants`
(`01_schema.sql`) and were already shown read-only on the admin
dashboard, but had no way for a restaurant to set them.

**Backend:**
- `backend/api/v1/restaurant/profile-update.php` — new
  `gst_number`/`fssai_number` handling, same dynamic-SET/`array_key_exists`
  partial-update pattern as every other field in this endpoint. Loose
  format validation (not a full GSTIN checksum): GST must be 15
  alphanumeric chars (auto-uppercased), FSSAI must be 14 digits. Empty
  string clears the field (same convention as `cuisine_tags`/
  `description`); omitted key leaves it unchanged. `profile-get.php`
  needed no change — it already does `SELECT *`.

**Android (Restaurant App):**
- `network/Models.kt` — `gstNumber`/`fssaiNumber` added to both
  `RestaurantProfileDetail` (read) and `ProfileUpdateBody` (write),
  `@SerializedName("gst_number")`/`@SerializedName("fssai_number")`.
- `ui/account/EditProfileActivity.kt` — `populate()` fills the two new
  fields from the loaded profile; `save()` validates the same shape as
  the backend client-side first (inline `TextInputLayout` error, same
  as the existing name-required check) before sending.
- `res/layout/activity_edit_profile.xml` — two new outlined text fields
  under a new "Business Details" section label, placed after cuisine
  tags and before the minimum-order-amount field. No required-field
  styling — both are optional (many small restaurants don't have a GST
  registration).
- `res/values/strings.xml` — `label_business_details`,
  `hint_gst_number`, `hint_fssai_number`, `error_invalid_gst_number`,
  `error_invalid_fssai_number`.

## Not done / still open

- **No PHP or Android toolchain in this sandbox** — same standing gap
  as doc 56. Could not run `php -l` on `profile-update.php`, could not
  build the Android app. Manual brace/paren-balance check and XML
  well-formedness check passed. Needs on the dev machine:
  - `php -l backend/api/v1/restaurant/profile-update.php`
  - A live restaurant-app build + manual test: open Edit Profile, enter
    a GST/FSSAI number, Save, re-open the screen and confirm both
    persisted; try an invalid value in each field and confirm the
    inline error fires before any network call; confirm clearing both
    fields to blank and saving actually clears them server-side (not
    just leaves them unchanged).
  - Same standing gap for §0/§7/§9's backend verification from doc 56 —
    still outstanding, not touched this session.

## Not touched this session

§1 (Add-on Group UI), §3's remaining items (Temp Closure scheduling,
Bank Details form), §6 (Peak hours, Export), §8 (per-category
notification toggle, FCM push), §10–12 (Staff/RBAC, Self Delivery,
Rider App).

## Suggested next priority

Per `today.md`'s ordering: **Item Add-on Group creation UI** (§1,
medium) next, then Temp Closure scheduling (§3), then Bank Details
form (§3).

## Files touched this session

- `backend/api/v1/restaurant/profile-update.php`
- `restaurant/app/src/main/java/com/anydrop/restaurant/network/Models.kt`
- `restaurant/app/src/main/java/com/anydrop/restaurant/ui/account/EditProfileActivity.kt`
- `restaurant/app/src/main/res/layout/activity_edit_profile.xml`
- `restaurant/app/src/main/res/values/strings.xml`
- `today.md` (§3 GST/FSSAI marked done)
- `docs/57_Handover_2026-08-28_GST_FSSAI_Fields_Built.md` (this file)
