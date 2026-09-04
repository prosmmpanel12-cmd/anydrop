# Handover — 2026-08-30 (doc 73): Restaurant Staff Audit Trail — Built

## What was asked

Continuing from doc 72. PENDING.md §7's one remaining checkbox: a
staff-action audit trail (who created/edited/disabled/removed which
staff row and when), plus an owner-facing view. Picked over Rider App
when the owner was asked to choose between the two top pending items.

**This closes out PENDING.md §7 (Restaurant Staff / RBAC) entirely** —
every checkbox in that section is now `[x]`.

## Backend — built

### Migration 64 (`backend/sql/64_migration_staff_audit_notes.sql`)
No schema change — this migration file is a documentation marker
only. The generic `audit_logs` table (`01_schema.sql`: actor_type,
actor_id, action, details_json, ip_address, created_at) was already
the right shape, same call this project already made for bank-details
verification (migration 59's own comment says as much). `actor_type`
stays `'restaurant'` and `actor_id` stays the restaurant's own id for
every staff action — there's no `'staff'` value in that enum and
adding one would be unnecessary churn. WHO actually acted (owner vs a
named staff member) lives inside `details_json` instead
(`acting_role`, `acting_staff_id`), not in `actor_id`.

### `backend/lib/permissions.php` — new `write_staff_audit_log()`
One helper, called from the three staff-mutation endpoints after
their write succeeds (never before — a failed mutation shouldn't
produce a log row implying it happened). Takes the acting `$owner`
array (from `require_auth('restaurant')`), an action string, the
target `restaurant_staff.id`, and an optional `$extra` array merged
into `details_json`.

### Endpoints edited
- `staff-create.php` — logs `staff_created` (name, username, role).
- `staff-update.php` — logs up to three separate rows per request
  depending on what actually changed: `staff_activated` /
  `staff_deactivated` (is_active flip), `staff_role_changed`
  (old_role/new_role), `staff_updated` (name and/or password). One
  action, one log line — a request that changes both role and
  is_active in one call produces two rows, not one generic row.
- `staff-delete.php` — logs `staff_deleted`, capturing the row's
  name/username/role before the soft-delete since a later audit-list
  read can no longer join back to a `deleted_at`-marked row.

### New endpoint: `backend/api/v1/restaurant/staff-audit-list.php`
GET, owner-only (`manage_staff` permission — same gate as the staff
CRUD endpoints, since seeing who changed staff accounts is exactly as
sensitive as changing them). Filters `audit_logs` to
`actor_type='restaurant' AND actor_id=<this restaurant> AND action IN
(<the 6 staff actions>)` — the action whitelist is what keeps this
screen scoped to staff changes specifically, since a restaurant's
`audit_logs` rows already include other actions (signup, login, bank
details save) that will keep growing over time. No pagination, capped
at 200 most-recent rows.

## Android — built

- **`network/Models.kt`** — `StaffAuditLogEntry` (id, action,
  target_staff_id, acting_role, acting_staff_id, details map,
  created_at), `StaffAuditLogListResult`.
- **`network/ApiService.kt`** — `listStaffAuditLog()`.
- **`res/layout/item_audit_log.xml`** (new) — read-only row (no
  edit/delete icons, unlike `item_staff.xml`/`item_closure.xml`),
  reuses the existing `ic_clock` drawable rather than adding a new
  icon resource.
- **`ui/staff/StaffAuditLogAdapter.kt`** (new) — turns each raw action
  + details map into a human-readable line ("Priya Sharma added as
  Kitchen", "Priya's role changed from Kitchen to Cashier", "Priya
  disabled", etc.) plus a "By you (Owner)" / "By staff (Manager)"
  sub-line and a formatted timestamp. An unrecognized future action
  string falls back to showing the raw action key rather than hiding
  the row.
- **`ui/staff/StaffAuditLogActivity.kt`** (new) — reuses
  `activity_notification_list.xml` as its shell, exactly like
  `StaffManagementActivity`/`ClosureScheduleActivity`, but `btnAction`
  stays hidden (`View.GONE`) since this is read-only — same pattern
  `ReviewListActivity` already uses for a screen with no "add" action.
  Guarded by `tokenManager.canManageStaff()` on launch, same
  client-side convenience check every other owner-only screen in this
  feature uses.
- **`AndroidManifest.xml`** — `StaffAuditLogActivity` registered.
- **`res/values/strings.xml`** — all new strings (screen title,
  empty-state, per-action templates, by-owner/by-staff templates).
- **`res/layout/fragment_account.xml`** — new `btnStaffAuditLogRow`
  row ("Staff Activity Log"), placed directly below the existing
  Staff Management row, same `bg_card_rounded` style,
  `visibility="gone"` by default.
- **`ui/account/AccountFragment.kt`** — wires that row's visibility
  and click, same `if (tokenManager.canManageStaff())` guard and
  one-line `startActivity(...)` pattern as the Staff Management row
  immediately above it.

## Genuinely still open

- [ ] `php -l` / real compile / real device test — standing
      constraint, no PHP CLI or Android SDK in this sandbox.
- [ ] Live test of the actual audit rows against a real staff account
      doing real create/edit/disable/remove actions — the action
      naming and details_json shape is a reasoned, documented pass,
      not yet exercised against a running restaurant + staff login.
- [ ] `StaffManagementActivity` itself has no link INTO the audit log
      screen (e.g. a small "View activity" icon in its own toolbar) —
      the only entry point built is the new Account tab row. Not a
      gap, just worth knowing if a more discoverable in-context link
      is wanted later.
- [ ] With this, PENDING.md §7 (Restaurant Staff / RBAC) is now fully
      `[x]` end to end. Next pending items per NEXT_SESSION_PROMPT.md's
      own list: Rider App (the largest remaining untouched phase),
      Payment/Refund Reconciliation, Email OTP Provider Failover,
      Security Hardening, or a real machine-verification pass across
      everything built so far.

## Files touched this session

**Backend:** `backend/sql/64_migration_staff_audit_notes.sql` (new,
no-op schema marker), `backend/lib/permissions.php` (edited —
`write_staff_audit_log()` added), `backend/api/v1/restaurant/staff-create.php`
(edited), `backend/api/v1/restaurant/staff-update.php` (edited),
`backend/api/v1/restaurant/staff-delete.php` (edited),
`backend/api/v1/restaurant/staff-audit-list.php` (new).

**Android — Restaurant:** `network/Models.kt` (edited),
`network/ApiService.kt` (edited), `res/layout/item_audit_log.xml`
(new), `ui/staff/StaffAuditLogAdapter.kt` (new),
`ui/staff/StaffAuditLogActivity.kt` (new), `AndroidManifest.xml`
(edited), `res/values/strings.xml` (edited),
`res/layout/fragment_account.xml` (edited),
`ui/account/AccountFragment.kt` (edited).

**Docs:** this file; `PENDING.md` §7 updated (all checkboxes now `[x]`).

## Verification done this session

Same standing constraint: no PHP CLI, Android SDK, or live DB in this
sandbox.

- Comment-and-string-aware brace/paren balance check on all 6
  new/edited backend files — all balanced.
- Comment-aware brace/paren balance check on all 5 new/edited Kotlin
  files (`Models.kt`, `ApiService.kt`, `StaffAuditLogAdapter.kt`,
  `StaffAuditLogActivity.kt`, `AccountFragment.kt`) — all balanced.
- XML well-formedness check on `AndroidManifest.xml`,
  `fragment_account.xml`, `item_audit_log.xml`, `strings.xml` — all
  parse cleanly.

## Suggested next session

PENDING.md §7 is fully closed. Pick between: **Rider App** (item 4,
largest untouched phase, needed for the last piece of the order
journey), **Payment/Refund Reconciliation** (item 24), **Email OTP
Provider Failover** (item 25, confirmed nothing exists for it yet),
**Security Hardening** (item 26, needs a real machine to test rate
limits/race conditions), or a **real build/device verification pass**
across everything accumulated so far — see NEXT_SESSION_PROMPT.md for
the full current-priority list.
