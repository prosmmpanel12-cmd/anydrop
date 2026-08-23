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

<!--
IMPORTANT:
Only add a session here after the complete session scope has passed testing.
Do not use this section as a development TODO list.
-->

## 2026-08-21 — Service Area Management (recall.md Phase A item 4)

**Module:** Backend / Admin Web Panel

### Completed
- `backend/sql/30_migration_service_areas.sql` — `service_areas`
  adjacency-list table + additive nullable `area_id` FK on
  `restaurants` and `customer_addresses`.
- `backend/sql/34_migration_fix_service_areas_level_enum.sql` — live-DB
  ENUM fix (the `'area'`→`'village'`→`'city_village'` restructure
  history, see recall.md item 2 for the full story).
- `backend/admin/areas.php` — single Add form (State/District/
  City-Village required, Area optional) with find-or-create-by-name
  matching, Fetch-by-Pincode autofill, full breadcrumb hierarchy list,
  duplicate-detection banner, Merge-duplicate-nodes tool, test-
  coordinates tool.
- Shared `admin_area_breadcrumb_compact()` helper in `_bootstrap.php`
  for "Neora, Osian, Jodhpur, Rajasthan" style display everywhere a
  service-area node is shown or selected.

### Tested (by app owner, live server)
- Migrations 30/32/33/34 run against live DB.
- Add State→District→City/Village→Area chain via the single form;
  Fetch by Pincode autofill; edit; deactivate; delete-blocked-by-
  attachment; delete-when-empty; test-coordinates tool against real
  lat/lng pairs.
- Duplicate-node banner and Merge tool.
- Breadcrumb format confirmed correct in the Hierarchy list, the
  Restaurants area filter/assign dropdowns, and the Banners area
  dropdown, after a follow-up bug fix (see below).

### Bug found & fixed during testing (2026-08-22)
`$areaOptions` in `restaurants.php`/`banners.php` only selected
`id, name, level` (no `parent_id`), so `admin_area_breadcrumb_compact()`
had nothing to walk up and silently fell back to the bare leaf name in
every dropdown — even though the Restaurants list table's Area column
(built from the full node map) showed the breadcrumb correctly. Fixed
by looking up the full node (with `parent_id`) from `$areaNodeById`
before building the breadcrumb, in all 5 call sites across
`restaurants.php` and `banners.php`. Re-tested and confirmed working
by app owner.

### Result
✅ PASS

### Remaining
Still genuinely pending, unchanged from recall.md items 3-5: area-wise
restaurant visibility, area-wise COD rules, area-wise banner *serving*
(admin-side targeting exists; the customer-facing fetch endpoint that
actually filters by area does not).

---

## 2026-08-21 — Admin Restaurant & Customer Management (recall.md Phase A item 5)

**Module:** Backend / Admin Web Panel

### Completed
- `backend/admin/restaurants.php` — search/filter/pagination over the
  full restaurants table, per-row Manage dialog with full status
  lifecycle (approve/reject/suspend/reactivate with reasons), area
  assignment (writes `restaurants.area_id`), commission_% override,
  soft-delete.
- `backend/admin/customers.php` — search/filter/pagination, per-row
  View dialog with saved addresses, last 5 orders, suspend/reactivate,
  soft delete.
- Nav links added; gated on already-seeded RBAC permission keys.

### Tested (by app owner, live server)
- Search/filter both screens.
- Full restaurant status lifecycle transitions in order (approve →
  suspend → reactivate → reject).
- Area assignment shows up correctly on a real restaurant row (using
  the corrected breadcrumb format, see Service Area Management entry
  above).
- Commission edit persists.
- Customer suspend/reactivate blocks/allows customer-app login.
- Both soft-deletes hide the row without breaking existing order
  history joins.

### Result
✅ PASS

### Remaining
No wallet adjustment (blocked on item 18, Customer Wallet, not built
yet). No bulk actions, no CSV export. Area-wise restaurant *visibility*
on the Customer Home feed (recall.md item 3) is still untouched — this
session only lets Admin *set* `restaurants.area_id`, not the
consumption side.

