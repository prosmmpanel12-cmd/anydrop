package com.anydrop.restaurant.ui.menu

import android.view.LayoutInflater
import android.view.ViewGroup
import androidx.recyclerview.widget.RecyclerView
import coil.decode.SvgDecoder
import coil.load
import com.anydrop.restaurant.R
import com.anydrop.restaurant.databinding.ItemCategoryIconSearchOptionBinding
import com.anydrop.restaurant.network.external.SearchResultImage

/**
 * Grid adapter for the "Search icons" tab of the category-icon picker
 * (Phase 1, 2026-08-19 UI/UX overhaul; multi-source fallback pass
 * 2026-08-20 — see ExternalApiClient.kt's header comment). Unlike
 * [CategoryIconPickerAdapter] (the bundled set) this list has no
 * persistent "selected" concept — each search re-issues a fresh result
 * list, and picking one immediately downloads/rasterizes it and
 * dismisses the picker (see MenuFragment.onIconSearchResultPicked()), so
 * there's nothing to keep highlighted across rebinds.
 *
 * Items are [SearchResultImage] rather than a single provider's own
 * result type — MenuFragment's runIconSearch() may hand this adapter
 * results from Iconify, Openclipart, or Wikimedia Commons depending on
 * which one actually returned something for the current query, and this
 * adapter doesn't need to know or care which.
 */
class CategoryIconSearchAdapter(
    private val onPicked: (SearchResultImage) -> Unit
) : RecyclerView.Adapter<CategoryIconSearchAdapter.ViewHolder>() {

    private var items: List<SearchResultImage> = emptyList()

    fun submit(newItems: List<SearchResultImage>) {
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
        fun bind(result: SearchResultImage) {
            // Most icon-provider results render in whatever color the
            // source asset itself specifies (many SVGs are monochrome
            // "currentColor" outlines, some — e.g. emoji-style sets —
            // are full color) — deliberately not force-tinted the way
            // the bundled grid's fixed-style icons are, since flattening
            // a colorful search result to a single tint would often make
            // it unrecognizable. The SVG decoder is attached
            // unconditionally (safe for the non-SVG fallback providers
            // too — coil-svg's decoder just declines and Coil falls
            // through to its normal bitmap decoder when the source isn't
            // actually SVG content).
            binding.iconSearchImage.load(result.previewUrl) {
                decoderFactory(SvgDecoder.Factory())
                placeholder(R.drawable.ic_food_placeholder)
                error(R.drawable.ic_food_placeholder)
                crossfade(true)
            }
            binding.root.setOnClickListener { onPicked(result) }
        }
    }
}
