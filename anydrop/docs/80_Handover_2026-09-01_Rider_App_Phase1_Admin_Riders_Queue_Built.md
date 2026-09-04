# Rider App — Phase 1 wrap-up: Admin "Riders" Approval Queue Handover
Session date: 01 Sep 2026 (follow-on to doc 79 in the same day's work)

> **⚠️ Not built/tested this pass.** Same standing sandbox limitation as
> doc 79: no PHP interpreter, no MySQL, no network access here. This
> file was hand-written to closely mirror `restaurants.php` (the
> already-working, already-tested full restaurant-management screen)
> and re-read carefully for syntax, but per `done.md`'s rule ("No
> successful test = No DONE mark"), treat it as
> `🟡 IMPLEMENTED — TEST PENDING` until run against a live DB with a
> real pending rider row.

## Context

Doc 79 (Rider App Phase 1: backend signup/OTP) left one explicit
next-session TODO (item 4): a self-signed-up rider lands at
`status = 'pending'` with **no admin UI** to approve or reject them —
`rider-signup.php` works, but the application just sits there. This
session closes that gap.

## ✅ Built this session

### `backend/admin/riders.php`
New admin screen, gated on the `riders_view` / `riders_edit` /
`riders_approve` permission keys — these were already seeded by
migration 29 (admin RBAC) back when the whole permission set was
planned, just never used until now, so **no new RBAC migration
needed**.

Deliberately mirrors `restaurants.php`'s shape rather than the
simpler pending-only `index.php` queue — search box (name/email/
mobile), status filter with counts, area filter, pagination, and a
per-row "Manage" dialog with the full status lifecycle:

- `pending` → **Approve** or **Reject** (reason required)
- `approved` → **Suspend** (reason required)
- `rejected`/`suspended` → **Reactivate** (sets back to `approved`,
  clears the stored reason)
- Service-area reassignment (writes `riders.service_area_id`)

Every transition writes to `audit_logs` via the existing
`write_audit_log()` helper — same trail every other admin action in
this codebase uses, actions named `rider_approved` / `rider_rejected`
/ `rider_suspended` / `rider_area_assigned`.

**Scope note — platform riders only:** every query in this page adds
`restaurant_id IS NULL` to the WHERE clause. The original
restaurant-scoped rider rows (a restaurant's own delivery boys,
`restaurant_id NOT NULL`, username/password login, no
pending-approval concept) are deliberately excluded — they were
already backfilled to `status = 'approved'` by migration 69 and don't
need or want to show up in an approval queue. `rider-settlements.php`
(COD cash-holding list) is the one existing screen that already reads
the `riders` table for *both* kinds of rider; it's unaffected since it
filters on `cod_cash_held`, not `status`.

**No delete action** — unlike `restaurants.php`'s soft-delete, there's
no `riders_delete` permission key seeded (checked; only view/edit/
approve exist for the `riders` module in migration 29), and suspend
already covers "stop this rider from working." Not flagging this as a
gap — just noting the asymmetry with restaurants.php is intentional,
not an oversight.

### `backend/admin/_layout_head.php`
Added a `riders` nav item (Operations group, right above Customers,
gated on `riders_view`) and added `'riders'` to the `$activeNav`
doc-comment's enum list. No other file touched — additive only.

## 🟡 Known gaps / next-session TODO

1. **Run migration 69 + smoke-test this page for real** — same top
   priority doc 79 already flagged, now with one more screen riding on
   it. Need at least one real `pending` rider row (via
   `rider-signup.php`) to click through Approve/Reject/Suspend/
   Reactivate and confirm the audit log entries land correctly.
2. Everything else doc 79 flagged (items 2, 3, 5) is still open and
   untouched by this session.
3. Phase 2 (the actual Android rider app) still hasn't started —
   this session was entirely the last missing piece of Phase 1's
   backend, not new scope.

## Files in this delivery

```
backend/admin/riders.php            (new)
backend/admin/_layout_head.php      (modified — nav item + activeNav enum comment only)
```
