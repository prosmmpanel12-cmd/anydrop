package com.anydrop.restaurant.ui.menu

import android.view.LayoutInflater
import android.view.ViewGroup
import androidx.recyclerview.widget.RecyclerView
import coil.load
import com.anydrop.restaurant.R
import com.anydrop.restaurant.databinding.ItemCategoryPhotoSearchOptionBinding
import com.anydrop.restaurant.network.external.SearchResultImage

/**
 * Grid adapter for the "Search photos" tab of the category-icon picker
 * (Phase 1, 2026-08-19 UI/UX overhaul; multi-source fallback pass
 * 2026-08-20 — see ExternalApiClient.kt's header comment) — same shape
 * as [CategoryIconSearchAdapter] but for real openly-licensed photos
 * rather than flat icons, for restaurants who'd rather show an actual
 * dish/ingredient photo on a category than a stylized icon.
 *
 * Items are [SearchResultImage] rather than a single provider's own
 * result type — MenuFragment's runPhotoSearch() may hand this adapter
 * results from any of up to six providers (Openverse, Wikimedia Commons,
 * Openclipart, Pixabay, Pexels, Unsplash) plus a DuckDuckGo scrape
 * last-resort, depending on which one actually returned something for
 * the current query, and this adapter doesn't need to know or care
 * which.
 */
class CategoryPhotoSearchAdapter(
    private val onPicked: (SearchResultImage) -> Unit
) : RecyclerView.Adapter<CategoryPhotoSearchAdapter.ViewHolder>() {

    private var items: List<SearchResultImage> = emptyList()

    fun submit(newItems: List<SearchResultImage>) {
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
        fun bind(image: SearchResultImage) {
            binding.photoSearchImage.load(image.previewUrl) {
                placeholder(R.drawable.ic_food_placeholder)
                error(R.drawable.ic_food_placeholder)
                crossfade(true)
            }
            binding.root.setOnClickListener { onPicked(image) }
        }
    }
}
