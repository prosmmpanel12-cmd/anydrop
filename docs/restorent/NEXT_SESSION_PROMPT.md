Restaurant app — continue from here.

**Both previously-flagged top-priority items are now done, confirmed by
reading the code (not just the docs):**
1. `AccountFragment.kt` matches `fragment_account.xml`'s current IDs
   (`profileNameText`, `profileAddressText`, `profileHoursText`,
   `profileLogoThumb`, `upiIdText`, `currentDueText`,
   `switchTempClosed`, `profileSummaryCard`/`btnEditProfileRow` →
   `EditProfileActivity` via `registerForActivityResult`, `swipeRefresh`,
   `btnLogout`) — the Account tab no longer crashes on open.
2. `MainActivity.kt`'s `loadOperationalStatus()` reads
   `isOpen = summary.operationalStatus == "open"` (was
   `!= "busy"`) — the top-bar pill correctly shows closed for a
   `temp_closed` restaurant.

Also done this session: **doc 19 §10 item 7, the pre-login/detail
screens ink pass** — see `00_Status.md`'s newest entry for the full
list (login/signup hero panel, splash, OTP verify header, order detail
header, edit profile header, signup success icon backdrop all updated
to use the `anydrop_ink`/`text_on_ink` tokens from the palette refresh).

Context: read `docs/restorent/00_Status.md`'s newest entry in full
before starting anything new — it has the complete list of files
touched and the known gaps (mainly: no build/visual verification, and
one judgment call flagged on the signup-success icon treatment).

**Still not build-verified — now TEN sessions running, no Android SDK,
no PHP CLI, no network access in this sandbox.** This is the single
biggest risk in the project and should be the top priority for whoever
has a real toolchain, oldest unverified surface first:

1. **Build both APKs first**, before adding anything else on top. The
   Account tab's now-fixed crash is exactly the kind of bug a real
   compile/run would have caught immediately — a strong signal this
   needs a real toolchain soon, not just more careful reading. This
   session's ink pass touches 7 layout files across every pre-login/
   detail screen in the Restaurant app — eyeball all of them (header
   contrast, hero panel legibility, status bar icon color) once a
   toolchain is available. Other unverified surface, newest to oldest:
   the palette refresh's global theme/status-bar/nav-chrome change, the
   Edit Profile screen end to end (logo pick → upload → save →
   reflected in the summary card), the `ItemTouchHelper` drag-to-reorder
   wiring and category-tabs-strip, the 8 backend PHP endpoints (never
   hit a real PHP runtime or DB), Coil dependency + skeleton
   `ScrollView` wrapping, and — further back — the full Orders tab
   redesign (`OrderAdapter`'s `mode`-based constructor, `Handler`-based
   countdown ticker, `fragment_orders.xml`'s three stacked
   `RecyclerView`s). See `00_Status.md`'s 2026-08-15/16 entries for that
   full list.
2. **Run the backend endpoints once each** (Postman/curl) against a
   seeded restaurant before trusting any client-side call into them —
   `profile-get.php`/`profile-update.php`/`logo-upload.php` alongside
   the earlier category/menu-item batch.
3. **Smoke-test the Menu tab end to end**: category/item CRUD,
   out-of-stock toggle, search, tabs-strip appearance at 5+ categories,
   drag-reorder persisting across a pull-to-refresh.
4. **Smoke-test the Orders tab** per the 2026-08-15 entry's checklist.
5. **Smoke-test the Account tab**: profile load, edit + save (including
   a logo change), temp-closed toggle reflecting correctly on the
   top-bar pill, logout.
6. **Smoke-test the pre-login flow**: splash → login/signup (ink hero
   panel legibility), OTP verify (new header bar), signup success (icon
   backdrop), order detail + edit profile headers.
7. Minor polish noted in `00_Status.md` if there's time after the
   above: reject-dialog title string, disabling action buttons
   mid-flight, stat-strip skeleton, category `sort_order` cleanup for
   soft-disabled categories.

**After build verification**, resume doc 18's recommended build order:
1. **Admin-side "Approve/Reject pending restaurants" screen** —
   flagged as increasingly overdue since self-signup already produces
   pending rows with no way to approve them except a manual DB update.
   This is now the top non-verification priority.
2. Everything else per doc 18 §"Recommended build order" (coupons,
   notification bell, reviews reply, settings, payments, analytics,
   staff, then Rider App last). Insights tab (doc 19 §10 item 6) needs
   a new backend `restaurant/insights.php` endpoint first — flag that
   to the app owner before starting it.

Test login: demo@anydrop.test / Demo@1234 (via
backend/scripts/seed-test-data.php?key=SEED_ME if not already seeded).
QA account: test@anydrop.com / test (docs/restorent/00_Status.md's
2026-08-14 "QA test restaurant account" entry).

CI: GitHub Actions workflow at .github/workflows/build-apks.yml builds
both APKs on push to `master` (not `main`) — already fixed, don't
reintroduce the branch mismatch.

Note: a previous session's zip export was partial (missing backend
`restaurant/` category/menu-item PHP files even though `ApiService.kt`
already declared the client-side calls for them). That gap was closed
as of the 2026-08-16 Backend entry. If a future upload is similarly
partial again, don't assume a missing file was never built — check
`00_Status.md` history first.
