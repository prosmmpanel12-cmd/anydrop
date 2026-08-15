Restaurant app — continue from here.

Context: read docs/restorent/00_Status.md's top entry (2026-08-15 —
"Orders tab redesign, OrderAdapter + OrdersFragment rebuild") plus the
UI-groundwork entry directly below it (same day). Both together cover
the full Orders tab redesign (docs/restorent/19_Restaurant_App_UI_Plan.md
§4, §10 item 3), which is now **feature-complete but build-unverified**.

Done this session (§10 item 3, all three "next work" items from the
prior prompt):
1. `OrderAdapter.kt` rebuilt around a `CardMode` enum (`NEW` /
   `IN_PROGRESS` / `COMPLETED`) — one instance per section now, not one
   shared instance. Wires up `countdownChip`/`stepperRow`/`actionRow`
   show-hide, accept/reject/mark-next-step callbacks, and a per-
   ViewHolder `Handler`-based countdown ticker (5-min cosmetic local
   window from `order.createdAt`, no backend deadline implied — none
   exists).
2. `OrdersFragment.kt` + `fragment_orders.xml` rebuilt: three
   always-visible sections (New / In-progress / Completed-today
   collapsed-by-default) each with its own `RecyclerView` + `OrderAdapter`
   instance, "Today" stat strip, skeleton loading states wired in. Old
   tab filters and the duplicate `switchAcceptingOrders`/`summaryText`
   operational-status code are deleted — that state lives only in
   `MainActivity` now (from the prior session).
3. Countdown ticker folded into item 1 above rather than done separately.

**Still not build-verified — now five sessions running, no Android SDK
or network access in this sandbox.** This is the top priority for
whoever has a real toolchain:

1. **Build both APKs first**, before adding anything else on top. Known
   unverified risk surface, oldest first: the Fragment conversion (view-
   binding class names, `InAppNotifier.show(activity, ...)` call sites)
   from several sessions ago; `MainActivity.renderPill()`'s `AlertDialog`/
   `ContextCompat.getDrawable` calls; this session's `OrderAdapter`
   constructor signature change (now requires `mode`), the `Handler`-
   based countdown ticker + its `onViewRecycled()` cleanup, and
   `fragment_orders.xml`'s three `RecyclerView`s (`wrap_content` height +
   `nestedScrollingEnabled="false"`) stacked inside a plain `ScrollView`.
2. **Smoke-test the whole Orders tab** on an emulator/device: accept a
   pending order (countdown chip + New/In-progress move correctly),
   reject one (reason dialog, order lands in Completed), mark-next-step
   through accepted → preparing → ready (stepper dots, action row
   disappearing once `ready`), expand/collapse Completed today, confirm
   skeletons show briefly on first load of each section, tap the
   top-bar OPEN/CLOSED pill (confirm dialog, revert-on-failure).
3. Minor polish noted in `00_Status.md` if there's time after the above:
   a dedicated reject-dialog title string (currently reuses
   `R.string.btn_reject`), disabling action buttons mid-flight to guard
   against a double-tap firing two requests, a skeleton/loading state
   for the stat strip itself.

**After build verification**, resume doc 18's recommended build order
(the Orders redesign was pulled forward out of turn — see
`00_Status.md`'s 2026-08-14 entry for the original ordering rationale):
1. **Menu Management** (Tier 1) — biggest remaining functional gap.
2. Restaurant Management profile screen (name/address/hours/logo, temp
   closure).
3. Admin-side "Approve/Reject pending restaurants" screen — flagged
   as increasingly overdue since self-signup already produces pending
   rows with no way to approve them except a manual DB update.
4. Everything else per doc 18 §"Recommended build order" (coupons,
   notification bell, reviews reply, settings, payments, analytics,
   staff, then Rider App last).

Test login: demo@anydrop.test / Demo@1234 (via
backend/scripts/seed-test-data.php?key=SEED_ME if not already seeded).
QA account: test@anydrop.com / test (docs/restorent/00_Status.md's
2026-08-14 "QA test restaurant account" entry).

CI: GitHub Actions workflow at .github/workflows/build-apks.yml builds
both APKs on push to `master` (not `main`) — already fixed, don't
reintroduce the branch mismatch.
