package com.anydrop.food.ui.home

import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import androidx.lifecycle.LifecycleCoroutineScope
import androidx.recyclerview.widget.RecyclerView
import coil.load
import com.anydrop.food.R
import com.anydrop.food.data.CartManager
import com.anydrop.food.data.FavoritesManager
import com.anydrop.food.ui.common.CartAddHelper
import com.anydrop.food.ui.common.QtyStepperTransition
import com.anydrop.food.databinding.ItemPopularDishBinding
import com.anydrop.food.network.PopularItem
import com.anydrop.food.network.toMenuItem

/**
 * "Popular dishes near you" horizontal row on Home, between the filter
 * chips and the restaurant list (§2.4 + §1.6). Same ADD/qty-stepper pattern
 * as MenuAdapter/item_menu_item.xml, and the same "card body opens the
 * restaurant, ADD button adds to cart without navigating" split as the
 * fix for bug 1.6 in SearchResultsAdapter.
 *
 * Cross-restaurant list, same as search/category results — adding an item
 * from a different restaurant than what's already in the cart now goes
 * through [CartAddHelper], which asks for confirmation instead of silently
 * wiping the cart (bug 1.7, docs/07_Phase_3.7_Bug_Tracker.md). This row is
 * exactly the scenario that surfaced that bug in the first place.
 */
class PopularItemsAdapter(
    private val lifecycleScope: LifecycleCoroutineScope,
    private val onDishClick: (PopularItem) -> Unit,
    private val onCartChanged: () -> Unit
) : RecyclerView.Adapter<PopularItemsAdapter.VH>() {

    private val items = mutableListOf<PopularItem>()
    // Optimistic bookmark-state overrides keyed by item id — PopularItem is
    // an immutable data class, same pattern as MenuAdapter.savedOverrides.
    private val savedOverrides = mutableMapOf<Int, Boolean>()

    fun submit(list: List<PopularItem>) {
        items.clear()
        items.addAll(list)
        notifyDataSetChanged()
    }

    fun isEmpty(): Boolean = items.isEmpty()

    /** Same "save" sync fix as MenuAdapter — current bookmark state as this
     * adapter sees it, for seeding the item-detail sheet's initial icon. */
    fun currentSavedState(itemId: Int): Boolean {
        savedOverrides[itemId]?.let { return it }
        return items.firstOrNull { it.id == itemId }?.isSaved ?: false
    }

    /** Bookmark toggled from inside the item-detail sheet — update this
     * card's icon immediately. */
    fun setSavedState(itemId: Int, saved: Boolean) {
        savedOverrides[itemId] = saved
        val position = items.indexOfFirst { it.id == itemId }
        if (position != -1) notifyItemChanged(position)
    }

    /** Re-binds one dish's row after the item-detail sheet adds/updates/
     * removes it, so this card's qty stepper doesn't go stale. */
    fun refreshCartUi(itemId: Int) {
        val position = items.indexOfFirst { it.id == itemId }
        if (position != -1) notifyItemChanged(position)
    }

    override fun onCreateViewHolder(parent: ViewGroup, viewType: Int): VH {
        val binding = ItemPopularDishBinding.inflate(LayoutInflater.from(parent.context), parent, false)
        return VH(binding)
    }

    override fun onBindViewHolder(holder: VH, position: Int) = holder.bind(items[position])

    override fun getItemCount() = items.size

    inner class VH(private val binding: ItemPopularDishBinding) :
        RecyclerView.ViewHolder(binding.root) {

        fun bind(item: PopularItem) {
            binding.dishName.text = item.name
            binding.dishPrice.text = "₹${item.price.toInt()}"
            binding.dishVegDot.setBackgroundResource(
                if (item.isVeg) R.drawable.dot_veg_fill else R.drawable.dot_nonveg_fill
            )
            binding.dishRestaurantTag.text =
                binding.root.context.getString(R.string.from_restaurant, item.restaurantName)

            // "Highly reordered" pill + discount corner badge (features.md
            // §3/§4) — same is_bestseller / discount_percent fields the
            // menu screen's MenuAdapter already binds, no backend change.
            binding.dishHighlyReordered.visibility =
                if (item.isBestseller) View.VISIBLE else View.GONE
            if (item.discountPercent > 0) {
                binding.dishDiscountBadge.text =
                    binding.root.context.getString(R.string.percent_off, item.discountPercent.toInt())
                binding.dishDiscountBadge.visibility = View.VISIBLE
            } else {
                binding.dishDiscountBadge.visibility = View.GONE
            }

            if (!item.imageUrl.isNullOrBlank()) {
                binding.dishImage.load(item.imageUrl) {
                    placeholder(R.drawable.ic_restaurant)
                    error(R.drawable.ic_restaurant)
                    crossfade(true)
                }
            } else {
                binding.dishImage.setImageResource(R.drawable.ic_restaurant)
            }

            val isSaved = savedOverrides[item.id] ?: item.isSaved
            binding.dishBookmark.setImageResource(
                if (isSaved) R.drawable.ic_bookmark_filled else R.drawable.ic_bookmark_outline
            )
            binding.dishBookmark.setOnClickListener {
                FavoritesManager.toggle(
                    context = binding.root.context,
                    scope = lifecycleScope,
                    favoriteType = "menu_item",
                    favoriteId = item.id,
                    currentlySaved = savedOverrides[item.id] ?: item.isSaved,
                    onResult = { newState ->
                        savedOverrides[item.id] = newState
                        binding.dishBookmark.setImageResource(
                            if (newState) R.drawable.ic_bookmark_filled else R.drawable.ic_bookmark_outline
                        )
                    }
                )
            }

            setQtyUiImmediate(item)

            binding.btnAdd.setOnClickListener {
                CartAddHelper.add(binding.root.context, item.restaurantId, item.restaurantName, item.toMenuItem()) {
                    refreshQtyUi(item)
                    onCartChanged()
                }
            }
            binding.btnIncrease.setOnClickListener {
                CartAddHelper.add(binding.root.context, item.restaurantId, item.restaurantName, item.toMenuItem()) {
                    refreshQtyUi(item)
                    onCartChanged()
                }
            }
            binding.btnDecrease.setOnClickListener {
                CartManager.decrease(item.restaurantId, item.toMenuItem())
                com.anydrop.food.data.CartSyncManager.scheduleSync(binding.root.context)
                refreshQtyUi(item)
                onCartChanged()
            }

            // Card body (image/name/price/tag) opens the dish's own detail
            // screen (bug 1.9) — ADD/stepper clicks above stop here via their
            // own listeners, so they never bubble up to this.
            binding.root.setOnClickListener { onDishClick(item) }
        }

        // Instant, no-animation state set — use only from bind() so a
        // recycled row never flashes a leftover mid-animation frame from
        // whatever it was previously bound to.
        private fun setQtyUiImmediate(item: PopularItem) {
            val qty = CartManager.quantityOf(item.restaurantId, item.id)
            if (qty > 0) binding.itemQuantity.text = qty.toString()
            QtyStepperTransition.setImmediate(binding.qtyStepper, binding.btnAdd, showStepper = qty > 0)
        }

        // Animated toggle — use from add/increase/decrease click callbacks
        // only, so the ADD button <-> stepper swap actually animates.
        private fun refreshQtyUi(item: PopularItem) {
            val qty = CartManager.quantityOf(item.restaurantId, item.id)
            if (qty > 0) {
                binding.itemQuantity.text = qty.toString()
                QtyStepperTransition.show(binding.qtyStepper, binding.btnAdd)
            } else {
                QtyStepperTransition.hide(binding.qtyStepper, binding.btnAdd)
            }
        }
    }
}
