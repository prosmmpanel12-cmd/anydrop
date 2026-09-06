package com.anydrop.rider.ui.earnings

import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import androidx.core.content.ContextCompat
import androidx.recyclerview.widget.RecyclerView
import com.anydrop.rider.R
import com.anydrop.rider.databinding.ItemEarningsLedgerBinding
import com.anydrop.rider.network.EarningsLedgerEntry

/**
 * EarningsActivity's ledger list — read-only, no click action, same
 * "nothing further to drill into on this screen" reasoning the
 * customer app's WalletWithdrawalAdapter already uses for its own
 * withdrawal-history rows. This is the rider app's first
 * RecyclerView.Adapter — no existing one to extend/share, per doc 90's
 * own note that `earnings-summary.php`'s `recent` array had a model
 * but nothing rendering it yet.
 */
class EarningsLedgerAdapter : RecyclerView.Adapter<EarningsLedgerAdapter.VH>() {

    private val items = mutableListOf<EarningsLedgerEntry>()

    fun submit(list: List<EarningsLedgerEntry>) {
        items.clear()
        items.addAll(list)
        notifyDataSetChanged()
    }

    override fun onCreateViewHolder(parent: ViewGroup, viewType: Int): VH {
        val binding = ItemEarningsLedgerBinding.inflate(LayoutInflater.from(parent.context), parent, false)
        return VH(binding)
    }

    override fun onBindViewHolder(holder: VH, position: Int) = holder.bind(items[position])

    override fun getItemCount() = items.size

    inner class VH(private val binding: ItemEarningsLedgerBinding) : RecyclerView.ViewHolder(binding.root) {
        fun bind(entry: EarningsLedgerEntry) {
            val context = binding.root.context

            // entry_type is the raw ENUM value written by lib/rider_earnings.php
            // (migration 73) — mapped to a display label here rather than
            // stored pre-formatted server-side, same "server sends raw,
            // Android formats for display" split every other ledger/status
            // screen in this project already follows (e.g. WalletWithdrawalAdapter's
            // own status mapping).
            val (label, isCredit) = when (entry.entryType) {
                "delivery_earning" -> context.getString(R.string.earnings_type_delivery) to true
                "incentive" -> context.getString(R.string.earnings_type_incentive) to true
                "payout" -> context.getString(R.string.earnings_type_payout) to false
                "adjustment" -> context.getString(R.string.earnings_type_adjustment) to (entry.amount >= 0)
                // Defensive fallback if the entry_type ENUM grows server-side
                // before this screen's next update.
                else -> entry.entryType.replaceFirstChar { it.uppercase() } to (entry.amount >= 0)
            }
            binding.ledgerEntryType.text = label
            binding.ledgerDate.text = formatDate(entry.createdAt)

            val amountAbs = kotlin.math.abs(entry.amount)
            val sign = if (isCredit) "+" else "\u2212" // proper minus sign, not a hyphen
            binding.ledgerAmount.text = "$sign\u20b9${"%.2f".format(amountAbs)}"
            binding.ledgerAmount.setTextColor(
                ContextCompat.getColor(context, if (isCredit) R.color.success_fg else R.color.error_fg)
            )

            if (!entry.orderCode.isNullOrBlank()) {
                binding.ledgerOrderCode.text = context.getString(R.string.earnings_order_code_format, entry.orderCode)
                binding.ledgerOrderCode.visibility = View.VISIBLE
            } else {
                binding.ledgerOrderCode.visibility = View.GONE
            }

            if (!entry.note.isNullOrBlank()) {
                binding.ledgerNote.text = entry.note
                binding.ledgerNote.visibility = View.VISIBLE
            } else {
                binding.ledgerNote.visibility = View.GONE
            }
        }

        /** Same "YYYY-MM-DD HH:MM:SS" -> "12 Jul, 8:42 PM" reformat as
         * WalletWithdrawalAdapter.formatDate() — duplicated rather than
         * shared since it's private to that file and the rider/customer
         * apps are separate Gradle modules with no shared code module
         * in this project's structure; falls back to the raw string if
         * parsing fails for any reason. */
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
