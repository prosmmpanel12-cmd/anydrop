# Handover — 2026-08-28 (cont'd, part 3): Add-on Group UI — IN PROGRESS

## What was asked

Continue `today.md`'s priority queue after GST/FSSAI (doc 57): §1 Item
Add-on Group creation UI.

## Important correction found this session

`today.md` and `docs/18_Restaurant_App_Full_Scope_And_Rating_System.md`
both stated `menu_item_addon_groups` **already exists** in the schema.
This is wrong — verified by grepping the actual schema before writing
any code. `migration 11`'s own kdoc explicitly flags it
("NOTE: this migration does NOT add addon groups... still open") and
the Customer App's `ItemDetailBottomSheetFragment` kdoc says the same
(flat checkbox list, no grouping/cap, no `menu_item_addon_groups` type
anywhere in the Customer App either). So this session had to design and
build real new schema, not just a UI layer on an existing table —
bigger than "medium" as originally sized in `today.md`.

## What was built (backend — complete)

- `backend/sql/57_migration_addon_groups.sql` (new) — `menu_item_addon_groups`
  table (`menu_item_id`, `name`, `min_select`, `max_select`, `is_required`,
  `sort_order`, `is_active`) + a new nullable `addon_group_id` FK column
  on `menu_item_addons`. Same idempotent `DELIMITER $$ ... CONTINUE
  HANDLER` rerun-safe pattern as migration 56. **Backward-compatible by
  design**: every existing addon keeps `addon_group_id = NULL` and keeps
  behaving exactly as before (flat checkbox, no cap) everywhere it's
  currently read (`restaurants/menu.php`, `lib/orders.php`, Customer
  App's cart/order flow) — nothing about existing addons changes unless
  a restaurant explicitly assigns them to a new group.
- `backend/lib/menu_item_addon_groups.php` (new) — shared helpers used
  by every endpoint below: `require_owned_menu_item()` /
  `require_owned_addon_group()` / `require_owned_addon()` (all resolve
  ownership by joining back to `menu_items.restaurant_id`, same
  defense-in-depth as `menu-items-update.php`'s `category_id` check),
  `validate_addon_group_selection_rules()` (min/max-select validation,
  `is_required` implicitly floors `min_select` at 1), `get_addon_groups_for_item()`
  + `serialize_addon()` (shared read/serialize path).
- `backend/api/v1/restaurant/addon-groups-list.php` (new) — GET
  `?item_id=`, returns `{ groups: [...with nested addons], ungrouped_addons: [...] }`.
- `backend/api/v1/restaurant/addon-groups-create.php` (new) — POST
  `{item_id, name, min_select?, max_select?, is_required?}`.
- `backend/api/v1/restaurant/addon-groups-update.php` (new) — POST
  `?id=`, partial update, same dynamic-SET pattern as `profile-update.php`.
- `backend/api/v1/restaurant/addon-groups-delete.php` (new) — POST
  `?id=`, soft-disable (`is_active = 0`), cascades the same soft-disable
  to every addon still inside the group.
- `backend/api/v1/restaurant/addons-create.php` (new) — POST
  `{item_id, addon_group_id?, name, price?}`. `addon_group_id` omitted/null
  = ungrouped (flat) addon, same behavior every addon already had.
- `backend/api/v1/restaurant/addons-update.php` (new) — POST `?id=`,
  partial update; `is_active: false` doubles as this addon's own
  remove/restore action (no separate `addons-delete.php` — mirrors how
  out-of-stock already works on `menu_items`, a plain field flip).

All seven new/edited backend files manually brace/paren-balance checked
(no mismatches). **`php -l` not run — no PHP in this sandbox**, same
standing gap as every other backend change this session.

## What was built (Android — partial, NOT feature-complete)

Done:
- `network/Models.kt` — `Addon`, `AddonGroup`, `AddonGroupsListResult`,
  `AddonGroupResult`, `AddonResult`, `AddonGroupCreateBody`,
  `AddonGroupUpdateBody`, `AddonCreateBody`, `AddonUpdateBody`.
- `network/ApiService.kt` — `getAddonGroups`, `createAddonGroup`,
  `updateAddonGroup`, `deleteAddonGroup`, `createAddon`, `updateAddon`.
- `res/layout/item_addon_group.xml` (new) — group card: name, edit/delete
  icons, rules text ("Required · Pick 1"), a plain `LinearLayout`
  (`addonsContainer`) that addon rows get inflated into directly (NOT a
  nested RecyclerView — see the file's own kdoc for why), "+ Add add-on"
  row.
- `res/layout/item_addon_row.xml` (new) — one addon row (name, price,
  edit/remove icons), reused both inside a group card and for the
  "Other add-ons" (ungrouped) section.
- `res/layout/dialog_add_addon_group.xml` (new) — name, Required switch,
  max-select number field. **`min_select` is deliberately not exposed in
  this dialog** — the backend derives it from the Required switch
  (required ⇒ floor of 1) rather than asking the owner to reason about
  two numbers; a real min/max-range picker wasn't in this pass's ask.
- `res/layout/dialog_add_addon.xml` (new) — name + price, used for both
  a grouped and an ungrouped addon.
- `ui/menu/AddonGroupAdapter.kt` (new) — `AddonGroupUi` data class
  (`groupId: Int? = null` means the synthetic "Other add-ons" section,
  not a real DB row), binds group cards + inflates addon rows.
- `res/values/strings.xml` — every string the above layouts/adapter
  reference (`addon_groups_title`, `btn_add_addon_group`,
  `hint_addon_group_name`, `label_addon_group_required`,
  `addon_price_prefix`, etc.) — added so this partial work is at least
  internally consistent/compile-ready, not left referencing missing
  resources.

All new/edited XML validated well-formed; `AddonGroupAdapter.kt` /
`Models.kt` / `ApiService.kt` brace/paren-balance checked.

**NOT done — this is the real gap, pick up here next session:**
- [ ] **`AddonGroupsActivity.kt` does not exist yet.** This is the
      actual screen — loads `addon-groups-list.php` for the item,
      builds a `List<AddonGroupUi>` (one per real group + one synthetic
      "Other add-ons" section from `ungroupedAddons`, always appended
      last regardless of whether it's empty, so there's always a way to
      add a flat addon), feeds `AddonGroupAdapter`, and wires:
      - "+ Add Group" (header action, `btnAction` slot — reuse
        `activity_notification_list.xml` as the shell, exact same
        "generalizable shell" reuse `ReviewListActivity.kt` already did;
        set `screenTitle` = item name, show `btnAction` with `ic_add`
        instead of hiding it).
      - Group edit/delete (`onEditGroup`/`onDeleteGroup` callbacks) —
        edit reuses `dialog_add_addon_group.xml` pre-filled; delete
        should confirm first (`addon_group_delete_confirm_title`/
        `_message` strings already added, unused so far).
      - Addon add/edit/remove (`onAddAddon`/`onEditAddon`/`onRemoveAddon`)
        — reuses `dialog_add_addon.xml`; remove calls `updateAddon` with
        `isActive = false` (confirm dialog first, `addon_remove_confirm_title`
        already added).
      - Empty state (`empty_addon_groups` string already added).
- [ ] **`MenuFragment.kt`'s `showItemDialog()`** needs a new "Manage
      Add-ons" row — visible **only when `existingItem != null`** (a
      brand-new item has no id yet to attach groups to; today.md
      already flagged this exact constraint back in the original §7
      investigation notes for a different feature, same reasoning
      applies here). Needs a matching row in
      `dialog_add_menu_item.xml` (`btn_manage_addons` string already
      added, unused so far) that launches
      `AddonGroupsActivity` with the item's id + name.
- [ ] **`AndroidManifest.xml`** — `AddonGroupsActivity` isn't registered
      yet. Follow the exact same block shape as `ReviewListActivity`'s
      entry (`android:exported="false"`,
      `android:windowSoftInputMode="adjustResize"` since this screen has
      text-input dialogs).
- [ ] A real build/run pass once the above exists — this session only
      got as far as manual balance/well-formedness checks, no compiler,
      no emulator in this sandbox.
- [ ] `php -l` on all seven new backend files + a live restaurant-app
      test (create a group, add addons to it, edit/delete, confirm an
      ungrouped addon still round-trips) — same standing sandbox gap.

## Explicitly out of scope (do not build unless separately asked)

The Customer App's `ItemDetailBottomSheetFragment` does **not** honor
`min_select`/`max_select`/`is_required` — it still renders every addon
as a flat checkbox regardless of which group (if any) it belongs to.
A restaurant can create a "pick exactly 1" group via this feature once
finished, but nothing in the Customer App enforces that at checkout
today. This was true before this session (migration 11's original
flag) and stays true after — this session's ask (doc 18's exact
wording: "restaurant-side UI to create/edit these groups") was
restaurant-side only. Making the Customer App group-aware is a separate,
likely medium-to-large, future item.

## Files touched this session (this part only)

- `backend/sql/57_migration_addon_groups.sql` (new)
- `backend/lib/menu_item_addon_groups.php` (new)
- `backend/api/v1/restaurant/addon-groups-list.php` (new)
- `backend/api/v1/restaurant/addon-groups-create.php` (new)
- `backend/api/v1/restaurant/addon-groups-update.php` (new)
- `backend/api/v1/restaurant/addon-groups-delete.php` (new)
- `backend/api/v1/restaurant/addons-create.php` (new)
- `backend/api/v1/restaurant/addons-update.php` (new)
- `restaurant/app/src/main/java/com/anydrop/restaurant/network/Models.kt`
- `restaurant/app/src/main/java/com/anydrop/restaurant/network/ApiService.kt`
- `restaurant/app/src/main/res/layout/item_addon_group.xml` (new)
- `restaurant/app/src/main/res/layout/item_addon_row.xml` (new)
- `restaurant/app/src/main/res/layout/dialog_add_addon_group.xml` (new)
- `restaurant/app/src/main/res/layout/dialog_add_addon.xml` (new)
- `restaurant/app/src/main/java/com/anydrop/restaurant/ui/menu/AddonGroupAdapter.kt` (new)
- `restaurant/app/src/main/res/values/strings.xml`
- `today.md` (§1 marked 🟡 in-progress, with the exact next-step list)
- `docs/58_Handover_2026-08-28_AddonGroups_InProgress.md` (this file)
