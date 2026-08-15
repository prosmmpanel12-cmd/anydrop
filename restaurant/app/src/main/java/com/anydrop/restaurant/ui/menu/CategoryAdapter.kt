package com.anydrop.restaurant.ui.menu

import android.content.Context
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import androidx.recyclerview.widget.LinearLayoutManager
import androidx.recyclerview.widget.RecyclerView
import com.anydrop.restaurant.databinding.ItemMenuCategoryBinding
import com.anydrop.restaurant.network.MenuCategory
import com.anydrop.restaurant.network.MenuItem

/**
 * Tier 1 "Menu Management" (docs/18) — one card per category, with its
 * items nested inside via MenuItemAdapter. Items are grouped client-side
 * from the flat menu-items-list.php response (keyed by category_id), same
 * shape the Customer App's own menu screen already groups by.
 */
class CategoryAdapter(
    private val context: Context,
    private val onEditCategory: (MenuCategory) -> Unit,
    private val onDeleteCategory: (MenuCategory) -> Unit,
    private val onAddItem: (MenuCategory) -> Unit,
    private val onToggleItemAvailable: (MenuItem, Boolean) -> Unit,
    private val onEditItem: (MenuItem) -> Unit,
    private val onDeleteItem: (MenuItem) -> Unit
) : RecyclerView.Adapter<CategoryAdapter.CategoryViewHolder>() {

    private val categories = mutableListOf<MenuCategory>()
    private var itemsByCategory: Map<Int, List<MenuItem>> = emptyMap()

    fun submitData(newCategories: List<MenuCategory>, newItems: List<MenuItem>) {
        categories.clear()
        // Hidden (soft-deleted) categories aren't shown in this list —
        // restore isn't built into this UI yet, only delete.
        categories.addAll(newCategories.filter { it.isActive })
        itemsByCategory = newItems.groupBy { it.categoryId }
        notifyDataSetChanged()
    }

    override fun onCreateViewHolder(parent: ViewGroup, viewType: Int): CategoryViewHolder {
        val binding = ItemMenuCategoryBinding.inflate(LayoutInflater.from(parent.context), parent, false)
        return CategoryViewHolder(binding)
    }

    override fun onBindViewHolder(holder: CategoryViewHolder, position: Int) {
        holder.bind(categories[position])
    }

    override fun getItemCount() = categories.size

    inner class CategoryViewHolder(private val binding: ItemMenuCategoryBinding) : RecyclerView.ViewHolder(binding.root) {
        fun bind(category: MenuCategory) {
            binding.categoryNameText.text = category.name
            val items = itemsByCategory[category.id] ?: emptyList()
            binding.categoryItemCountText.text = "${items.size} item${if (items.size == 1) "" else "s"}"

            binding.itemsRecycler.layoutManager = LinearLayoutManager(context)
            binding.itemsRecycler.adapter = MenuItemAdapter(
                context,
                onToggleItemAvailable,
                onEditItem,
                onDeleteItem
            ).apply { submitList(items) }

            binding.emptyItemsText.visibility = if (items.isEmpty()) View.VISIBLE else View.GONE

            binding.btnEditCategory.setOnClickListener { onEditCategory(category) }
            binding.btnDeleteCategory.setOnClickListener { onDeleteCategory(category) }
            binding.btnAddItem.setOnClickListener { onAddItem(category) }
        }
    }
}
