package com.anydrop.food.ui.home

import android.view.LayoutInflater
import android.view.ViewGroup
import androidx.recyclerview.widget.RecyclerView
import coil.load
import com.anydrop.food.databinding.ItemPromoBannerBinding
import com.anydrop.food.network.ApiClient
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
            binding.promoItemImage.load(resolveImageUrl(banner.imageUrl)) { crossfade(true) }
            binding.root.setOnClickListener { onClick(banner) }
        }

        /**
         * Bug fix (2026-08-24) — this carousel merges two backend sources
         * (see promo-banners.php's own header comment): the legacy
         * `promo_banners` table, whose image_url has always been a full
         * https:// URL (seed data / presumably hand-edited directly in the
         * DB, no admin UI exists for it), and the newer admin Banner
         * Manager's `banners` table, whose image_url is a *relative* path
         * ("uploads/admin_banners/banner_....jpg" — see
         * admin/banners.php's save_banner_image()), same shape as address
         * photos / restaurant logos elsewhere in this app. This adapter
         * used to pass banner.imageUrl straight to Coil with no prefix,
         * which only ever worked for the legacy (already-absolute) source
         * — any admin-uploaded banner silently failed to load (title/
         * subtitle text rendered fine since those aren't image loads, so
         * the slide *looked* present, just with a placeholder image).
         * Only prefix when the value isn't already absolute, same
         * "starts with http(s)://" check promo-banners.php's own
         * deep_link_to_target() already uses server-side for the same
         * kind of ambiguity.
         */
        private fun resolveImageUrl(imageUrl: String?): String? {
            if (imageUrl.isNullOrBlank()) return imageUrl
            return if (imageUrl.startsWith("http://") || imageUrl.startsWith("https://")) {
                imageUrl
            } else {
                ApiClient.baseUrlForStaticFiles(binding.root.context) + imageUrl
            }
        }
    }
}

