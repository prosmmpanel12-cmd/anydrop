package com.anydrop.restaurant.ui.reviews

import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import androidx.core.content.ContextCompat
import androidx.recyclerview.widget.RecyclerView
import com.anydrop.restaurant.R
import com.anydrop.restaurant.databinding.ItemReviewBinding
import com.anydrop.restaurant.network.Review

/**
 * Reviews reply screen (docs/restorent/00_Status.md, this session).
 * Same submit/appendPage/single-item-patch shape as NotificationAdapter —
 * kept consistent with that screen's already-established pattern in this
 * app rather than inventing a new one.
 *
 * Three callbacks instead of one onClick, because a review row has
 * distinct actions (send a reply / start editing an existing one /
 * report the review, §7 today.md 2026-08-28) rather than the single
 * "tap the row" action notifications have.
 */
class ReviewAdapter(
    private val onSendReply: (Review, String) -> Unit,
    private val onEditReply: (Review) -> Unit,
    private val onReportReview: (Review) -> Unit
) : RecyclerView.Adapter<ReviewAdapter.VH>() {

    private val items = mutableListOf<Review>()
    // Reviews that already have a reply but are being edited right now —
    // tracked by id so re-binding (e.g. after scroll recycle) keeps the
    // input open instead of snapping back to the read-only display.
    private val editingIds = mutableSetOf<Int>()
    // §7, today.md 2026-08-28: reviews reported this session. This is a
    // client-side, in-memory flag only — the backend has no
    // `is_reported_by_me`-style field yet (today.md §7 step 6 flags this
    // as an open decision), so a fresh screen load won't remember it.
    // Good enough to stop an accidental double-tap in the same session;
    // the DB-level unique constraint (migration 56) is the real abuse
    // protection regardless of what this set remembers.
    private val reportedIds = mutableSetOf<Int>()

    fun submit(list: List<Review>) {
        items.clear()
        items.addAll(list)
        editingIds.clear()
        notifyDataSetChanged()
    }

    fun appendPage(list: List<Review>) {
        val startIndex = items.size
        items.addAll(list)
        notifyItemRangeInserted(startIndex, list.size)
    }

    /** After a successful reply, patch that row in place — cheaper than a
     * full reload and matches NotificationAdapter.markRead's local-patch
     * pattern. */
    fun patchReply(reviewId: Int, updated: Review) {
        val index = items.indexOfFirst { it.id == reviewId }
        if (index == -1) return
        items[index] = updated
        editingIds.remove(reviewId)
        notifyItemChanged(index)
    }

    fun startEditing(reviewId: Int) {
        editingIds.add(reviewId)
        val index = items.indexOfFirst { it.id == reviewId }
        if (index != -1) notifyItemChanged(index)
    }

    /** §7, today.md 2026-08-28: called after a successful report so the
     * row flips to the disabled "Reported" state without a full reload. */
    fun markReported(reviewId: Int) {
        reportedIds.add(reviewId)
        val index = items.indexOfFirst { it.id == reviewId }
        if (index != -1) notifyItemChanged(index)
    }

    override fun onCreateViewHolder(parent: ViewGroup, viewType: Int): VH {
        val binding = ItemReviewBinding.inflate(LayoutInflater.from(parent.context), parent, false)
        return VH(binding)
    }

    override fun onBindViewHolder(holder: VH, position: Int) = holder.bind(items[position])

    override fun getItemCount() = items.size

    inner class VH(private val binding: ItemReviewBinding) : RecyclerView.ViewHolder(binding.root) {
        fun bind(item: Review) {
            binding.reviewCustomerName.text = item.customerName ?: "Customer"
            binding.reviewTime.text = formatTimestamp(item.createdAt)

            if (item.comment.isNullOrBlank()) {
                binding.reviewComment.visibility = View.GONE
            } else {
                binding.reviewComment.visibility = View.VISIBLE
                binding.reviewComment.text = item.comment
            }

            bindStars(item.restaurantRating ?: 0)
            bindReportButton(item)

            val isEditing = editingIds.contains(item.id)
            val hasReply = !item.restaurantReply.isNullOrBlank()

            if (hasReply && !isEditing) {
                binding.repliedGroup.visibility = View.VISIBLE
                binding.replyGroup.visibility = View.GONE
                binding.repliedText.text = item.restaurantReply
                binding.btnEditReply.setOnClickListener {
                    onEditReply(item)
                }
            } else {
                binding.repliedGroup.visibility = View.GONE
                binding.replyGroup.visibility = View.VISIBLE
                // Pre-fill with the existing reply when editing, so the
                // restaurant is correcting text rather than retyping it.
                binding.replyInput.setText(if (isEditing) item.restaurantReply else "")
                binding.btnSendReply.setOnClickListener {
                    val text = binding.replyInput.text?.toString()?.trim().orEmpty()
                    onSendReply(item, text)
                }
            }
        }

        /** §7, today.md 2026-08-28: independent of the reply state above —
         * a review can be reported whether or not it's been replied to. */
        private fun bindReportButton(item: Review) {
            val isReported = reportedIds.contains(item.id)
            binding.btnReportReview.isEnabled = !isReported
            binding.btnReportReview.text = binding.root.context.getString(
                if (isReported) R.string.review_reported else R.string.review_report
            )
            binding.btnReportReview.setOnClickListener {
                if (!isReported) onReportReview(item)
            }
        }

        private fun bindStars(rating: Int) {
            val stars = listOf(binding.ivStar1, binding.ivStar2, binding.ivStar3, binding.ivStar4, binding.ivStar5)
            val filledColor = ContextCompat.getColor(binding.root.context, R.color.anydrop_primary)
            val outlineColor = ContextCompat.getColor(binding.root.context, R.color.text_secondary)
            stars.forEachIndexed { index, star ->
                star.setColorFilter(if (index < rating) filledColor else outlineColor)
                star.alpha = if (index < rating) 1f else 0.35f
            }
        }

        /** Same "d MMM, h:mm a" absolute format NotificationAdapter uses,
         * kept consistent across this app's list screens. */
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
