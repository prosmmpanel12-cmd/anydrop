# Handover — Deep Plan Phase 4: Area-wise Rider COD Cash-Hold Limit + Block Enforcement

**Date:** 09 Sep 2026
**Session type:** Continuation (recall.md's own "NEXT SESSION" pointer at the bottom of the file)
**Plan doc:** `docs/00_Deep_Plan_Statement_CODLimit_QRPay_CashFlow_2026-09-09.md` §4
**Status:** 🟡 Code-complete, **NOT build/device-verified** — same standing sandbox limitation as every other session (no PHP CLI / MySQL / Android SDK, network disabled).

---

## What this phase does

Before this session, `rider_cod_settlement_limit` (`app_settings`, migration 53, default ₹2000) was a single **platform-wide** cap on how much COD cash a rider may hold before `lib/dispatch.php`'s `find_eligible_riders()` excludes them from new COD-order dispatch. This phase adds a **per-service-area override** on top of that, mirroring the exact "one row per area, no row = platform default" shape `area_cod_rules` (migration 35) already established for the separate, customer-facing COD-eligibility system.

**Important distinction, restated from the plan doc:** this is *not* the same table or concern as `area_cod_rules.php`/`cod-rules.php`. That system decides whether a *customer* may pay by COD at all. This one decides how much cash a *rider* may hold before being blocked from further COD deliveries. Two different tables, two different admin pages, two different money-flow questions — kept deliberately separate rather than folded into the existing COD Rules page.

---

## Files built / changed this session

### Backend
- **`backend/sql/81_migration_area_rider_cod_limits.sql`** (new) — `area_rider_cod_limits(id, area_id UNIQUE, cod_hold_limit, is_active, created_at, updated_at)`, FK to `service_areas`. No new `app_settings` row needed — `rider_cod_settlement_limit` (migration 53) keeps serving as the fallback.
- **`backend/lib/rider_cod_limit.php`** (new) —
  - `resolve_area_rider_cod_limit_by_area_id($db, $areaId)` — walks the area's parent chain (Area → City/Village → District → State) same as `cod_rules.php`'s `resolve_area_cod_rule_by_area_id()`, returns the first active override's `cod_hold_limit`, or `null`.
  - `get_effective_rider_cod_limit($db, ?$riderAreaId)` — returns `['limit' => float, 'area_id' => ?int, 'source' => 'platform_default'|'area_rule']`. Falls back to `rider_cod_settlement_limit()` (`lib/rider_ledger.php`, unchanged) when no override applies or the rider has no `service_area_id`.
- **`backend/lib/dispatch.php`** — `find_eligible_riders()`:
  - SQL now also selects `r.service_area_id`.
  - Removed the old flat `$codLimit = get_setting('rider_cod_settlement_limit', 2000)`.
  - New per-candidate check via `get_effective_rider_cod_limit()`, memoized in a local `$codLimitCache` keyed by area id (0 = no area) so riders sharing an area only walk the parent chain once per dispatch call, not once per rider.
- **`backend/api/v1/rider/me.php`** — response now additionally includes `cod_cash_held` (float), `cod_limit` (float, the *effective* resolved limit for that rider's own area), and `cod_blocked` (bool, `cod_cash_held >= cod_limit`) inside the `rider` object. Purely additive — every existing field/shape is untouched.
- **`backend/admin/rider-cod-limits.php`** (new) — same card layout as `cod-rules.php`: a "Platform-wide COD Cash-Hold Limit" card (this is also the **first admin UI ever for `rider_cod_settlement_limit`** — it previously had no editor, only the hardcoded `get_setting(..., 2000)` fallback at every call site), an "Add Area Override" form, and an "Area Overrides" table (toggle active / delete). Gated on `riders_view` / `riders_edit` (not `areas_view`/`areas_edit` — this is rider cash policy, not area/service-area config).
- **`backend/admin/_layout_head.php`** — new `rider_cod_limits` nav entry ("Rider COD Limits") added to the Operations group, right after "COD Rules"'s original position pattern, and added to the `$activeNav` doc-comment enum.

### Rider Android App
- **`network/Models.kt`** — `RiderMeProfile` gained `codCashHeld` / `codLimit` / `codBlocked` (all additive, defaulted).
- **`res/drawable/bg_banner_warning.xml`** (new) — flat `warning_bg`-tinted rounded rect, reusing colors that already existed in `colors.xml` (`warning_bg`/`warning_fg`) but had no drawable using them yet.
- **`res/values/strings.xml`** — new `dashboard_cod_blocked_banner` string with a `%.0f` amount placeholder.
- **`res/layout/fragment_home.xml`** — new `codBlockedBanner` (`LinearLayout`, `gone` by default) + `codBlockedBannerText`, placed directly under the online/offline card, above the "CURRENT DELIVERY" section.
- **`ui/home/HomeFragment.kt`** — new `renderCodBlockedBanner(codBlocked, codLimit)`, called from the existing `refreshFromServer()` (the same `/rider/me` call that already drives the online-switch state) right after `renderOnlineState()`. No new network call added.

---

## What was deliberately NOT touched

- **Rider Assignment eligibility for non-COD orders** — unaffected; the new check only runs `if ($isCod)`, exactly like the old flat check did.
- **`admin/riders.php` / `admin/rider-settlements.php`** — both already display `cod_cash_held` against the flat `rider_cod_settlement_limit()` helper. Left as-is this session (they still show the *platform* number, not each rider's *effective* area-aware number) — flagged below as the one follow-up worth doing before this phase is called fully finished.
- **Phase 5 (Pay COD QR + auto-verify)** — not started; this phase only builds the limit + block side, per the plan's own build order.
- **No cron/worker** — same standing "lazy-eval on read" philosophy `dispatch.php`'s own file header already documents; nothing new needed here since the limit is resolved fresh on every dispatch call anyway.

---

## Verification checklist (for whenever a real PHP/MySQL/Android environment exists)

1. Run migration 81 against the live DB.
2. Set a low `cod_hold_limit` override for a test area via the new admin page.
3. Put a test rider (whose `service_area_id` resolves into that area) over that limit via `rider_cod_ledger`/`riders.cod_cash_held`.
4. Place a COD order dispatched to that restaurant/area and confirm that rider is excluded from `find_eligible_riders()`'s candidate list (should fall through to the next nearest eligible rider, or `no_riders_available` if none).
5. Hit `rider/me.php` as that rider and confirm `cod_blocked: true` with the correct `cod_limit`.
6. Build the Rider App, open the Home tab as that rider, confirm the warning banner renders with the right ₹ figure.
7. Deactivate/delete the area override and confirm the rider falls back to the platform default correctly (both in dispatch and in `/me`).

**Not done yet — do not mark Phase 4 complete until the above passes on a real environment**, per this project's own `done.md` rule that "built" and "verified" are tracked separately.

## Next session
Phase 5 — Rider App "Pay COD Amount" button (QR + auto-verify, reusing the existing UPIPE flow) per the Deep Plan §5. Two open questions from the plan doc still need the owner's answer before/while building it: full-amount-only deposit vs. partial, and where the deposit-initiate endpoint should live.
