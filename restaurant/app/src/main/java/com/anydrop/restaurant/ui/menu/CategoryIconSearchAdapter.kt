package com.anydrop.restaurant.ui.menu

import android.view.LayoutInflater
import android.view.ViewGroup
import androidx.recyclerview.widget.RecyclerView
import coil.decode.SvgDecoder
import coil.load
import com.anydrop.restaurant.R
import com.anydrop.restaurant.databinding.ItemCategoryIconSearchOptionBinding
import com.anydrop.restaurant.network.external.IconifyResult

/**
 * Grid adapter for the "Search icons" tab of the category-icon picker
 * (Phase 1, 2026-08-19 UI/UX overhaul — CategoryIcons.kt's own kdoc had
 * this flagged as "option 2, deliberately deferred"; this is that
 * follow-up). Unlike [CategoryIconPickerAdapter] (the bundled set) this
 * list has no persistent "selected" concept — each search re-issues a
 * fresh result list, and picking one immediately downloads/rasterizes it
 * and dismisses the picker (see MenuFragment.onIconSearchResultPicked()),
 * so there's nothing to keep highlighted across rebinds.
 */
class CategoryIconSearchAdapter(
    private val onPicked: (IconifyResult) -> Unit
) : RecyclerView.Adapter<CategoryIconSearchAdapter.ViewHolder>() {

    private var items: List<IconifyResult> = emptyList()

    fun submit(newItems: List<IconifyResult>) {
        items = newItems
        notifyDataSetChanged()
    }

    override fun onCreateViewHolder(parent: ViewGroup, viewType: Int): ViewHolder {
        val binding = ItemCategoryIconSearchOptionBinding.inflate(LayoutInflater.from(parent.context), parent, false)
        return ViewHolder(binding)
    }

    override fun onBindViewHolder(holder: ViewHolder, position: Int) = holder.bind(items[position])

    override fun getItemCount() = items.size

    inner class ViewHolder(private val binding: ItemCategoryIconSearchOptionBinding) :
        RecyclerView.ViewHolder(binding.root) {
        fun bind(result: IconifyResult) {
            // Most Iconify icons render in whatever color the SVG itself
            // specifies (many are monochrome "currentColor" outlines,
            // some — e.g. the twemoji/emoji-style sets — are full color)
            // — deliberately not force-tinted the way the bundled grid's
            // fixed-style icons are, since flattening a colorful search
            // result to a single tint would often make it unrecognizable.
            binding.iconSearchImage.load(result.svgUrl) {
                decoderFactory(SvgDecoder.Factory())
                placeholder(R.drawable.ic_food_placeholder)
                error(R.drawable.ic_food_placeholder)
                crossfade(true)
            }
            binding.root.setOnClickListener { onPicked(result) }
        }
    }
}
