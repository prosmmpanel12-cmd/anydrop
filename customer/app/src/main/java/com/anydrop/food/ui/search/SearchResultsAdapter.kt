package com.anydrop.food.ui.search

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
import com.anydrop.food.databinding.ItemRestaurantBinding
import com.anydrop.food.databinding.ItemSearchDishBinding
import com.anydrop.food.databinding.ItemSearchSectionHeaderBinding
import com.anydrop.food.network.ApiClient
import com.anydrop.food.network.Restaurant
import com.anydrop.food.network.SearchItem
import com.anydrop.food.network.toMenuItem
import com.google.android.material.chip.Chip

/**
 * Search results screen adapter — shows, in order:
 *   1. Matching restaurant cards (name/cuisine match, e.g. searching
 *      "Burger King" itself)
 *   2. "Menu items" header + that restaurant's own dishes
 *   3. "Also available at" header + the same dish from OTHER restaurants,
 *      each item card tagged "from <Restaurant Name>" — this is what lets
 *      a dish search (or a restaurant search) surface cross-restaurant
 *      alternatives without ever losing track of which restaurant an item
 *      belongs to.
 */
sealed class SearchRow {
    data class RestaurantRow(val restaurant: Restaurant) : SearchRow()
    data class SectionHeader(val title: String) : SearchRow()
    data class DishRow(val item: SearchItem) : SearchRow()
}

