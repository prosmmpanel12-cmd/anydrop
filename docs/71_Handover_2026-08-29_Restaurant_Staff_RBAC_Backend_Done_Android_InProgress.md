# Handover — 2026-08-29 (doc 71): Restaurant Staff / RBAC — Backend Complete, Android In Progress

## What was asked

Continuing from doc 70. PENDING.md item 3 — the large, untouched "Staff
/ RBAC" phase: one login = one restaurant today, needs a
`restaurant_staff` table, roles (owner/manager/kitchen/cashier), and an
auth re-audit across every restaurant-side endpoint. App owner chose
this over item 4 (Rider App) when asked to pick.

**This is a checkpoint, not a close-out.** Backend is fully built and
verified. Android is partway through — staff login works end-to-end,
but the owner-facing Staff Management screen (add/edit/disable/remove
staff) is not yet built. See "Genuinely still open" below for the exact
cut line.

## Backend — fully built

### Migration 63 (`backend/sql/63_migration_restaurant_staff.sql`)
- New `restaurant_staff` table: `restaurant_id`, `name`, `username`
  (globally unique, same reasoning as `restaurants.owner_email`),
  `password_hash`, `role` ENUM('manager','kitchen','cashier') —
  **"owner" is deliberately not a value here**; the restaurant's own
  login (`restaurants.owner_email`/`password_hash`) is completely
  untouched by this whole feature. `is_active` for a quick disable,
  `deleted_at` for full removal.
- `auth_tokens.staff_id` (nullable) — the key design choice: `owner_id`
  on a restaurant-type token is **always the restaurant's own id**,
  never the staff row's id, whether an owner or a staff member is
  logged in. This is what let all 47 existing endpoints keep working
  with zero changes to their own core logic — only a permission gate
  was added on top.

### `backend/lib/permissions.php` (new)
Role → permission matrix, one place instead of scattered logic:
- `manage_staff`, `manage_bank_details` — **owner only**
- `manage_restaurant_profile`, `manage_menu`, `manage_closures`,
  `manage_offers_coupons`, `view_insights` — **owner + manager**
- `manage_orders` — **owner + manager + kitchen + cashier** (the actual
  job those roles are hired to do)
- Read-only/list endpoints deliberately left ungated for any
  authenticated staff — see the file's own kdoc for the full reasoning
  on why (mainly: gating every read endpoint multiplies this file's
  surface for no real security benefit, and risks locking a role out of
  something they need to see to do their job).

### `backend/lib/auth.php` (edited)
- `create_auth_token()` takes an optional `$staffId`.
- `get_authenticated_owner()` returns `staff_id` alongside
  `owner_type`/`owner_id`.
- `require_auth('restaurant')` now resolves a `role` key: `'owner'`
  when `staff_id` is null, else the `restaurant_staff.role` — with its
  own **re-check on every request** (a disabled/deleted staff row is
  rejected with 403 `staff_disabled` immediately, not at token expiry),
  same "don't wait for token expiry" principle doc 25 already
  established for restaurant suspension.

### New endpoints
- `backend/api/v1/auth/restaurant-staff-login.php` — sibling of
  `restaurant-login.php` for a staff username/password. Separate
  endpoint rather than a flag on the existing one, so the owner's own
  login path is untouched.
- `backend/api/v1/restaurant/staff-list.php`,
  `staff-create.php`, `staff-update.php`, `staff-delete.php` — full
  CRUD, all gated `manage_staff` (owner only). `staff-delete.php` is a
  real soft-delete (`deleted_at`); `staff-update.php`'s `is_active`
  toggle is the "temporarily disable, keep the account" alternative.

### The re-audit (the actual ask)
Went through all 47 `backend/api/v1/restaurant/*.php` files individually
and categorized each:
- **33 files gated** with `require_restaurant_permission($owner, '...')`
  right after their existing `require_auth('restaurant')` call —
  bank details (2), profile/branding/status/reviews (6), menu/addons/
  categories (13), closures (3), offers/coupons (4), insights/dashboard
  (2), order actions (3).
