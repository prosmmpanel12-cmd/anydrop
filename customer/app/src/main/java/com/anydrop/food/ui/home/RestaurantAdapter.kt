package com.anydrop.food.ui.home

import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import androidx.lifecycle.LifecycleCoroutineScope
import androidx.recyclerview.widget.RecyclerView
import com.google.android.material.chip.Chip
import com.anydrop.food.R
import com.anydrop.food.data.FavoritesManager
import com.anydrop.food.databinding.ItemRestaurantBinding
import com.anydrop.food.network.Restaurant

class RestaurantAdapter(
    private val lifecycleScope: LifecycleCoroutineScope,
    private val onClick: (Restaurant) -> Unit,
    // restaurantList sits inside a NestedScrollView with wrap_content height
    // (the well-known RecyclerView-in-NestedScrollView anti-pattern — see
    // HomeActivity.kt's updateCarouselVisibility() kdoc for the full
    // explanation), so every row attaches at once regardless of scroll
    // position and RecyclerView's normal recycling never kicks in here.
    // Without this, VH.bind() starting a card's carousel unconditionally
    // means every restaurant's photo carousel would start auto-advancing
    // the moment the list loads, all at once, all the time — not just the
    // one actually on-screen. HomeActivity supplies the real on-screen
    // check; defaulting to false here means a freshly-bound card just
    // waits for that first visibility pass rather than starting-then-
    // immediately-stopping a frame later.
    private val isCarouselVisible: (View) -> Boolean = { false }
) : RecyclerView.Adapter<RestaurantAdapter.VH>() {

    private val items = mutableListOf<Restaurant>()

    companion object {
        // Partial-bind payload (H2 fix) — lets refreshSavedStates() below
        // update just the bookmark icon on every row without re-running
        // the rest of bind(), which would restart each card's photo
        // carousel (see the isCarouselVisible kdoc above) for no reason.
        private const val PAYLOAD_BOOKMARK = "bookmark"
    }

    fun submit(list: List<Restaurant>) {
        items.clear()
        items.addAll(list)
        notifyDataSetChanged()
    }

    override fun onCreateViewHolder(parent: ViewGroup, viewType: Int): VH {
        val binding = ItemRestaurantBinding.inflate(LayoutInflater.from(parent.context), parent, false)
        return VH(binding)
    }

    override fun onBindViewHolder(holder: VH, position: Int) {
        holder.bind(items[position])
    }

    override fun onBindViewHolder(holder: VH, position: Int, payloads: MutableList<Any>) {
        if (payloads.contains(PAYLOAD_BOOKMARK)) {
            holder.bindBookmarkOnly(items[position])
        } else {
            super.onBindViewHolder(holder, position, payloads)
        }
    }

    override fun getItemCount() = items.size

    /** Bug fix (2026-08-10, H2) — call this from the host screen's
     * onResume() (cheap, local-only, no network) so a restaurant bookmarked
     * on another screen (e.g. RestaurantDetailActivity reached from a cart
     * card) shows correctly here without waiting for a full data reload. */
    fun refreshSavedStates() {
        for (i in items.indices) notifyItemChanged(i, PAYLOAD_BOOKMARK)
    }

    inner class VH(val binding: ItemRestaurantBinding) : RecyclerView.ViewHolder(binding.root) {
        fun bind(restaurant: Restaurant) {
            binding.restaurantName.text = restaurant.name
            binding.restaurantCuisines.text = restaurant.cuisineTags ?: restaurant.address ?: ""
            binding.restaurantRating.text = String.format("%.1f", restaurant.ratingAvg)

            // features.md §5 — cross-fading ETA/distance <-> "Near & Fast" meta
            // line (RotatingEtaView), shown regardless of open/closed status.
            binding.restaurantEta.bind(restaurant.etaMinutes, restaurant.distanceKm)

            if (restaurant.isOpenNow) {
                binding.restaurantStatus.text = "Open"
                binding.restaurantStatus.setTextColor(
                    binding.root.context.getColor(R.color.success_fg)
                )
                binding.root.alpha = 1.0f
            } else {
                binding.restaurantStatus.text = "Closed"
                binding.restaurantStatus.setTextColor(
                    binding.root.context.getColor(R.color.error_fg)
                )
                // Dim the whole card (cover image + text + chips) so a
                // closed restaurant reads as unavailable at a glance,
                // instead of only the small status label saying "Closed".
                binding.root.alpha = 0.5f
            }

            if (!restaurant.offerBadgeText.isNullOrBlank()) {
                binding.restaurantOfferBadge.text = restaurant.offerBadgeText
                binding.restaurantOfferBadge.visibility = android.view.View.VISIBLE
            } else {
                binding.restaurantOfferBadge.visibility = android.view.View.GONE
            }

            binding.restaurantTagsGroup.removeAllViews()
            val tags = restaurant.tags.orEmpty()
            if (tags.isNotEmpty()) {
                binding.restaurantTagsGroup.visibility = android.view.View.VISIBLE
                tags.forEach { tag ->
                    val chip = Chip(binding.root.context).apply {
                        text = tag.name
                        isClickable = false
                        isCheckable = false
                        textSize = 11f
                        chipMinHeight = 28f * binding.root.context.resources.displayMetrics.density
                        setChipBackgroundColorResource(R.color.anydrop_primary_container)
                        setTextColor(binding.root.context.getColor(R.color.anydrop_primary))
                        chipStrokeWidth = 0f
                    }
                    binding.restaurantTagsGroup.addView(chip)
                }
            } else {
                binding.restaurantTagsGroup.visibility = android.view.View.GONE
            }

            val galleryPhotos = restaurant.gallery.orEmpty().map {
                com.anydrop.food.ui.common.DishPhotoCarouselView.Photo(it.imageUrl, it.dishName, it.price)
            }
            // setPhotos() only actually starts the auto-advance timer if
            // the carousel view is both attached AND already marked
            // visible-to-user — since this list never recycles (see the
            // constructor comment on isCarouselVisible), a card is never
            // visible the instant it's bound, so this correctly does NOT
            // start ticking here. setVisibleToUser() below immediately
            // corrects that for a card that turns out to already be
            // on-screen at bind-time (e.g. the first visible rows on
            // initial load, before any scroll event has fired).
            binding.restaurantCarousel.setPhotos(galleryPhotos, restaurant.coverUrl)
            binding.restaurantCarousel.setVisibleToUser(isCarouselVisible(binding.root))

            binding.root.setOnClickListener { onClick(restaurant) }

            bindBookmarkOnly(restaurant)
        }

        /** Just the bookmark icon + its click listener — split out so
         * refreshSavedStates() can update this without re-running the rest
         * of bind() (see PAYLOAD_BOOKMARK kdoc above). */
        fun bindBookmarkOnly(restaurant: Restaurant) {
            val isSaved = FavoritesManager.isSaved("restaurant", restaurant.id, restaurant.isSaved)
            binding.restaurantBookmark.setImageResource(
                if (isSaved) R.drawable.ic_bookmark_filled else R.drawable.ic_bookmark_outline
            )
            binding.restaurantBookmark.setOnClickListener {
                FavoritesManager.toggle(
                    context = binding.root.context,
                    scope = lifecycleScope,
                    favoriteType = "restaurant",
                    favoriteId = restaurant.id,
                    currentlySaved = FavoritesManager.isSaved("restaurant", restaurant.id, restaurant.isSaved),
                    onResult = { newState ->
                        binding.restaurantBookmark.setImageResource(
                            if (newState) R.drawable.ic_bookmark_filled else R.drawable.ic_bookmark_outline
                        )
                    }
                )
            }
        }
    }
}
