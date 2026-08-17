Restaurant app — continue from here.

**Read `docs/restorent/00_Status.md`'s newest entry in full first** —
"Item 4 client half: verified already complete, no code changes needed"
(2026-08-17, at the very top of the file). All four app-owner
real-device-feedback items are now fully done, backend AND client. Do
not redo item 4's checklist — it was checked file-by-file this session
and everything (Models.kt, ApiService.kt, both dialog XMLs, MenuFragment.kt,
the MenuItemAdapter bug fix, category thumbnail) is already correct.

---

## Next feature work

**Admin-side "Approve/Reject pending restaurants" screen** — still
overdue. Self-signup (built 2026-08-14) produces `status='pending'`
restaurant rows with no approval path except a manual DB `UPDATE`. This
is the next item per doc 18's recommended build order now that item 4 is
closed out.

After that, resume doc 18 §"Recommended build order": coupons,
notification bell, reviews reply, settings, payments, analytics, staff,
then Rider App last. Insights tab (doc 19 §10 item 6) needs a new backend
`restaurant/insights.php` endpoint first.

---

## Standing risk — build verification

**Still not build-verified — now FIFTEEN+ sessions running, no Android
SDK, no PHP CLI, no network access in this sandbox.** This remains the
single biggest risk in the project and isn't gated on any feature work
above — do it the moment a real toolchain is available:

1. **Build both APKs.** Priority order for what to eyeball first: the
   Edit Profile screen end to end (logo upload bug lives here, plus the
   location-picker row/screen), the palette revert across the 10 files it
   touched, the Menu tab's drag-to-reorder + category-tabs-strip + the
   now-complete photo upload UI (dish photos, category photos), the 13+
   backend PHP endpoints, and the full Orders tab redesign.
2. **Run `backend/sql/22_migration_category_image.sql`** against the live
   DB before testing any category endpoint — `categories-create.php`/
   `categories-update.php` reference `image_url` unconditionally and will
   hard-fail without it.
3. **Confirm `restaurant_logos/`, `restaurant_dish_photos/`, and
   `category_photos/` actually get created on the live server** — three
   upload directories, none confirmed to exist on InfinityFree.
4. **Run the backend endpoints once each** against a seeded restaurant —
   `profile-get.php`/`profile-update.php`/`logo-upload.php` first given
   the still-open logo-upload bug report, then the two photo-upload
   endpoints for item 4.
5. Smoke-test Menu tab (including both new photo pickers), Orders tab,
   Account tab (including the location picker), and the pre-login flow
   (orange + white) per the running checklist in prior `00_Status.md`
   entries.

**Still-open from a prior session, unrelated to item 4:** the logo-upload
bug report needs a direct question answered before further investigation
is useful — what does the actually-tested build's `BASE_URL` point at,
and is a real InfinityFree domain provisioned yet? See the 2026-08-16
"Logo-upload bug: investigation" entry in `00_Status.md` for full detail.

Test login: demo@anydrop.test / Demo@1234 (via
backend/scripts/seed-test-data.php?key=SEED_ME if not already seeded).
QA account: test@anydrop.com / test (docs/restorent/00_Status.md's
2026-08-14 "QA test restaurant account" entry).

CI: GitHub Actions workflow at .github/workflows/build-apks.yml builds
both APKs on push to `master` (not `main`) — already fixed, don't
reintroduce the branch mismatch.

Note on zip-export completeness: a previous session's zip export was
partial (missing backend `restaurant/` category/menu-item PHP files even
though `ApiService.kt` already declared the client-side calls). Closed
2026-08-16. This session (2026-08-17) hit the *mirror* problem instead —
the docs said item 4's client half was "not started" when the files were
actually already complete. **Lesson: check the actual files a checklist
item points at before trusting the doc's done/not-done status in either
direction** — zip exports and status docs can each independently drift
from what the code actually contains.
