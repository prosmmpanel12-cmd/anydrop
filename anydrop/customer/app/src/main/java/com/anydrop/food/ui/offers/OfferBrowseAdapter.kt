package com.anydrop.food.ui.offers

import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import androidx.recyclerview.widget.RecyclerView
import coil.load
import com.anydrop.food.R
import com.anydrop.food.data.CartManager
import com.anydrop.food.data.CartSyncManager
import com.anydrop.food.databinding.ItemOfferBrowseDishBinding
import com.anydrop.food.databinding.ItemOfferBrowseRestaurantHeaderBinding
import com.anydrop.food.network.ApiClient
import com.anydrop.food.network.OfferBrowseItem
import com.anydrop.food.network.OfferBrowseRestaurant
import com.anydrop.food.network.OffersBrowseResult
import com.anydrop.food.network.toMenuItem
import com.anydrop.food.ui.common.CartAddHelper
import com.anydrop.food.ui.common.QtyStepperTransition

/**
 * Offers screen (docs/33/34/35) — flat RecyclerView adapter over a sealed
 * row type, same shape as SearchResultsAdapter.SearchRow. One
 * RestaurantHeader row followed by that restaurant's own DishRows, in
 * offers-browse.php's response order.
 */
sealed class OfferBrowseRow {
    data class RestaurantHeader(val restaurant: OfferBrowseRestaurant) : OfferBrowseRow()
    data class DishRow(val restaurant: OfferBrowseRestaurant, val item: OfferBrowseItem) : OfferBrowseRow()
}

