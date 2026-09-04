package com.anydrop.food.ui.common

import android.content.Context
import android.content.res.ColorStateList
import android.graphics.Typeface
import android.util.AttributeSet
import android.view.Gravity
import android.view.animation.AnimationUtils
import android.widget.ImageView
import android.widget.LinearLayout
import android.widget.TextView
import android.widget.ViewFlipper
import com.anydrop.food.R

/**
 * features.md §5 — a restaurant card's ETA/distance meta line cross-fades
 * between two states when the restaurant "qualifies" as fast
 * ([NEAR_FAST_ETA_THRESHOLD_MIN]): the default "{eta} mins | {distance} km"
 * row (clock icon, grey text) and a highlighted "Near & Fast" row
 * (lightning icon, green bold text — same [R.drawable.ic_bolt] +
 * `success_fg` combo as the ETA row on Restaurant Detail's own header).
 *
 * Applies regardless of `restaurant.isOpenNow` (2026-08-09 decision) — a
 * closed restaurant's whole card is already dimmed via `alpha` elsewhere
 * (`RestaurantAdapter`/`SearchResultsAdapter`'s bind()), but the meta line
 * itself still flips exactly the same way a card that's currently open
 * does, so nothing needs a second pass once the restaurant re-opens.
 *
 * Deliberately a thin [ViewFlipper] subclass rather than a fully custom-
 * drawn view — `ViewFlipper` already gives the cross-fade animation and
 * the attach/detach-aware start/stop for free (its own `onAttachedToWindow`/
 * `onDetachedFromWindow` resume/pause the flip loop, so this doesn't need
 * to duplicate that). The two child rows are built once per [bind] call in
 * plain Kotlin, not inflated from a layout XML, matching this codebase's
 * existing pattern for small dynamic view sets (see
 * `ItemDetailBottomSheetFragment.buildQuickSelectChips()`).
 */
class RotatingEtaView @JvmOverloads constructor(
    context: Context,
    attrs: AttributeSet? = null
) : ViewFlipper(context, attrs) {

    companion object {
        // features.md §5 — "qualifies" threshold for the highlighted
        // "Near & Fast" state, per the original spec ("e.g., ETA < 20 min").
        private const val NEAR_FAST_ETA_THRESHOLD_MIN = 20
        private const val FLIP_INTERVAL_MS = 2800
    }

    init {
        inAnimation = AnimationUtils.loadAnimation(context, android.R.anim.fade_in)
        outAnimation = AnimationUtils.loadAnimation(context, android.R.anim.fade_out)
        flipInterval = FLIP_INTERVAL_MS
    }

    /**
     * Rebuilds this view's flip state(s) for [etaMinutes]/[distanceKm] —
     * call from every `bind()` (this view isn't recycled today per the
     * RecyclerView-in-NestedScrollView setup these adapters already use,
     * but rebuilding is cheap and keeps this safe if that ever changes).
     *
     * When there's nothing to show (both null), no rows are added at all.
     * When the restaurant doesn't qualify as "Near & Fast", only the
     * default row is added — with a single child, [ViewFlipper] has
     * nothing to flip to and just sits still, so this is a no-op cost for
     * the common case rather than something that needs its own branch to
     * "turn off" flipping.
     */
    fun bind(etaMinutes: Int?, distanceKm: Double?) {
        stopFlipping()
        removeAllViews()

        val etaText = buildString {
            if (etaMinutes != null) append("$etaMinutes mins")
            if (etaMinutes != null && distanceKm != null) append(" | ")
            if (distanceKm != null) append(String.format(java.util.Locale.getDefault(), "%.1f km", distanceKm))
        }
        if (etaText.isNotBlank()) {
            addView(buildRow(R.drawable.ic_clock, etaText, R.color.text_secondary, bold = false))
        }

        val qualifiesAsNearFast = etaMinutes != null && etaMinutes < NEAR_FAST_ETA_THRESHOLD_MIN
        if (qualifiesAsNearFast) {
            addView(
                buildRow(R.drawable.ic_bolt, context.getString(R.string.near_and_fast), R.color.success_fg, bold = true)
            )
        }

        if (childCount > 1) startFlipping()
    }

    private fun buildRow(iconRes: Int, text: String, colorRes: Int, bold: Boolean): LinearLayout {
        val density = resources.displayMetrics.density
        val color = context.getColor(colorRes)

        val icon = ImageView(context).apply {
            layoutParams = LinearLayout.LayoutParams((12 * density).toInt(), (12 * density).toInt())
            setImageResource(iconRes)
            imageTintList = ColorStateList.valueOf(color)
        }
        val label = TextView(context).apply {
            layoutParams = LinearLayout.LayoutParams(
                LinearLayout.LayoutParams.WRAP_CONTENT,
                LinearLayout.LayoutParams.WRAP_CONTENT
            ).apply { marginStart = (4 * density).toInt() }
            this.text = text
            textSize = 12f
            setTextColor(color)
            if (bold) setTypeface(typeface, Typeface.BOLD)
        }

        return LinearLayout(context).apply {
            orientation = LinearLayout.HORIZONTAL
            gravity = Gravity.CENTER_VERTICAL
            layoutParams = LinearLayout.LayoutParams(
                LinearLayout.LayoutParams.WRAP_CONTENT,
                LinearLayout.LayoutParams.WRAP_CONTENT
            )
            addView(icon)
            addView(label)
        }
    }
}
