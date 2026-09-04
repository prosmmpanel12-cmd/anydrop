package com.anydrop.restaurant.ui.menu

import android.content.Context
import android.view.LayoutInflater
import android.view.ViewGroup
import androidx.core.content.ContextCompat
import androidx.recyclerview.widget.RecyclerView
import coil.load
import com.anydrop.restaurant.R
import com.anydrop.restaurant.databinding.ItemMenuFoodBinding
import com.anydrop.restaurant.network.ApiClient
import com.anydrop.restaurant.network.MenuItem

/**
 * Tier 1 "Menu Management" (docs/18) — food items within one category card.
 * Availability switch fires immediately (out-of-stock toggle is meant to be
 * a quick action, not gated behind an edit dialog); edit/delete open the
 * full form / confirm dialog via the callbacks below.
 */
class MenuItemAdapter(
    private val context: Context,
    private val onToggleAvailable: (MenuItem, Boolean) -> Unit,
    private val onEdit: (MenuItem) -> Unit,
    private val onDelete: (MenuItem) -> Unit
) : RecyclerView.Adapter<MenuItemAdapter.ItemViewHolder>() {

    private val items = mutableListOf<MenuItem>()

    fun submitList(newItems: List<MenuItem>) {
        items.clear()
        items.addAll(newItems)
        notifyDataSetChanged()
    }

    override fun onCreateViewHolder(parent: ViewGroup, viewType: Int): ItemViewHolder {
        val binding = ItemMenuFoodBinding.inflate(LayoutInflater.from(parent.context), parent, false)
        return ItemViewHolder(binding)
    }

    override fun onBindViewHolder(holder: ItemViewHolder, position: Int) {
        holder.bind(items[position])
    }

    override fun getItemCount() = items.size

    inner class ItemViewHolder(private val binding: ItemMenuFoodBinding) : RecyclerView.ViewHolder(binding.root) {
        fun bind(item: MenuItem) {
            binding.itemNameText.text = item.name
            binding.itemPriceText.text = "₹${"%.2f".format(item.price)}"

            // §5 photo thumbnail slot — placeholder icon (inset, tinted, so
            // it reads as "no photo yet" rather than a broken image) until
            // photo upload ships and image_url starts getting populated;
            // real photos fill the full 44dp square edge-to-edge.
            val secondaryTint = ContextCompat.getColor(context, R.color.text_secondary)
            if (!item.imageUrl.isNullOrBlank()) {
                binding.itemThumb.imageTintList = null
                binding.itemThumb.setPadding(0, 0, 0, 0)
                binding.itemThumb.scaleType = android.widget.ImageView.ScaleType.CENTER_CROP
                // Bug fix (NEXT_SESSION_PROMPT.md item 5) — image_url is a
                // relative path from the API; needs the same static-files
                // base-URL prefix EditProfileActivity's logo preview uses,
                // not the raw path. Harmless before now since image_url
                // was always null, but broke the moment real values start
                // coming back from menu-items-create/update.php.
                binding.itemThumb.load(ApiClient.baseUrlForStaticFiles(context) + item.imageUrl) {
                    placeholder(R.drawable.ic_food_placeholder)
                    error(R.drawable.ic_food_placeholder)
                    crossfade(true)
                }
            } else {
                val insetPx = (10 * context.resources.displayMetrics.density).toInt()
                binding.itemThumb.setPadding(insetPx, insetPx, insetPx, insetPx)
                binding.itemThumb.scaleType = android.widget.ImageView.ScaleType.FIT_CENTER
                binding.itemThumb.setImageResource(R.drawable.ic_food_placeholder)
                binding.itemThumb.imageTintList = android.content.res.ColorStateList.valueOf(secondaryTint)
            }

            val vegColor = ContextCompat.getColor(context, if (item.isVeg) R.color.veg_green else R.color.nonveg_red)
            binding.vegDot.background.setTint(vegColor)

            // Avoid the listener firing from this programmatic bind.
            binding.switchAvailable.setOnCheckedChangeListener(null)
            binding.switchAvailable.isChecked = item.isAvailable
            binding.switchAvailable.setOnCheckedChangeListener { _, isChecked ->
                onToggleAvailable(item, isChecked)
            }

            binding.btnEditItem.setOnClickListener { onEdit(item) }
            binding.btnDeleteItem.setOnClickListener { onDelete(item) }
        }
    }
}
