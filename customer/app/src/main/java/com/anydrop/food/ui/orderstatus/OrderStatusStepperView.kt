package com.anydrop.food.ui.orderstatus

import android.animation.ValueAnimator
import android.content.Context
import android.graphics.Typeface
import android.util.AttributeSet
import android.view.Gravity
import android.view.View
import android.widget.FrameLayout
import android.widget.LinearLayout
import android.widget.TextView
import com.anydrop.food.R

/**
 * I2 — Order tracking status timeline (docs/features.md Phase I). A 5-stage
 * visual stepper: Placed → Accepted → Preparing → Out for delivery →
 * Delivered, with the current stage highlighted and completed ones checked
 * off.
 *
 * Built programmatically rather than from a layout XML, matching this
 * codebase's existing pattern for small dynamic view sets (see
 * `RotatingEtaView`).
 *
 * The backend tracks 9 granular statuses (`orders.status`), more than the
 * 5-step happy path this stepper shows — [stepIndexFor] collapses them:
 * `ready`, `rider_assigned`, and `picked_up` all read as being on the way to
 * "Out for delivery" (the customer doesn't need a 7-step timeline to know
 * their food is coming). `cancelled`/`rejected` fall outside the happy path
 * entirely — per features.md's own note — so this view is hidden for those
 * statuses rather than trying to force them onto a step; the existing plain
 * `statusText` label on this screen already carries that message.
 *
 * Plan doc 127 §3 (2026-09-11) — rebuilt from a horizontal row-of-dots to a
 * vertical timeline (dot+connector column on the left, label to the right
 * of each dot, standard Swiggy/Zomato-style layout), with animated step
 * transitions. Two structural changes from before, both required for the
 * animation to be possible at all:
 *   1. [setStatus] no longer calls `removeAllViews()` on every call — views
 *      are built once ([buildViews]) and mutated in place on every
 *      subsequent call ([updateViews]), since there's nothing to animate
 *      *from* once old views are torn down and rebuilt.
 *   2. A transition is only animated when [currentStep] actually differs
 *      from the previous call's value (`lastStep`) — this view is still
 *      called on every 5s poll tick regardless of whether the step
 *      changed, so re-running the same animation on every poll would be
 *      wrong. The very first call ([lastStep] == -1, screen just opened)
 *      also skips animation and jumps straight to the correct state —
 *      animation is for transitions the user watches happen, not initial
 *      paint, same principle [OrderStatusActivity.updateMap] already
 *      applies to its own first-marker-placement vs. animated-move split.
 */
