# Handover — 2026-09-07 — Rider Bottom-Nav Shell: Independent Re-Verification + Minor Cleanup

**Continues:** `docs/rider/113_Handover_2026-09-07_RiderBottomNavShell_Part2_Complete.md`
**Status:** No new features. This session independently re-verified doc 113's shell work from a fresh read of the code (not just re-reading the doc), then did the one small cleanup doc 113 explicitly left open. Build/device verification are still outstanding — same blocker as before (no Android SDK/Gradle/emulator available in this environment).

---

## What this session did

1. **Independent re-verification of the bottom-nav shell** (doc 113's work), cross-checking code on disk directly rather than trusting the handover doc's own claims:
   - Every `binding.___`/`b.___` view reference in `RiderMainActivity.kt`, `HomeFragment.kt`, `EarningsFragment.kt`, `NotificationsFragment.kt`, `AccountFragment.kt` diffed against the `android:id` values actually present in their corresponding layout XMLs (`activity_rider_main.xml`, `fragment_home.xml`, `fragment_earnings.xml`, `fragment_notifications.xml`, `fragment_account.xml`) — no mismatches.
   - Every `R.color.___` reference in `AccountFragment.kt`/`EarningsFragment.kt` (the two files with status-pill/COD-bar coloring logic) diffed against `colors.xml` — no mismatches.
   - Every `R.string.___` reference across all five files diffed against `strings.xml` — one apparent miss (`R.string.cancel` in `HomeFragment`'s OTP dialog) turned out to be `android.R.string.cancel` (the system resource), confirmed correct, not a bug.
   - `RiderMeProfile`'s fields in `Models.kt` (`mobile`, `email`, `serviceAreaName`, `vehicleType`, `vehicleNumber`, `createdAt`, `documentsStatus`) checked against `AccountFragment.populate()`'s usage — exact match, including nullability.
   - `AndroidManifest.xml` re-read directly — confirms `RiderMainActivity` registered and reachable, `RiderDashboardActivity`/`EarningsActivity`/`NotificationListActivity` present but commented as superseded, matching doc 113's description exactly.
   - Fragment lifecycle pattern (`_binding`/`binding` nullable pair, `null` in `onDestroyView`, `_binding?.___` guards inside async `lifecycleScope.launch` callbacks that could resolve after the view is gone) read through in full on all four fragments — consistent and correct everywhere it's used.
   - Compared `RiderMainActivity`'s tab-switch shape (`supportFragmentManager.beginTransaction().replace(...).commit()`, no back stack, one new Fragment instance per tab select) directly against the restaurant app's own `MainActivity.kt` — confirmed this is the established project pattern, not a shortcut specific to this screen. The restaurant app's own kdoc even documents the same "reloads on every switch, revisit later if slow" tradeoff.

   **Result: no discrepancies found.** Doc 113's manual-verification claims hold up against an independent check.

2. **Cleanup:** removed the unused `account_earnings_row_label` string from `res/values/strings.xml`. Doc 113 flagged this as dead (no shortcut row was built since Earnings already has its own tab) and left the choice open — wire it up or delete it. Deleting was the smaller, safer change and nothing else in the codebase referenced it (`grep -rn` confirms zero remaining references after removal). `strings.xml` re-parsed as well-formed XML after the edit.

## Not done (unchanged from doc 113)

1. **Build it.** Still no Android SDK, Gradle, or `kotlinc` available in this sandbox, and no network access to fetch them. A real `./gradlew assembleDebug` is still the next concrete step whenever there's an environment that can run it.
2. **Manual smoke test on a device/emulator** — unchanged from doc 113's checklist (login → approved rider → 4 tabs visible → online/offline toggle → tab switching → Documents row → Logout).
3. **Old Activities** (`RiderDashboardActivity`, `EarningsActivity`, `NotificationListActivity`) — left in place, untouched. Not deleted this session either; that's still an open decision for the app owner, not something to default into.
