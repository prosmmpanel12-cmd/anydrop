package com.anydrop.food.ui.notifications

import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import androidx.recyclerview.widget.RecyclerView
import com.anydrop.food.R
import com.anydrop.food.databinding.ItemNotificationBinding
import com.anydrop.food.network.NotificationItem

/**
 * Notification bell list (Type 1, docs/Status.md 2026-08-20). Same
 * submit/appendPage shape as OrderHistoryAdapter so NotificationListActivity
 * can reuse the identical infinite-scroll pattern.
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
     * reload — mirrors OrderHistoryAdapter.markRated()'s single-item patch. */
    fun markRead(id: Int) {
        val index = items.indexOfFirst { it.id == id }
        if (index == -1 || items[index].isRead) return
        items[index] = items[index].copy(isRead = true)
        notifyItemChanged(index)
    }

    /** Opening the list itself counts as "seen" — flips every currently
     * unread row to read locally, same reasoning as [markRead] but for
     * all of them at once. Called from NotificationListActivity right
     * after a fetch completes. 2026-08-21 — this was built for the
     * Restaurant App's equivalent adapter but missed here, which is why
     * auto-mark-read never worked on the Customer App bell. */
    fun markAllRead() {
        for (i in items.indices) {
            if (!items[i].isRead) items[i] = items[i].copy(isRead = true)
        }
        notifyDataSetChanged()
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

        private fun iconFor(type: String): Int = when (type) {
            "order" -> R.drawable.ic_restaurant
            "promo" -> R.drawable.ic_offer_tag
            "security" -> R.drawable.ic_lock
            else -> R.drawable.ic_notification
        }

        /** created_at comes as "YYYY-MM-DD HH:MM:SS" from MySQL — same
         * absolute "d MMM, h:mm a" format OrderHistoryAdapter.formatOrderDate()
         * uses, kept consistent rather than introducing a separate "X min
         * ago" relative-time convention elsewhere in the app. Falls back to
         * the raw string if parsing fails for any reason. */
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