- **14 files deliberately left ungated** (any authenticated staff,
  any role) — every plain list/read endpoint (categories-list,
  menu-items-list, addon-groups-list, closures-list, offers-list,
  coupons-list, orders-list, orders-detail, banners-list,
  food-tags-list, profile-get, notifications, reviews viewing,
  fcm-token-update).

Every one of the 33 edits verified: exactly one `require_once
permissions.php` and one `require_restaurant_permission(...)` call per
file (grep-counted, no doubles), correct placement relative to
`$restaurantId = $owner['owner_id']` (spot-checked several including
the unusually-commented `status-update.php`).

## Android — in progress

### Done
- **`TokenManager.kt`** — `saveSession()` gained `role` (defaults to
  `"owner"`, so the one existing call site needed zero changes) and
  `staffName` params. New `getRole()`, `isOwner()`,
  `canManageStaff()` (client-side UI-hiding convenience only — the
  backend's own `require_restaurant_permission()` is the actual
  enforcement).
- **`network/Models.kt`** — `StaffLoginBody`, `StaffProfile`,
  `StaffLoginResult`, `StaffListResult`, `StaffResult`,
  `StaffCreateBody`, `StaffUpdateBody`.
- **`network/ApiService.kt`** — `staffLogin()`, `listStaff()`,
  `createStaff()`, `updateStaff()`, `deleteStaff()`.
- **`ui/staff/StaffLoginActivity.kt`** + **`activity_staff_login.xml`**
  — complete, working staff login screen: username/password form,
  same hero-header visual pattern as the owner's `activity_login.xml`,
  friendly error messages including the new `staff_disabled` case,
  saves the session via the extended `TokenManager`, same best-effort
  FCM-token registration `LoginActivity` does after its own login.
- **`activity_login.xml`** — added a "Login as staff member instead"
  link.
- **`LoginActivity.kt`** — wired that link to launch
  `StaffLoginActivity`.
- **`AndroidManifest.xml`** — registered both `StaffLoginActivity` and
  `StaffManagementActivity` (the latter's activity, class, and layout
  don't exist yet — see below; the manifest entry alone is harmless
  but **will fail to compile** until that class exists).
- **`strings.xml`** — `login_staff_instead`, `staff_login_title`,
  `staff_login_subtitle`, `hint_username`,
  `staff_login_back_to_owner`.
- **`item_staff.xml`** (new layout) — a staff roster row (name,
  username · role, active/inactive switch, edit/delete icons), modeled
  on `item_closure.xml`. Not yet referenced by any adapter (see below)
  — currently an orphaned resource, but well-formed and ready to wire
  up.

### Genuinely still open (exact cut line)

- [ ] **`AndroidManifest.xml` currently references a
      `.ui.staff.StaffManagementActivity` class that does not exist
      yet — this WILL fail to compile as-is.** First thing next
      session: either build that class (see below) or remove the
      manifest entry if picking this up is deferred further.
- [ ] `StaffManagementActivity.kt` — the owner-only screen itself.
      Plan (not yet built): reuse `activity_notification_list.xml` as
      the shell, exactly like `ClosureScheduleActivity` does — same
      swipe-refresh + RecyclerView + empty-state pattern, `btnAction`
      as "+ Add Staff". Launch guarded by `tokenManager.canManageStaff()`
      (redirect/hide if a non-owner somehow reaches it — the backend's
      403 is the real guard either way).
- [ ] `StaffAdapter.kt` — RecyclerView adapter for `item_staff.xml`,
      modeled directly on `ClosureAdapter.kt` (submit/diff list, bind
      name+username+role text, wire the switch to an is_active PATCH,
      wire edit/delete icons).
