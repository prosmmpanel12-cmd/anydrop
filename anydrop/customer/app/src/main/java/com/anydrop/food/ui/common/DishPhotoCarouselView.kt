package com.anydrop.food.ui.common

import android.content.Context
import android.os.Handler
import android.os.Looper
import android.util.AttributeSet
import android.view.View
import android.widget.FrameLayout
import android.widget.ImageView
import android.widget.LinearLayout
import android.widget.TextView
import coil.load
import com.anydrop.food.R
import kotlin.math.roundToInt

/**
 * Auto-advancing dish-photo carousel shown on a restaurant card, in place
 * of a single static cover image, whenever the restaurant has 2+ gallery
 * photos (§2.7 — screenshot reference: cover image cycles through dish
 * photos with a "Dish Name · ₹price" tag and Instagram/WhatsApp-Stories-
 * style progress segments across the top, each animating its fill from
 * 0→100% over the interval before advancing to the next photo — §2.7's
 * own original ask, previously built as plain static dot indicators until
 * part 19 replaced them with this).
 *
 * Restaurants with 0 or 1 gallery photo fall back to a single static image
 * (the restaurant's existing cover_url, or the one gallery photo) with no
 * dots/overlay/auto-advance — there's nothing to cycle between. This is
 * the built-in handling for "restaurant hasn't uploaded gallery photos
 * yet": nothing breaks, it just looks like the old single-image card.
 *
 * Lifecycle: auto-advance starts in onAttachedToWindow and stops in
 * onDetachedFromWindow, which RecyclerView already calls whenever a row is
 * recycled/rebound — so a scrolled-away card's timer stops itself with no
 * extra wiring needed in the adapter, and a freshly-bound recycled row's
 * setPhotos() call (which always stops+restarts) can't accidentally stack
 * a second timer on top of a leftover one from whatever it was previously
 * bound to.
 *
 * That lifecycle assumption only holds when this view is actually inside a
 * real RecyclerView that recycles — i.e. attach/detach only fires once per
 * screen-load if the RecyclerView is inside a NestedScrollView with
 * wrap_content height (activity_home.xml's restaurantList is exactly this;
 * see HomeActivity.kt's setupCollapsingHeader()/updateCarouselVisibility()
 * for why). To stay correct either way, this view also exposes
 * [setVisibleToUser] so an owner that manages its own on-screen visibility
 * (because attach/detach won't do it) can pause/resume independent of
 * attach state. [start]/[stop] remain safe to call directly too — both are
 * now idempotent against redundant calls, so callers never need to track
 * "did I already start this" themselves.
 */
