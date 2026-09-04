package com.anydrop.food.ui.profile

import android.view.LayoutInflater
import android.view.ViewGroup
import androidx.lifecycle.LifecycleCoroutineScope
import androidx.recyclerview.widget.RecyclerView
import coil.load
import com.anydrop.food.R
import com.anydrop.food.data.FavoritesManager
import com.anydrop.food.databinding.ItemSavedDishBinding
import com.anydrop.food.network.ApiClient
import com.anydrop.food.network.FavoriteItem

class SavedDishAdapter(
    private val lifecycleScope: LifecycleCoroutineScope,
    private val onClick: (FavoriteItem) -> Unit,
    private val onRemoved: (FavoriteItem) -> Unit
) : RecyclerView.Adapter<SavedDishAdapter.VH>() {

    private val items = mutableListOf<FavoriteItem>()

    fun submit(list: List<FavoriteItem>) {
        items.clear()
        items.addAll(list)
        notifyDataSetChanged()
    }

    override fun onCreateViewHolder(parent: ViewGroup, viewType: Int): VH {
        val binding = ItemSavedDishBinding.inflate(LayoutInflater.from(parent.context), parent, false)
        return VH(binding)
    }

    override fun onBindViewHolder(holder: VH, position: Int) = holder.bind(items[position])

    override fun getItemCount() = items.size

    inner class VH(private val binding: ItemSavedDishBinding) : RecyclerView.ViewHolder(binding.root) {
        fun bind(item: FavoriteItem) {
            binding.savedDishName.text = item.name
            binding.savedDishVegDot.setBackgroundResource(
                if (item.isVeg) R.drawable.dot_veg_fill else R.drawable.dot_nonveg_fill
            )
            binding.savedDishPriceAndRestaurant.text = binding.root.context.getString(
                R.string.dish_price_and_restaurant_format,
                item.price.toInt(),
                item.restaurantName ?: ""
            )

            if (!item.imageUrl.isNullOrBlank()) {
                binding.savedDishImage.load(ApiClient.baseUrlForStaticFiles(binding.root.context) + item.imageUrl) {
                    placeholder(R.drawable.ic_restaurant)
                    error(R.drawable.ic_restaurant)
                    crossfade(true)
                }
            } else {
                binding.savedDishImage.setImageResource(R.drawable.ic_restaurant)
            }

            binding.root.setOnClickListener { onClick(item) }
            binding.savedDishBookmark.setOnClickListener {
                FavoritesManager.toggle(
                    context = binding.root.context,
                    scope = lifecycleScope,
                    favoriteType = "menu_item",
                    favoriteId = item.id,
                    currentlySaved = true,
                    onResult = { newState ->
                        if (!newState) {
                            val position = items.indexOf(item)
                            if (position != -1) {
                                items.removeAt(position)
                                notifyItemRemoved(position)
                                onRemoved(item)
                            }
                        }
                    }
                )
            }
        }
    }
}