---

## 2026-08-21 — Admin Category Management (recall.md item 16)

**Module:** Backend / Admin Web Panel

### Completed
- `backend/sql/32_migration_restaurant_categories.sql` — new
  `restaurant_categories` table (seeded: Cafe/Bakery/Sweet Shop/
  Pharmacy/Grocery/Restaurant) + additive `restaurants.restaurant_category_id`.
- `backend/admin/categories.php` — two tabs: Restaurant Types (new
  table) and Food Categories (CRUD on the existing `food_categories`
  table, which previously had no admin UI). Both: add/edit/deactivate/
  hard-delete-when-empty with a reference-count guard.
- Gated on already-seeded `categories_view`/`categories_edit`/
  `categories_delete` permission keys — no new RBAC migration needed.

### Tested (by app owner, live server)
- Migration 32 run against live DB.
- Both tabs: add, edit, deactivate, delete-blocked (with reference
  count shown), delete-when-empty.

### Result
✅ PASS

### Remaining
Nothing yet sets `restaurants.restaurant_category_id` on existing
restaurants or surfaces it in `restaurants.php`'s own list/filter UI —
flagged as a small follow-up, not part of this session's scope.

---

## 2026-08-21 — Admin Banner Manager (recall.md item 17)

**Module:** Backend / Admin Web Panel

### Completed
- `backend/sql/33_migration_banners.sql` — new `banners` table.
- `backend/admin/banners.php` — add/edit with image upload (5MB cap,
  jpg/png/webp), banner type, deep link, priority, start/end date,
  area targeting (empty = platform-wide, or scoped to a City/Village
  or Area node — shown via the breadcrumb helper), deactivate, hard
  delete (also removes the image file from disk).
- Draggable 3:1 crop-preview on upload, server-side GD crop with
  fallback to the untouched original if GD/format support is missing.

### Tested (by app owner, live server)
- Migration 33 run against live DB.
- Add with image, edit, replace image, area-scoped vs platform-wide
  (breadcrumb format confirmed correct after the fix noted in the
  Service Area Management entry above), deactivate, delete.
- Crop preview and server-side crop.

### Result
✅ PASS

### Remaining
The customer-facing banner-fetch endpoint that would actually filter
`WHERE area_id IS NULL OR area_id = :customer_area_id` does not exist
yet — depends on customer address → area resolution (recall.md item 3,
still pending). Banners created here are admin-side management only
until that's built.

---

## 2026-08-21 — Admin Panel UI Redesign (design system + responsive shell)

**Module:** Backend / Admin Web Panel

### Completed
- `backend/admin/assets/admin.css` — shared design-system file (light +
  dark theme) replacing five separate copy-pasted `<style>` blocks.
- `backend/admin/assets/admin.js` — theme toggle, responsive sidebar
  (desktop collapse-to-rail / tablet rail / mobile off-canvas drawer),
  shared `<dialog>`-based confirm modal, toast notifications, button
  loading-spinner state.
- `backend/admin/_layout_head.php` + `_layout_foot.php` — shared page
  shell, included by every admin page.
- Retrofitted dashboard/index/roles/areas/login onto the new shell.
- Two follow-up fixes applied before first live test: rebuilt the
  shell as flexbox (was CSS Grid, squeezed content on real mobile),
  and fixed a CSS specificity bug where `.desktop-only`/`.menu-btn`
  were losing the cascade to `.icon-btn`, causing a duplicate
  hamburger on phones and no expand button on tablet widths.

### Tested (by app owner, live server, real devices)
- Desktop: sidebar collapse/expand, theme toggle persists on reload,
  dialogs open/close/animate, toasts appear after form actions.
- Tablet width (~768-1024px): icon rail default, expand toggle works.
- Mobile width (≤640px): hamburger opens drawer, backdrop tap closes
  it, forms/tables don't overflow horizontally.
- Light and dark theme on all 5 retrofitted pages.

### Result
✅ PASS

### Remaining
No new functionality — pure UI/UX layer. Business logic in every
retrofitted page is unchanged from its own tested session.


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

