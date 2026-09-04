package com.anydrop.restaurant.ui.staff

import android.view.LayoutInflater
import android.view.ViewGroup
import androidx.recyclerview.widget.RecyclerView
import com.anydrop.restaurant.R
import com.anydrop.restaurant.databinding.ItemStaffBinding
import com.anydrop.restaurant.network.StaffProfile

/**
 * StaffManagementActivity's RecyclerView row (doc 71, migration 63,
 * PENDING.md item 3). Modeled directly on ClosureAdapter.kt — same
 * "no pagination, every mutation re-fetches the whole list" reasoning
 * (a restaurant's own staff roster is always small).
 *
 * The active/inactive switch is wired via [onToggleActive] rather than
 * a plain checked-change listener set once in onBindViewHolder — see
 * that callback's own note on why (submit()'s notifyDataSetChanged
 * rebinds every row, including the one whose PATCH is in flight, and a
 * naive listener would re-fire on that rebind).
 */
class StaffAdapter(
    private val onEdit: (StaffProfile) -> Unit,
    private val onDelete: (StaffProfile) -> Unit,
    private val onToggleActive: (StaffProfile, Boolean) -> Unit
) : RecyclerView.Adapter<StaffAdapter.ViewHolder>() {

    private val items = mutableListOf<StaffProfile>()

    fun submit(newItems: List<StaffProfile>) {
        items.clear()
        items.addAll(newItems)
        notifyDataSetChanged()
    }

    inner class ViewHolder(val binding: ItemStaffBinding) : RecyclerView.ViewHolder(binding.root)

    override fun onCreateViewHolder(parent: ViewGroup, viewType: Int): ViewHolder {
        val binding = ItemStaffBinding.inflate(LayoutInflater.from(parent.context), parent, false)
        return ViewHolder(binding)
    }

    override fun onBindViewHolder(holder: ViewHolder, position: Int) {
        val staff = items[position]
        val context = holder.binding.root.context

        holder.binding.staffNameText.text = staff.name
        holder.binding.staffUsernameRoleText.text = context.getString(
            R.string.staff_username_role_format,
            staff.username,
            roleLabel(context, staff.role)
        )

        // Cleared before the programmatic set below, same
        // guard-against-self-trigger pattern AccountFragment uses for
        // switchTempClosed — notifyDataSetChanged() rebinding this row
        // mid-flight must not re-fire the mutation it's still waiting on.
        holder.binding.staffActiveSwitch.setOnCheckedChangeListener(null)
        holder.binding.staffActiveSwitch.isChecked = staff.isActive
        holder.binding.staffActiveSwitch.setOnCheckedChangeListener { _, isChecked ->
            onToggleActive(staff, isChecked)
        }

        holder.binding.btnEditStaff.setOnClickListener { onEdit(staff) }
        holder.binding.btnDeleteStaff.setOnClickListener { onDelete(staff) }
    }

    override fun getItemCount(): Int = items.size

    private fun roleLabel(context: android.content.Context, role: String): String = when (role) {
        "manager" -> context.getString(R.string.staff_role_manager)
        "kitchen" -> context.getString(R.string.staff_role_kitchen)
        "cashier" -> context.getString(R.string.staff_role_cashier)
        else -> role
    }
}
