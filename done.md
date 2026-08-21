# AnyDrop — DONE.md

## Purpose

This file is the **verified completion record** for the AnyDrop project.

From now on, every development session must update this file **only after the complete work of that session has been tested and verified**.

A feature must NOT be marked `DONE` merely because:
- code was written,
- compilation succeeded,
- the screen opens,
- an API exists,
- or Claude says the implementation is complete.

It is `DONE` only when the session's work has been fully tested against the intended feature behavior.

---

# Completion Rules

## 1. Session work → Test → DONE

For every development session:

1. Identify exactly what was changed.
2. Build/compile the affected application/backend.
3. Test the complete user flow, not only the changed screen.
4. Test relevant API/database interactions.
5. Test success and important failure/edge cases.
6. Fix any discovered issue.
7. Re-test after the fix.
8. Only then add the completed work to this file as `DONE`.

### Rule

> **No successful test = No DONE mark.**

---

## 2. Never mark historical/stale work as DONE

Old `Status.md`, session notes, TODO files, or Claude messages may contain outdated information.

Do not copy old status blindly.

`DONE.md` is the authoritative record for work that has been **verified after implementation**.

If an old document says a feature was completed but there is no verified test record for the current implementation, keep it out of `DONE.md` until it is tested again.

---

## 3. What each DONE entry should contain

Every completed session should record:

- Date
- Session/work name
- App/module
- Features changed
- Tests performed
- Important edge cases tested
- Result
- Any remaining limitation

Example:

```md
## 2026-08-21 — Customer Notification Fix

**Module:** Customer App

### Completed
- Notification list
- Auto mark-as-read
- Read/unread state refresh

### Tested
- Open notification screen
- Existing unread notifications
- Mark all as read
- Reopen screen
- Backend/database state
- App restart

### Result
✅ PASS

### Remaining
None
```

---

# Status Meaning

Use only these statuses:

- `✅ DONE` — implemented and fully tested
- `🟡 PARTIAL` — partially implemented/tested; must NOT be treated as complete
- `🔴 PENDING` — not completed
- `⚠️ BLOCKED` — implementation/testing cannot continue because of an external blocker

Only `✅ DONE` means the work can be considered completed.

---

# Session Completion Checklist

Before adding a session here:

- [ ] Implementation completed
- [ ] Build successful
- [ ] Main flow tested
- [ ] Error/validation flow tested
- [ ] Relevant API tested
- [ ] Relevant database operation tested
- [ ] UI behavior tested
- [ ] Edge cases tested where applicable
- [ ] Regression check completed
- [ ] Bugs found during testing fixed
- [ ] Re-tested after fixes
- [ ] No known blocker for the completed scope

After all applicable checks pass:

**Mark the session `✅ DONE`.**

---

# Important Rule for Claude / AI Agents

When working on AnyDrop:

> **Do not claim a session is DONE until the complete session scope has been tested.**

If implementation is finished but testing remains, report:

`🟡 IMPLEMENTED — TEST PENDING`

Do NOT add it to the verified `DONE` section.

If testing fails, report:

`❌ TEST FAILED`

Fix the issue and test again.

Only after successful verification should the final status become:

`✅ DONE`

---

# Verified Sessions

Add new verified sessions below this line.

<!--
IMPORTANT:
Only add a session here after the complete session scope has passed testing.
Do not use this section as a development TODO list.
-->

<!--
🟡 IMPLEMENTED — TEST PENDING (not a verified session yet, do not treat as DONE):

2026-08-21 — Admin Panel UI Redesign (design system + responsive shell)
Module: Backend / Admin Web Panel

Completed:
- backend/admin/assets/admin.css — one shared design-system file
  (CSS custom properties, light + dark theme) replacing five separate
  copy-pasted <style> blocks across dashboard/index/roles/areas/login.
- backend/admin/assets/admin.js — theme toggle (persisted to
  localStorage, respects prefers-color-scheme on first visit), responsive
  sidebar behavior (desktop collapse-to-rail / tablet rail / mobile
  off-canvas drawer with backdrop), a reusable <dialog>-based confirm
  modal (replaces every window.confirm()), toast notifications
  (replaces the static flash <div>), and button loading-spinner state
  on submit.
