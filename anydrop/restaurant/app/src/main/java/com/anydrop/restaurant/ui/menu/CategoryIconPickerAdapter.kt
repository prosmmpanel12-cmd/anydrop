package com.anydrop.restaurant.ui.menu

import android.content.Context
import android.content.res.ColorStateList
import android.view.LayoutInflater
import android.view.ViewGroup
import androidx.core.content.ContextCompat
import androidx.recyclerview.widget.RecyclerView
import com.anydrop.restaurant.R
import com.anydrop.restaurant.databinding.ItemCategoryIconOptionBinding

/**
 * Single-select grid adapter for [CategoryIcons.ALL] (doc 22 item 1).
 * [onPicked] fires immediately on tap — the picker dialog dismisses right
 * away rather than needing a separate "confirm" button, since a 14-item
 * icon grid is a fast, low-regret choice (same "tap and done" pattern as
 * MenuFragment's discount-type chips, not a multi-field form that
 * benefits from a review step before committing).
 */
class CategoryIconPickerAdapter(
    private val context: Context,
    private val selectedKey: String?,
    private val onPicked: (CategoryIcons.Option) -> Unit
) : RecyclerView.Adapter<CategoryIconPickerAdapter.ViewHolder>() {

    override fun onCreateViewHolder(parent: ViewGroup, viewType: Int): ViewHolder {
        val binding = ItemCategoryIconOptionBinding.inflate(LayoutInflater.from(parent.context), parent, false)
        return ViewHolder(binding)
    }

    override fun onBindViewHolder(holder: ViewHolder, position: Int) {
        holder.bind(CategoryIcons.ALL[position])
    }

    override fun getItemCount() = CategoryIcons.ALL.size

    inner class ViewHolder(private val binding: ItemCategoryIconOptionBinding) : RecyclerView.ViewHolder(binding.root) {
        fun bind(option: CategoryIcons.Option) {
            binding.iconOptionImage.setImageResource(option.iconRes)
            binding.iconOptionLabel.text = context.getString(option.labelRes)

            val isSelected = option.key == selectedKey
            val primary = ContextCompat.getColor(context, R.color.anydrop_primary)
            val secondary = ContextCompat.getColor(context, R.color.text_secondary)
            val containerBg = ContextCompat.getColor(context, R.color.anydrop_primary_container)
            val chipBg = ContextCompat.getColor(context, R.color.stat_chip_bg)

            binding.iconOptionCard.setCardBackgroundColor(if (isSelected) containerBg else chipBg)
            binding.iconOptionCard.strokeColor = if (isSelected) primary else android.graphics.Color.TRANSPARENT
            binding.iconOptionImage.imageTintList = ColorStateList.valueOf(if (isSelected) primary else secondary)
            binding.iconOptionLabel.setTextColor(if (isSelected) primary else secondary)

            binding.root.setOnClickListener { onPicked(option) }
        }
    }
}
