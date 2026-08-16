package com.anydrop.restaurant.ui.menu

import android.annotation.SuppressLint
import android.content.Context
import android.view.LayoutInflater
import android.view.MotionEvent
import android.view.View
import android.view.ViewGroup
import androidx.core.content.ContextCompat
import androidx.recyclerview.widget.LinearLayoutManager
import androidx.recyclerview.widget.RecyclerView
import coil.load
import com.anydrop.restaurant.R
import com.anydrop.restaurant.databinding.ItemMenuCategoryBinding
import com.anydrop.restaurant.network.ApiClient
import com.anydrop.restaurant.network.MenuCategory
import com.anydrop.restaurant.network.MenuItem

/**
 * Tier 1 "Menu Management" (docs/18) — one card per category, with its
 * items nested inside via MenuItemAdapter. Items are grouped client-side
 * from the flat menu-items-list.php response (keyed by category_id), same
 * shape the Customer App's own menu screen already groups by.
 *
 * §10 item 4 follow-up (this pass) — drag-to-reorder. `reorderMode` is
 * driven by MenuFragment's "⇅ Reorder" toggle: while active, this adapter
 * shows every active category (search/tab-strip filtering is bypassed by
 * the fragment for the duration, see MenuFragment.enterReorderMode()) with
 * a drag handle, and collapses each card down to just name + item count —
 * items/edit/delete/add-item are hidden so a drag can't accidentally land
 * on one of those controls mid-gesture. `moveItem()` only reorders the
 * adapter's own in-memory list for live drag feedback; MenuFragment is the
 * one that persists the final order (sort_order) to the backend once the
 * user taps "Done".
 */
class CategoryAdapter(
    private val context: Context,
    private val onEditCategory: (MenuCategory) -> Unit,
    private val onDeleteCategory: (MenuCategory) -> Unit,
    private val onAddItem: (MenuCategory) -> Unit,
    private val onToggleItemAvailable: (MenuItem, Boolean) -> Unit,
    private val onEditItem: (MenuItem) -> Unit,
    private val onDeleteItem: (MenuItem) -> Unit,
    private val onStartDrag: (RecyclerView.ViewHolder) -> Unit = {}
) : RecyclerView.Adapter<CategoryAdapter.CategoryViewHolder>() {

    private val categories = mutableListOf<MenuCategory>()
    private var itemsByCategory: Map<Int, List<MenuItem>> = emptyMap()
    var reorderMode: Boolean = false
        private set

    fun submitData(newCategories: List<MenuCategory>, newItems: List<MenuItem>) {
        categories.clear()
        // Hidden (soft-deleted) categories aren't shown in this list —
        // restore isn't built into this UI yet, only delete.
        categories.addAll(newCategories.filter { it.isActive })
        itemsByCategory = newItems.groupBy { it.categoryId }
        notifyDataSetChanged()
    }

    fun setReorderMode(enabled: Boolean) {
        if (reorderMode == enabled) return
        reorderMode = enabled
        notifyDataSetChanged()
    }

    /** Current on-screen order — MenuFragment reads this to compute new sort_order values. */
    fun currentOrder(): List<MenuCategory> = categories.toList()

    /** Live-reorders the in-memory list as the user drags; ItemTouchHelper calls this per swap. */
    fun moveItem(fromPosition: Int, toPosition: Int) {
        if (fromPosition == toPosition) return
        val moved = categories.removeAt(fromPosition)
        categories.add(toPosition, moved)
        notifyItemMoved(fromPosition, toPosition)
    }

    override fun onCreateViewHolder(parent: ViewGroup, viewType: Int): CategoryViewHolder {
        val binding = ItemMenuCategoryBinding.inflate(LayoutInflater.from(parent.context), parent, false)
        return CategoryViewHolder(binding)
    }

    override fun onBindViewHolder(holder: CategoryViewHolder, position: Int) {
        holder.bind(categories[position])
    }

    override fun getItemCount() = categories.size

    @SuppressLint("ClickableViewAccessibility")
    inner class CategoryViewHolder(private val binding: ItemMenuCategoryBinding) : RecyclerView.ViewHolder(binding.root) {
        fun bind(category: MenuCategory) {
            binding.categoryNameText.text = category.name
            val items = itemsByCategory[category.id] ?: emptyList()
            binding.categoryItemCountText.text = "${items.size} item${if (items.size == 1) "" else "s"}"

            // Thumbnail (NEXT_SESSION_PROMPT.md item 6) — same
            // placeholder/real-image pattern as MenuItemAdapter's
            // itemThumb, reusing ic_food_placeholder since no distinct
            // category placeholder icon exists yet.
            if (!category.imageUrl.isNullOrBlank()) {
                binding.categoryThumb.imageTintList = null
                binding.categoryThumb.setPadding(0, 0, 0, 0)
                binding.categoryThumb.scaleType = android.widget.ImageView.ScaleType.CENTER_CROP
                binding.categoryThumb.load(ApiClient.baseUrlForStaticFiles(context) + category.imageUrl) {
                    placeholder(R.drawable.ic_food_placeholder)
                    error(R.drawable.ic_food_placeholder)
                    crossfade(true)
                }
            } else {
                val secondaryTint = ContextCompat.getColor(context, R.color.text_secondary)
                val insetPx = (10 * context.resources.displayMetrics.density).toInt()
                binding.categoryThumb.setPadding(insetPx, insetPx, insetPx, insetPx)
                binding.categoryThumb.scaleType = android.widget.ImageView.ScaleType.FIT_CENTER
                binding.categoryThumb.setImageResource(R.drawable.ic_food_placeholder)
                binding.categoryThumb.imageTintList = android.content.res.ColorStateList.valueOf(secondaryTint)
            }

            if (reorderMode) {
                binding.dragHandle.visibility = View.VISIBLE
                binding.categoryThumb.visibility = View.GONE
                binding.itemsRecycler.visibility = View.GONE
                binding.emptyItemsText.visibility = View.GONE
                binding.btnAddItem.visibility = View.GONE
                binding.btnEditCategory.visibility = View.GONE
                binding.btnDeleteCategory.visibility = View.GONE
                binding.dragHandle.setOnTouchListener { _, event ->
                    if (event.actionMasked == MotionEvent.ACTION_DOWN) {
                        onStartDrag(this)
                    }
                    false
                }
                binding.itemsRecycler.adapter = null
                return
            }

            binding.dragHandle.visibility = View.GONE
            binding.dragHandle.setOnTouchListener(null)
            binding.categoryThumb.visibility = View.VISIBLE
            binding.itemsRecycler.visibility = View.VISIBLE
            binding.btnAddItem.visibility = View.VISIBLE
            binding.btnEditCategory.visibility = View.VISIBLE
            binding.btnDeleteCategory.visibility = View.VISIBLE

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
