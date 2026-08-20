package com.anydrop.restaurant.ui.notifications

import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import androidx.recyclerview.widget.RecyclerView
import com.anydrop.restaurant.R
import com.anydrop.restaurant.databinding.ItemNotificationBinding
import com.anydrop.restaurant.network.NotificationItem

/**
 * Notification bell list (Type 1, docs/Status.md 2026-08-20). Restaurant
 * App mirror of the Customer App's adapter of the same name — same
 * submit/appendPage/single-item-patch shape so NotificationListActivity
 * can reuse the identical infinite-scroll pattern OrderAdapter already
 * uses elsewhere in this app.
 */
class NotificationAdapter(
    private val onClick: (NotificationItem) -> Unit
) : RecyclerView.Adapter<NotificationAdapter.VH>() {

    private val items = mutableListOf<NotificationItem>()

    fun submit(list: List<NotificationItem>) {
        items.clear()
        items.addAll(list)
        notifyDataSetChanged()
    }

    fun appendPage(list: List<NotificationItem>) {
        val startIndex = items.size
        items.addAll(list)
        notifyItemRangeInserted(startIndex, list.size)
    }

    /** After a row is tapped, flip it to read locally without a full
     * reload. */
    fun markRead(id: Int) {
        val index = items.indexOfFirst { it.id == id }
        if (index == -1 || items[index].isRead) return
        items[index] = items[index].copy(isRead = true)
        notifyItemChanged(index)
    }

    override fun onCreateViewHolder(parent: ViewGroup, viewType: Int): VH {
        val binding = ItemNotificationBinding.inflate(
            LayoutInflater.from(parent.context), parent, false
        )
        return VH(binding)
    }

    override fun onBindViewHolder(holder: VH, position: Int) = holder.bind(items[position])

    override fun getItemCount() = items.size

    inner class VH(private val binding: ItemNotificationBinding) : RecyclerView.ViewHolder(binding.root) {
        fun bind(item: NotificationItem) {
            binding.notificationTitle.text = item.title
            if (item.body.isNullOrBlank()) {
                binding.notificationBody.visibility = View.GONE
            } else {
                binding.notificationBody.visibility = View.VISIBLE
                binding.notificationBody.text = item.body
            }
            binding.notificationTime.text = formatTimestamp(item.createdAt)
            binding.notificationIcon.setImageResource(iconFor(item.type))
            binding.unreadDot.visibility = if (item.isRead) View.GONE else View.VISIBLE
            binding.root.alpha = if (item.isRead) 0.7f else 1f
            binding.notificationRow.setOnClickListener { onClick(item) }
        }

        /** Restaurant App has no ic_restaurant/ic_offer_tag drawables (only
         * the Customer App does) — mapped to the closest equivalents that
         * do exist here instead: ic_store for order events (the dominant
         * Type 1 case for this app — new order/accepted/rejected/ready),
         * ic_percent for promo, ic_lock for security, ic_notification as
         * the bell-shaped default/system fallback. */
        private fun iconFor(type: String): Int = when (type) {
            "order" -> R.drawable.ic_store
            "promo" -> R.drawable.ic_percent
            "security" -> R.drawable.ic_lock
            else -> R.drawable.ic_notification
        }

        /** created_at comes as "YYYY-MM-DD HH:MM:SS" from MySQL — same
         * absolute "d MMM, h:mm a" format the Customer App's equivalent
         * adapter uses, kept consistent across both apps rather than
         * introducing a relative "X min ago" convention here. Falls back
         * to the raw string if parsing fails for any reason. */
        private fun formatTimestamp(raw: String): String {
            return try {
                val input = java.text.SimpleDateFormat("yyyy-MM-dd HH:mm:ss", java.util.Locale.US)
                val output = java.text.SimpleDateFormat("d MMM, h:mm a", java.util.Locale.US)
                val date = input.parse(raw)
                if (date != null) output.format(date) else raw
            } catch (e: Exception) {
                raw
            }
        }
    }
}
