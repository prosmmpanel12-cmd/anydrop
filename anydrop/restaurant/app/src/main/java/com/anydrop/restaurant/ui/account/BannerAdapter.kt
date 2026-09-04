package com.anydrop.restaurant.ui.account

import android.view.LayoutInflater
import android.view.ViewGroup
import androidx.recyclerview.widget.RecyclerView
import coil.load
import com.anydrop.restaurant.databinding.ItemRestaurantBannerBinding
import com.anydrop.restaurant.network.ApiClient
import com.anydrop.restaurant.network.Banner

/** Banner grid for BannerManagerActivity (app-owner feedback item #3, 2026-08-17). */
class BannerAdapter(
    private val onDelete: (Banner) -> Unit
) : RecyclerView.Adapter<BannerAdapter.BannerViewHolder>() {

    private val banners = mutableListOf<Banner>()

    fun submitList(newBanners: List<Banner>) {
        banners.clear()
        banners.addAll(newBanners)
        notifyDataSetChanged()
    }

    fun addBanner(banner: Banner) {
        banners.add(banner)
        notifyItemInserted(banners.size - 1)
    }

    fun removeBanner(bannerId: Int) {
        val index = banners.indexOfFirst { it.id == bannerId }
        if (index != -1) {
            banners.removeAt(index)
            notifyItemRemoved(index)
        }
    }

    override fun onCreateViewHolder(parent: ViewGroup, viewType: Int): BannerViewHolder {
        val binding = ItemRestaurantBannerBinding.inflate(LayoutInflater.from(parent.context), parent, false)
        return BannerViewHolder(binding)
    }

    override fun onBindViewHolder(holder: BannerViewHolder, position: Int) {
        holder.bind(banners[position])
    }

    override fun getItemCount() = banners.size

    inner class BannerViewHolder(private val binding: ItemRestaurantBannerBinding) : RecyclerView.ViewHolder(binding.root) {
        fun bind(banner: Banner) {
            binding.bannerImage.load(ApiClient.baseUrlForStaticFiles(binding.root.context) + banner.imageUrl) {
                crossfade(true)
            }
            binding.btnDeleteBanner.setOnClickListener { onDelete(banner) }
        }
    }
}
