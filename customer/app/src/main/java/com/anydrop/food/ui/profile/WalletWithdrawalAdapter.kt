package com.anydrop.food.ui.profile

import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import androidx.core.content.ContextCompat
import androidx.recyclerview.widget.RecyclerView
import com.anydrop.food.R
import com.anydrop.food.databinding.ItemWithdrawalBinding
import com.anydrop.food.network.WalletWithdrawal

/**
 * PENDING.md §37 — WithdrawActivity's withdrawal history list.
 * Read-only, no click action (same as WalletTransactionAdapter — a
 * wallet_withdrawals row has nowhere further to drill into on this
 * screen). Status pill color is set programmatically per status since
 * this project's drawables don't already have a per-status chip set.
 */
class WalletWithdrawalAdapter : RecyclerView.Adapter<WalletWithdrawalAdapter.VH>() {

    private val items = mutableListOf<WalletWithdrawal>()

    fun submit(list: List<WalletWithdrawal>) {
        items.clear()
        items.addAll(list)
        notifyDataSetChanged()
    }

    override fun onCreateViewHolder(parent: ViewGroup, viewType: Int): VH {
        val binding = ItemWithdrawalBinding.inflate(LayoutInflater.from(parent.context), parent, false)
        return VH(binding)
    }

    override fun onBindViewHolder(holder: VH, position: Int) = holder.bind(items[position])

    override fun getItemCount() = items.size

    inner class VH(private val binding: ItemWithdrawalBinding) : RecyclerView.ViewHolder(binding.root) {
        fun bind(w: WalletWithdrawal) {
            val context = binding.root.context

            binding.withdrawalAmount.text = "₹${"%.2f".format(w.amount)}"
            binding.withdrawalMethod.text = if (w.payoutMethod == "upi") {
                context.getString(R.string.withdraw_method_upi)
            } else {
                context.getString(R.string.withdraw_method_bank)
            }
            binding.withdrawalDate.text = formatDate(w.requestedAt)

            val (statusLabel, statusColorRes) = when (w.status) {
                "requested" -> context.getString(R.string.withdraw_status_requested) to R.color.text_secondary
                "approved" -> context.getString(R.string.withdraw_status_approved) to R.color.anydrop_primary
                "processing" -> context.getString(R.string.withdraw_status_processing) to R.color.anydrop_primary
                "completed" -> context.getString(R.string.withdraw_status_completed) to R.color.success_fg
                "rejected" -> context.getString(R.string.withdraw_status_rejected) to R.color.error_fg
                // Defensive fallback if the status ENUM grows server-side
                // before this screen's next update — same "raw string,
                // render defensively" choice WalletTransactionAdapter's
                // reason handling already made.
                else -> w.status.replaceFirstChar { it.uppercase() } to R.color.text_secondary
            }
            binding.withdrawalStatus.text = statusLabel
            binding.withdrawalStatus.backgroundTintList =
                ContextCompat.getColorStateList(context, statusColorRes)

            if (w.status == "rejected" && !w.rejectReason.isNullOrBlank()) {
                binding.withdrawalRejectReason.text =
                    "${context.getString(R.string.withdraw_status_rejected)}: ${w.rejectReason}"
                binding.withdrawalRejectReason.visibility = View.VISIBLE
            } else {
                binding.withdrawalRejectReason.visibility = View.GONE
            }
        }

        /** Same "YYYY-MM-DD HH:MM:SS" -> "12 Jul, 8:42 PM" reformat as
         * WalletTransactionAdapter.formatTxnDate() — duplicated rather
         * than shared since that one's private to its own file; falls
         * back to the raw string if parsing fails for any reason. */
        private fun formatDate(raw: String): String {
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
