# Handover — 2026-08-31 — bugs.md doc audit + delivery-OTP display fix

**Scope this session:** app owner asked to continue coding work and fix
bugs, without naming a specific item. Picked up from `docs/bugs.md`
(the money/security/race-condition tracker) since it's the project's own
standing bug list — but before fixing anything off it, re-verified every
item directly against current source rather than trusting the doc's own
🔴/🟢 marks, per this project's established "check code, not old docs"
discipline (same approach `Status.md`'s 2026-08-27 session-12 doc-audit
used).

## What was found

`docs/bugs.md` had gone significantly stale. Six items were still marked
🔴 even though the underlying code had already been fixed in earlier
sessions and never reported back into this file:

| # | Item | Real status found |
|---|---|---|
| 1.1 | `discount_percent` no upper clamp | Already fixed — `price_cart()` clamps `min(100, max(0, ...))` |
| 2.1 | OTP request no rate limit | Already fixed — per-email cooldown in `customer-request-otp.php` |
| 2.2 | `debug_otp` exposed unconditionally | Already fixed — gated behind `debug_otp_enabled`, real `EmailOtpService` now wired |
| 2.4 | No idempotency on `POST /orders` | Already fixed — `idempotency_key` + race-safe fallback in `create.php` |
| 4.1 | No notification template pool | Already fixed — 45-template pool + rotation (Phase J, 2026-08-14) |
| 6.2 | Address Book "set as default" missing | Already fixed — `AddressAdapter`/`AddressBookActivity` fully wired |
| 6.3 | Orders placeable on closed restaurant | Already fixed (2026-08-13) — `price_cart()` checks `operational_status` |
| 3.1 | "No admin panel exists" | Stale — admin panel now covers order control, analytics, restaurant approval, settlements, refunds, etc. Downgraded, not marked fully closed (still genuine per-module gaps, see `PENDING.md`). |

Each was confirmed by reading the actual current file (grep + direct
`view`), not by re-deriving from `Status.md`'s claims — cited inline in
each corrected entry so the next session doesn't have to re-derive it a
third time.

**One item (1.2) was genuinely still open** — real bug, not a stale
mark — and is the actual code fix this session made.

## Bug fixed — 1.2, delivery-OTP generation/display condition mismatch

**Where:** `backend/api/v1/orders/track.php` (the endpoint the Customer
App polls to *show* the delivery OTP).

**Was:** `orders/create.php` generates a `delivery_otp` whenever
`payment_method === 'upi' || otp_required_for_cod` (a real, existing
`app_settings` toggle). But `track.php` only *returned* that OTP when
`payment_method === 'upi'` — re-deriving the condition instead of
checking whether an OTP actually existed for the order. If an admin ever
flipped `otp_required_for_cod` on, a COD order would get a real
`delivery_otp` written to the DB that the customer could never see —
no way to hand the rider a code they were never shown.

**Fix:** the condition now checks `$order['delivery_otp'] !== null`
directly ("was one actually generated for this order") instead of
re-deriving `payment_method === 'upi'`. No change was needed on the
generation side (`create.php`) — only the display side was out of sync
with it. Docblock comment updated to explain the fix and why, same
pattern every other bug-fix comment in this codebase follows.

This item was previously flagged (`Status.md`, Phase K note) as
deliberately deferred until the Rider App exists, on the reasoning that
"fixing it in isolation before a rider flow exists has nothing to verify
against." That reasoning holds for *testing* it end-to-end, but the fix
itself is a one-line, self-contained condition change with zero
dependency on a rider flow existing — so it was safe to land the code
fix now rather than continue waiting. The *live* end-to-end test (COD +
`otp_required_for_cod` on + an order reaching `rider_assigned` +
confirming `track.php` returns a real `otp`) genuinely does still need a
rider flow to reach that order status naturally, or a manually-forced
test order — noted as the outstanding verification step.

**Not build-verified** — no PHP CLI in this sandbox, same standing
limitation as every session in this project. Verified by:
- Manual brace/paren/bracket balance check on the full file (0/0/0).
- Full-file re-read after the edit to confirm no other logic was
  disturbed.

## Files touched this session

- `backend/api/v1/orders/track.php` — the actual bug fix (docblock +
  the one-line condition change).
- `docs/bugs.md` — doc-audit corrections to 8 stale entries (1.1, 1.2,
  2.1, 2.2, 2.4, 3.1, 4.1, 6.2), summary table, and the "genuinely still
  open" priority note. 6.3's detail section already had its own
  ✅-resolved history note from an earlier session and didn't need
  touching there, only in the summary table.
- This file (new).

## Genuinely still open, after this pass

Per `docs/bugs.md`'s corrected summary table:
- **2.3** — confirm the GitHub PAT pasted into chat earlier in the
  project has actually been revoked at github.com/settings/tokens. Not
  a code fix — an action item for the app owner.
- **3.2** — no restaurant-side UI to set `discount_percent`/
  `is_bestseller`/`is_spicy`/`is_kids_choice` (still manual phpMyAdmin
  `UPDATE` only).
- **6.1** — Home screen GPS-off banner (Zomato-style, dynamic text) —
  still spec-only, not built.

Beyond `bugs.md`'s own scope, the project's larger standing priority
list (per `PENDING.md`/`docs/76`'s "suggested next session" notes) is
still: Rider App (largest remaining untouched phase), Wallet
withdrawal/auto-refund (§37 — code built, needs migration 65 run + live
test + Gradle build), Payment/Refund Reconciliation (item 24 — code
built, needs verification), Email OTP Provider Failover, Security
Hardening — plus the restaurant-app UI items from `HANDOVER.md`'s
31-Aug session (CI build re-trigger to confirm the `fragment_insights.xml`
fix, splash loading-animation polish, the full 85-screen re-theme).

## Suggested next session

1. Re-verify `docs/bugs.md`'s "genuinely still open" list the same
   way this session did (read the actual code) before starting any of
   them, in case any have also quietly landed without the doc being
   updated — this session found 6 of 8 checked items were already stale.
2. If continuing pure bug-fixing: 3.2 (restaurant discount/flag UI) is
   the most concrete remaining code gap in `bugs.md`'s scope.
3. If continuing the restaurant-app UI thread instead: `HANDOVER.md`'s
   own next-session list (CI rebuild confirmation, coupon toggle check,
   splash animation, full re-theme) is the more current one.
