package com.anydrop.restaurant.ui.common

import android.content.Context
import android.graphics.Bitmap
import android.graphics.Canvas
import android.graphics.Color
import android.graphics.Matrix
import android.graphics.Paint
import android.graphics.PorterDuff
import android.graphics.PorterDuffXfermode
import android.graphics.RectF
import android.util.AttributeSet
import android.view.MotionEvent
import android.view.ScaleGestureDetector
import android.view.View
import kotlin.math.max
import kotlin.math.min

/**
 * Self-contained crop view (item #2 from app-owner feedback, 2026-08-17 —
 * "logo/dish photo upload ke time crop option dikhna chahiye, konsa ratio
 * sahi hai kitna hissa crop hoga"). Deliberately built from scratch with
 * plain Canvas/Matrix rather than pulling in a third-party crop library
 * (e.g. uCrop/Android-Image-Cropper) — this sandbox has no network access
 * to resolve a new Maven dependency and no way to build-verify it either,
 * so a zero-new-dependency implementation is the safer bet for a change
 * that can't be test-compiled before hand-off. See CropActivity's kdoc for
 * the full picker→crop→upload flow this plugs into.
 *
 * How it works: draws the source [bitmap] under a fixed-aspect-ratio
 * "window" rectangle (always centered, sized to the largest rect of
 * [aspectRatio] that fits inside the view with padding), dims everything
 * outside that window, and lets the user pan (one-finger drag) and pinch
 * -zoom the bitmap underneath it — same mental model as a phone's native
 * "set as wallpaper"/contact-photo cropper. [getCroppedBitmap] reads
 * exactly the pixels currently showing inside the window at full source
 * resolution (not the screen-scaled preview), so crop quality doesn't
 * depend on how big the on-screen crop window happened to be.
 */
