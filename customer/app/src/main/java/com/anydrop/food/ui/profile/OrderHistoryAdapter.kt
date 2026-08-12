package com.anydrop.food.ui.profile

import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import androidx.appcompat.app.AppCompatActivity
import androidx.core.content.ContextCompat
import androidx.recyclerview.widget.RecyclerView
import coil.load
import com.anydrop.food.R
import com.anydrop.food.databinding.ItemOrderCardBinding
import com.anydrop.food.network.OrderHistoryEntry
import com.anydrop.food.ui.orders.RateOrderDialog

class OrderHistoryAdapter(
    private val onClick: (OrderHistoryEntry) -> Unit,
    private val onRated: (OrderHistoryEntry) -> Unit = {}
) : RecyclerView.Adapter<OrderHistoryAdapter.VH>() {

    private val items = mutableListOf<OrderHistoryEntry>()

    fun submit(list: List<OrderHistoryEntry>) {
        items.clear()
        items.addAll(list)
        notifyDataSetChanged()
    }

    fun appendPage(list: List<OrderHistoryEntry>) {
        val startIndex = items.size
        items.addAll(list)
        notifyItemRangeInserted(startIndex, list.size)
    }

    /** Part 13 — after a successful rating, swap that one card's "Rate
     * Order" button for the "Rated" label without a full list reload. */
    fun markRated(orderId: Int) {
        val index = items.indexOfFirst { it.id == orderId }
        if (index == -1) return
        items[index] = items[index].copy(isRated = true)
        notifyItemChanged(index)
    }

    override fun onCreateViewHolder(parent: ViewGroup, viewType: Int): VH {
        val binding = ItemOrderCardBinding.inflate(LayoutInflater.from(parent.context), parent, false)
        return VH(binding)
    }

    override fun onBindViewHolder(holder: VH, position: Int) = holder.bind(items[position])

    override fun getItemCount() = items.size

    inner class VH(private val binding: ItemOrderCardBinding) : RecyclerView.ViewHolder(binding.root) {
        fun bind(order: OrderHistoryEntry) {
            binding.orderRestaurantName.text = order.restaurantName
            binding.orderMeta.text = binding.root.context.getString(
                R.string.order_meta_format,
                order.itemCount,
                order.grandTotal.toInt(),
                formatOrderDate(order.createdAt)
            )

            if (!order.restaurantCoverUrl.isNullOrBlank()) {
                binding.orderRestaurantImage.load(order.restaurantCoverUrl) {
                    placeholder(R.drawable.ic_restaurant)
                    error(R.drawable.ic_restaurant)
                    crossfade(true)
                }
            } else {
                binding.orderRestaurantImage.setImageResource(R.drawable.ic_restaurant)
            }

            binding.orderStatusBadge.text = order.status.replaceFirstChar { it.uppercase() }
            binding.orderStatusBadge.setBackgroundResource(R.drawable.bg_status_pill)
            val tintColor = when (order.status) {
                "delivered" -> R.color.success_fg
                "cancelled", "rejected", "failed", "refunded", "expired" -> R.color.error_fg
                else -> R.color.anydrop_primary
            }
            binding.orderStatusBadge.backgroundTintList =
                ContextCompat.getColorStateList(binding.root.context, tintColor)
            binding.orderStatusBadge.setTextColor(ContextCompat.getColor(binding.root.context, android.R.color.white))

            val canRate = order.status == "delivered" && !order.isRated
            binding.btnRateOrder.visibility = if (canRate) View.VISIBLE else View.GONE
            binding.orderRatedLabel.visibility = if (order.status == "delivered" && order.isRated) View.VISIBLE else View.GONE

            binding.btnRateOrder.setOnClickListener {
                val activity = binding.root.context as? AppCompatActivity ?: return@setOnClickListener
                RateOrderDialog.show(
                    activity = activity,
                    orderId = order.id,
                    restaurantName = order.restaurantName,
                    hasRider = order.hasRider
                ) {
                    markRated(order.id)
                    onRated(order)
                }
            }

            binding.root.setOnClickListener { onClick(order) }
        }

        /** created_at comes as "YYYY-MM-DD HH:MM:SS" from MySQL — reformat to
         * a short, readable "12 Jul, 8:42 PM" style without pulling in a
         * date-parsing library for one field. Falls back to the raw string
         * if parsing fails for any reason (unexpected format, locale issue). */
        private fun formatOrderDate(raw: String): String {
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
