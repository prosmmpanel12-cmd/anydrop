package com.anydrop.rider.ui.earnings

import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import androidx.core.content.ContextCompat
import androidx.recyclerview.widget.RecyclerView
import com.anydrop.rider.R
import com.anydrop.rider.databinding.ItemRiderPayoutBinding
import com.anydrop.rider.network.RiderPayoutRequest

/**
 * Deep-plan §21, migration 74 — RequestPayoutActivity's payout-request
 * history list. Modeled directly on the customer app's
 * WalletWithdrawalAdapter (same statuses, same "colored pill +
 * reject-reason line" shape); read-only, no click action — a
 * rider_payout_requests row has nowhere further to drill into on this
 * screen, same reasoning the customer-side equivalent gives.
 *
 * Status pill color is set programmatically per status, same as the
 * customer adapter, but using this app's own color set (anydrop_green
 * in place of anydrop_primary, which doesn't exist in this app's
 * colors.xml).
 */
class RiderPayoutAdapter : RecyclerView.Adapter<RiderPayoutAdapter.VH>() {

    private val items = mutableListOf<RiderPayoutRequest>()

    fun submit(list: List<RiderPayoutRequest>) {
        items.clear()
        items.addAll(list)
        notifyDataSetChanged()
    }

    override fun onCreateViewHolder(parent: ViewGroup, viewType: Int): VH {
        val binding = ItemRiderPayoutBinding.inflate(LayoutInflater.from(parent.context), parent, false)
        return VH(binding)
    }

    override fun onBindViewHolder(holder: VH, position: Int) = holder.bind(items[position])

    override fun getItemCount() = items.size

    inner class VH(private val binding: ItemRiderPayoutBinding) : RecyclerView.ViewHolder(binding.root) {
        fun bind(p: RiderPayoutRequest) {
            val context = binding.root.context

            binding.payoutAmount.text = "₹${"%.2f".format(p.amount)}"
            binding.payoutMethod.text = if (p.payoutMethod == "upi") {
                context.getString(R.string.payout_method_upi)
            } else {
                context.getString(R.string.payout_method_bank)
            }
            binding.payoutDate.text = formatDate(p.requestedAt)

            // bg/fg color PAIRS, not a single fill color — same convention
            // ApplicationStatusActivity's own status pill already uses
            // (status_pending_bg/fg, status_approved_bg/fg, etc.), rather
            // than the customer app adapter's single-color-fill approach,
            // so this pill matches every other status chip already in
            // this app.
            val (statusLabel, bgRes, fgRes) = when (p.status) {
                "requested" -> Triple(context.getString(R.string.payout_status_requested), R.color.status_pending_bg, R.color.status_pending_fg)
                "approved" -> Triple(context.getString(R.string.payout_status_approved), R.color.status_approved_bg, R.color.status_approved_fg)
                "processing" -> Triple(context.getString(R.string.payout_status_processing), R.color.status_approved_bg, R.color.status_approved_fg)
                "completed" -> Triple(context.getString(R.string.payout_status_completed), R.color.success_bg, R.color.success_fg)
                "rejected" -> Triple(context.getString(R.string.payout_status_rejected), R.color.status_rejected_bg, R.color.status_rejected_fg)
                // Defensive fallback if the status ENUM grows server-side
                // before this screen's next update — same "raw string,
                // render defensively" choice the customer adapter makes.
                else -> Triple(p.status.replaceFirstChar { it.uppercase() }, R.color.status_suspended_bg, R.color.status_suspended_fg)
            }
            binding.payoutStatus.text = statusLabel
            binding.payoutStatus.setTextColor(ContextCompat.getColor(context, fgRes))
            binding.payoutStatus.backgroundTintList =
                ContextCompat.getColorStateList(context, bgRes)

            if (p.status == "rejected" && !p.rejectReason.isNullOrBlank()) {
                binding.payoutRejectReason.text =
                    "${context.getString(R.string.payout_status_rejected)}: ${p.rejectReason}"
                binding.payoutRejectReason.visibility = View.VISIBLE
            } else {
                binding.payoutRejectReason.visibility = View.GONE
            }
        }

        /** Same "YYYY-MM-DD HH:MM:SS" -> "12 Jul, 8:42 PM" reformat as
         * the customer app's WalletWithdrawalAdapter.formatDate() —
         * duplicated rather than shared since it's private to that file
         * (and lives in a different app module entirely). Falls back to
         * the raw string if parsing fails for any reason. */
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