class CropImageView @JvmOverloads constructor(
    context: Context,
    attrs: AttributeSet? = null
) : View(context, attrs) {

    /** Width:height, e.g. 1f for a square logo/dish-photo thumbnail. */
    var aspectRatio: Float = 1f
        set(value) {
            field = value
            computeWindowRect()
            invalidate()
        }

    private var bitmap: Bitmap? = null
    private val matrix = Matrix()
    private val windowRect = RectF()

    // Bounds on how far in/out the user can zoom, computed once the
    // bitmap + window are both known — min is "window is fully covered",
    // max is an arbitrary 5x beyond that so a big source image can still
    // be zoomed into for a tight crop.
    private var minScale = 1f
    private var maxScale = 1f
    private var currentScale = 1f

    private val dimPaint = Paint().apply { color = Color.parseColor("#B3000000") }
    private val clearPaint = Paint().apply {
        xfermode = PorterDuffXfermode(PorterDuff.Mode.CLEAR)
        isAntiAlias = true
    }
    private val borderPaint = Paint().apply {
        color = Color.WHITE
        style = Paint.Style.STROKE
        strokeWidth = 2.5f * resources.displayMetrics.density
        isAntiAlias = true
    }
    private val gridPaint = Paint().apply {
        color = Color.parseColor("#66FFFFFF")
        style = Paint.Style.STROKE
        strokeWidth = 1f * resources.displayMetrics.density
    }

    private val scaleDetector = ScaleGestureDetector(context, object : ScaleGestureDetector.SimpleOnScaleGestureListener() {
        override fun onScale(detector: ScaleGestureDetector): Boolean {
            setScale(currentScale * detector.scaleFactor, detector.focusX, detector.focusY)
            return true
        }
    })

    private var lastTouchX = 0f
    private var lastTouchY = 0f
    private var isDragging = false

    fun setImageBitmap(bmp: Bitmap) {
        bitmap = bmp
        post {
            computeWindowRect()
            fitBitmapToWindow()
            invalidate()
        }
    }

    override fun onSizeChanged(w: Int, h: Int, oldw: Int, oldh: Int) {
        super.onSizeChanged(w, h, oldw, oldh)
        computeWindowRect()
        bitmap?.let { fitBitmapToWindow() }
    }

    private fun computeWindowRect() {
        if (width == 0 || height == 0) return
        val paddingPx = 24f * resources.displayMetrics.density
        val maxW = width - paddingPx * 2
        val maxH = height - paddingPx * 2
        var w = maxW
        var h = w / aspectRatio
        if (h > maxH) {
            h = maxH
            w = h * aspectRatio
        }
        val left = (width - w) / 2f
        val top = (height - h) / 2f
        windowRect.set(left, top, left + w, top + h)
    }

    /** Centers the bitmap in the window at the smallest scale that fully covers it (no letterboxing gaps). */
    private fun fitBitmapToWindow() {
        val bmp = bitmap ?: return
        if (windowRect.width() <= 0 || windowRect.height() <= 0) return
        val scaleToCoverW = windowRect.width() / bmp.width
        val scaleToCoverH = windowRect.height() / bmp.height
        minScale = max(scaleToCoverW, scaleToCoverH)
        maxScale = minScale * 5f
        currentScale = minScale

        matrix.reset()
        matrix.postScale(currentScale, currentScale)
        val scaledW = bmp.width * currentScale
        val scaledH = bmp.height * currentScale
        val dx = windowRect.left + (windowRect.width() - scaledW) / 2f
        val dy = windowRect.top + (windowRect.height() - scaledH) / 2f
        matrix.postTranslate(dx, dy)
    }

    private fun setScale(newScale: Float, focusX: Float, focusY: Float) {
        val clamped = newScale.coerceIn(minScale, maxScale)
        val factor = clamped / currentScale
        matrix.postScale(factor, factor, focusX, focusY)
        currentScale = clamped
        constrainTranslation()
        invalidate()
    }

    /** Keeps the bitmap always fully covering the crop window — no empty/transparent gaps inside it. */
    private fun constrainTranslation() {
        val bmp = bitmap ?: return
        val bounds = RectF(0f, 0f, bmp.width.toFloat(), bmp.height.toFloat())
        matrix.mapRect(bounds)

        var dx = 0f
        var dy = 0f
        if (bounds.left > windowRect.left) dx = windowRect.left - bounds.left
        if (bounds.right < windowRect.right) dx = windowRect.right - bounds.right
        if (bounds.top > windowRect.top) dy = windowRect.top - bounds.top
        if (bounds.bottom < windowRect.bottom) dy = windowRect.bottom - bounds.bottom
        if (dx != 0f || dy != 0f) matrix.postTranslate(dx, dy)
    }

    override fun onTouchEvent(event: MotionEvent): Boolean {
        scaleDetector.onTouchEvent(event)
        when (event.actionMasked) {
            MotionEvent.ACTION_DOWN -> {
                lastTouchX = event.x
                lastTouchY = event.y
                isDragging = true
            }
            MotionEvent.ACTION_MOVE -> {
                if (isDragging && !scaleDetector.isInProgress) {
                    val dx = event.x - lastTouchX
                    val dy = event.y - lastTouchY
                    matrix.postTranslate(dx, dy)
                    constrainTranslation()
                    lastTouchX = event.x
                    lastTouchY = event.y
                    invalidate()
                }
            }
            MotionEvent.ACTION_UP, MotionEvent.ACTION_CANCEL -> isDragging = false
        }
        return true
    }

    override fun onDraw(canvas: Canvas) {
        super.onDraw(canvas)
        val bmp = bitmap ?: return

        canvas.drawBitmap(bmp, matrix, null)

        // Dim everything outside the crop window using a layer + CLEAR
        // punch-out, rather than four separate opaque rectangles — this
        // way the dim color stays exactly consistent right up to the
        // window edge instead of relying on four rects lining up pixel-
        // perfectly.
        val layerId = canvas.saveLayer(0f, 0f, width.toFloat(), height.toFloat(), null)
        canvas.drawRect(0f, 0f, width.toFloat(), height.toFloat(), dimPaint)
        canvas.drawRect(windowRect, clearPaint)
        canvas.restoreToCount(layerId)

        canvas.drawRect(windowRect, borderPaint)

        // Rule-of-thirds grid — purely a framing aid, same as native
        // camera/gallery croppers.
        val w = windowRect.width()
        val h = windowRect.height()
        for (i in 1..2) {
            val x = windowRect.left + w * i / 3f
            canvas.drawLine(x, windowRect.top, x, windowRect.bottom, gridPaint)
            val y = windowRect.top + h * i / 3f
            canvas.drawLine(windowRect.left, y, windowRect.right, y, gridPaint)
        }
    }

    /**
     * Reads exactly the source-bitmap pixels currently visible inside
     * [windowRect], at full source resolution (inverts [matrix] to map
     * the on-screen window back into bitmap-pixel space) — so the output
     * quality is the source photo's own resolution, not however large the
     * crop window happened to be laid out on screen.
     */
    fun getCroppedBitmap(): Bitmap? {
        val bmp = bitmap ?: return null
        val inverse = Matrix()
        if (!matrix.invert(inverse)) return null

        val srcRect = RectF()
        inverse.mapRect(srcRect, windowRect)

        val left = srcRect.left.toInt().coerceIn(0, bmp.width - 1)
        val top = srcRect.top.toInt().coerceIn(0, bmp.height - 1)
        val right = srcRect.right.toInt().coerceIn(left + 1, bmp.width)
        val bottom = srcRect.bottom.toInt().coerceIn(top + 1, bmp.height)

        return try {
            Bitmap.createBitmap(bmp, left, top, right - left, bottom - top)
        } catch (e: IllegalArgumentException) {
            null
        }
    }
}