class OfferBrowseAdapter(
    private val onRestaurantClick: (OfferBrowseRestaurant) -> Unit,
    private val onDishClick: (OfferBrowseRestaurant, OfferBrowseItem) -> Unit,
    private val onCartChanged: () -> Unit
) : RecyclerView.Adapter<RecyclerView.ViewHolder>() {

    private val rows = mutableListOf<OfferBrowseRow>()

    companion object {
        private const val TYPE_HEADER = 0
        private const val TYPE_DISH = 1
    }

    fun submit(result: OffersBrowseResult) {
        rows.clear()
        result.restaurants.forEach { restaurant ->
            rows.add(OfferBrowseRow.RestaurantHeader(restaurant))
            restaurant.items.forEach { item ->
                rows.add(OfferBrowseRow.DishRow(restaurant, item))
            }
        }
        notifyDataSetChanged()
    }

    fun isEmpty(): Boolean = rows.isEmpty()

    /** Re-binds every dish row for this item id after the item-detail sheet
     * adds/updates/removes it — same pattern as SearchResultsAdapter's own
     * refreshCartUi(), needed since the same dish can't appear twice here
     * (one section per restaurant) but the sheet's callback doesn't know
     * this adapter's row indices. */
    fun refreshCartUi(itemId: Int) {
        rows.forEachIndexed { index, row ->
            if (row is OfferBrowseRow.DishRow && row.item.id == itemId) notifyItemChanged(index)
        }
    }

    override fun getItemViewType(position: Int): Int = when (rows[position]) {
        is OfferBrowseRow.RestaurantHeader -> TYPE_HEADER
        is OfferBrowseRow.DishRow -> TYPE_DISH
    }

    override fun onCreateViewHolder(parent: ViewGroup, viewType: Int): RecyclerView.ViewHolder {
        val inflater = LayoutInflater.from(parent.context)
        return when (viewType) {
            TYPE_HEADER -> RestaurantHeaderVH(
                ItemOfferBrowseRestaurantHeaderBinding.inflate(inflater, parent, false)
            )
            else -> DishVH(ItemOfferBrowseDishBinding.inflate(inflater, parent, false))
        }
    }

    override fun onBindViewHolder(holder: RecyclerView.ViewHolder, position: Int) {
        when (val row = rows[position]) {
            is OfferBrowseRow.RestaurantHeader -> (holder as RestaurantHeaderVH).bind(row.restaurant)
            is OfferBrowseRow.DishRow -> (holder as DishVH).bind(row.restaurant, row.item)
        }
    }

    override fun getItemCount() = rows.size

    inner class RestaurantHeaderVH(private val binding: ItemOfferBrowseRestaurantHeaderBinding) :
        RecyclerView.ViewHolder(binding.root) {
        fun bind(restaurant: OfferBrowseRestaurant) {
            if (!restaurant.logoUrl.isNullOrBlank()) {
                binding.headerLogo.load(ApiClient.baseUrlForStaticFiles(binding.root.context) + restaurant.logoUrl) {
                    placeholder(R.drawable.ic_restaurant)
                    error(R.drawable.ic_restaurant)
                    crossfade(true)
                }
            } else {
                binding.headerLogo.setImageResource(R.drawable.ic_restaurant)
            }
            binding.headerRestaurantName.text = restaurant.name
            binding.headerRating.text = String.format("%.1f", restaurant.ratingAvg)
            if (restaurant.distanceKm != null) {
                binding.headerDistance.text = String.format("%.1f km", restaurant.distanceKm)
                binding.headerDistance.visibility = View.VISIBLE
            } else {
                binding.headerDistance.visibility = View.GONE
            }
            binding.headerOfferTitles.text = restaurant.offerTitles.joinToString(" • ")
            binding.root.setOnClickListener { onRestaurantClick(restaurant) }
        }
    }

    inner class DishVH(private val binding: ItemOfferBrowseDishBinding) :
        RecyclerView.ViewHolder(binding.root) {
        fun bind(restaurant: OfferBrowseRestaurant, item: OfferBrowseItem) {
            binding.dishName.text = item.name
            binding.dishPrice.text = "₹${item.price.toInt()}"
            binding.dishVegDot.setBackgroundResource(
                if (item.isVeg) R.drawable.dot_veg_fill else R.drawable.dot_nonveg_fill
            )

            // Every item on this screen has a badge by construction (see
            // offers-browse.php) — no visibility toggle needed, unlike
            // MenuAdapter/SearchResultsAdapter where most items don't.
            binding.dishOfferTag.text = item.offerTag

            if (!item.imageUrl.isNullOrBlank()) {
                binding.dishImage.load(ApiClient.baseUrlForStaticFiles(binding.root.context) + item.imageUrl) {
                    placeholder(R.drawable.ic_restaurant)
                    error(R.drawable.ic_restaurant)
                    crossfade(true)
                }
            } else {
                binding.dishImage.setImageResource(R.drawable.ic_restaurant)
            }

            setQtyUiImmediate(restaurant, item)

            // Bug 1.6 fix pattern (same as SearchResultsAdapter.DishVH):
            // ADD button / qty-stepper own their click listeners and
            // consume the touch, so only the card body opens the sheet.
            binding.btnAdd.setOnClickListener {
                CartAddHelper.add(binding.root.context, restaurant.id, restaurant.name, item.toMenuItem()) {
                    refreshQtyUi(restaurant, item)
                    onCartChanged()
                }
            }
            binding.btnIncrease.setOnClickListener {
                CartAddHelper.add(binding.root.context, restaurant.id, restaurant.name, item.toMenuItem()) {
                    refreshQtyUi(restaurant, item)
                    onCartChanged()
                }
            }
            binding.btnDecrease.setOnClickListener {
                CartManager.decrease(restaurant.id, item.toMenuItem())
                CartSyncManager.scheduleSync(binding.root.context)
                refreshQtyUi(restaurant, item)
                onCartChanged()
            }

            binding.root.setOnClickListener { onDishClick(restaurant, item) }
        }

        private fun setQtyUiImmediate(restaurant: OfferBrowseRestaurant, item: OfferBrowseItem) {
            val qty = CartManager.quantityOf(restaurant.id, item.id)
            if (qty > 0) binding.itemQuantity.text = qty.toString()
            QtyStepperTransition.setImmediate(binding.qtyStepper, binding.btnAdd, showStepper = qty > 0)
        }

        private fun refreshQtyUi(restaurant: OfferBrowseRestaurant, item: OfferBrowseItem) {
            val qty = CartManager.quantityOf(restaurant.id, item.id)
            if (qty > 0) {
                binding.itemQuantity.text = qty.toString()
                QtyStepperTransition.show(binding.qtyStepper, binding.btnAdd)
            } else {
                QtyStepperTransition.hide(binding.qtyStepper, binding.btnAdd)
            }
        }
    }
}
