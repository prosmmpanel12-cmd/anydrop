package com.anydrop.food.ui.profile

import android.view.LayoutInflater
import android.view.ViewGroup
import androidx.lifecycle.LifecycleCoroutineScope
import androidx.recyclerview.widget.RecyclerView
import coil.load
import com.anydrop.food.R
import com.anydrop.food.data.FavoritesManager
import com.anydrop.food.databinding.ItemSavedRestaurantBinding
import com.anydrop.food.network.ApiClient
import com.anydrop.food.network.FavoriteRestaurant

/**
 * Every entry here is, by definition, currently saved — so the bookmark
 * icon only ever removes (no add path needed in this screen). Removing
 * updates the visible list immediately via onRemoved.
 */
class SavedRestaurantAdapter(
    private val lifecycleScope: LifecycleCoroutineScope,
    private val onClick: (FavoriteRestaurant) -> Unit,
    private val onRemoved: (FavoriteRestaurant) -> Unit
) : RecyclerView.Adapter<SavedRestaurantAdapter.VH>() {

    private val items = mutableListOf<FavoriteRestaurant>()

    fun submit(list: List<FavoriteRestaurant>) {
        items.clear()
        items.addAll(list)
        notifyDataSetChanged()
    }

    override fun onCreateViewHolder(parent: ViewGroup, viewType: Int): VH {
        val binding = ItemSavedRestaurantBinding.inflate(LayoutInflater.from(parent.context), parent, false)
        return VH(binding)
    }

    override fun onBindViewHolder(holder: VH, position: Int) = holder.bind(items[position])

    override fun getItemCount() = items.size

    inner class VH(private val binding: ItemSavedRestaurantBinding) : RecyclerView.ViewHolder(binding.root) {
        fun bind(restaurant: FavoriteRestaurant) {
            binding.savedRestaurantName.text = restaurant.name
            binding.savedRestaurantRating.text = "★ ${String.format("%.1f", restaurant.ratingAvg)}"

            val imageUrl = restaurant.coverUrl ?: restaurant.logoUrl
            if (!imageUrl.isNullOrBlank()) {
                binding.savedRestaurantImage.load(ApiClient.baseUrlForStaticFiles(binding.root.context) + imageUrl) {
                    placeholder(R.drawable.ic_restaurant)
                    error(R.drawable.ic_restaurant)
                    crossfade(true)
                }
            } else {
                binding.savedRestaurantImage.setImageResource(R.drawable.ic_restaurant)
            }

            binding.root.setOnClickListener { onClick(restaurant) }
            binding.savedRestaurantBookmark.setOnClickListener {
                FavoritesManager.toggle(
                    context = binding.root.context,
                    scope = lifecycleScope,
                    favoriteType = "restaurant",
                    favoriteId = restaurant.id,
                    currentlySaved = true, // always true here — see class doc
                    onResult = { newState ->
                        if (!newState) {
                            val position = items.indexOf(restaurant)
                            if (position != -1) {
                                items.removeAt(position)
                                notifyItemRemoved(position)
                                onRemoved(restaurant)
                            }
                        }
                        // If the removal itself failed and rolled back to
                        // true, FavoritesManager already toasted the error —
                        // nothing further to do, the item stays in the list.
                    }
                )
            }
        }
    }
}
