package com.anydrop.restaurant.ui.common

import android.animation.ValueAnimator
import android.content.Context
import android.graphics.Canvas
import android.graphics.LinearGradient
import android.graphics.Matrix
import android.graphics.Paint
import android.graphics.PorterDuff
import android.graphics.PorterDuffXfermode
import android.graphics.Shader
import android.util.AttributeSet
import android.view.animation.LinearInterpolator
import android.widget.FrameLayout
import androidx.core.content.ContextCompat
import com.anydrop.restaurant.R

/**
 * Wraps skeleton placeholder shapes (see skeleton_order_card.xml,
 * skeleton_menu_item_row.xml) and sweeps a soft light-gray -> white ->
 * light-gray gradient across them, so a loading screen reads as
 * "loading" rather than "broken" (docs/restorent/19, §9.3).
 *
 * Written once here and reused across every tab's skeleton state
 * (Orders/Menu/Insights/Account) instead of duplicating animation
 * code per screen — one ShimmerFrameLayout can wrap several stacked
 * skeleton rows at once (e.g. 3 order-card skeletons).
 *
 * Usage: just wrap the skeleton rows in XML —
 *
 * <com.anydrop.restaurant.ui.common.ShimmerFrameLayout
 *     android:layout_width="match_parent"
 *     android:layout_height="wrap_content">
 *     <include layout="@layout/skeleton_order_card" />
 *     <include layout="@layout/skeleton_order_card" />
 *     <include layout="@layout/skeleton_order_card" />
 * </com.anydrop.restaurant.ui.common.ShimmerFrameLayout>
 *
 * The shimmer starts automatically when attached to the window and
 * stops when detached, so callers just need to toggle this view's
 * visibility — no manual start()/stop() calls required in the common
 * case (they're exposed anyway in case a screen wants to pause it,
 * e.g. while a RecyclerView holding it is off-screen).
 */
class ShimmerFrameLayout @JvmOverloads constructor(
    context: Context,
    attrs: AttributeSet? = null,
    defStyleAttr: Int = 0
) : FrameLayout(context, attrs, defStyleAttr) {

    private val baseColor = ContextCompat.getColor(context, R.color.skeleton_base)
    private val highlightColor = ContextCompat.getColor(context, R.color.skeleton_shimmer_highlight)

    private val shimmerPaint = Paint(Paint.ANTI_ALIAS_FLAG).apply {
        xfermode = PorterDuffXfermode(PorterDuff.Mode.SRC_IN)
    }
    private val shaderMatrix = Matrix()
    private var gradient: LinearGradient? = null
    private var shimmerTranslateX = 0f
    private var animator: ValueAnimator? = null

    init {
        setWillNotDraw(false)
        // Children (the skeleton bars/blobs) are drawn with their own
        // opaque skeleton_base color; the shimmer paint is composited
        // with SRC_IN in dispatchDraw so the sweeping highlight only
        // shows up over those shapes, not the transparent gaps between them.
    }

    override fun onSizeChanged(w: Int, h: Int, oldw: Int, oldh: Int) {
        super.onSizeChanged(w, h, oldw, oldh)
        if (w <= 0 || h <= 0) return
        val bandWidth = w * 0.6f
        gradient = LinearGradient(
            -bandWidth, 0f, bandWidth, h * 0.4f,
            intArrayOf(baseColor, highlightColor, baseColor),
            floatArrayOf(0f, 0.5f, 1f),
            Shader.TileMode.CLAMP
        )
        shimmerPaint.shader = gradient
        restartShimmer()
    }

    override fun dispatchDraw(canvas: Canvas) {
        if (width <= 0 || height <= 0 || gradient == null) {
            super.dispatchDraw(canvas)
            return
        }
        val saveCount = canvas.saveLayer(0f, 0f, width.toFloat(), height.toFloat(), null)
        super.dispatchDraw(canvas)
        shaderMatrix.reset()
        shaderMatrix.setTranslate(shimmerTranslateX, 0f)
        gradient?.setLocalMatrix(shaderMatrix)
        canvas.drawRect(0f, 0f, width.toFloat(), height.toFloat(), shimmerPaint)
        canvas.restoreToCount(saveCount)
    }

    /** Starts the sweep animation. Safe to call repeatedly — no-op if already running. */
    fun startShimmer() {
        if (animator?.isRunning == true || width <= 0) return
        val distance = width * 1.6f
        animator = ValueAnimator.ofFloat(-distance, distance).apply {
            duration = SHIMMER_DURATION_MS
            repeatCount = ValueAnimator.INFINITE
            interpolator = LinearInterpolator()
            addUpdateListener {
                shimmerTranslateX = it.animatedValue as Float
                invalidate()
            }
            start()
        }
    }

    /** Stops the sweep animation and releases the animator. */
    fun stopShimmer() {
        animator?.cancel()
        animator = null
    }

    private fun restartShimmer() {
        stopShimmer()
        startShimmer()
    }

    override fun onAttachedToWindow() {
        super.onAttachedToWindow()
        post { startShimmer() }
    }

    override fun onDetachedFromWindow() {
        stopShimmer()
        super.onDetachedFromWindow()
    }

    companion object {
        private const val SHIMMER_DURATION_MS = 1200L
    }
}
