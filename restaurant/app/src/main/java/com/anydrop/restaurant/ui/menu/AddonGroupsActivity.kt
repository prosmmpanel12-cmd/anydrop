package com.anydrop.restaurant.ui.menu

import android.os.Bundle
import android.view.View
import androidx.appcompat.app.AppCompatActivity
import androidx.lifecycle.lifecycleScope
import androidx.recyclerview.widget.LinearLayoutManager
import com.anydrop.restaurant.R
import com.anydrop.restaurant.databinding.ActivityNotificationListBinding
import com.anydrop.restaurant.databinding.DialogAddAddonBinding
import com.anydrop.restaurant.databinding.DialogAddAddonGroupBinding
import com.anydrop.restaurant.databinding.DialogConfirmDeleteBinding
import com.anydrop.restaurant.network.Addon
import com.anydrop.restaurant.network.AddonCreateBody
import com.anydrop.restaurant.network.AddonGroup
import com.anydrop.restaurant.network.AddonGroupCreateBody
import com.anydrop.restaurant.network.AddonGroupUpdateBody
import com.anydrop.restaurant.network.AddonUpdateBody
import com.anydrop.restaurant.network.ApiClient
import com.anydrop.restaurant.ui.common.InAppNotifier
import com.google.android.material.dialog.MaterialAlertDialogBuilder
import kotlinx.coroutines.launch

/**
 * Restaurant-side add-on group management screen (§1, today.md
 * 2026-08-28; doc 58 — this was the missing piece, everything else that
 * session built was groundwork for this activity). Launched from
 * MenuFragment's "Manage Add-ons" row (existing items only) with
 * [EXTRA_ITEM_ID]/[EXTRA_ITEM_NAME].
 *
 * Reuses activity_notification_list.xml as its shell — same
 * "generalizable shell" reuse ReviewListActivity already did.
 * screenTitle is set to the item's name; unlike ReviewListActivity
 * (which hides btnAction), this screen shows it as "+ Add Group"
 * (ic_add instead of the default ic_check_circle).
 *
 * No pagination/infinite-scroll — a restaurant's own add-on list for a
 * single item is always small (a handful of groups, a handful of addons
 * each — see AddonGroupAdapter's kdoc), so [loadGroups] just fetches
 * everything in one call and every mutation (add/edit/delete a group or
 * addon) re-fetches the whole list rather than patching local state.
 * Simplest-correct choice for a management screen a restaurant owner
 * opens occasionally, not a hot-path list.
 */
class AddonGroupsActivity : AppCompatActivity() {

    companion object {
        const val EXTRA_ITEM_ID = "extra_item_id"
        const val EXTRA_ITEM_NAME = "extra_item_name"
    }

    private lateinit var binding: ActivityNotificationListBinding
    private val api by lazy { ApiClient.create(this) }
    private lateinit var adapter: AddonGroupAdapter
    private var itemId: Int = 0

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityNotificationListBinding.inflate(layoutInflater)
        setContentView(binding.root)

        itemId = intent.getIntExtra(EXTRA_ITEM_ID, 0)
        if (itemId == 0) {
            // Shouldn't happen — MenuFragment only shows the "Manage
            // Add-ons" row for an existingItem, which always has an id.
            finish()
            return
        }
        val itemName = intent.getStringExtra(EXTRA_ITEM_NAME)

        binding.screenTitle.text = if (!itemName.isNullOrBlank()) itemName else getString(R.string.addon_groups_title)
        binding.btnBack.setOnClickListener { finish() }
        binding.btnAction.setImageResource(R.drawable.ic_add)
        binding.btnAction.visibility = View.VISIBLE
        binding.btnAction.setOnClickListener { showAddonGroupDialog(existing = null) }

        adapter = AddonGroupAdapter(
            onEditGroup = { showAddonGroupDialog(existing = it) },
            onDeleteGroup = { confirmDeleteGroup(it) },
            onAddAddon = { groupId -> showAddonDialog(groupId = groupId, existing = null) },
            onEditAddon = { addon -> showAddonDialog(groupId = addon.addonGroupId, existing = addon) },
            onRemoveAddon = { addon -> confirmRemoveAddon(addon) }
        )
        binding.contentList.layoutManager = LinearLayoutManager(this)
        binding.contentList.adapter = adapter

        binding.emptyStateText.text = getString(R.string.empty_addon_groups)
        binding.swipeRefresh.setOnRefreshListener { loadGroups() }

