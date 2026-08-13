package com.anydrop.restaurant.ui.dashboard

import android.content.Context
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import androidx.core.content.ContextCompat
import androidx.recyclerview.widget.RecyclerView
import com.anydrop.restaurant.R
import com.anydrop.restaurant.databinding.ItemOrderCardBinding
import com.anydrop.restaurant.network.Order
import com.anydrop.restaurant.util.ScheduledTimeFormatter

class OrderAdapter(
    private val context: Context,
    private val onClick: (Order) -> Unit
) : RecyclerView.Adapter<OrderAdapter.OrderViewHolder>() {

    private val orders = mutableListOf<Order>()

    fun submitList(newOrders: List<Order>) {
        orders.clear()
        orders.addAll(newOrders)
        notifyDataSetChanged()
    }

    override fun onCreateViewHolder(parent: ViewGroup, viewType: Int): OrderViewHolder {
        val binding = ItemOrderCardBinding.inflate(LayoutInflater.from(parent.context), parent, false)
        return OrderViewHolder(binding)
    }

    override fun onBindViewHolder(holder: OrderViewHolder, position: Int) {
        holder.bind(orders[position])
    }

    override fun getItemCount() = orders.size

    inner class OrderViewHolder(private val binding: ItemOrderCardBinding) : RecyclerView.ViewHolder(binding.root) {
        fun bind(order: Order) {
            binding.orderCodeText.text = order.orderCode
            binding.itemsSummaryText.text = order.items.joinToString(", ") { "${it.quantity}x ${it.name}" }
            binding.grandTotalText.text = "₹${"%.2f".format(order.grandTotal)}"
            binding.paymentMethodText.text = order.paymentMethod.uppercase()

            val scheduledTime = ScheduledTimeFormatter.formatTime(order.scheduledFor)
            if (scheduledTime != null) {
                binding.scheduledBadge.visibility = View.VISIBLE
                binding.scheduledBadge.text = context.getString(R.string.order_scheduled_for_format, scheduledTime)
            } else {
                binding.scheduledBadge.visibility = View.GONE
            }

            val (bgColor, fgColor, label) = statusStyle(order.status)
            binding.statusChip.text = label
            binding.statusChip.setTextColor(fgColor)
            binding.statusChip.background.setTint(bgColor)

            binding.root.setOnClickListener { onClick(order) }
        }

        private fun statusStyle(status: String): Triple<Int, Int, String> {
            val bg: Int
            val fg: Int
            val label: String
            when (status) {
                "pending" -> {
                    bg = ContextCompat.getColor(context, R.color.status_pending_bg)
                    fg = ContextCompat.getColor(context, R.color.status_pending_fg)
                    label = "NEW"
                }
                "delivered" -> {
                    bg = ContextCompat.getColor(context, R.color.status_done_bg)
                    fg = ContextCompat.getColor(context, R.color.status_done_fg)
                    label = "DELIVERED"
                }
                "cancelled", "rejected" -> {
                    bg = ContextCompat.getColor(context, R.color.error_bg)
                    fg = ContextCompat.getColor(context, R.color.error_fg)
                    label = status.uppercase()
                }
                else -> {
                    bg = ContextCompat.getColor(context, R.color.status_active_bg)
                    fg = ContextCompat.getColor(context, R.color.status_active_fg)
                    label = status.uppercase().replace("_", " ")
                }
            }
            return Triple(bg, fg, label)
        }
    }
}
