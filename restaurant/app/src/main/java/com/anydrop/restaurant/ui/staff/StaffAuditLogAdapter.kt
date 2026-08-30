package com.anydrop.restaurant.ui.staff

import android.view.LayoutInflater
import android.view.ViewGroup
import androidx.recyclerview.widget.RecyclerView
import com.anydrop.restaurant.R
import com.anydrop.restaurant.databinding.ItemAuditLogBinding
import com.anydrop.restaurant.network.StaffAuditLogEntry
import java.text.SimpleDateFormat
import java.util.Locale
import java.util.TimeZone

/**
 * StaffAuditLogActivity's RecyclerView row (migration 64, PENDING.md
 * §7's last checkbox). Read-only — no edit/delete, same
 * no-pagination reasoning as ClosureAdapter (an owner's own staff
 * activity history is realistically small; the backend itself caps
 * at 200 rows, see staff-audit-list.php).
 */
class StaffAuditLogAdapter : RecyclerView.Adapter<StaffAuditLogAdapter.ViewHolder>() {

    private val items = mutableListOf<StaffAuditLogEntry>()

    fun submit(newItems: List<StaffAuditLogEntry>) {
        items.clear()
        items.addAll(newItems)
        notifyDataSetChanged()
    }

    inner class ViewHolder(val binding: ItemAuditLogBinding) : RecyclerView.ViewHolder(binding.root)

    override fun onCreateViewHolder(parent: ViewGroup, viewType: Int): ViewHolder {
        val binding = ItemAuditLogBinding.inflate(LayoutInflater.from(parent.context), parent, false)
        return ViewHolder(binding)
    }

    override fun onBindViewHolder(holder: ViewHolder, position: Int) {
        val entry = items[position]
        val context = holder.binding.root.context
        val name = (entry.details["name"] as? String) ?: (entry.details["username"] as? String)

        holder.binding.auditActionText.text = when (entry.action) {
            "staff_created" -> {
                val role = (entry.details["role"] as? String)?.replaceFirstChar { it.uppercase() }
                context.getString(R.string.audit_staff_created, name ?: "", role ?: "")
            }
            "staff_role_changed" -> {
                val oldRole = (entry.details["old_role"] as? String)?.replaceFirstChar { it.uppercase() }
                val newRole = (entry.details["new_role"] as? String)?.replaceFirstChar { it.uppercase() }
                context.getString(R.string.audit_staff_role_changed, name ?: "", oldRole ?: "", newRole ?: "")
            }
            "staff_activated" -> context.getString(R.string.audit_staff_activated, name ?: "")
            "staff_deactivated" -> context.getString(R.string.audit_staff_deactivated, name ?: "")
            "staff_deleted" -> context.getString(R.string.audit_staff_deleted, name ?: "")
            "staff_updated" -> context.getString(R.string.audit_staff_updated, name ?: "")
            else -> entry.action // unrecognized future action — show the raw key rather than hiding the row
        }

        holder.binding.auditByText.text = if (entry.actingRole == "owner") {
            context.getString(R.string.audit_by_owner)
        } else {
            val roleLabel = entry.actingRole.replaceFirstChar { it.uppercase() }
            context.getString(R.string.audit_by_staff, roleLabel)
        }

        holder.binding.auditTimeText.text = formatDisplayTime(entry.createdAt)
    }

    override fun getItemCount(): Int = items.size

    /** audit_logs.created_at comes back as a MySQL TIMESTAMP string
     * ("yyyy-MM-dd HH:mm:ss", server-local same as every other
     * timestamp this app already displays, e.g. ClosureAdapter's own
     * date parsing) — falls back to the raw value rather than
     * crashing on an unexpected format. */
    private fun formatDisplayTime(value: String?): String {
        if (value.isNullOrBlank()) return ""
        return try {
            val wire = SimpleDateFormat("yyyy-MM-dd HH:mm:ss", Locale.US)
            val display = SimpleDateFormat("d MMM yyyy, h:mm a", Locale.getDefault())
            display.format(wire.parse(value)!!)
        } catch (e: Exception) {
            value
        }
    }
}
