Restaurant app — continue from here.

**Read `docs/restorent/00_Status.md`'s newest entry in full first** —
"Dish + category photo upload: backend only, partial" (2026-08-16, at the
very top of the file). That entry closes out the backend/DB half of the
app-owner's fourth and final real-device-feedback item; **the client
(Kotlin/XML) half was not started and is what this session should do.**

All four app-owner items are now backend-complete; item 4 is the only one
still missing its client half. Do not re-ask which item to prioritize —
this is the only one left.

---

## What's already done for item 4 (don't redo it)

- **Migration `backend/sql/22_migration_category_image.sql`** — adds
  `menu_categories.image_url`. **Must be run against the DB before
  testing anything below** — `categories-create.php`/
  `categories-update.php` now reference this column unconditionally and
  will hard-fail without it.
- **Two new upload endpoints**, same shape as `logo-upload.php`:
  `menu-item-photo-upload.php` (field `photo` →
  `uploads/restaurant_dish_photos/`) and `category-photo-upload.php`
  (field `photo` → `uploads/category_photos/`). Both upload-only, return
  `{ image_url: "..." }`, don't touch the DB themselves.
- **`menu-items-create.php`/`menu-items-update.php`/
  `categories-create.php`/`categories-update.php`/`categories-list.php`**
  all now accept and/or return `image_url`.
- `docs/01_Database_Schema.md` updated for the new column.

## What's left — do these in order

1. **`Models.kt`** — add `imageUrl` (`@SerializedName("image_url")`) to
   `MenuCategory`, `CategoryCreateBody`, `CategoryUpdateBody`,
   `MenuItemCreateBody`, `MenuItemUpdateBody`. (`MenuItem`/
   `MenuItemsListResult` already have `imageUrl` — no change needed.) Add
   two small result data classes for the upload responses, mirroring
   `LogoUploadResult`.
2. **`ApiService.kt`** — `uploadMenuItemPhoto`/`uploadCategoryPhoto`,
   `@Multipart @POST`, mirroring `uploadLogo` but with field name `photo`.
3. **`dialog_add_menu_item.xml`** / **`dialog_add_category.xml`** — add a
   photo-picker row to each. Copy the visual pattern from
   `activity_edit_profile.xml`'s `logoPickerRow` block (rounded preview +
   "Add/Change photo" label).
4. **`MenuFragment.kt`** — stage picked Uris the same way
   `EditProfileActivity.pickedLogoUri` is staged (upload fires on dialog
   Save, not on pick, so cancelling the dialog never orphans a DB write —
   the uploaded file itself being an orphan is an acceptable cheap cost,
   same reasoning as the logo). Wire into `showItemDialog()`/`saveItem()`
   and `showCategoryDialog()`/`saveCategory()`.
5. **Fix a bug in `MenuItemAdapter.kt`** while you're in this code: its
   `binding.itemThumb.load(item.imageUrl)` call loads the raw relative
   path instead of prefixing `ApiClient.baseUrlForStaticFiles(context)`
   like `EditProfileActivity`'s logo preview does. Harmless until now
   (image_url was always null), but will break the moment real values
   start coming back. Fix in the same pass, not a separate one.
6. **`item_menu_category.xml`** + **`CategoryAdapter.kt`** — category
   rows have no image slot at all yet. Add a 44dp thumbnail, same pattern
   as `item_menu_food.xml`'s `itemThumb` (`bg_skeleton_thumb` background,
   tinted `ic_food_placeholder` fallback — no distinct category
   placeholder icon exists, reusing the food one is the pragmatic choice
   unless the app owner asks for a different one).

Full reasoning/detail for all of the above is in `00_Status.md`'s newest
entry — read it before starting, this prompt is just the checklist.

---

## Standing risk — build verification

**Still not build-verified — now FOURTEEN+ sessions running, no Android
SDK, no PHP CLI, no network access in this sandbox.** This remains the
single biggest risk in the project. Once a real toolchain is available:

1. **Build both APKs.** Priority order for what to eyeball first: the
   Edit Profile screen end to end (logo upload bug lives here, plus the
   location-picker row/screen from a prior session — two risks stacked on
   one screen), the palette revert across the 10 files it touched, the
   Menu tab's drag-to-reorder + category-tabs-strip + the new photo
   upload UI once item 4's client half above is finished, the 11+
   backend PHP endpoints (9 pre-existing + 2 new this session), and the
   full Orders tab redesign.
2. **Run `backend/sql/22_migration_category_image.sql`** against the live
   DB before testing any category endpoint — see the hard-fail note above.
3. **Confirm `restaurant_logos/`, `restaurant_dish_photos/`, and
   `category_photos/` actually get created on the live server** — three
   upload directories now, none confirmed to exist on InfinityFree.
4. **Run the backend endpoints once each** against a seeded restaurant —
   `profile-get.php`/`profile-update.php`/`logo-upload.php` first given
   the still-open logo-upload bug report, then the two new photo-upload
   endpoints once item 4's client half is wired up.
5. Smoke-test Menu tab (including the new photo pickers), Orders tab,
   Account tab (including the location picker), and the pre-login flow
   (orange + white) per the running checklist in prior `00_Status.md`
   entries.

**Still-open from a prior session, unrelated to item 4:** the logo-upload
bug report needs a direct question answered before further investigation
is useful — what does the actually-tested build's `BASE_URL` point at,
and is a real InfinityFree domain provisioned yet? See the 2026-08-16
"Logo-upload bug: investigation" entry in `00_Status.md` for full detail.

**After item 4 and build verification**, resume doc 18's recommended
build order:
1. Admin-side "Approve/Reject pending restaurants" screen — still
   overdue, self-signup produces pending rows with no approval path
   except a manual DB update.
2. Everything else per doc 18 §"Recommended build order" (coupons,
   notification bell, reviews reply, settings, payments, analytics,
   staff, then Rider App last). Insights tab (doc 19 §10 item 6) needs a
   new backend `restaurant/insights.php` endpoint first.

Test login: demo@anydrop.test / Demo@1234 (via
backend/scripts/seed-test-data.php?key=SEED_ME if not already seeded).
QA account: test@anydrop.com / test (docs/restorent/00_Status.md's
2026-08-14 "QA test restaurant account" entry).

CI: GitHub Actions workflow at .github/workflows/build-apks.yml builds
both APKs on push to `master` (not `main`) — already fixed, don't
reintroduce the branch mismatch.

Note: a previous session's zip export was partial (missing backend
`restaurant/` category/menu-item PHP files even though `ApiService.kt`
already declared the client-side calls for them). That gap was closed as
of the 2026-08-16 Backend entry. If a future upload is similarly partial
again, don't assume a missing file was never built — check
`00_Status.md` history first. This session's own zip export was
spot-checked and complete (backend + docs; the Kotlin/XML client
genuinely wasn't touched, not a partial-export gap) — but keep checking
on every new upload.
