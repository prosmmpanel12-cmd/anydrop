package com.anydrop.food.ui.home

import android.view.LayoutInflater
import android.view.ViewGroup
import androidx.recyclerview.widget.RecyclerView
import coil.load
import com.anydrop.food.databinding.ItemPromoBannerBinding
import com.anydrop.food.network.PromoBanner

/**
 * Backs the Home promo carousel ViewPager2 (§2.2). Each slide taps through
 * per its own target_type (none/restaurant/category/url) — handled by the
 * caller via onClick, this adapter only renders.
 */
class PromoBannerAdapter(
    private val onClick: (PromoBanner) -> Unit
) : RecyclerView.Adapter<PromoBannerAdapter.VH>() {

    private val items = mutableListOf<PromoBanner>()

    fun submit(list: List<PromoBanner>) {
        items.clear()
        items.addAll(list)
        notifyDataSetChanged()
    }

    override fun onCreateViewHolder(parent: ViewGroup, viewType: Int): VH {
        val binding = ItemPromoBannerBinding.inflate(LayoutInflater.from(parent.context), parent, false)
        return VH(binding)
    }

    override fun onBindViewHolder(holder: VH, position: Int) {
        holder.bind(items[position])
    }

    override fun getItemCount() = items.size

    inner class VH(private val binding: ItemPromoBannerBinding) : RecyclerView.ViewHolder(binding.root) {
        fun bind(banner: PromoBanner) {
            binding.promoItemTitle.text = banner.title.orEmpty()
            binding.promoItemSubtitle.text = banner.subtitle.orEmpty()
            binding.promoItemImage.load(banner.imageUrl) { crossfade(true) }
            binding.root.setOnClickListener { onClick(banner) }
        }
    }
}
