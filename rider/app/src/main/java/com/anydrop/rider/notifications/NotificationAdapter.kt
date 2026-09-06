package com.anydrop.rider.notifications

import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import androidx.recyclerview.widget.RecyclerView
import com.anydrop.rider.R
import com.anydrop.rider.databinding.ItemNotificationBinding
import com.anydrop.rider.network.NotificationItem

/**
 * Notification bell list adapter (deep-plan §23, docs 99-102). Same
 * submit/appendPage/markRead/markAllRead shape as the customer app's
 * own `NotificationAdapter` (confirmed template, doc 101/102's
 * verification notes) so `NotificationListActivity` can reuse the
 * identical infinite-scroll + auto-mark-read pattern.
 *
 * Unlike the customer app's adapter, this one doesn't branch on
 * `item.type` for the icon — every notification this app's backend
 * sends today is `type: "account"` (doc 102's read of
 * `backend/admin/riders.php`'s four `create_notification(...)` call
 * sites), so `ic_notification` is used unconditionally rather than
 * introducing a `when` block with only one real branch.
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
     * reload — same single-item patch as the customer app's adapter. */
    fun markRead(id: Int) {
        val index = items.indexOfFirst { it.id == id }
        if (index == -1 || items[index].isRead) return
        items[index] = items[index].copy(isRead = true)
        notifyItemChanged(index)
    }

    /** Opening the list itself counts as "seen" — flips every currently
     * unread row to read locally. Called from NotificationListActivity
     * right after a fetch completes. */
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
            binding.notificationIcon.setImageResource(R.drawable.ic_notification)
            binding.unreadDot.visibility = if (item.isRead) View.GONE else View.VISIBLE
            binding.root.alpha = if (item.isRead) 0.7f else 1f
            binding.notificationRow.setOnClickListener { onClick(item) }
        }

        /** created_at comes as "YYYY-MM-DD HH:MM:SS" from MySQL — same
         * absolute "d MMM, h:mm a" format the customer app's own adapter
         * uses, kept consistent rather than introducing a separate
         * relative-time convention just for this app. Falls back to the
         * raw string if parsing fails for any reason. */
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
