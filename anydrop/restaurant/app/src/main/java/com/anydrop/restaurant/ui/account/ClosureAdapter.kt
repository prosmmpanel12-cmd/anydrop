package com.anydrop.restaurant.ui.account

import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import androidx.recyclerview.widget.RecyclerView
import com.anydrop.restaurant.R
import com.anydrop.restaurant.databinding.ItemClosureBinding
import com.anydrop.restaurant.network.Closure
import java.text.SimpleDateFormat
import java.util.Locale

/**
 * ClosureScheduleActivity's RecyclerView row (§3, today.md 2026-08-28,
 * doc 60/61). No pagination — same "a restaurant's own list is always
 * small" reasoning as AddonGroupAdapter, every mutation re-fetches the
 * whole list rather than patching local state.
 */
class ClosureAdapter(
    private val onEdit: (Closure) -> Unit,
    private val onDelete: (Closure) -> Unit
) : RecyclerView.Adapter<ClosureAdapter.ViewHolder>() {

    private val items = mutableListOf<Closure>()

    fun submit(newItems: List<Closure>) {
        items.clear()
        items.addAll(newItems)
        notifyDataSetChanged()
    }

    inner class ViewHolder(val binding: ItemClosureBinding) : RecyclerView.ViewHolder(binding.root)

    override fun onCreateViewHolder(parent: ViewGroup, viewType: Int): ViewHolder {
        val binding = ItemClosureBinding.inflate(LayoutInflater.from(parent.context), parent, false)
        return ViewHolder(binding)
    }

    override fun onBindViewHolder(holder: ViewHolder, position: Int) {
        val closure = items[position]
        val context = holder.binding.root.context

        holder.binding.closureDateText.text = if (closure.closureType == "weekly_recurring") {
            val dayNames = context.resources.getStringArray(R.array.day_names_full)
            // day_of_week is 1(Mon)..7(Sun); dayNames is 0-indexed Mon..Sun.
            val label = closure.dayOfWeek?.let { dayNames.getOrNull(it - 1) } ?: ""
            context.getString(R.string.closure_summary_weekly, label)
        } else {
            context.getString(
                R.string.closure_summary_date_range,
                formatDisplayDate(closure.startDate),
                formatDisplayDate(closure.endDate)
            )
        }

        val reason = closure.reason
        if (reason.isNullOrBlank()) {
            holder.binding.closureReasonText.visibility = View.GONE
        } else {
            holder.binding.closureReasonText.visibility = View.VISIBLE
            holder.binding.closureReasonText.text = reason
        }

        holder.binding.btnEditClosure.setOnClickListener { onEdit(closure) }
        holder.binding.btnDeleteClosure.setOnClickListener { onDelete(closure) }
    }

    override fun getItemCount(): Int = items.size

    private fun formatDisplayDate(value: String?): String {
        if (value.isNullOrBlank()) return ""
        return try {
            val wire = SimpleDateFormat("yyyy-MM-dd", Locale.US)
            val display = SimpleDateFormat("d MMM yyyy", Locale.getDefault())
            display.format(wire.parse(value)!!)
        } catch (e: Exception) {
            value // fall back to the raw value rather than crashing on an unexpected format
        }
    }
}
