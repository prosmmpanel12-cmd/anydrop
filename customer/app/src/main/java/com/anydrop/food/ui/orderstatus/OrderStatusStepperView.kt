package com.anydrop.food.ui.orderstatus

import android.content.Context
import android.graphics.Typeface
import android.util.AttributeSet
import android.view.Gravity
import android.widget.FrameLayout
import android.widget.LinearLayout
import android.widget.TextView
import android.widget.View
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
    private val lineHeightPx = (2 * density).toInt()

    init {
        orientation = VERTICAL
    }

    /** Rebuilds this view's dots/lines/labels for [status]. Cheap enough to
     * call on every poll tick (every [OrderStatusActivity.POLL_INTERVAL_MS]),
     * matching [RotatingEtaView.bind]'s rebuild-on-every-bind approach. */
    fun setStatus(currentStep: Int) {
        removeAllViews()

        val dotsRow = LinearLayout(context).apply {
            orientation = HORIZONTAL
            gravity = Gravity.CENTER_VERTICAL
            layoutParams = LayoutParams(LayoutParams.MATCH_PARENT, LayoutParams.WRAP_CONTENT)
        }
        val labelsRow = LinearLayout(context).apply {
            orientation = HORIZONTAL
            layoutParams = LayoutParams(LayoutParams.MATCH_PARENT, LayoutParams.WRAP_CONTENT).apply {
                topMargin = (4 * density).toInt()
            }
        }

        for (i in STEP_LABELS.indices) {
            dotsRow.addView(buildDot(i, currentStep))
            if (i < STEP_LABELS.size - 1) {
                dotsRow.addView(buildConnectorLine(reached = i < currentStep))
            }
            labelsRow.addView(buildLabel(STEP_LABELS[i], current = i == currentStep))
        }

        addView(dotsRow)
        addView(labelsRow)
    }

    private fun buildDot(index: Int, currentStep: Int): View {
        val bg = when {
            index < currentStep -> R.drawable.bg_step_dot_done
            index == currentStep -> R.drawable.bg_step_dot_current
            else -> R.drawable.bg_step_dot_pending
        }
        return FrameLayout(context).apply {
            layoutParams = LinearLayout.LayoutParams(dotSizePx, dotSizePx)
            setBackgroundResource(bg)
        }
    }

    private fun buildConnectorLine(reached: Boolean): View {
        return View(context).apply {
            layoutParams = LinearLayout.LayoutParams(0, lineHeightPx, 1f).apply {
                gravity = Gravity.CENTER_VERTICAL
            }
            setBackgroundColor(
                context.getColor(if (reached) R.color.anydrop_primary else R.color.outline)
            )
        }
    }

    private fun buildLabel(textRes: Int, current: Boolean): TextView {
        return TextView(context).apply {
            layoutParams = LinearLayout.LayoutParams(0, LinearLayout.LayoutParams.WRAP_CONTENT, 1f)
            text = context.getString(textRes)
            textSize = 10f
            gravity = Gravity.CENTER
            setTextColor(context.getColor(if (current) R.color.anydrop_primary else R.color.text_secondary))
            if (current) setTypeface(typeface, Typeface.BOLD)
        }
    }
}