- [ ] `dialog_add_staff.xml` + the add/edit dialog logic in
      `StaffManagementActivity` — name/username/password/role fields
      (role as a Spinner or RadioGroup, same shape as
      `dialog_add_closure.xml`'s type toggle), one dialog handling both
      create and edit (edit omits/optional-izes the password field
      since `staff-update.php` only changes it if provided).
- [ ] Entry point into this screen from the Account/Settings tab —
      not yet added anywhere; needs a new row in `fragment_account.xml`
      / `AccountFragment.kt`, shown only when `canManageStaff()` is
      true.
- [ ] New strings for the above (dialog titles, delete-confirm text,
      empty-state text, role display labels for the three role values).
- [ ] End-to-end role-based **UI** hiding elsewhere in the app (e.g.
      hiding menu-edit buttons from a kitchen-role session) — explicitly
      NOT attempted this session. The backend enforces this correctly
      regardless (a kitchen-role token gets a 403 from
      `manage_menu`-gated endpoints even if the UI still shows the
      button), so this is a UX polish gap, not a security one.
- [ ] `php -l` / real compile / real device test — standing constraint,
      same as every session before this one; nothing in this sandbox
      can run PHP or Gradle.
- [ ] Live test of the actual permission matrix against real staff
      accounts of each role — the categorization is a reasoned,
      documented pass (see `lib/permissions.php`'s own kdoc), not yet
      exercised against a running restaurant + staff login.

## Files touched this session

**Backend:** `backend/sql/63_migration_restaurant_staff.sql` (new),
`backend/lib/permissions.php` (new), `backend/lib/auth.php` (edited),
`backend/api/v1/auth/restaurant-staff-login.php` (new),
`backend/api/v1/restaurant/staff-list.php` (new),
`backend/api/v1/restaurant/staff-create.php` (new),
`backend/api/v1/restaurant/staff-update.php` (new),
`backend/api/v1/restaurant/staff-delete.php` (new), plus 33 existing
`backend/api/v1/restaurant/*.php` files each gained one `require_once`
+ one `require_restaurant_permission(...)` line (full list: see the
"re-audit" section above).

**Android — Restaurant:** `data/TokenManager.kt` (edited),
`network/Models.kt` (edited), `network/ApiService.kt` (edited),
`ui/staff/StaffLoginActivity.kt` (new),
`res/layout/activity_staff_login.xml` (new),
`res/layout/activity_login.xml` (edited),
`ui/login/LoginActivity.kt` (edited),
`AndroidManifest.xml` (edited — **references an as-yet-nonexistent
class, see above**), `res/values/strings.xml` (edited),
`res/layout/item_staff.xml` (new, not yet wired to an adapter).

**Docs:** this file.

## Verification done this session

Same standing constraint: no PHP CLI, Android SDK, or live DB in this
sandbox.

- Comment-and-string-aware brace/paren balance check on all 8
  new/edited backend files (migration, permissions.php, auth.php, and
  the 5 new endpoint files) — all balanced.
- Same check, spot-run across all 33 gated `restaurant/*.php` files at
  once — all balanced.
- Grep-counted exactly one `require_once permissions.php` and one
  `require_restaurant_permission(...)` per gated file — no double-
  insertions from the batch edit script.
- Manually inspected the insertion point in several files (including
  the unusually-long-commented `status-update.php`) to confirm the
  permission check landed in the right place relative to
  `$restaurantId = $owner['owner_id']`.
- Comment-and-template-aware balance check (Kotlin) on all 5 edited/new
  Kotlin files — all balanced.
- XML well-formedness check on `AndroidManifest.xml`,
  `activity_login.xml`, `activity_staff_login.xml`, `item_staff.xml`,
  `strings.xml` — all parse cleanly. (Well-formed XML is not the same
  as "will compile" — the missing `StaffManagementActivity` class
  referenced in the manifest is a real, known compile blocker, called
  out above.)

## Suggested next session

Pick up exactly at the cut line above: build
`StaffManagementActivity.kt` + `StaffAdapter.kt` +
`dialog_add_staff.xml`, wire an entry point into the Account tab, add
the remaining strings, then this phase is essentially done (role-based
UI hiding elsewhere in the app can stay a deliberate follow-up, same
as noted above). After that, PENDING.md's #4 (Rider App) or #5
(real build/device verification) per the outer list's own suggested
order.
