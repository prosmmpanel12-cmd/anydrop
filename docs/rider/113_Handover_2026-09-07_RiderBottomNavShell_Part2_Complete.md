# Handover — 2026-09-07 — Rider Bottom-Nav Shell (v24 Part 2) Complete

**Continues:** `docs/rider/112_Handover_2026-09-06_OrdersNotReceived_BackgroundPollingFix_And_MainShell_Started.md`
**Status:** Part 1 (background-polling fix) was already done and verified at the start of this session. Part 2 (bottom-nav shell) is now **fully built and wired**. Not yet compiled/run on a device or emulator — see "Not done" below.

---

## What this session did

Verified the full project state first (all 93+ docs + `docs/Status.md` consistent with the actual code on disk — no drift found), then completed Part 2 exactly as scoped in doc 112: converted the rider Android app's approved-rider landing screen from a single `RiderDashboardActivity` into a 4-tab bottom-nav shell (`RiderMainActivity`), mirroring the restaurant app's existing `MainActivity` + `BottomNavigationView` + Fragments pattern.

### New files

**Resources**
- `res/color/bottom_nav_item_color.xml` — selected/unselected tint selector (brand green / text_secondary, adapted from the restaurant app's own file for this app's dark theme)
- `res/drawable/ic_nav_home.xml`, `ic_nav_earnings.xml` — new tab icons (Alerts and Account tabs reuse the app's existing `ic_notification.xml`/`ic_person.xml`)
- `res/menu/bottom_nav_menu.xml` — 4 items: `nav_home`, `nav_earnings`, `nav_notifications`, `nav_account`
- `res/layout/activity_rider_main.xml` — the shell: shared top bar (greeting + documents-rejected alert + notification bell, ported from the old dashboard header) → `FragmentContainerView` → `BottomNavigationView`
- `res/layout/fragment_home.xml` — ported from `activity_rider_dashboard.xml` minus the header row (moved to the shell)
- `res/layout/fragment_earnings.xml` — ported from `activity_earnings.xml` minus the back-arrow header
- `res/layout/fragment_notifications.xml` — ported from `activity_notifications.xml` minus the back-arrow header
- `res/layout/fragment_account.xml` — **new screen**: profile card (name/mobile/email/service area/vehicle/member-since), Documents row with status pill, Logout button
- New strings in `res/values/strings.xml`: `nav_home`, `nav_earnings`, `nav_notifications`, `nav_account`, `account_title`, `account_profile_load_failed`, `account_documents_row_label`, `account_earnings_row_label` (unused — no separate earnings shortcut row was added since Earnings is already a tab; left defined in case a future pass wants it, harmless either way), `account_service_area_label`, `account_vehicle_label`, `account_member_since_format`, `account_not_set`

**Kotlin**
- `ui/main/RiderMainActivity.kt` — the shell. Owns the shared top bar, tab switching, and navigation helpers (`goToHomeTab()`, `goToEarningsTab()`, `goToStatusScreen()`, `goToLogin()`, `renderDocumentsEntryPoint()`) that fragments call via `(activity as? RiderMainActivity)?.___()`.
- `ui/home/HomeFragment.kt` — line-for-line port of `RiderDashboardActivity`'s body (online/offline toggle, location polling at 30s/7s idle/active intervals, the R3 assignment-engine poller, offer accept/reject with countdown, pickup/deliver actions, the delivery OTP dialog). Only the Activity-specific scaffolding changed (nullable `_binding`, `requireContext()`/`requireActivity()`, navigation delegated to `RiderMainActivity`).
- `ui/earnings/EarningsFragment.kt` — port of `EarningsActivity` (today/balance card, COD cash-held card with 3-state pill, share-percent note, Request Payout button, recent ledger list).
- `notifications/NotificationsFragment.kt` — port of `NotificationListActivity` (paginated list, mark-all-read, swipe refresh). Deep-link taps on an in-app row now switch tabs via `RiderMainActivity` instead of starting a new Activity.
- `ui/account/AccountFragment.kt` — **new class**. Loads `/rider/me`, renders profile summary + documents status pill (reusing the same 4-state color scheme as elsewhere in the app), handles Documents-row tap → `SubmitDocumentsActivity`, and Logout (immediate, no confirmation dialog — matches this app's one existing logout site in `ApplicationStatusActivity`, which also logs out immediately).

### Files edited (reference repointing)

- `ui/pending/ApplicationStatusActivity.kt` — `goToDashboard()` now targets `RiderMainActivity` instead of `RiderDashboardActivity`. This is the **only** place that starts the approved-rider landing screen, so this one change is what makes the whole shell reachable.
- `notifications/RiderNotificationHelper.kt` — `contentIntentFor()`'s `screen` → target-class map (`dashboard`, `order_offer`, `order_status`, `earnings`) now all point at `RiderMainActivity` (a system-tray tap always starts a fresh task and always lands on the shell's default Home tab — there's no running instance whose tab it could select). `submit_documents` and the fallback (`ApplicationStatusActivity`) are unchanged.
- `AndroidManifest.xml` — added `<activity android:name=".ui.main.RiderMainActivity">`. `RiderDashboardActivity`, `EarningsActivity`, and `NotificationListActivity`'s entries are all left registered but commented as superseded/unreachable, matching this project's established "leave the old screen's code alone, don't delete" convention (see e.g. how earlier phases handled superseded screens).

### Design decisions worth knowing about

- **Old Activities were not deleted.** `RiderDashboardActivity.kt`, `EarningsActivity.kt`, `NotificationListActivity.kt` and their layouts still exist and still compile, but are no longer reachable from anywhere in the running app (verified — see checks below). This matches how this project has handled superseded screens before. If you want them physically removed, that's a separate, easy follow-up.
- **Top-bar elements are shell-level, not per-tab.** The greeting, documents-rejected alert, and notification bell live in `RiderMainActivity`'s layout (not `HomeFragment`'s), same reasoning as the restaurant app's OPEN/CLOSED pill living in its `MainActivity`. The online/offline switch itself was **not** promoted to the shell — it stays inside `HomeFragment` since toggling it is a Home-specific action, not a global status.
- **No confirmation dialog on logout.** Checked first — this app has never had one anywhere (`ApplicationStatusActivity`'s existing logout button also fires immediately). Account tab's logout matches that instead of introducing new UX.
- **Account tab is read-only this pass.** No edit-profile flow exists yet (backend has no update-profile endpoint that this doc's author found evidence of) — this is a display + navigation hub only, same scope `RiderDashboardActivity`'s old header row had (alert + logout), just centralized and always-visible.

---

## Verification performed (no build environment available in this session)

Since there's no Android SDK/Gradle in this sandbox, verification was done by hand, cross-referencing every new/changed line against the actual existing model/API/resource definitions on disk:

- ✅ All 5 new/changed layout XML files + manifest + strings.xml parse as well-formed XML (`xml.etree.ElementTree`)
- ✅ Brace balance checked on all 5 new Kotlin files (all balanced)
- ✅ Every `ApiService` method called (`getMe`, `setOnlineStatus`, `updateLocation`, `getAvailableOffer`, `getCurrentOrder`, `acceptOrder`, `rejectOrder`, `pickupOrder`, `deliverOrder`, `getEarningsSummary`, `getNotifications`, `markNotificationRead`, `markAllNotificationsRead`) checked against its real signature in `ApiService.kt` — all match exactly, including named-arg usage (`page=`, `perPage=`, `unreadOnly=`)
- ✅ Every model field referenced (`RiderMeProfile`, `Offer`, `CurrentOrder`, `EarningsSummaryResult`, `NotificationItem`, `NotificationsResult`, request bodies) checked against `Models.kt` — all match
- ✅ Every `TokenManager` method used checked against its real definition — all match
- ✅ Every string resource referenced (~50+) confirmed present in `strings.xml`, including the ones newly added
- ✅ Every color resource referenced confirmed present in `colors.xml`
- ✅ Every drawable referenced confirmed present
- ✅ `NotificationAdapter`/`EarningsLedgerAdapter` constructor signatures and public methods checked against their real definitions — match
- ✅ `grep -rl RiderDashboardActivity` re-run after edits — only comments/kdoc remain pointing at it (informational, not functional references) plus its own still-registered-but-dead manifest entry and its own source file
- ✅ Layout filenames confirmed to match the View Binding class names used (`ActivityRiderMainBinding`, `FragmentHomeBinding`, `FragmentEarningsBinding`, `FragmentNotificationsBinding`, `FragmentAccountBinding`)

**What this does NOT catch:** actual Kotlin type-checking, Gradle resource merging, or runtime behavior. This still needs a real `./gradlew assembleDebug` (or opening in Android Studio) before treating it as done-done. Recommend that as the very next step.

## Not done / next steps

1. **Build it.** No compiler was available here — run a real build and fix whatever a type-checker catches that manual review didn't.
2. **Manual smoke test** on a device/emulator: login → approved rider → lands on Home tab with all 4 tabs visible → toggle online/offline still works → switch tabs → Earnings/Alerts/Account all load real data → tap Documents row → tap Logout → confirm it actually returns to Login.
3. Consider whether to physically delete `RiderDashboardActivity`, `EarningsActivity`, `NotificationListActivity` now that nothing reaches them, or leave them as reference/rollback material a while longer.
4. `account_earnings_row_label` string was added but never used in the layout (Earnings is already a tab, so a shortcut row felt redundant) — either wire it up if you want a shortcut anyway, or remove the unused string in a later cleanup pass.
