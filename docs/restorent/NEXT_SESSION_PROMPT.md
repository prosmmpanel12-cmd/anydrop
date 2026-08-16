Restaurant app — continue from here.

Context: read docs/restorent/00_Status.md's top three entries, all dated
2026-08-16 (newest first):
1. "Palette refresh: Exotic Orange + Midnight Blue 'ink' chrome" —
   global color/theme change, see doc 19 §8.1 for full rationale.
2. "Menu tab: category-tabs-strip + drag-to-reorder" + "Backend:
   category/menu-item PHP endpoints" — together close out §10 item 4
   completely, client + server.
Below those, the 2026-08-15 entries cover the full Orders tab redesign
(§4) — feature-complete but still build-unverified.

Done this session (palette refresh):
1. `colors.xml` — primary bumped `#E64A19` → `#F54F1B`; new "ink" dark-
   surface token family added (`anydrop_ink`, `anydrop_ink_light`,
   `text_on_ink`, `text_on_ink_muted`).
2. `activity_main.xml` (shared top bar + bottom nav), `themes.xml`
   (status bar), `bottom_nav_item_color.xml` (unselected-state contrast)
   — all switched to the new ink surface/tokens.
3. Confirmed no other file hardcodes the old primary hex values, so the
   rest of the app's buttons/switches/active-states inherit the new
   orange automatically through `colorPrimary`/`@color/anydrop_primary`.
4. Doc 19 (`19_Restaurant_App_UI_Plan.md`) §8 rewritten: new §8.1 with
   the picked palette + full rationale + an honest "implemented this
   pass" vs "flagged as follow-up" breakdown, old table kept as §8.2 for
   history. New §10 item 7 added for the flagged follow-up.

**Not done this session, explicitly flagged (doc 19 §10 item 7):**
Pre-login/detail screens (`activity_login.xml`, `activity_signup.xml`,
`activity_otp_verify.xml`, `activity_signup_success.xml`,
`activity_splash.xml`, `activity_order_detail.xml`) still use plain
white full-screen backgrounds from before this refresh — a per-screen
visual pass, not a token swap, so it was left for a dedicated session.

**Still not build-verified — now EIGHT sessions running, no Android SDK,
no PHP CLI, no network access in this sandbox.** This is the single
biggest risk in the project and should be the top priority for whoever
has a real toolchain, oldest unverified surface first:

1. **Build both APKs first**, before adding anything else on top. This
   session raised the stakes on that: the palette refresh touches the
   app's global theme, status bar, and the shared top-bar/bottom-nav
   layout every single screen sits inside — a visual mistake here
   (e.g. `windowLightStatusBar=false` not applying as expected, the
   `text_on_ink_muted` unselected-icon contrast being wrong in practice,
   the OPEN/CLOSED pill's light badge looking odd against the dark bar)
   is visible on every screen, not isolated to one. Eyeball this before
   anything else. Other unverified surface, newest to oldest: the
   `ItemTouchHelper` drag-to-reorder wiring and category-tabs-strip
   (2026-08-16 Menu tab entry), the 8 new backend PHP endpoints (2026-
   08-16 Backend entry — never hit a real PHP runtime or DB), last
   session's Coil dependency + skeleton `ScrollView` wrapping, and —
   further back — the full Orders tab redesign from 2026-08-15
   (`OrderAdapter`'s `mode`-based constructor, `Handler`-based countdown
   ticker, `fragment_orders.xml`'s three stacked `RecyclerView`s). See
   `00_Status.md`'s 2026-08-15 entry for that full list.
2. **Run the new backend endpoints once each** (Postman/curl) against a
   seeded restaurant before trusting any client-side call into them.
3. **Smoke-test the Menu tab end to end**: category/item CRUD,
   out-of-stock toggle, search, tabs-strip appearance at 5+ categories,
   drag-reorder persisting across a pull-to-refresh.
4. **Smoke-test the Orders tab** per the 2026-08-15 entry's checklist.
5. Minor polish noted in `00_Status.md` if there's time after the
   above: reject-dialog title string, disabling action buttons
   mid-flight, stat-strip skeleton, category `sort_order` cleanup for
   soft-disabled categories.

**After build verification**, resume doc 18's recommended build order:
1. Plan §10 item 7 — pre-login/detail screens ink pass (flagged above).
2. Restaurant Management profile screen (name/address/hours/logo, temp
   closure) — doc 19 §10 item 5 (Account tab).
3. Admin-side "Approve/Reject pending restaurants" screen — flagged
   as increasingly overdue since self-signup already produces pending
   rows with no way to approve them except a manual DB update.
4. Everything else per doc 18 §"Recommended build order" (coupons,
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