class DishPhotoCarouselView @JvmOverloads constructor(
    context: Context,
    attrs: AttributeSet? = null
) : FrameLayout(context, attrs) {

    data class Photo(
        val imageUrl: String,
        val dishName: String? = null,
        val price: Double? = null
    )

    private val imageView: ImageView
    private val overlayText: TextView
    private val segmentsContainer: LinearLayout

    // One entry per photo, each the fill View inside that photo's segment
    // (see buildSegments()) — indexed identically to `photos`, so
    // segmentFills[currentIndex] is always the one currently animating.
    private var segmentFills: List<View> = emptyList()
    private var segmentFillAnimator: android.animation.ValueAnimator? = null

    private var photos: List<Photo> = emptyList()
    private var currentIndex = 0

    private val handler = Handler(Looper.getMainLooper())
    private val advanceRunnable = Runnable { advance() }

    // Whether the auto-advance timer is currently scheduled — lets
    // start()/stop() be idempotent (calling either redundantly is a no-op)
    // instead of relying on callers to track this themselves.
    private var isRunning = false

    // Caller-driven on-screen visibility (see class kdoc) — defaults to
    // true so a view actually managed the old attach/detach way (a normal
    // recycling RecyclerView) behaves exactly as before; only an owner
    // that calls setVisibleToUser(false) changes this.
    private var isVisibleToUser = true

    companion object {
        // §2.7's own ask was "~4-5s, not 2.5s" once this became a visible
        // filling progress bar rather than a static dot — 2.5s read fine
        // for an instant dot-swap, but is too fast to actually watch a
        // segment fill. Picked the middle of that stated range.
        private const val INTERVAL_MS = 4500L
    }

    init {
        inflate(context, R.layout.view_dish_photo_carousel, this)
        imageView = findViewById(R.id.carouselImage)
        overlayText = findViewById(R.id.carouselOverlayText)
        segmentsContainer = findViewById(R.id.carouselProgressSegments)
    }

    /**
     * [photos] = the restaurant's gallery (already ordered by sort_order
     * server-side). [fallbackCoverUrl] is used when the gallery is empty —
     * i.e. the restaurant just hasn't uploaded gallery photos yet — so the
     * card still shows *something* instead of a blank/placeholder image.
     */
    fun setPhotos(photos: List<Photo>, fallbackCoverUrl: String?) {
        stop()
        this.photos = photos
        currentIndex = 0
        segmentsContainer.removeAllViews()
        segmentFills = emptyList()

        when {
            photos.isEmpty() -> {
                overlayText.visibility = View.GONE
                segmentsContainer.visibility = View.GONE
                loadImage(fallbackCoverUrl, animate = false)
            }
            photos.size == 1 -> {
                overlayText.visibility = View.GONE
                segmentsContainer.visibility = View.GONE
                showPhoto(0, animate = false)
            }
            else -> {
                segmentsContainer.visibility = View.VISIBLE
                buildSegments(photos.size)
                showPhoto(0, animate = false)
                // Only actually schedule the timer if this card is both
                // attached AND currently on-screen per the caller's own
                // tracking — a card that has never been visible yet (e.g.
                // every row on this screen at initial load, since there's
                // no view recycling here) must not start ticking just
                // because setPhotos() ran, per the bind-time check this
                // was built for. See RestaurantAdapter.VH.bind().
                if (isAttachedToWindow && isVisibleToUser) start()
            }
        }
    }

    /**
     * Builds [count] Stories-style segments: an equal-width row of tracks
     * (bg_carousel_progress_track), each containing a fill View
     * (bg_carousel_progress_fill) that starts at width 0 and is later
     * animated to full width by animateCurrentSegmentFill(). Segment
     * height is a thin bar (3dp), same visual weight as the dot indicators
     * it replaces.
     */
    private fun buildSegments(count: Int) {
        val segmentHeightPx = (3 * resources.displayMetrics.density).roundToInt()
        val segmentSpacingPx = (4 * resources.displayMetrics.density).roundToInt()
        val fills = mutableListOf<View>()
        repeat(count) { i ->
            val track = FrameLayout(context).apply {
                layoutParams = LinearLayout.LayoutParams(0, segmentHeightPx, 1f).apply {
                    if (i > 0) marginStart = segmentSpacingPx
                }
                setBackgroundResource(R.drawable.bg_carousel_progress_track)
                clipToPadding = false
            }
            val fill = View(context).apply {
                layoutParams = FrameLayout.LayoutParams(0, FrameLayout.LayoutParams.MATCH_PARENT)
                setBackgroundResource(R.drawable.bg_carousel_progress_fill)
            }
            track.addView(fill)
            segmentsContainer.addView(track)
            fills.add(fill)
        }
        segmentFills = fills
    }

    /**
     * Sets every segment's fill to either full width (already-viewed
     * photos, indices < [index]) or zero width (not-yet-viewed, indices >
     * [index]) instantly — no animation. The current index's own fill is
     * left at zero here; animateCurrentSegmentFill() is what actually
     * animates it 0→100%, called separately once the timer is actually
     * running (so a paused/off-screen card shows its progress frozen at 0
     * for the current segment rather than a fill that's silently stuck
     * mid-animation).
     */
    private fun resetSegmentFillsUpTo(index: Int) {
        segmentFills.forEachIndexed { i, fill ->
            val params = fill.layoutParams as FrameLayout.LayoutParams
            val track = fill.parent as View
            params.width = if (i < index) track.width else 0
            fill.layoutParams = params
        }
    }

    /** Animates only the current photo's segment fill from 0 to full width over INTERVAL_MS. */
    private fun animateCurrentSegmentFill() {
        segmentFillAnimator?.cancel()
        val fill = segmentFills.getOrNull(currentIndex) ?: return
        val track = fill.parent as? View ?: return
        if (track.width <= 0) {
            // Not laid out yet (e.g. very first bind, before the row has
            // measured) — defer one frame rather than animating to a
            // width of 0, which would never visibly fill.
            track.post { if (isRunning) animateCurrentSegmentFill() }
            return
        }
        val params = fill.layoutParams as FrameLayout.LayoutParams
        segmentFillAnimator = android.animation.ValueAnimator.ofInt(0, track.width).apply {
            duration = INTERVAL_MS
            interpolator = android.view.animation.LinearInterpolator()
            addUpdateListener { anim ->
                params.width = anim.animatedValue as Int
                fill.layoutParams = params
            }
            start()
        }
    }

    private fun showPhoto(index: Int, animate: Boolean) {
        val photo = photos.getOrNull(index) ?: return
        currentIndex = index
        loadImage(photo.imageUrl, animate)

        overlayText.text = when {
            !photo.dishName.isNullOrBlank() && photo.price != null ->
                "${photo.dishName} · \u20B9${photo.price.roundToInt()}"
            !photo.dishName.isNullOrBlank() -> photo.dishName
            else -> null
        }
        overlayText.visibility = if (overlayText.text.isNullOrBlank()) View.GONE else View.VISIBLE

        // Every already-viewed segment (index < currentIndex) shows full,
        // every not-yet-viewed one shows empty — the current one starts
        // empty here and is animated to full separately, only once the
        // timer is actually running (start()), so a card that's paused
        // right after showPhoto() (off-screen) doesn't show a segment
        // frozen mid-fill.
        segmentFillAnimator?.cancel()
        resetSegmentFillsUpTo(index)
    }

    private fun loadImage(url: String?, animate: Boolean) {
        // Bug fix — url is a relative path from the API (gallery photo or
        // restaurant cover_url fallback), same root cause as every other
        // image_url field in this app; needs the static-files base-URL
        // prefix or it silently fails to load.
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
        if (photos.size < 2) return
        showPhoto((currentIndex + 1) % photos.size, animate = true)
        handler.postDelayed(advanceRunnable, INTERVAL_MS)
        animateCurrentSegmentFill()
    }

    fun start() {
        if (photos.size < 2) return
        if (isRunning) return // idempotent — a redundant start() is a no-op
        isRunning = true
        handler.removeCallbacks(advanceRunnable)
        handler.postDelayed(advanceRunnable, INTERVAL_MS)
        animateCurrentSegmentFill()
    }

    fun stop() {
        isRunning = false
        handler.removeCallbacks(advanceRunnable)
        // Freeze progress rather than leaving the fill animator running
        // against a View that's no longer supposed to be advancing — an
        // off-screen/detached card should show its last real progress
        // (or the reset-to-empty state from showPhoto()), not a fill that
        // silently keeps animating in the background.
        segmentFillAnimator?.cancel()
    }

    /**
     * Called by an owner that manages its own on-screen visibility (e.g.
     * HomeActivity's scroll-driven check on restaurantList, since that
     * RecyclerView never recycles — see class kdoc) to pause/resume the
     * timer independent of attach/detach. Safe to call redundantly (e.g.
     * every scroll tick for an already-visible card) — start()/stop() are
     * both idempotent, so this never restarts a timer that's already
     * running or double-schedules anything.
     */
    fun setVisibleToUser(visible: Boolean) {
        isVisibleToUser = visible
        if (visible) {
            if (isAttachedToWindow) start()
        } else {
            stop()
        }
    }

    override fun onAttachedToWindow() {
        super.onAttachedToWindow()
        if (isVisibleToUser) start()
    }

    override fun onDetachedFromWindow() {
        super.onDetachedFromWindow()
        stop()
    }
}
