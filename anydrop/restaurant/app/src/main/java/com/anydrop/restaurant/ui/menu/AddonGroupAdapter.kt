package com.anydrop.restaurant.ui.menu

import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import androidx.recyclerview.widget.RecyclerView
import com.anydrop.restaurant.R
import com.anydrop.restaurant.databinding.ItemAddonGroupBinding
import com.anydrop.restaurant.databinding.ItemAddonRowBinding
import com.anydrop.restaurant.network.Addon

/**
 * One row/section for AddonGroupsActivity's RecyclerView (§1, today.md
 * 2026-08-28). [groupId] null means "the ungrouped/Other add-ons
 * section" — a synthetic section built client-side from
 * AddonGroupsListResult.ungroupedAddons, not a real
 * menu_item_addon_groups row, so it has no edit/delete/rules of its own
 * (see AddonGroupsActivity.buildSections()).
 */
data class AddonGroupUi(
    val groupId: Int?,
    val name: String,
    val minSelect: Int,
    val maxSelect: Int,
    val isRequired: Boolean,
    val addons: List<Addon>
)

/**
 * Addon rows inside each group card are inflated directly into a plain
 * LinearLayout ([ItemAddonGroupBinding.addonsContainer]), not a nested
 * RecyclerView — see item_addon_group.xml's kdoc for why. That means
 * this adapter does its own child-view diffing on every bind (clear +
 * re-inflate), which is fine at the scale a restaurant's own add-on list
 * actually runs at (a handful of groups, a handful of addons each) —
 * this is a management screen a restaurant owner opens occasionally,
 * not a hot-path list.
 */
class AddonGroupAdapter(
    private val onEditGroup: (AddonGroupUi) -> Unit,
    private val onDeleteGroup: (AddonGroupUi) -> Unit,
    private val onAddAddon: (groupId: Int?) -> Unit,
    private val onEditAddon: (Addon) -> Unit,
    private val onRemoveAddon: (Addon) -> Unit
) : RecyclerView.Adapter<AddonGroupAdapter.ViewHolder>() {

    private val sections = mutableListOf<AddonGroupUi>()

    fun submit(newSections: List<AddonGroupUi>) {
        sections.clear()
        sections.addAll(newSections)
        notifyDataSetChanged()
    }

    inner class ViewHolder(val binding: ItemAddonGroupBinding) : RecyclerView.ViewHolder(binding.root)

    override fun onCreateViewHolder(parent: ViewGroup, viewType: Int): ViewHolder {
        val binding = ItemAddonGroupBinding.inflate(LayoutInflater.from(parent.context), parent, false)
        return ViewHolder(binding)
    }

    override fun onBindViewHolder(holder: ViewHolder, position: Int) {
        val section = sections[position]
        val binding = holder.binding
        val context = binding.root.context

        binding.groupName.text = section.name

        val isUngrouped = section.groupId == null
        binding.btnEditGroup.visibility = if (isUngrouped) View.GONE else View.VISIBLE
        binding.btnDeleteGroup.visibility = if (isUngrouped) View.GONE else View.VISIBLE
        if (isUngrouped) {
            binding.groupRulesText.visibility = View.GONE
        } else {
            binding.groupRulesText.visibility = View.VISIBLE
            binding.groupRulesText.text = formatRulesText(context, section)
        }

        binding.btnEditGroup.setOnClickListener { onEditGroup(section) }
        binding.btnDeleteGroup.setOnClickListener { onDeleteGroup(section) }
        binding.btnAddAddonToGroup.setOnClickListener { onAddAddon(section.groupId) }

        binding.addonsContainer.removeAllViews()
        section.addons.forEach { addon ->
            val rowBinding = ItemAddonRowBinding.inflate(
                LayoutInflater.from(context), binding.addonsContainer, false
            )
            rowBinding.addonName.text = addon.name
            rowBinding.addonPrice.text = context.getString(R.string.addon_price_prefix, formatPrice(addon.price))
            rowBinding.btnEditAddon.setOnClickListener { onEditAddon(addon) }
            rowBinding.btnRemoveAddon.setOnClickListener { onRemoveAddon(addon) }
            binding.addonsContainer.addView(rowBinding.root)
        }
    }

    override fun getItemCount(): Int = sections.size

    private fun formatRulesText(context: android.content.Context, section: AddonGroupUi): String {
        val requiredLabel = if (section.isRequired) {
            context.getString(R.string.label_addon_group_required_badge)
        } else {
            context.getString(R.string.label_addon_group_optional_badge)
        }
        val pickLabel = if (section.maxSelect <= 1) {
            context.getString(R.string.label_addon_group_pick_one)
        } else {
            context.getString(R.string.label_addon_group_pick_up_to, section.maxSelect)
        }
        return "$requiredLabel · $pickLabel"
    }

    /** Trims a trailing ".0" for a whole-rupee price, same convention as
     * EditProfileActivity.formatAmountForInput(). */
    private fun formatPrice(amount: Double): String {
        return if (amount == amount.toLong().toDouble()) {
            amount.toLong().toString()
        } else {
            amount.toString()
        }
    }
}
