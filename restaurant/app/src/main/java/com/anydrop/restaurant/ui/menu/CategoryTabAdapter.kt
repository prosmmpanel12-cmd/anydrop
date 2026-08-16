package com.anydrop.restaurant.ui.menu

import android.content.Context
import android.view.LayoutInflater
import android.view.ViewGroup
import android.widget.TextView
import androidx.core.content.ContextCompat
import androidx.recyclerview.widget.RecyclerView
import com.anydrop.restaurant.R
import com.anydrop.restaurant.network.MenuCategory

/**
 * §5 category tabs strip — only shown by MenuFragment once there are 5+
 * active categories ("stacked cards get long to scroll through"). Tapping
 * a tab filters the category list below down to just that one category;
 * an "All" tab (id = null, always first) returns to the full stacked view.
 * Doesn't touch item search or reorder — MenuFragment hides this strip
 * entirely for the duration of either of those instead of trying to
 * combine three filter states at once.
 */
class CategoryTabAdapter(
    private val context: Context,
    private val onTabSelected: (categoryId: Int?) -> Unit
) : RecyclerView.Adapter<CategoryTabAdapter.TabViewHolder>() {

    private val tabs = mutableListOf<MenuCategory?>() // null = "All"
    private var selectedId: Int? = null

    fun submitCategories(categories: List<MenuCategory>) {
        tabs.clear()
        tabs.add(null)
        tabs.addAll(categories)
        // If the previously-selected category no longer exists (deleted/
        // renamed-away mid-session), fall back to "All" rather than
        // silently filtering to nothing.
        if (selectedId != null && categories.none { it.id == selectedId }) {
            selectedId = null
        }
        notifyDataSetChanged()
    }

    fun setSelected(categoryId: Int?) {
        selectedId = categoryId
        notifyDataSetChanged()
    }

    override fun onCreateViewHolder(parent: ViewGroup, viewType: Int): TabViewHolder {
        val view = LayoutInflater.from(parent.context).inflate(R.layout.item_category_tab_chip, parent, false)
        return TabViewHolder(view as TextView)
    }

    override fun onBindViewHolder(holder: TabViewHolder, position: Int) {
        holder.bind(tabs[position])
    }

    override fun getItemCount() = tabs.size

    inner class TabViewHolder(private val chip: TextView) : RecyclerView.ViewHolder(chip) {
        fun bind(category: MenuCategory?) {
            chip.text = category?.name ?: context.getString(R.string.menu_tab_all)
            val isSelected = category?.id == selectedId
            chip.background = ContextCompat.getDrawable(
                context,
                if (isSelected) R.drawable.bg_menu_tab_selected else R.drawable.bg_chip_unselected
            )
            chip.setTextColor(
                ContextCompat.getColor(context, if (isSelected) R.color.white else R.color.text_secondary)
            )
            chip.setOnClickListener {
                selectedId = category?.id
                notifyDataSetChanged()
                onTabSelected(selectedId)
            }
        }
    }
}
