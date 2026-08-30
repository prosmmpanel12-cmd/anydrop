# Handover — 2026-08-28 (cont'd, part 4): Add-on Group UI — COMPLETE

## What was asked

Continue exactly where doc 58 left off: the four "NOT done" items for
§1 Item Add-on Group UI.

## What was built this session

- **`restaurant/app/src/main/java/com/anydrop/restaurant/ui/menu/AddonGroupsActivity.kt`
  (new)** — the screen itself. Reuses `activity_notification_list.xml`
  as its shell (same reuse `ReviewListActivity` already established),
  with `screenTitle` set to the item's name and `btnAction` shown as
  "+ Add Group" (`ic_add`) rather than hidden.
  - `loadGroups()` calls `addon-groups-list.php`, builds a
    `List<AddonGroupUi>` via `buildSections()` — one per real group,
    plus a synthetic "Other add-ons" section from `ungroupedAddons`
    always appended last (even empty), feeds `AddonGroupAdapter`.
  - No pagination — a single item's add-on list is always small (see
    `AddonGroupAdapter`'s own kdoc), so every mutation just re-calls
    `loadGroups()` rather than patching local state.
  - Group add/edit (`showAddonGroupDialog`) — plain
    `MaterialAlertDialogBuilder` + `dialog_add_addon_group.xml`, same
    shape as `MenuFragment`'s category-add dialog (`setPositiveButton`
    with inline validation, not a custom show-listener override —
    matched that exact existing convention rather than introducing a
    new one).
  - Group delete (`confirmDeleteGroup`) — reuses
    `dialog_confirm_delete.xml` / `DialogConfirmDeleteBinding`, same
    pattern as `MenuFragment.confirmDeleteItem()`.
  - Addon add/edit (`showAddonDialog`) — `dialog_add_addon.xml`, same
    dialog shape, used for both a grouped addon (`groupId` non-null,
    passed in from `onAddAddonToGroup`) and an ungrouped one (`groupId`
    null, from the "Other add-ons" section's own add row).
  - Addon remove (`confirmRemoveAddon` → `removeAddon`) — confirms via
    the same `dialog_confirm_delete.xml`, then sends
    `AddonUpdateBody(isActive = false)` — no separate delete endpoint,
    matches `addons-update.php`'s design from doc 58.
- **`MenuFragment.kt`'s `showItemDialog()`** — added a "Manage Add-ons"
  row wire-up right after `loadFoodTagsIntoDialog()`. Visible only when
  `existingItem != null`; launches `AddonGroupsActivity` with
  `EXTRA_ITEM_ID`/`EXTRA_ITEM_NAME`. Added the `Intent` import (wasn't
  needed in this file before).
- **`dialog_add_menu_item.xml`** — new `rowManageAddons` row (GONE by
  default, shown from Kotlin), placed after the tags section and before
  the Save/Cancel button row. Same click-row visual shape as
  `activity_signup.xml`'s `rowSetLocation` (icon + text in a
  `bg_input_outline` box), using the already-added `ic_tag` icon and
  `btn_manage_addons` string (both sat unused since doc 58).
- **`AndroidManifest.xml`** — registered `AddonGroupsActivity`, same
  block shape as `ReviewListActivity`'s entry (`exported="false"`,
  `windowSoftInputMode="adjustResize"` — this screen's dialogs have
  text inputs).
- **`res/values/strings.xml`** — added the handful of strings doc 58
  didn't need yet: `addon_remove_confirm_message`, `addon_group_saved`,
  `addon_group_deleted`, `addon_saved`, `addon_removed`,
  `addon_groups_load_failed`. (`addon_group_delete_confirm_message`,
  `addon_remove_confirm_title`, `empty_addon_groups`, etc. were already
  added in doc 58 and are now actually wired in.)

All new/edited Kotlin brace/paren-balance checked; all new/edited XML
validated well-formed (Python `xml.etree`). No compiler, no emulator,
no PHP, no network in this sandbox — same standing gaps as every prior
session.

## What's still open (unchanged from doc 58, standing sandbox gaps)

- [ ] Real Android build/run pass — this and the prior session only got
      as far as manual balance/well-formedness checks.
- [ ] `php -l` on all seven backend files from doc 58
      (`57_migration_addon_groups.sql`,
      `lib/menu_item_addon_groups.php`, and the six
      `addon-groups-*`/`addons-*` endpoints) + a live restaurant-app
      test: create a group, add addons to it, edit/delete a group,
      confirm an ungrouped addon still round-trips unaffected.
- [ ] Customer App `ItemDetailBottomSheetFragment` still doesn't honor
      `min_select`/`max_select`/`is_required` — unchanged, still
      explicitly out of scope (doc 18's exact ask was restaurant-side
      creation only). Separate future item.

## Files touched this session (this part only)

- `restaurant/app/src/main/java/com/anydrop/restaurant/ui/menu/AddonGroupsActivity.kt` (new)
- `restaurant/app/src/main/java/com/anydrop/restaurant/ui/menu/MenuFragment.kt`
- `restaurant/app/src/main/res/layout/dialog_add_menu_item.xml`
- `restaurant/app/src/main/AndroidManifest.xml`
- `restaurant/app/src/main/res/values/strings.xml`
- `today.md` (§1 marked ✅ done, priority list updated)
- `docs/59_Handover_2026-08-28_AddonGroups_Completed.md` (this file)

## Suggested next session

Per `today.md`'s priority list: **Temp Closure/Holiday full scheduling**
(§3) next, then **Bank Details form** (§3). Both medium-sized, neither
touched yet.