- backend/admin/_layout_head.php + _layout_foot.php — shared page shell
  (sidebar with permission-gated nav + topbar with theme toggle/user
  chip), included by every admin page instead of each page carrying its
  own header/nav markup.
- Retrofitted dashboard.php, index.php, roles.php, areas.php, login.php
  onto the new shell/tokens. index.php's reject flow now uses a real
  dialog instead of a toggled inline box; all destructive/irreversible
  actions (restaurant reject already had a text reason, admin
  deactivate, area delete) now go through the shared confirm dialog
  instead of window.confirm().
- Responsive at three breakpoints: >1024px desktop (full sidebar,
  user-collapsible), 641-1024px tablet (icon rail, expandable), <=640px
  mobile (off-canvas drawer via hamburger).

NOT done in this session:
- No new functionality — this is a pure UI/UX layer on top of the
  already-tested RBAC, dashboard, and area-management logic from prior
  sessions. Business logic in every retrofitted page is untouched.

Follow-up fix (same day, before first live test): the sidebar/content
shell was originally built with CSS Grid, which on a real mobile
browser could leave the content area squeezed into a narrow leftover
column instead of spanning full width (topbar/title not visible,
everything squeezed left) — rebuilt as flexbox (.app-shell flex row,
.sidebar fixed-basis, .shell-main flex:1 + min-width:0) which is a
more predictable pattern for this off-canvas-drawer use case. Also
gave each sidebar nav icon a distinct colored rounded chip (info/warn/
success/purple per module) instead of plain flat-monochrome icons, and
swapped the Approvals icon for a clearer storefront glyph.

Second follow-up fix (root cause of the "duplicate hamburger" / squeezed-
topbar glitch reported on real-device testing): `.desktop-only` and
`.menu-btn` base rules were declared *before* `.icon-btn` in the
stylesheet — since both had equal CSS specificity (single class each),
the later `.icon-btn { display: flex; }` rule was winning the cascade
regardless of media query, so the desktop-only sidebar-collapse button
was showing on phones too (the second, oddly-placed hamburger-looking
icon in the screenshots), and on tablet widths (641-1024px) there was
no visible button at all to expand the sidebar rail. Fixed by using
`.icon-btn.desktop-only` / `.icon-btn.menu-btn` (two-class selectors)
everywhere instead of relying on source order, and widened the
hamburger's "hide on desktop" threshold from 641px to 1025px so tablet
still has a way to expand the rail.

Tested: NOT YET — needs a live click-through by the app owner across:
- Desktop: sidebar collapse/expand, theme toggle persists on reload,
  dialogs open/close/animate, toasts appear after form actions.
- Tablet width (~768-1024px): icon rail default, expand toggle.
- Mobile width (<=640px): hamburger opens drawer, backdrop tap closes
  it, forms/tables don't overflow horizontally.
- Both light and dark theme on all 5 pages (dashboard, approvals,
  areas, roles, login).

Do not mark this DONE until that live test happens and this block is
promoted to a normal dated entry above.
-->

Module: Backend / Admin Web Panel

Completed:
- backend/sql/30_migration_service_areas.sql — new `service_areas`
  adjacency-list table (parent_id, level enum state/district/city/area,
  is_active, center_lat/center_lng/radius_km) + additive nullable
  `area_id` FK on `restaurants` and `customer_addresses` (idempotent,
  same CONTINUE-HANDLER-for-1060 pattern as migration 25).
