package com.anydrop.food.ui.profile

import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import androidx.appcompat.app.AppCompatActivity
import androidx.core.content.ContextCompat
import androidx.lifecycle.lifecycleScope
import androidx.recyclerview.widget.RecyclerView
import coil.load
import com.anydrop.food.R
import com.anydrop.food.data.CartManager
import com.anydrop.food.data.CartSyncManager
import com.anydrop.food.databinding.ItemOrderCardBinding
import com.anydrop.food.network.ApiClient
import com.anydrop.food.network.OrderHistoryEntry
import com.anydrop.food.ui.cart.CartBottomSheetFragment
import com.anydrop.food.ui.common.InAppNotifier
import com.anydrop.food.ui.orders.RateOrderDialog
import kotlinx.coroutines.launch

/**
 * I1 (Reorder button, docs/features.md Phase I). Tapping "Reorder" on a
 * past order refills the cart with that order's items and opens the cart
 * sheet — instead of re-browsing the restaurant menu from scratch.
 *
 * Re-fetches both the order's own line items (GET /orders/{id} — has
 * menu_item_id + addon ids/prices at order time) AND the restaurant's
 * *current* live menu (GET restaurants/menu.php), then matches by
 * menu_item_id so price/availability is always today's, never a stale
 * snapshot from whenever the order was placed. A menu item that's been
 * deleted/86'd since, or an addon no longer offered on it, is silently
 * dropped from that one line rather than failing the whole reorder —
 * matches the pattern flagged in features.md's own I1 note ("decide
 * whether to silently skip them or show a note"): skip + tell the user
 * how many were skipped, don't block the rest of the order.
 */
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

            binding.btnReorder.setOnClickListener {
                val activity = binding.root.context as? AppCompatActivity ?: return@setOnClickListener
                reorder(activity, order)
            }
        }

        private fun reorder(activity: AppCompatActivity, order: OrderHistoryEntry) {
            val button = binding.btnReorder
            button.isEnabled = false
            val api = ApiClient.create(activity)
            activity.lifecycleScope.launch {
                try {
                    val orderDetail = api.getOrder(order.id).body()?.data?.order
                    val pastItems = orderDetail?.items.orEmpty()
                    if (pastItems.isEmpty()) {
                        InAppNotifier.show(activity, activity.getString(R.string.reorder_none_available), InAppNotifier.Type.ERROR)
                        return@launch
                    }

                    val menu = api.getMenu(order.restaurantId).body()?.data
                    val liveItemsById = menu?.categories.orEmpty()
                        .flatMap { it.items }
                        .associateBy { it.id }

                    var addedCount = 0
                    var skippedCount = 0
                    for (line in pastItems) {
                        val liveItem = line.menuItemId?.let { liveItemsById[it] }
                        if (liveItem == null) {
                            skippedCount++
                            continue
                        }
                        val liveAddonIds = liveItem.addons.map { it.id }.toSet()
                        val addonIds = line.addons.map { it.id }.filter { it in liveAddonIds }
                        CartManager.setCustomized(
                            restaurantId = order.restaurantId,
                            item = liveItem,
                            quantity = line.quantity,
                            addonIds = addonIds,
                            specialInstructions = null,
                            restaurantName = order.restaurantName
                        )
                        addedCount++
                    }

                    if (addedCount == 0) {
                        InAppNotifier.show(activity, activity.getString(R.string.reorder_none_available), InAppNotifier.Type.ERROR)
                        return@launch
                    }

                    CartSyncManager.scheduleSync(activity)

                    val message = if (skippedCount > 0) {
                        activity.getString(R.string.reorder_some_items_unavailable, skippedCount)
                    } else {
                        activity.getString(R.string.reorder_added_to_cart)
                    }
                    InAppNotifier.show(activity, message, InAppNotifier.Type.SUCCESS)

                    CartBottomSheetFragment().show(activity.supportFragmentManager, "cart")
                } catch (e: Exception) {
                    InAppNotifier.show(activity, activity.getString(R.string.reorder_failed), InAppNotifier.Type.ERROR)
                } finally {
                    button.isEnabled = true
                }
            }
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
