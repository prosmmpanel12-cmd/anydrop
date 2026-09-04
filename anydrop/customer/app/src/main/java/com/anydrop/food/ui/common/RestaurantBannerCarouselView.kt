package com.anydrop.food.ui.common

import android.content.Context
import android.os.Handler
import android.os.Looper
import android.util.AttributeSet
import android.view.View
import android.widget.FrameLayout
import android.widget.ImageView
import android.widget.LinearLayout
import coil.load
import com.anydrop.food.R

/**
 * Auto-advancing banner carousel for the top of a restaurant's detail page
 * (app-owner feedback item #3, 2026-08-17 — "restaurant open ke baad
 * restaurant banners dikhenge yaha pe multiple with a cool transition,
 * agar 1 baner hi upload ho to usko fix rakho"). Deliberately a near-copy
 * of [DishPhotoCarouselView]'s "2+ = auto-advance, else static, 0 = plain
 * fallback image" shape and attach/detach lifecycle — see that class's
 * kdoc for the full reasoning, not repeated here — but simplified for what
 * a restaurant banner actually needs:
 * - Plain dot indicators (reusing dot_carousel_selected/unselected, the
 *   same drawables an existing promo-banner carousel elsewhere in this
 *   app already uses) instead of Stories-style filling progress segments
 *   — a fixed detail-page header doesn't need the "how much longer until
 *   this photo changes" affordance a scrolling feed card benefits from.
 * - Plain crossfade transition between banners, no dish-name/price
 *   overlay — a restaurant's own promotional banner isn't captioning a
 *   specific dish.
 * - No RecyclerView to be recycled inside — this view lives once, statically,
 *   in activity_restaurant_detail.xml's header, so onAttachedToWindow/
 *   onDetachedFromWindow firing once per screen visit (not per-scroll,
 *   unlike a card list) is exactly the lifecycle this needs — no
 *   setVisibleToUser() escape hatch required here.
 */
class RestaurantBannerCarouselView @JvmOverloads constructor(
    context: Context,
    attrs: AttributeSet? = null
) : FrameLayout(context, attrs) {

    private val imageView: ImageView
    private val dotsContainer: LinearLayout

    private var banners: List<String> = emptyList()
    private var currentIndex = 0
    private var isRunning = false

    private val handler = Handler(Looper.getMainLooper())
    private val advanceRunnable = Runnable { advance() }

    companion object {
        // Same interval DishPhotoCarouselView settled on for "long enough
        // to actually look at, not so long it feels static" — no reason
        // for banners to differ.
        private const val INTERVAL_MS = 4500L
    }

    init {
        inflate(context, R.layout.view_restaurant_banner_carousel, this)
        imageView = findViewById(R.id.bannerCarouselImage)
        dotsContainer = findViewById(R.id.bannerCarouselDots)
    }

    /**
     * [banners] = restaurant.banners from restaurants/menu.php, already
     * ordered server-side by sort_order. [fallbackCoverUrl] is shown when
     * the restaurant hasn't uploaded any banners at all — i.e. exactly
     * today's existing single-cover-image behaviour, unchanged.
     */
    fun setBanners(banners: List<String>, fallbackCoverUrl: String?) {
        stop()
        this.banners = banners
        currentIndex = 0
        dotsContainer.removeAllViews()

        when {
            banners.isEmpty() -> {
                dotsContainer.visibility = View.GONE
                loadImage(fallbackCoverUrl, animate = false)
            }
            banners.size == 1 -> {
                // Exactly the app-owner's "agar 1 baner hi upload ho to
                // usko fix rakho" ask — static, no dots, no timer.
                dotsContainer.visibility = View.GONE
                loadImage(banners[0], animate = false)
            }
            else -> {
                dotsContainer.visibility = View.VISIBLE
                buildDots(banners.size)
                loadImage(banners[0], animate = false)
                if (isAttachedToWindow) start()
            }
        }
    }

    private fun buildDots(count: Int) {
        val sizePx = (6 * resources.displayMetrics.density).toInt()
        val marginPx = (3 * resources.displayMetrics.density).toInt()
        repeat(count) { i ->
            val dot = View(context).apply {
                layoutParams = LinearLayout.LayoutParams(sizePx, sizePx).apply {
                    marginStart = marginPx
                    marginEnd = marginPx
                }
                setBackgroundResource(
                    if (i == currentIndex) R.drawable.dot_carousel_selected else R.drawable.dot_carousel_unselected
                )
            }
            dotsContainer.addView(dot)
        }
    }

    private fun updateDots() {
        for (i in 0 until dotsContainer.childCount) {
            dotsContainer.getChildAt(i).setBackgroundResource(
                if (i == currentIndex) R.drawable.dot_carousel_selected else R.drawable.dot_carousel_unselected
            )
        }
    }

    private fun loadImage(url: String?, animate: Boolean) {
        val fullUrl = if (!url.isNullOrBlank()) {
            com.anydrop.food.network.ApiClient.baseUrlForStaticFiles(context) + url
        } else {
            url
        }
        imageView.load(fullUrl) {
            placeholder(R.drawable.ic_restaurant)
            error(R.drawable.ic_restaurant)
            if (animate) crossfade(350) else crossfade(true)
        }
    }

    private fun advance() {
        if (banners.size < 2) return
        currentIndex = (currentIndex + 1) % banners.size
        loadImage(banners[currentIndex], animate = true)
        updateDots()
        handler.postDelayed(advanceRunnable, INTERVAL_MS)
    }

    fun start() {
        if (banners.size < 2) return
        if (isRunning) return
        isRunning = true
        handler.removeCallbacks(advanceRunnable)
        handler.postDelayed(advanceRunnable, INTERVAL_MS)
    }

    fun stop() {
        isRunning = false
        handler.removeCallbacks(advanceRunnable)
    }

    override fun onAttachedToWindow() {
        super.onAttachedToWindow()
        start()
    }

    override fun onDetachedFromWindow() {
        super.onDetachedFromWindow()
        stop()
    }
}
