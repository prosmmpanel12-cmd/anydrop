package com.anydrop.food.ui.restaurant

import android.view.LayoutInflater
import android.view.ViewGroup
import androidx.recyclerview.widget.RecyclerView
import coil.load
import com.anydrop.food.R
import com.anydrop.food.databinding.ItemMenuCategoryTabBinding
import com.anydrop.food.network.ApiClient
import com.anydrop.food.network.MenuCategory

/**
 * Horizontal in-menu category tab row, restyled to match Home's food-category
 * row (icon in a circle + name below) instead of the old plain-text Chip row.
 * Data source is unchanged — the same restaurant-owner-defined categories
 * from the menu response (MenuCategory.imageUrl for the icon, with a local
 * vector fallback), still jumps scroll position to that category's header
 * on tap, same as before.
 */
class MenuCategoryTabAdapter(
    private val categories: List<MenuCategory>,
    private val onTap: (Int) -> Unit
) : RecyclerView.Adapter<MenuCategoryTabAdapter.VH>() {

    private var selectedPosition = 0

    fun setSelected(position: Int) {
        if (position == selectedPosition) return
        val prev = selectedPosition
        selectedPosition = position
        notifyItemChanged(prev)
        notifyItemChanged(position)
    }

    override fun onCreateViewHolder(parent: ViewGroup, viewType: Int): VH {
        val binding = ItemMenuCategoryTabBinding.inflate(LayoutInflater.from(parent.context), parent, false)
        return VH(binding)
    }

    override fun onBindViewHolder(holder: VH, position: Int) {
        holder.bind(categories[position], position == selectedPosition)
    }

    override fun getItemCount() = categories.size

    inner class VH(private val binding: ItemMenuCategoryTabBinding) : RecyclerView.ViewHolder(binding.root) {
        fun bind(category: MenuCategory, isSelected: Boolean) {
            val ctx = binding.root.context

            binding.tabName.text = category.name
            binding.tabName.setTextColor(
                ctx.getColor(if (isSelected) R.color.anydrop_primary else R.color.text_primary)
            )
            binding.tabCard.strokeWidth =
                if (isSelected) (2 * ctx.resources.displayMetrics.density).toInt() else 0
            binding.tabCard.setCardBackgroundColor(
                ctx.getColor(if (isSelected) R.color.anydrop_primary_container else R.color.surface)
            )

            val iconUrl = category.imageUrl
            if (!iconUrl.isNullOrBlank()) {
                binding.tabIcon.load(ApiClient.baseUrlForStaticFiles(ctx) + iconUrl) {
                    placeholder(R.drawable.ic_restaurant)
                    error(R.drawable.ic_restaurant)
                    crossfade(true)
                }
            } else {
                binding.tabIcon.setImageResource(R.drawable.ic_restaurant)
            }

            binding.root.setOnClickListener {
                val pos = bindingAdapterPosition
                if (pos != RecyclerView.NO_POSITION) onTap(pos)
            }
        }
    }
}