class SearchResultsAdapter(
    private val lifecycleScope: LifecycleCoroutineScope,
    private val onRestaurantClick: (Restaurant) -> Unit,
    private val onDishClick: (SearchItem) -> Unit,
    private val onCartChanged: () -> Unit
) : RecyclerView.Adapter<RecyclerView.ViewHolder>() {

    private val rows = mutableListOf<SearchRow>()
    // Optimistic bookmark-state overrides keyed by item id — SearchItem is
    // an immutable data class, same pattern as MenuAdapter.savedOverrides.
    private val savedOverrides = mutableMapOf<Int, Boolean>()

    companion object {
        private const val TYPE_RESTAURANT = 0
        private const val TYPE_HEADER = 1
        private const val TYPE_DISH = 2
    }

    /** Same "save" sync fix as MenuAdapter — current dish-bookmark state as
     * this adapter sees it, for seeding the item-detail sheet's initial
     * icon (a dish can appear twice here — own-restaurant + "also
     * available at" — but both rows share the same item id/override). */
    fun currentSavedState(itemId: Int): Boolean {
        savedOverrides[itemId]?.let { return it }
        return rows.firstOrNull { it is SearchRow.DishRow && it.item.id == itemId }
            ?.let { (it as SearchRow.DishRow).item.isSaved } ?: false
    }

    /** Bookmark toggled from inside the item-detail sheet — updates every
     * row showing this dish (it may appear in both the own-restaurant and
     * "also available at" sections). */
    fun setSavedState(itemId: Int, saved: Boolean) {
        savedOverrides[itemId] = saved
        rows.forEachIndexed { index, row ->
            if (row is SearchRow.DishRow && row.item.id == itemId) notifyItemChanged(index)
        }
    }

    /** Bug fix (2026-08-10, H2) — same shared-cache fix as RestaurantAdapter;
     * call from the host screen's onResume() so a restaurant bookmarked
     * elsewhere (e.g. RestaurantDetailActivity reached from a cart card)
     * shows correctly here without a full search re-run. Cheap/local-only —
     * restaurant rows in search results are a small fraction of the list. */
    fun refreshSavedStates() {
        rows.forEachIndexed { index, row ->
            if (row is SearchRow.RestaurantRow) notifyItemChanged(index)
        }
    }

    /** Re-binds every row showing this dish after the item-detail sheet
     * adds/updates/removes it, so stepper UI doesn't go stale. */
    fun refreshCartUi(itemId: Int) {
        rows.forEachIndexed { index, row ->
            if (row is SearchRow.DishRow && row.item.id == itemId) notifyItemChanged(index)
        }
    }

    /**
     * Builds the row list from a search response's restaurants + items.
     * Own-restaurant items appear right after the matching restaurant
     * card(s); cross-restaurant matches for the same dish are grouped
     * under an "Also available at" header.
     */
    fun submit(restaurants: List<Restaurant>, items: List<SearchItem>) {
        rows.clear()

        restaurants.forEach { rows.add(SearchRow.RestaurantRow(it)) }

        val ownItems = items.filter { !it.isCrossRestaurantMatch }
        val crossItems = items.filter { it.isCrossRestaurantMatch }

        if (ownItems.isNotEmpty()) {
            rows.add(SearchRow.SectionHeader("Dishes"))
            ownItems.forEach { rows.add(SearchRow.DishRow(it)) }
        }
        if (crossItems.isNotEmpty()) {
            rows.add(SearchRow.SectionHeader("Also available at"))
            crossItems.forEach { rows.add(SearchRow.DishRow(it)) }
        }

        notifyDataSetChanged()
    }

    fun isEmpty(): Boolean = rows.isEmpty()

    override fun getItemViewType(position: Int): Int = when (rows[position]) {
        is SearchRow.RestaurantRow -> TYPE_RESTAURANT
        is SearchRow.SectionHeader -> TYPE_HEADER
        is SearchRow.DishRow -> TYPE_DISH
    }

    override fun onCreateViewHolder(parent: ViewGroup, viewType: Int): RecyclerView.ViewHolder {
        val inflater = LayoutInflater.from(parent.context)
        return when (viewType) {
            TYPE_RESTAURANT -> RestaurantVH(ItemRestaurantBinding.inflate(inflater, parent, false))
            TYPE_HEADER -> HeaderVH(ItemSearchSectionHeaderBinding.inflate(inflater, parent, false))
            else -> DishVH(ItemSearchDishBinding.inflate(inflater, parent, false))
        }
    }

    override fun onBindViewHolder(holder: RecyclerView.ViewHolder, position: Int) {
        when (val row = rows[position]) {
            is SearchRow.RestaurantRow -> (holder as RestaurantVH).bind(row.restaurant)
            is SearchRow.SectionHeader -> (holder as HeaderVH).bind(row.title)
            is SearchRow.DishRow -> (holder as DishVH).bind(row.item)
        }
    }

    override fun getItemCount() = rows.size

    inner class RestaurantVH(private val binding: ItemRestaurantBinding) :
        RecyclerView.ViewHolder(binding.root) {
        fun bind(restaurant: Restaurant) {
            binding.restaurantName.text = restaurant.name
            binding.restaurantCuisines.text = restaurant.cuisineTags ?: restaurant.address ?: ""
            binding.restaurantRating.text = String.format("%.1f", restaurant.ratingAvg)
            // features.md §5 — cross-fading ETA/distance <-> "Near & Fast" meta
            // line (RotatingEtaView), shown regardless of open/closed status.
            binding.restaurantEta.bind(restaurant.etaMinutes, restaurant.distanceKm)
            binding.restaurantStatus.text = when {
                restaurant.isOpenNow -> "Open"
                restaurant.isPaused -> binding.root.context.getString(R.string.restaurant_temporarily_unavailable)
                else -> "Closed"
            }
            binding.restaurantStatus.setTextColor(
                binding.root.context.getColor(
                    when {
                        restaurant.isOpenNow -> R.color.success_fg
                        restaurant.isPaused -> R.color.paused_fg
                        else -> R.color.error_fg
                    }
                )
            )
            // Same closed-restaurant dimming as the Home restaurant list
            // (RestaurantAdapter) — keeps search results visually consistent.
            binding.root.alpha = if (restaurant.isOpenNow) 1.0f else 0.5f
            if (!restaurant.offerBadgeText.isNullOrBlank()) {
                binding.restaurantOfferBadge.text = restaurant.offerBadgeText
                binding.restaurantOfferBadge.visibility = android.view.View.VISIBLE
            } else {
                binding.restaurantOfferBadge.visibility = android.view.View.GONE
            }
            binding.restaurantTagsGroup.removeAllViews()
            val tags = restaurant.tags.orEmpty()
            if (tags.isNotEmpty()) {
                binding.restaurantTagsGroup.visibility = android.view.View.VISIBLE
                tags.forEach { tag ->
                    val chip = Chip(binding.root.context).apply {
                        text = tag.name
                        isClickable = false
                        isCheckable = false
                        textSize = 11f
                        setChipBackgroundColorResource(R.color.anydrop_primary_container)
                        setTextColor(binding.root.context.getColor(R.color.anydrop_primary))
                        chipStrokeWidth = 0f
                    }
                    binding.restaurantTagsGroup.addView(chip)
                }
            } else {
                binding.restaurantTagsGroup.visibility = android.view.View.GONE
            }
            val galleryPhotos = restaurant.gallery.orEmpty().map {
                com.anydrop.food.ui.common.DishPhotoCarouselView.Photo(it.imageUrl, it.dishName, it.price)
            }
            binding.restaurantCarousel.setPhotos(galleryPhotos, restaurant.coverUrl)
            binding.root.setOnClickListener { onRestaurantClick(restaurant) }

            val isSaved = FavoritesManager.isSaved("restaurant", restaurant.id, restaurant.isSaved)
            binding.restaurantBookmark.setImageResource(
                if (isSaved) R.drawable.ic_bookmark_filled else R.drawable.ic_bookmark_outline
            )
            binding.restaurantBookmark.setOnClickListener {
                FavoritesManager.toggle(
                    context = binding.root.context,
                    scope = lifecycleScope,
                    favoriteType = "restaurant",
                    favoriteId = restaurant.id,
                    currentlySaved = FavoritesManager.isSaved("restaurant", restaurant.id, restaurant.isSaved),
                    onResult = { newState ->
                        binding.restaurantBookmark.setImageResource(
                            if (newState) R.drawable.ic_bookmark_filled else R.drawable.ic_bookmark_outline
                        )
                    }
                )
            }
        }
    }

    inner class HeaderVH(private val binding: ItemSearchSectionHeaderBinding) :
        RecyclerView.ViewHolder(binding.root) {
        fun bind(title: String) {
            binding.sectionHeaderText.text = title
        }
    }

    inner class DishVH(private val binding: ItemSearchDishBinding) :
        RecyclerView.ViewHolder(binding.root) {
        fun bind(item: SearchItem) {
            binding.dishName.text = item.name
            binding.dishPrice.text = "₹${item.price.toInt()}"
            binding.dishVegDot.setBackgroundResource(
                if (item.isVeg) R.drawable.dot_veg_fill else R.drawable.dot_nonveg_fill
            )
            // The requirement: every item card carries a visible tag naming
            // its source restaurant, whether it's the searched restaurant's
            // own dish or a cross-restaurant "also available at" match.
            binding.dishRestaurantTag.text =
                binding.root.context.getString(R.string.from_restaurant, item.restaurantName)

            // Offers Engine tag pill (docs/33) — same offerTag field/pattern
            // as MenuAdapter.ItemVH.bind(), text straight from the server.
            if (!item.offerTag.isNullOrBlank()) {
                binding.dishOfferTag.text = item.offerTag
                binding.dishOfferTag.visibility = View.VISIBLE
            } else {
                binding.dishOfferTag.visibility = View.GONE
            }

            if (!item.imageUrl.isNullOrBlank()) {
                binding.dishImage.load(ApiClient.baseUrlForStaticFiles(binding.root.context) + item.imageUrl) {
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

            // Bug 1.6 fix: the inner ADD button / qty-stepper have their own
            // click listeners (set below) and consume the touch, so they
            // never bubble up to root's onDishClick — only tapping the card
            // body (image/name/price/tag) opens the restaurant.
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
                CartSyncManager.scheduleSync(binding.root.context)
                refreshQtyUi(item)
                onCartChanged()
            }

            binding.root.setOnClickListener { onDishClick(item) }
        }

        // Instant, no-animation state set — use only from bind() so a
        // recycled row never flashes a leftover mid-animation frame from
        // whatever it was previously bound to.
        private fun setQtyUiImmediate(item: SearchItem) {
            val qty = CartManager.quantityOf(item.restaurantId, item.id)
            if (qty > 0) binding.itemQuantity.text = qty.toString()
            QtyStepperTransition.setImmediate(binding.qtyStepper, binding.btnAdd, showStepper = qty > 0)
        }

        // Animated toggle — use from add/increase/decrease click callbacks
        // only, so the ADD button <-> stepper swap actually animates.
        private fun refreshQtyUi(item: SearchItem) {
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
