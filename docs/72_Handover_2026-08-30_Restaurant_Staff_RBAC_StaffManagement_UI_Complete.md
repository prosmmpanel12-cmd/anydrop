# Handover — 2026-08-30 (doc 72): Restaurant Staff / RBAC — Staff Management UI Complete

## What was asked

Continuing from doc 71 exactly at its cut line: build
`StaffManagementActivity.kt` + `StaffAdapter.kt` +
`dialog_add_staff.xml`, wire an entry point into the Account tab, add
the remaining strings.

## What was built

- **`ui/staff/StaffAdapter.kt`** (new) — RecyclerView adapter for
  `item_staff.xml`, modeled directly on `ClosureAdapter.kt`: binds
  name + "username · role" text (role values mapped to display labels
  via new `staff_role_manager/kitchen/cashier` strings), wires the
  active/inactive switch to an `onToggleActive` callback (listener is
  cleared before each programmatic `isChecked` set so
  `notifyDataSetChanged()` rebinding a row mid-flight can't re-fire the
  mutation it's still waiting on), and wires the edit/delete icons.
- **`res/layout/dialog_add_staff.xml`** (new) — name / username /
  password fields + a role `RadioGroup` (manager/kitchen/cashier only,
  no "owner" option, matching migration 63's ENUM). Username field is
  disabled on edit (see below).
- **`ui/staff/StaffManagementActivity.kt`** (new) — reuses
  `activity_notification_list.xml` exactly as doc 71 planned:
  swipe-refresh + RecyclerView + empty-state, `btnAction` as "+ Add
  Staff". One dialog handles add/edit
  (`showStaffDialog(existing: StaffProfile?)`); on edit, username is
  locked (staff-update.php doesn't accept a username field, and
  changing the display value without a matching backend field would
  silently mislead) and the password field's hint switches to "leave
  blank to keep current," submitting `password = null` when empty so
  `staff-update.php`'s partial-update semantics apply. The active
  switch's `onToggleActive` fires a standalone `updateStaff(isActive=…)`
  PATCH independent of the full edit dialog, and always reloads the
  list afterward (success or failure) so the switch never shows a
  state the server doesn't actually have. Delete reuses
  `dialog_confirm_delete.xml`, same pattern as
  `ClosureScheduleActivity.confirmDeleteClosure()`. Launch is guarded
  by `tokenManager.canManageStaff()` (finishes immediately if false) —
  client-side convenience only, the backend's `manage_staff` 403 is the
  real enforcement.
- **`AndroidManifest.xml`** — no change needed; doc 71's session had
  already registered `.ui.staff.StaffManagementActivity`, so today's
  new class resolves that compile blocker doc 71 flagged rather than
  requiring a manifest edit.
- **Account tab entry point**: `fragment_account.xml` gained
  `btnStaffManagementRow` (same row style as `btnBankDetailsRow`,
  `visibility="gone"` by default), placed directly after Bank Details
  and before Logout per doc 71's suggested placement.
  `AccountFragment.kt` shows it and wires the launch only when
  `tokenManager.canManageStaff()` is true — a staff-role session never
  sees the row at all, on top of the Activity's own launch guard above.
- **`strings.xml`** — all strings the above needed: screen/dialog
  titles, empty-state and load/save-failure text, delete-confirm
  title/message, role display labels, the username-role row format
  string, and the two password-field hints (create vs. edit wording).

## Genuinely still open

Same two items doc 71 explicitly deferred, unchanged by this session:

- [ ] End-to-end role-based **UI** hiding elsewhere in the app (e.g.
      hiding menu-edit buttons from a kitchen-role session) — still not
      attempted. Backend enforcement is unaffected either way.
- [ ] `php -l` / real compile / real device test, and a live test of
      the permission matrix against real staff accounts of each role —
      standing constraint, nothing in this sandbox can run Gradle or
      hit a live DB.

With those two aside, PENDING.md item 3 (Staff / RBAC) is functionally
complete: backend (doc 71) + Android login (doc 71) + Android
management UI (this session).

## Files touched this session

**Android — Restaurant:** `ui/staff/StaffAdapter.kt` (new),
`ui/staff/StaffManagementActivity.kt` (new),
`res/layout/dialog_add_staff.xml` (new),
`res/layout/fragment_account.xml` (edited — new
`btnStaffManagementRow`), `ui/account/AccountFragment.kt` (edited —
wires that row), `res/values/strings.xml` (edited).

**Docs:** this file.

## Verification done this session

Same standing constraint as every prior session — no PHP CLI, Android
SDK, or live DB in this sandbox.

- Comment-and-string-aware brace/paren/bracket balance check on all 3
  edited/new Kotlin files (`StaffAdapter.kt`,
  `StaffManagementActivity.kt`, `AccountFragment.kt`) — all balanced.
- XML well-formedness check on `dialog_add_staff.xml`,
  `fragment_account.xml`, `strings.xml` — all parse cleanly.
- Manually cross-checked every view ID referenced from
  `StaffAdapter.kt`/`StaffManagementActivity.kt` against
  `item_staff.xml`/`dialog_add_staff.xml`'s actual IDs — no
  mismatches.
- Confirmed `StaffCreateBody`/`StaffUpdateBody`/`StaffProfile` and the
  `listStaff/createStaff/updateStaff/deleteStaff` `ApiService`
  signatures used here match doc 71's already-built definitions
  exactly (no backend or model changes were needed this session).

## Suggested next session

PENDING.md's #4 (Rider App) or #5 (real build/device verification),
per doc 71's own suggested order — this phase's remaining two items
above are deliberate follow-ups, not blockers.
