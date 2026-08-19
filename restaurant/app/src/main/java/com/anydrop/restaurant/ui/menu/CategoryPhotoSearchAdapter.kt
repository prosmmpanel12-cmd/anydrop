package com.anydrop.restaurant.ui.menu

import android.view.LayoutInflater
import android.view.ViewGroup
import androidx.recyclerview.widget.RecyclerView
import coil.load
import com.anydrop.restaurant.R
import com.anydrop.restaurant.databinding.ItemCategoryPhotoSearchOptionBinding
import com.anydrop.restaurant.network.external.OpenverseImage

/**
 * Grid adapter for the "Search photos" tab of the category-icon picker
 * (Phase 1, 2026-08-19 UI/UX overhaul) — same shape as
 * [CategoryIconSearchAdapter] but for real openly-licensed photos
 * (Openverse) rather than flat icons, for restaurants who'd rather show
 * an actual dish/ingredient photo on a category than a stylized icon.
 */
class CategoryPhotoSearchAdapter(
    private val onPicked: (OpenverseImage) -> Unit
) : RecyclerView.Adapter<CategoryPhotoSearchAdapter.ViewHolder>() {

    private var items: List<OpenverseImage> = emptyList()

    fun submit(newItems: List<OpenverseImage>) {
        items = newItems
        notifyDataSetChanged()
    }

    override fun onCreateViewHolder(parent: ViewGroup, viewType: Int): ViewHolder {
        val binding = ItemCategoryPhotoSearchOptionBinding.inflate(LayoutInflater.from(parent.context), parent, false)
        return ViewHolder(binding)
    }

    override fun onBindViewHolder(holder: ViewHolder, position: Int) = holder.bind(items[position])

    override fun getItemCount() = items.size

    inner class ViewHolder(private val binding: ItemCategoryPhotoSearchOptionBinding) :
        RecyclerView.ViewHolder(binding.root) {
        fun bind(image: OpenverseImage) {
            binding.photoSearchImage.load(image.previewUrl) {
                placeholder(R.drawable.ic_food_placeholder)
                error(R.drawable.ic_food_placeholder)
                crossfade(true)
            }
            binding.root.setOnClickListener { onPicked(image) }
        }
    }
}
