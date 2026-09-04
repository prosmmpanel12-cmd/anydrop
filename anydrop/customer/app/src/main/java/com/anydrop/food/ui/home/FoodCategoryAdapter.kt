package com.anydrop.food.ui.home

import android.view.LayoutInflater
import android.view.ViewGroup
import androidx.recyclerview.widget.RecyclerView
import coil.load
import com.anydrop.food.R
import com.anydrop.food.databinding.ItemFoodCategoryBinding
import com.anydrop.food.network.FoodCategory

/**
 * Horizontal category chip row under the search bar on Home
 * (screenshot reference: All / Pizza / Rolls / Burger). Backed by
 * GET /home/categories.php — icons are admin-manageable image URLs,
 * with a local vector fallback if icon_url is null/fails to load.
 */
class FoodCategoryAdapter(
    private val onClick: (FoodCategory) -> Unit
) : RecyclerView.Adapter<FoodCategoryAdapter.VH>() {

    private val items = mutableListOf<FoodCategory>()
    private var selectedSlug: String? = null

    fun submit(list: List<FoodCategory>) {
        items.clear()
        items.addAll(list)
        notifyDataSetChanged()
    }

    fun setSelected(slug: String?) {
        selectedSlug = slug
        notifyDataSetChanged()
    }

    override fun onCreateViewHolder(parent: ViewGroup, viewType: Int): VH {
        val binding = ItemFoodCategoryBinding.inflate(LayoutInflater.from(parent.context), parent, false)
        return VH(binding)
    }

    override fun onBindViewHolder(holder: VH, position: Int) {
        holder.bind(items[position])
    }

    override fun getItemCount() = items.size

    inner class VH(private val binding: ItemFoodCategoryBinding) : RecyclerView.ViewHolder(binding.root) {
        fun bind(category: FoodCategory) {
            // The synthetic "Offers" chip (docs/33/34/35) opens its own
            // screen and never becomes the active category filter — it
            // must never render with the selection ring/badge either,
            // regardless of what selectedSlug currently is.
            val isSelected = category.slug == selectedSlug && category.slug != "__offers__"
            val ctx = binding.root.context

            binding.categoryName.text = category.name
            binding.categoryName.setTextColor(
                ctx.getColor(if (isSelected) R.color.anydrop_primary else R.color.text_primary)
            )

            // "Achha sa chooser": selection is now a visible ring + tinted
            // background around the icon, not just the label turning orange —
            // makes it obvious at a glance which category (if any) is active.
            binding.categoryCard.strokeWidth =
                if (isSelected) (2 * ctx.resources.displayMetrics.density).toInt() else 0
            binding.categoryCard.setCardBackgroundColor(
                ctx.getColor(if (isSelected) R.color.anydrop_primary_container else R.color.surface)
            )

            // Small × badge on the selected category only — tapping it clears
            // the filter back to "All" (same toggle-off logic as tapping the
            // category itself again), so undo is discoverable without having
            // to remember "tap it again to deselect".
            binding.categoryRemoveBadge.visibility = if (isSelected) android.view.View.VISIBLE else android.view.View.GONE
            binding.categoryRemoveBadge.setOnClickListener { onClick(category) }

            if (category.slug == "__offers__") {
                // Always null iconUrl for this synthetic entry — without
                // this branch it would fall through to the generic
                // ic_restaurant fallback below, same as any other category
                // with no admin-set icon.
                binding.categoryIcon.setImageResource(R.drawable.ic_offer_tag)
            } else if (!category.iconUrl.isNullOrBlank()) {
                binding.categoryIcon.load(category.iconUrl) {
                    placeholder(R.drawable.ic_restaurant)
                    error(R.drawable.ic_restaurant)
                    crossfade(true)
                }
            } else {
                binding.categoryIcon.setImageResource(R.drawable.ic_restaurant)
            }
            binding.root.setOnClickListener { onClick(category) }
        }
    }
}
