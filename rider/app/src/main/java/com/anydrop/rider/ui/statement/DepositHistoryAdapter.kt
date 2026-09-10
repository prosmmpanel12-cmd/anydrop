package com.anydrop.rider.ui.statement

import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import androidx.core.content.ContextCompat
import androidx.recyclerview.widget.RecyclerView
import com.anydrop.rider.R
import com.anydrop.rider.databinding.ItemDepositHistoryBinding
import com.anydrop.rider.network.RiderDepositHistoryItem
import java.text.SimpleDateFormat
import java.util.Locale

/**
 * StatementActivity's "Deposits" tab row (Deep Plan Phase 6). Same
 * no-pagination convention as StatementOrderAdapter — the whole list
 * is re-fetched once when the tab is first opened, not per date (this
 * list isn't date-scoped, unlike the Orders tab).
 */
class DepositHistoryAdapter : RecyclerView.Adapter<DepositHistoryAdapter.VH>() {

    private val items = mutableListOf<RiderDepositHistoryItem>()

    fun submit(list: List<RiderDepositHistoryItem>) {
        items.clear()
        items.addAll(list)
        notifyDataSetChanged()
    }

    override fun onCreateViewHolder(parent: ViewGroup, viewType: Int): VH {
        val binding = ItemDepositHistoryBinding.inflate(LayoutInflater.from(parent.context), parent, false)
        return VH(binding)
    }

    override fun onBindViewHolder(holder: VH, position: Int) = holder.bind(items[position])

    override fun getItemCount() = items.size

    inner class VH(private val binding: ItemDepositHistoryBinding) : RecyclerView.ViewHolder(binding.root) {
        fun bind(deposit: RiderDepositHistoryItem) {
            val ctx = binding.root.context
            binding.depositAmount.text = "\u20b9${"%.0f".format(deposit.amount)}"
            binding.depositDate.text = formatDate(deposit.createdAt)

            // reference falls back to the note (admin manual-settlement
            // rows have no payment_transactions row at all, so no
            // reference — the note, e.g. "Rider handed over COD cash",
            // is the only context available for that row).
            val refText = deposit.reference ?: deposit.note
            if (!refText.isNullOrBlank()) {
                binding.depositReference.visibility = View.VISIBLE
                binding.depositReference.text = refText
            } else {
                binding.depositReference.visibility = View.GONE
            }

            // Same bg/fg color-pair + bg_status_pill drawable convention
            // every other status chip in this app uses (RiderPayoutAdapter,
            // ApplicationStatusActivity, documents) — backgroundTintList
            // over the shared pill drawable, not a raw fill color.
            val (label, bgRes, fgRes) = when (deposit.status) {
                "auto_verified" -> Triple(
                    ctx.getString(R.string.deposit_status_auto_verified),
                    R.color.status_approved_bg, R.color.status_approved_fg
                )
                "manual_review" -> Triple(
                    ctx.getString(R.string.deposit_status_manual_review),
                    R.color.status_pending_bg, R.color.status_pending_fg
                )
                else -> Triple(
                    ctx.getString(R.string.deposit_status_manual_settlement),
                    R.color.status_suspended_bg, R.color.status_suspended_fg
                )
            }
            binding.depositStatusChip.text = label
            binding.depositStatusChip.setTextColor(ContextCompat.getColor(ctx, fgRes))
            binding.depositStatusChip.backgroundTintList =
                ContextCompat.getColorStateList(ctx, bgRes)
        }

        private fun formatDate(raw: String): String {
            return try {
                val input = SimpleDateFormat("yyyy-MM-dd HH:mm:ss", Locale.US)
                val output = SimpleDateFormat("d MMM yyyy, h:mm a", Locale.US)
                val date = input.parse(raw)
                if (date != null) output.format(date) else raw
            } catch (e: Exception) {
                raw
            }
        }
    }
}
