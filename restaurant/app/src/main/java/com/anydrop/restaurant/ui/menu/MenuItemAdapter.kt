package com.anydrop.restaurant.ui.menu

import android.content.Context
import android.view.LayoutInflater
import android.view.ViewGroup
import androidx.core.content.ContextCompat
import androidx.recyclerview.widget.RecyclerView
import com.anydrop.restaurant.R
import com.anydrop.restaurant.databinding.ItemMenuFoodBinding
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