- backend/admin/areas.php — new screen: add area (parent picker derives
  the child's level automatically), edit name/center/radius, activate/
  deactivate, hard-delete (only when no children/restaurants/addresses
  are attached, otherwise blocked with a clear message), full indented
  hierarchy list with restaurant counts per area, and a "test
  coordinates" tool (reuses lib/geo.php's haversine_km()) to sanity-
  check center/radius against a real GPS pair before this feeds into
  customer address resolution.
- Gated on the already-seeded `areas_view` / `areas_edit` /
  `areas_delete` permission keys (backend/sql/29_migration_admin_rbac.sql)
  — no new permission keys needed.
- Nav links added from dashboard.php / index.php / roles.php.

NOT done in this session (deliberately out of scope, per recall.md
Phase B, items 9-16):
- Customer address -> area_id resolution job/endpoint.
- Area-wise restaurant visibility filtering on the Home feed.
- Area-wise COD/payment/banner rules.
- Restaurant onboarding/admin UI to actually assign a restaurant's
  area_id (the column exists; nothing sets it yet).

Tested: NOT YET — needs backend/sql/30_migration_service_areas.sql run
against the live DB, then live click-through by the app owner (add a
State → District → City → Area chain, edit, deactivate, delete-blocked-
by-attachment, delete-when-empty, and the test-coordinates tool against
a couple of real lat/lng pairs).

Do not mark this DONE until that live test happens and this block is
promoted to a normal dated entry above.
-->


## 2026-08-21 — Admin Dashboard (Phase A item 3)

**Module:** Backend / Admin Web Panel

### Completed
- `backend/admin/dashboard.php` — stats overview: Today's orders/
  revenue/gross, Restaurants (approved/open/pending), Customers
  (active/ordered last 30 days), Pending payouts (count + amount).
  Gated on `dashboard_view`; every widget additionally gated on its own
  module's `_view` permission so a role only sees numbers it's allowed
  to see.
- Nav links added from `index.php` and `roles.php` to the new page.

### Tested (by app owner, live server)
- Super Admin: all four sections render with correct live numbers.
- Role with only `dashboard_view` (no other `_view` perms): "nothing to
  show" placeholder message confirmed.
- Role without `dashboard_view`: no Dashboard link shown; direct URL
  hit returns 403.

### Result
✅ PASS — confirmed working on live server by app owner.

### Remaining
None for this scope. recall.md Phase A item 3 is now complete.

---

## 2026-08-21 — Admin RBAC Foundation (Roles & Permissions)

**Module:** Backend / Admin Web Panel

### Completed
- New schema: `admin_roles`, `admin_permissions`, `admin_role_permissions`
  (`backend/sql/29_migration_admin_rbac.sql`); replaces the old unused
  `admins.role` enum with `role_id` + `name`/`email`/`is_active`/
  `last_login_at`; seeds the 48 permission keys from doc 19 §1 and a
  Super Admin role with every permission; migrates existing admins onto
  Super Admin so no one is locked out.
- `backend/lib/admin_auth.php` — `admin_has_permission()` /
  `admin_require_permission()`.
- `backend/admin/roles.php` — new screen: create custom roles, edit a
  role's permission checkbox grid, create new admin accounts (username/
  password/name/email + role), reassign an existing admin's role,
  activate/deactivate admins.
- `_bootstrap.php` — `admin_require_login()` now also rejects
  deactivated admins on every page load, not just at login.
- `login.php` — blocks deactivated admins at login, stamps
  `last_login_at`, audit-logs login/blocked-login.
- `index.php` (Pending Restaurant Approvals) — gated on
  `restaurants_view` (approve/reject buttons additionally gated on
  `restaurants_approve`); `dashboard_view` reserved for the future
  separate stats dashboard (recall.md Phase A item 3), not this page.
- `seed-admin.php` — updated to assign the Super Admin role_id instead
  of the removed enum.

### Tested (by app owner, live KS Web + real DB)
- Created custom "Restaurant Manager" role via Roles screen.
- Created a new admin ("Altaf") assigned to that role via the new Add
  Admin form.
- Confirmed a role with only `restaurants_approve` (no
  `restaurants_view`) got a 403 on the approvals page — found and fixed
  during this session (page was wrongly gated on `dashboard_view`).
- After adding `restaurants_view` + `restaurants_edit` to the role and
  redeploying the corrected `index.php`: Altaf's login reaches the
  pending-approvals page and can approve/reject restaurants.
- Existing Super Admin login/approve/reject flow re-confirmed working
  after the RBAC changes (no regression).

### Result
✅ PASS — confirmed working on live device/server by app owner.

### Remaining
- Full Admin Dashboard (stats widgets) is a separate, not-yet-built
  page — still 🔴 PENDING per recall.md Phase A item 3.
- Service Area Management, and every other recall.md pending item, are
  untouched by this session.

