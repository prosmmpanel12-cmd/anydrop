Restaurant app — continue from here.

**⚠️ TOP PRIORITY — the Account tab is currently BROKEN, fix this
first.** Read `00_Status.md`'s newest entry ("Account tab / Edit
Profile UI, PARTIAL — later session") in full before touching anything.
Summary: `EditProfileActivity.kt` + `activity_edit_profile.xml` (the
edit form) are done and should NOT need re-doing. `fragment_account.xml`
was redesigned but `AccountFragment.kt` was NOT updated to match it —
the Kotlin file still references view-binding IDs
(`restaurantNameText`, and `btnLogout` at its old position) that no
longer exist in the new layout in the same form, so **opening the
Account tab will crash**. Fix, in this order:

1. **Rewrite `AccountFragment.kt`** to match the new
   `fragment_account.xml`'s IDs:
   - `profileNameText`, `profileAddressText`, `profileHoursText`,
     `profileLogoThumb` — populate from `api.getProfile()`
     (`RestaurantProfileDetail`), logo via Coil `.load()` same pattern
     `EditProfileActivity` already uses
     (`ApiClient.baseUrlForStaticFiles(context) + logoUrl`).
   - `upiIdText` / `currentDueText` — from `upiId`/`currentDue`, fall
     back to "Not set" (`@string/account_not_set`) if `upiId` is null.
   - `switchTempClosed` — set `isChecked` from
     `operationalStatus == "temp_closed"` before attaching its change
     listener (don't fire a network call on the initial programmatic
     set), then on user toggle call the existing
     `updateOperationalStatus()` with `"temp_closed"`/`"open"`, with
     revert-on-failure like `MainActivity.setOperationalStatus()`.
   - `profileSummaryCard` and `btnEditProfileRow` — both launch
     `EditProfileActivity` via `registerForActivityResult` (not plain
     `startActivity`), passing `Gson().toJson(profile)` as
     `EditProfileActivity.EXTRA_PROFILE_JSON`; on a non-cancelled
     result, re-run `getProfile()` so a saved change reflects
     immediately.
   - `swipeRefresh` — re-run `getProfile()` on refresh, call
     `isRefreshing = false` when done (success or failure).
   - `btnLogout` — port the old logic over unchanged (clear token,
     `startActivity` to `LoginActivity` with the same flags, finish).
   - Show `InAppNotifier` on load/save failure
     (`@string/account_profile_load_failed` etc. already exist in
     `strings.xml`).
2. **Apply the correctness fix, still not done despite being flagged
   twice now**: `MainActivity.kt`'s `loadOperationalStatus()` — change
   `isOpen = summary.operationalStatus != "busy"` to
   `isOpen = summary.operationalStatus == "open"`, otherwise the top-bar
   pill will wrongly show green/open for a `temp_closed` restaurant now
   that the switch above can actually send that value.
3. Don't ship (1) without (2) in the same pass — they're two lines of
   context apart in `MainActivity.kt` and easy to forget separately.

Once the Account tab actually opens without crashing and the pill fix
is in, this closes out doc 19 §10 item 5 completely (backend, models,
edit form, and the tab itself all done).

Context after that: read docs/restorent/00_Status.md's next four
entries (all dated 2026-08-16, newest first, after the two Account-tab
entries above):
1. "Palette refresh: Exotic Orange + Midnight Blue 'ink' chrome" —
   global color/theme change, see doc 19 §8.1 for full rationale.
2. "Menu tab: category-tabs-strip + drag-to-reorder" + "Backend:
   category/menu-item PHP endpoints" — together close out §10 item 4
   completely, client + server.
Below those, the 2026-08-15 entries cover the full Orders tab redesign
(§4) — feature-complete but still build-unverified.

**Still not build-verified — now NINE sessions running, no Android SDK,
no PHP CLI, no network access in this sandbox.** This is the single
biggest risk in the project and should be the top priority for whoever
has a real toolchain, oldest unverified surface first:

1. **Build both APKs first**, before adding anything else on top. The
   Account tab's current crash (see above) is exactly the kind of bug
   a real compile/run would have caught immediately — a strong signal
   this needs a real toolchain soon, not just more careful reading.
   The palette refresh also touches the app's global theme, status bar,
   and the shared top-bar/bottom-nav layout every screen sits inside —
   eyeball that too. Other unverified surface, newest to oldest: the
   new Edit Profile screen end to end (logo pick → upload → save →
   reflected in the summary card), the `ItemTouchHelper` drag-to-reorder
   wiring and category-tabs-strip (2026-08-16 Menu tab entry), the 8 new
   backend PHP endpoints (2026-08-16 Backend entry — never hit a real
   PHP runtime or DB), last session's Coil dependency + skeleton
   `ScrollView` wrapping, and — further back — the full Orders tab
   redesign from 2026-08-15 (`OrderAdapter`'s `mode`-based constructor,
   `Handler`-based countdown ticker, `fragment_orders.xml`'s three
   stacked `RecyclerView`s). See `00_Status.md`'s 2026-08-15 entry for
   that full list.
2. **Run the new backend endpoints once each** (Postman/curl) against a
   seeded restaurant before trusting any client-side call into them —
   this now includes `profile-get.php`/`profile-update.php`/
   `logo-upload.php` alongside the earlier category/menu-item batch.
3. **Smoke-test the Menu tab end to end**: category/item CRUD,
   out-of-stock toggle, search, tabs-strip appearance at 5+ categories,
   drag-reorder persisting across a pull-to-refresh.
4. **Smoke-test the Orders tab** per the 2026-08-15 entry's checklist.
5. **Smoke-test the Account tab** once (1)/(2) above are done: profile
   load, edit + save (including a logo change), temp-closed toggle
   reflecting correctly on the top-bar pill, logout.
6. Minor polish noted in `00_Status.md` if there's time after the
   above: reject-dialog title string, disabling action buttons
   mid-flight, stat-strip skeleton, category `sort_order` cleanup for
   soft-disabled categories.

**After build verification**, resume doc 18's recommended build order:
1. Plan §10 item 7 — pre-login/detail screens ink pass (flagged
   previously, still not started).
2. Admin-side "Approve/Reject pending restaurants" screen — flagged
   as increasingly overdue since self-signup already produces pending
   rows with no way to approve them except a manual DB update.
3. Everything else per doc 18 §"Recommended build order" (coupons,
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
already declared the client-side calls for them). That gap is closed as
of the 2026-08-16 Backend entry. If a future upload is similarly
partial again, don't assume a missing file was never built — check
`00_Status.md` history first.