        loadGroups()
    }

    private fun loadGroups() {
        binding.swipeRefresh.isRefreshing = true
        lifecycleScope.launch {
            try {
                val result = api.getAddonGroups(itemId).body()?.data
                val groups = result?.groups.orEmpty()
                val ungrouped = result?.ungroupedAddons.orEmpty()
                adapter.submit(buildSections(groups, ungrouped))

                val isEmpty = groups.isEmpty() && ungrouped.isEmpty()
                binding.emptyState.visibility = if (isEmpty) View.VISIBLE else View.GONE
                binding.contentList.visibility = if (isEmpty) View.GONE else View.VISIBLE
            } catch (e: Exception) {
                InAppNotifier.show(this@AddonGroupsActivity, getString(R.string.addon_groups_load_failed), InAppNotifier.Type.ERROR)
            } finally {
                binding.swipeRefresh.isRefreshing = false
            }
        }
    }

    /** One [AddonGroupUi] per real group, plus a synthetic "Other
     * add-ons" section built from [ungrouped] and always appended last
     * — even when empty — so there's always a "+ Add add-on" entry
     * point for a flat, group-less addon (doc 58 / AddonGroupUi's
     * kdoc). */
    private fun buildSections(groups: List<AddonGroup>, ungrouped: List<Addon>): List<AddonGroupUi> {
        val groupSections = groups.map { g ->
            AddonGroupUi(
                groupId = g.id,
                name = g.name,
                minSelect = g.minSelect,
                maxSelect = g.maxSelect,
                isRequired = g.isRequired,
                addons = g.addons
            )
        }
        val otherSection = AddonGroupUi(
            groupId = null,
            name = getString(R.string.other_addons_section_title),
            minSelect = 0,
            maxSelect = 0,
            isRequired = false,
            addons = ungrouped
        )
        return groupSections + otherSection
    }

    /** min_select isn't exposed here on purpose — see
     * dialog_add_addon_group.xml's kdoc, the backend derives it from
     * the Required switch. */
    private fun showAddonGroupDialog(existing: AddonGroupUi?) {
        val dialogBinding = DialogAddAddonGroupBinding.inflate(layoutInflater)
        dialogBinding.inputGroupName.setText(existing?.name ?: "")
        dialogBinding.switchGroupRequired.isChecked = existing?.isRequired ?: false
        dialogBinding.inputGroupMaxSelect.setText(existing?.maxSelect?.takeIf { it > 0 }?.toString() ?: "")

        MaterialAlertDialogBuilder(this)
            .setTitle(if (existing == null) R.string.dialog_add_addon_group_title else R.string.dialog_edit_addon_group_title)
            .setView(dialogBinding.root)
            .setPositiveButton(R.string.btn_save) { _, _ ->
                val name = dialogBinding.inputGroupName.text?.toString()?.trim().orEmpty()
                if (name.isEmpty()) {
                    InAppNotifier.show(this, getString(R.string.addon_group_save_failed), InAppNotifier.Type.ERROR)
                    return@setPositiveButton
                }
                val isRequired = dialogBinding.switchGroupRequired.isChecked
                val maxSelect = dialogBinding.inputGroupMaxSelect.text?.toString()?.trim()?.toIntOrNull()
                saveAddonGroup(existing, name, isRequired, maxSelect)
            }
            .setNegativeButton(R.string.btn_cancel, null)
            .show()
    }

    private fun saveAddonGroup(existing: AddonGroupUi?, name: String, isRequired: Boolean, maxSelect: Int?) {
        lifecycleScope.launch {
            try {
                val ok = if (existing == null) {
                    api.createAddonGroup(
                        AddonGroupCreateBody(itemId = itemId, name = name, maxSelect = maxSelect, isRequired = isRequired)
                    ).isSuccessful
                } else {
                    api.updateAddonGroup(
                        existing.groupId!!,
                        AddonGroupUpdateBody(name = name, maxSelect = maxSelect, isRequired = isRequired)
                    ).isSuccessful
                }
                if (ok) {
                    InAppNotifier.show(this@AddonGroupsActivity, getString(R.string.addon_group_saved), InAppNotifier.Type.SUCCESS)
                    loadGroups()
                } else {
                    InAppNotifier.show(this@AddonGroupsActivity, getString(R.string.addon_group_save_failed), InAppNotifier.Type.ERROR)
                }
            } catch (e: Exception) {
                InAppNotifier.show(this@AddonGroupsActivity, getString(R.string.addon_group_save_failed), InAppNotifier.Type.ERROR)
            }
        }
    }

    /** Reuses dialog_confirm_delete.xml, same as MenuFragment's
     * confirmDeleteItem/confirmDeleteCategory — title/message set per
     * call site rather than a second near-identical layout. */
    private fun confirmDeleteGroup(group: AddonGroupUi) {
        val groupId = group.groupId ?: return
        val dialogBinding = DialogConfirmDeleteBinding.inflate(layoutInflater)
        dialogBinding.deleteDialogTitle.text = getString(R.string.addon_group_delete_confirm_title)
        dialogBinding.deleteDialogMessage.text = getString(R.string.addon_group_delete_confirm_message)
        val dialog = MaterialAlertDialogBuilder(this).setView(dialogBinding.root).create()
        dialogBinding.btnDeleteDialogCancel.setOnClickListener { dialog.dismiss() }
        dialogBinding.btnDeleteDialogDelete.setOnClickListener {
            dialog.dismiss()
            deleteAddonGroup(groupId)
        }
        dialog.show()
    }

    /** Soft-disable server-side (addon-groups-delete.php), same
     * out-of-stock-style field-flip convention as everything else in
     * this app — see doc 58. */
    private fun deleteAddonGroup(groupId: Int) {
        lifecycleScope.launch {
            try {
                if (api.deleteAddonGroup(groupId).isSuccessful) {
                    InAppNotifier.show(this@AddonGroupsActivity, getString(R.string.addon_group_deleted), InAppNotifier.Type.SUCCESS)
                    loadGroups()
                } else {
                    InAppNotifier.show(this@AddonGroupsActivity, getString(R.string.addon_group_save_failed), InAppNotifier.Type.ERROR)
                }
            } catch (e: Exception) {
                InAppNotifier.show(this@AddonGroupsActivity, getString(R.string.addon_group_save_failed), InAppNotifier.Type.ERROR)
            }
        }
    }

    /** [groupId] null means this is a flat/ungrouped addon (launched
     * from the "Other add-ons" section's "+ Add add-on" row, or editing
     * an addon whose [Addon.addonGroupId] is already null) — passed
     * straight through to AddonCreateBody unchanged. */
    private fun showAddonDialog(groupId: Int?, existing: Addon?) {
        val dialogBinding = DialogAddAddonBinding.inflate(layoutInflater)
        dialogBinding.inputAddonName.setText(existing?.name ?: "")
        dialogBinding.inputAddonPrice.setText(existing?.price?.let { formatPriceForInput(it) } ?: "")

        MaterialAlertDialogBuilder(this)
            .setTitle(if (existing == null) R.string.dialog_add_addon_title else R.string.dialog_edit_addon_title)
            .setView(dialogBinding.root)
            .setPositiveButton(R.string.btn_save) { _, _ ->
                val name = dialogBinding.inputAddonName.text?.toString()?.trim().orEmpty()
                if (name.isEmpty()) {
                    InAppNotifier.show(this, getString(R.string.addon_save_failed), InAppNotifier.Type.ERROR)
                    return@setPositiveButton
                }
                val price = dialogBinding.inputAddonPrice.text?.toString()?.trim()?.toDoubleOrNull() ?: 0.0
                saveAddon(groupId, existing, name, price)
            }
            .setNegativeButton(R.string.btn_cancel, null)
            .show()
    }

    private fun saveAddon(groupId: Int?, existing: Addon?, name: String, price: Double) {
        lifecycleScope.launch {
            try {
                val ok = if (existing == null) {
                    api.createAddon(
                        AddonCreateBody(itemId = itemId, addonGroupId = groupId, name = name, price = price)
                    ).isSuccessful
                } else {
                    api.updateAddon(existing.id, AddonUpdateBody(name = name, price = price)).isSuccessful
                }
                if (ok) {
                    InAppNotifier.show(this@AddonGroupsActivity, getString(R.string.addon_saved), InAppNotifier.Type.SUCCESS)
                    loadGroups()
                } else {
                    InAppNotifier.show(this@AddonGroupsActivity, getString(R.string.addon_save_failed), InAppNotifier.Type.ERROR)
                }
            } catch (e: Exception) {
                InAppNotifier.show(this@AddonGroupsActivity, getString(R.string.addon_save_failed), InAppNotifier.Type.ERROR)
            }
        }
    }

    private fun confirmRemoveAddon(addon: Addon) {
        val dialogBinding = DialogConfirmDeleteBinding.inflate(layoutInflater)
        dialogBinding.deleteDialogTitle.text = getString(R.string.addon_remove_confirm_title)
        dialogBinding.deleteDialogMessage.text = getString(R.string.addon_remove_confirm_message)
        val dialog = MaterialAlertDialogBuilder(this).setView(dialogBinding.root).create()
        dialogBinding.btnDeleteDialogCancel.setOnClickListener { dialog.dismiss() }
        dialogBinding.btnDeleteDialogDelete.setOnClickListener {
            dialog.dismiss()
            removeAddon(addon)
        }
        dialog.show()
    }

    /** "Remove" doubles as isActive=false — no separate delete
     * endpoint for a single addon, see addons-update.php's kdoc /
     * AddonUpdateBody's kdoc in Models.kt. */
    private fun removeAddon(addon: Addon) {
        lifecycleScope.launch {
            try {
                if (api.updateAddon(addon.id, AddonUpdateBody(isActive = false)).isSuccessful) {
                    InAppNotifier.show(this@AddonGroupsActivity, getString(R.string.addon_removed), InAppNotifier.Type.SUCCESS)
                    loadGroups()
                } else {
                    InAppNotifier.show(this@AddonGroupsActivity, getString(R.string.addon_save_failed), InAppNotifier.Type.ERROR)
                }
            } catch (e: Exception) {
                InAppNotifier.show(this@AddonGroupsActivity, getString(R.string.addon_save_failed), InAppNotifier.Type.ERROR)
            }
        }
    }

    /** Trims a trailing ".0" for a whole-rupee price when pre-filling
     * the edit dialog — same convention as
     * AddonGroupAdapter.formatPrice() / EditProfileActivity's
     * formatAmountForInput(). */
    private fun formatPriceForInput(amount: Double): String {
        return if (amount == amount.toLong().toDouble()) amount.toLong().toString() else amount.toString()
    }
}
