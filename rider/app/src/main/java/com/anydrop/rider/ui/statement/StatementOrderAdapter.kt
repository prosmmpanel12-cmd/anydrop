package com.anydrop.rider.ui.statement

import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import androidx.recyclerview.widget.RecyclerView
import com.anydrop.rider.databinding.ItemStatementOrderBinding
import com.anydrop.rider.network.RiderStatementOrder
import java.text.SimpleDateFormat
import java.util.Locale

/**
 * StatementActivity's RecyclerView row (Deep Plan Phase 3). No
 * pagination — same "a single day's deliveries is always small"
 * reasoning EarningsLedgerAdapter already uses; the whole list is
 * re-fetched on every date change.
 */
class StatementOrderAdapter : RecyclerView.Adapter<StatementOrderAdapter.VH>() {

    private val items = mutableListOf<RiderStatementOrder>()

    fun submit(list: List<RiderStatementOrder>) {
        items.clear()
        items.addAll(list)
        notifyDataSetChanged()
    }

    override fun onCreateViewHolder(parent: ViewGroup, viewType: Int): VH {
        val binding = ItemStatementOrderBinding.inflate(LayoutInflater.from(parent.context), parent, false)
        return VH(binding)
    }

    override fun onBindViewHolder(holder: VH, position: Int) = holder.bind(items[position])

    override fun getItemCount() = items.size

    inner class VH(private val binding: ItemStatementOrderBinding) : RecyclerView.ViewHolder(binding.root) {
        fun bind(order: RiderStatementOrder) {
            binding.orderCode.text = "#${order.orderCode}"

            val timeLabel = formatTime(order.deliveredAt)
            val methodLabel = if (order.paymentMethod == "cod") "COD" else "Prepaid"
            binding.orderTime.text = "$timeLabel · $methodLabel"

            // Only a COD order ever collected cash from the customer —
            // a prepaid order's row shows no COD line rather than "₹0",
            // same "hide what doesn't apply rather than show a zero"
            // convention EarningsLedgerAdapter's own note/orderCode
            // visibility toggles already use.
            if (order.paymentMethod == "cod") {
                binding.orderCodAmount.visibility = View.VISIBLE
                binding.orderCodAmount.text = "COD collected: \u20b9${"%.0f".format(order.codAmountCollected)}"
            } else {
                binding.orderCodAmount.visibility = View.GONE
            }

            binding.orderEarning.text = "+\u20b9${"%.0f".format(order.deliveryEarning)}"
        }

        private fun formatTime(raw: String): String {
            return try {
                val input = SimpleDateFormat("yyyy-MM-dd HH:mm:ss", Locale.US)
                val output = SimpleDateFormat("h:mm a", Locale.US)
                val date = input.parse(raw)
                if (date != null) output.format(date) else raw
            } catch (e: Exception) {
                raw
            }
        }
    }
}
