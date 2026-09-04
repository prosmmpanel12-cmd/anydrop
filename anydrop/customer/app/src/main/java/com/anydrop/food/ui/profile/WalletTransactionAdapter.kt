package com.anydrop.food.ui.profile

import android.view.LayoutInflater
import android.view.ViewGroup
import androidx.core.content.ContextCompat
import androidx.recyclerview.widget.RecyclerView
import com.anydrop.food.R
import com.anydrop.food.databinding.ItemWalletTransactionBinding
import com.anydrop.food.network.WalletTransaction

/**
 * item 26 §D.15 — Wallet screen's transaction list. Read-only, no
 * click action (unlike OrderHistoryAdapter) — a wallet_transactions
 * row has nowhere further to drill into yet; order_id is present on
 * order_payment/refund rows but there's no customer-facing "view this
 * order" affordance designed for this screen in this session, so it's
 * left as a plain list for now.
 */
class WalletTransactionAdapter : RecyclerView.Adapter<WalletTransactionAdapter.VH>() {

    private val items = mutableListOf<WalletTransaction>()

    fun submit(list: List<WalletTransaction>) {
        items.clear()
        items.addAll(list)
        notifyDataSetChanged()
    }

    override fun onCreateViewHolder(parent: ViewGroup, viewType: Int): VH {
        val binding = ItemWalletTransactionBinding.inflate(LayoutInflater.from(parent.context), parent, false)
        return VH(binding)
    }

    override fun onBindViewHolder(holder: VH, position: Int) = holder.bind(items[position])

    override fun getItemCount() = items.size

    inner class VH(private val binding: ItemWalletTransactionBinding) : RecyclerView.ViewHolder(binding.root) {
        fun bind(txn: WalletTransaction) {
            val context = binding.root.context
            val isCredit = txn.type == "credit"

            binding.txnReason.text = when (txn.reason) {
                "refund" -> context.getString(R.string.wallet_reason_refund)
                "admin_adjustment" -> context.getString(R.string.wallet_reason_admin_adjustment)
                "cashback" -> context.getString(R.string.wallet_reason_cashback)
                "order_payment" -> context.getString(R.string.wallet_reason_order_payment)
                // Defensive fallback if the reason ENUM grows server-side
                // before this screen's next update — same "raw string,
                // render defensively" choice the Models.kt kdoc flagged.
                else -> txn.reason.replaceFirstChar { it.uppercase() }
            }

            if (!txn.note.isNullOrBlank()) {
                binding.txnNote.text = txn.note
                binding.txnNote.visibility = android.view.View.VISIBLE
            } else {
                binding.txnNote.visibility = android.view.View.GONE
            }

            binding.txnDate.text = formatTxnDate(txn.createdAt)

            val amountColor = if (isCredit) R.color.success_fg else R.color.error_fg
            val sign = if (isCredit) "+" else "-"
            binding.txnAmount.text = "$sign₹${"%.2f".format(txn.amount)}"
            binding.txnAmount.setTextColor(ContextCompat.getColor(context, amountColor))
            binding.txnIcon.setColorFilter(ContextCompat.getColor(context, amountColor))
            binding.txnIcon.rotation = if (isCredit) 0f else 180f
        }

        /** Same "YYYY-MM-DD HH:MM:SS" -> "12 Jul, 8:42 PM" reformat as
         * OrderHistoryAdapter.formatOrderDate() — duplicated rather than
         * shared since that one's private to its own file; falls back to
         * the raw string if parsing fails for any reason. */
        private fun formatTxnDate(raw: String): String {
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
