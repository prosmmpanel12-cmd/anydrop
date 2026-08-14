package com.anydrop.food.ui.restaurant

import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import androidx.lifecycle.LifecycleCoroutineScope
import androidx.recyclerview.widget.RecyclerView
import coil.load
import com.anydrop.food.R
import com.anydrop.food.data.CartManager
import com.anydrop.food.data.CartSyncManager
import com.anydrop.food.data.FavoritesManager
import com.anydrop.food.ui.common.CartAddHelper
import com.anydrop.food.ui.common.QtyStepperTransition
import com.anydrop.food.databinding.ItemMenuCategoryHeaderBinding
import com.anydrop.food.databinding.ItemMenuItemBinding
import com.anydrop.food.network.MenuCategory
import com.anydrop.food.network.MenuItem

private const val TYPE_HEADER = 0
private const val TYPE_ITEM = 1

private sealed class Row {
    data class Header(val title: String) : Row()
    data class Item(val item: MenuItem) : Row()
}

class MenuAdapter(
    private val restaurantId: Int,
    // Used only to word the bug-1.7 confirmation dialog if the cart already
    // holds items from a different restaurant (e.g. added via Home's
    // Popular row) when the customer tries to add from this menu instead.
    private val restaurantName: String?,
    private val lifecycleScope: LifecycleCoroutineScope,
    private val onDishClick: (MenuItem) -> Unit,
    private val onCartChanged: () -> Unit
) : RecyclerView.Adapter<RecyclerView.ViewHolder>() {

    private val rows = mutableListOf<Row>()
    // Optimistic bookmark-state overrides keyed by menu item id — MenuItem itself is
    // immutable, so toggles from FavoritesManager are tracked here instead of mutating rows.
    private val savedOverrides = mutableMapOf<Int, Boolean>()

    fun submit(categories: List<MenuCategory>) {
        rows.clear()
        categories.forEach { category ->
            if (category.items.isNotEmpty()) {
                rows.add(Row.Header(category.name))
                category.items.forEach { rows.add(Row.Item(it)) }
            }
        }
        notifyDataSetChanged()
    }

    /** Current bookmark state for [itemId] as this adapter sees it right
     * now (override if one exists, else the item's own last-fetched
     * value) — passed into [com.anydrop.food.ui.itemdetail.ItemDetailBottomSheetFragment.newInstance]
     * so re-opening the sheet doesn't show a stale icon after a save done
     * from the card. */
    fun currentSavedState(itemId: Int): Boolean {
        savedOverrides[itemId]?.let { return it }
        return rows.firstOrNull { it is Row.Item && it.item.id == itemId }
            ?.let { (it as Row.Item).item.isSaved } ?: false
    }

    /** Bookmark toggled from *inside* the item-detail sheet — updates this
     * card's icon immediately instead of waiting for an unrelated rebind. */
    fun setSavedState(itemId: Int, saved: Boolean) {
        savedOverrides[itemId] = saved
        val position = rows.indexOfFirst { it is Row.Item && it.item.id == itemId }
        if (position != -1) notifyItemChanged(position)
    }

    /** Re-binds one dish's row so its qty stepper/ADD button reflects
     * whatever [CartManager] now holds for it — used after the item-detail
     * sheet adds/updates/removes this dish, since that sheet's own "Add
     * item" button doesn't go through this adapter's own click handlers. */
    fun refreshCartUi(itemId: Int) {
        val position = rows.indexOfFirst { it is Row.Item && it.item.id == itemId }
        if (position != -1) notifyItemChanged(position)
    }

    /** Adapter position of [itemId]'s row, or null if it's not currently
     * shown (e.g. filtered out) — used by a shared item link (bug 3) to
     * scroll straight to the dish it points at. */
    fun findItemPosition(itemId: Int): Int? {
        val position = rows.indexOfFirst { it is Row.Item && it.item.id == itemId }
        return if (position != -1) position else null
    }

    override fun getItemViewType(position: Int) = when (rows[position]) {
        is Row.Header -> TYPE_HEADER
        is Row.Item -> TYPE_ITEM
    }

    override fun onCreateViewHolder(parent: ViewGroup, viewType: Int): RecyclerView.ViewHolder {
        return if (viewType == TYPE_HEADER) {
            val binding = ItemMenuCategoryHeaderBinding.inflate(LayoutInflater.from(parent.context), parent, false)
            HeaderVH(binding)
        } else {
            val binding = ItemMenuItemBinding.inflate(LayoutInflater.from(parent.context), parent, false)
            ItemVH(binding)
        }
    }

    override fun onBindViewHolder(holder: RecyclerView.ViewHolder, position: Int) {
        when (val row = rows[position]) {
            is Row.Header -> (holder as HeaderVH).bind(row.title)
            is Row.Item -> (holder as ItemVH).bind(row.item)
        }
    }

    override fun getItemCount() = rows.size

    private inner class HeaderVH(private val binding: ItemMenuCategoryHeaderBinding) :
        RecyclerView.ViewHolder(binding.root) {
        fun bind(title: String) {
            binding.root.text = title
        }
    }

    private inner class ItemVH(private val binding: ItemMenuItemBinding) :
        RecyclerView.ViewHolder(binding.root) {

        fun bind(item: MenuItem) {
            binding.itemName.text = item.name
            binding.itemDescription.text = item.description ?: ""
            binding.itemPrice.text = "₹${item.price.toInt()}"

            binding.vegBadge.setBackgroundResource(
                if (item.isVeg) R.drawable.bg_badge_veg else R.drawable.bg_badge_nonveg
            )

            // "Highly reordered" pill (features.md §3) — reuses the
            // existing is_bestseller flag rather than adding a new field.
            binding.itemHighlyReordered.visibility =
                if (item.isBestseller) View.VISIBLE else View.GONE

            // Discount corner badge (features.md §4) — reuses the existing
            // discount_percent field, no backend change needed.
            if (item.discountPercent > 0) {
                binding.itemDiscountBadge.text =
                    binding.root.context.getString(R.string.percent_off, item.discountPercent.toInt())
                binding.itemDiscountBadge.visibility = View.VISIBLE
            } else {
                binding.itemDiscountBadge.visibility = View.GONE
            }

            if (!item.imageUrl.isNullOrBlank()) {
                binding.itemImage.load(item.imageUrl)
            } else {
                binding.itemImage.setImageDrawable(null)
            }

            // bugs.md §6.3 follow-up — out of stock. Previously these
            // items never reached the app at all (menu.php filtered them
            // out server-side), so there was nothing to render here.
            binding.itemOutOfStock.visibility = if (!item.isAvailable) View.VISIBLE else View.GONE
            binding.root.alpha = if (item.isAvailable) 1.0f else 0.5f
            binding.itemImage.alpha = if (item.isAvailable) 1.0f else 0.6f
            // ADD button / qty stepper both hidden rather than just
            // disabled — a dimmed-but-tappable-looking button reads as a
            // bug, not as "unavailable".
            if (!item.isAvailable) {
                binding.btnAdd.visibility = View.GONE
                binding.qtyStepper.visibility = View.GONE
            }

            val isSaved = savedOverrides[item.id] ?: item.isSaved
            binding.itemBookmark.setImageResource(
                if (isSaved) R.drawable.ic_bookmark_filled else R.drawable.ic_bookmark_outline
            )
            binding.itemBookmark.setOnClickListener {
                FavoritesManager.toggle(
                    context = binding.root.context,
                    scope = lifecycleScope,
                    favoriteType = "menu_item",
                    favoriteId = item.id,
                    currentlySaved = savedOverrides[item.id] ?: item.isSaved,
                    onResult = { newState ->
                        savedOverrides[item.id] = newState
                        binding.itemBookmark.setImageResource(
                            if (newState) R.drawable.ic_bookmark_filled else R.drawable.ic_bookmark_outline
                        )
                    }
                )
            }

            // Out-of-stock rows skip qty/cart wiring entirely — the
            // controls are hidden above, and price_cart() (bugs.md §6.3's
            // server-side check) would reject an add anyway, but no point
            // wiring click listeners onto GONE views.
            if (item.isAvailable) {
                setQtyUiImmediate(item)

                binding.btnAdd.setOnClickListener {
                    CartAddHelper.add(binding.root.context, restaurantId, restaurantName, item) {
                        refreshQtyUi(item)
                        onCartChanged()
                    }
                }
                binding.btnIncrease.setOnClickListener {
                    CartAddHelper.add(binding.root.context, restaurantId, restaurantName, item) {
                        refreshQtyUi(item)
                        onCartChanged()
                    }
                }
                binding.btnDecrease.setOnClickListener {
                    CartManager.decrease(restaurantId, item)
                    CartSyncManager.scheduleSync(binding.root.context)
                    refreshQtyUi(item)
                    onCartChanged()
                }
            }

            // Card body opens the dish detail/customization sheet (§2.6/1.9) —
            // ADD/stepper/bookmark clicks above stop here via their own
            // listeners, so they never bubble up to this. Same pattern as
            // PopularItemsAdapter/SearchResultsAdapter.
            binding.root.setOnClickListener { onDishClick(item) }
        }

        // Instant, no-animation state set — use only from bind() so a
        // recycled row never flashes a leftover mid-animation frame from
        // whatever it was previously bound to.
        private fun setQtyUiImmediate(item: MenuItem) {
            val qty = CartManager.quantityOf(restaurantId, item.id)
            if (qty > 0) binding.itemQuantity.text = qty.toString()
            QtyStepperTransition.setImmediate(binding.qtyStepper, binding.btnAdd, showStepper = qty > 0)
        }

        // Animated toggle — use from add/increase/decrease click callbacks
        // only, so the ADD button <-> stepper swap actually animates.
        private fun refreshQtyUi(item: MenuItem) {
            val qty = CartManager.quantityOf(restaurantId, item.id)
            if (qty > 0) {
                binding.itemQuantity.text = qty.toString()
                QtyStepperTransition.show(binding.qtyStepper, binding.btnAdd)
            } else {
                QtyStepperTransition.hide(binding.qtyStepper, binding.btnAdd)
            }
        }
    }
}