class OrderStatusStepperView @JvmOverloads constructor(
    context: Context,
    attrs: AttributeSet? = null
) : LinearLayout(context, attrs) {

    companion object {
        private val STEP_LABELS = intArrayOf(
            R.string.step_placed,
            R.string.step_accepted,
            R.string.step_preparing,
            R.string.step_out_for_delivery,
            R.string.step_delivered
        )

        /**
         * Maps a raw backend `orders.status` value to a 0-based index into
         * [STEP_LABELS] — the highest step reached so far. Returns null for
         * `cancelled`/`rejected`, which callers should treat as "hide the
         * stepper" (see class kdoc).
         */
        fun stepIndexFor(status: String): Int? = when (status) {
            "pending" -> 0
            "accepted" -> 1
            "preparing" -> 2
            "ready", "rider_assigned", "picked_up", "out_for_delivery" -> 3
            "delivered" -> 4
            else -> null // cancelled, rejected, or any unrecognized value
        }
    }

    private val density = resources.displayMetrics.density
    private val dotSizePx = (22 * density).toInt()
    private val lineWidthPx = (3 * density).toInt()

    // Fixed-height segments between dots rather than weight-based — a
    // vertical timeline doesn't need to "fill remaining space" the way the
    // old horizontal row's flexible-width connector did, and vertical
    // stepper rows typically have more breathing room than horizontal ones
    // need. See plan doc 127 §3.
    private val connectorHeightPx = (28 * density).toInt()
    private val labelMarginStartPx = (12 * density).toInt()

    private var built = false
    private var lastStep = -1

    // Parallel to STEP_LABELS.indices — built once in buildViews(), then
    // only mutated (never re-created) by every later setStatus() call.
    private val dotViews = mutableListOf<FrameLayout>()
    private val labelViews = mutableListOf<TextView>()
    // One shorter than the above — connectorFillViews[i] is the animated
    // "reached" segment between dot i and dot i+1. Each sits inside a
    // fixed-size FrameLayout on top of a static pending-colored base line;
    // filling it in just grows this child's height from 0 to full,
    // top-down, rather than swapping the base line's own color/height.
    private val connectorFillViews = mutableListOf<View>()

    // Only one dot is ever "current" at a time, so a single field (not an
    // array) is enough to track/cancel its gentle repeating pulse.
    private var currentPulseAnimator: ValueAnimator? = null

    init {
        orientation = VERTICAL
    }

    /** Cheap enough to call on every poll tick (every
     * [OrderStatusActivity.POLL_INTERVAL_MS]) — builds the view tree once,
     * then every call after that just mutates colors/sizes/animations in
     * place. See class kdoc for why this changed from the old
     * rebuild-every-call approach. */
    fun setStatus(currentStep: Int) {
        if (!built) {
            buildViews()
            built = true
        }
        val animate = lastStep != -1 && currentStep != lastStep
        updateViews(currentStep, animate)
        lastStep = currentStep
    }

    private fun buildViews() {
        removeAllViews()
        dotViews.clear()
        labelViews.clear()
        connectorFillViews.clear()

        for (i in STEP_LABELS.indices) {
            val row = LinearLayout(context).apply {
                orientation = HORIZONTAL
                gravity = Gravity.CENTER_VERTICAL
                layoutParams = LayoutParams(LayoutParams.MATCH_PARENT, LayoutParams.WRAP_CONTENT)
            }
            val dot = FrameLayout(context).apply {
                layoutParams = LinearLayout.LayoutParams(dotSizePx, dotSizePx)
            }
            val label = TextView(context).apply {
                layoutParams = LinearLayout.LayoutParams(
                    LinearLayout.LayoutParams.WRAP_CONTENT,
                    LinearLayout.LayoutParams.WRAP_CONTENT
                ).apply { marginStart = labelMarginStartPx }
                text = context.getString(STEP_LABELS[i])
                textSize = 13f
            }
            row.addView(dot)
            row.addView(label)
            addView(row)
            dotViews.add(dot)
            labelViews.add(label)

            // No connector after the last step.
            if (i < STEP_LABELS.size - 1) {
                // Wrapper is dotSizePx wide (same as the dot column above
                // it) so the connector centers directly under the dot.
                val connectorWrapper = FrameLayout(context).apply {
                    layoutParams = LayoutParams(dotSizePx, connectorHeightPx)
                }
                val base = View(context).apply {
                    layoutParams = FrameLayout.LayoutParams(lineWidthPx, FrameLayout.LayoutParams.MATCH_PARENT).apply {
                        gravity = Gravity.CENTER_HORIZONTAL
                    }
                    setBackgroundColor(context.getColor(R.color.outline))
                }
                // Starts at height 0 (pending); grows to connectorHeightPx,
                // either instantly (no animation needed) or animated via
                // animateConnectorFill(), per updateViews() below.
                val fill = View(context).apply {
                    layoutParams = FrameLayout.LayoutParams(lineWidthPx, 0).apply {
                        gravity = Gravity.CENTER_HORIZONTAL or Gravity.TOP
                    }
                    setBackgroundColor(context.getColor(R.color.anydrop_primary))
                }
                connectorWrapper.addView(base)
                connectorWrapper.addView(fill)
                addView(connectorWrapper)
                connectorFillViews.add(fill)
            }
        }
    }

    private fun updateViews(currentStep: Int, animate: Boolean) {
        for (i in STEP_LABELS.indices) {
            val dot = dotViews[i]
            val label = labelViews[i]
            val wasDone = lastStep != -1 && i < lastStep
            val nowDone = i < currentStep
            val wasCurrent = i == lastStep
            val nowCurrent = i == currentStep

            when {
                nowDone -> {
                    // A dot that was pulsing as "current" just got
                    // overtaken (e.g. two steps advanced between polls) —
                    // stop its loop before swapping it to "done".
                    if (wasCurrent) stopCurrentDotPulse(dot)
                    if (animate && !wasDone) {
                        animateDotComplete(dot)
                    } else {
                        dot.setBackgroundResource(R.drawable.bg_step_dot_done)
                        dot.scaleX = 1f
                        dot.scaleY = 1f
                    }
                }
                nowCurrent -> {
                    dot.setBackgroundResource(R.drawable.bg_step_dot_current)
                    if (!wasCurrent) {
                        if (animate) startCurrentDotPulse(dot) else {
                            dot.scaleX = 1f
                            dot.scaleY = 1f
                        }
                    }
                    // else: already the current dot last call — its pulse
                    // animator (if any) is already running; don't restart
                    // it every poll tick, which is exactly why setStatus()
                    // no longer rebuilds unconditionally.
                }
                else -> {
                    if (wasCurrent) stopCurrentDotPulse(dot)
                    dot.setBackgroundResource(R.drawable.bg_step_dot_pending)
                    dot.scaleX = 1f
                    dot.scaleY = 1f
                }
            }

            label.setTextColor(context.getColor(if (nowCurrent) R.color.anydrop_primary else R.color.text_secondary))
            label.setTypeface(label.typeface, if (nowCurrent) Typeface.BOLD else Typeface.NORMAL)
        }

        for (i in connectorFillViews.indices) {
            val fill = connectorFillViews[i]
            val reached = i < currentStep
            val wasReached = lastStep != -1 && i < lastStep
            val params = fill.layoutParams as FrameLayout.LayoutParams
            when {
                reached && animate && !wasReached -> animateConnectorFill(fill)
                reached -> {
                    params.height = connectorHeightPx
                    fill.layoutParams = params
                }
                else -> {
                    params.height = 0
                    fill.layoutParams = params
                }
            }
        }
    }

    /** The newly-completed dot's background swap, via a scale pulse
     * (~1.3x then back to 1x over ~300ms) rather than an instant drawable
     * swap. */
    private fun animateDotComplete(dot: View) {
        dot.setBackgroundResource(R.drawable.bg_step_dot_done)
        ValueAnimator.ofFloat(1f, 1.3f, 1f).apply {
            duration = 300L
            addUpdateListener { anim ->
                val scale = anim.animatedValue as Float
                dot.scaleX = scale
                dot.scaleY = scale
            }
            start()
        }
    }

    /** Gentle, low-key repeating scale pulse on the new current-step dot,
     * to draw the eye to "you are here" — the lowest-priority piece of the
     * three animations per plan doc 127 §3. Only ever one of these
     * running at a time (see [currentPulseAnimator] kdoc). */
    private fun startCurrentDotPulse(dot: View) {
        currentPulseAnimator?.cancel()
        dot.scaleX = 1f
        dot.scaleY = 1f
        currentPulseAnimator = ValueAnimator.ofFloat(1f, 1.15f).apply {
            duration = 700L
            repeatMode = ValueAnimator.REVERSE
            repeatCount = ValueAnimator.INFINITE
            addUpdateListener { anim ->
                val scale = anim.animatedValue as Float
                dot.scaleX = scale
                dot.scaleY = scale
            }
            start()
        }
    }

    private fun stopCurrentDotPulse(dot: View) {
        currentPulseAnimator?.cancel()
        currentPulseAnimator = null
        dot.scaleX = 1f
        dot.scaleY = 1f
    }

    /** The connector line between the just-completed step and the next,
     * filling in top-to-bottom (height animate) rather than snapping to
     * full "reached" color instantly. */
    private fun animateConnectorFill(fill: View) {
        val params = fill.layoutParams as FrameLayout.LayoutParams
        ValueAnimator.ofInt(0, connectorHeightPx).apply {
            duration = 350L
            addUpdateListener { anim ->
                params.height = anim.animatedValue as Int
                fill.layoutParams = params
            }
            start()
        }
    }

    /** A repeating pulse animator left running past this view's lifetime
     * would be a leak — cancel it when the view leaves the window, same
     * as [OrderStatusActivity.onDestroy] cancels its own animators. */
    override fun onDetachedFromWindow() {
        super.onDetachedFromWindow()
        currentPulseAnimator?.cancel()
        currentPulseAnimator = null
    }
}
