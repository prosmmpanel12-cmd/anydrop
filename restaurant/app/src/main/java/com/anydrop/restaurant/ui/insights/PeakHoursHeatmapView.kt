package com.anydrop.restaurant.ui.insights

import android.content.Context
import android.graphics.Canvas
import android.graphics.Color
import android.graphics.Paint
import android.graphics.RectF
import android.util.AttributeSet
import android.view.View
import androidx.core.content.ContextCompat
import com.anydrop.restaurant.R
import com.anydrop.restaurant.network.InsightPeakHourCell

/**
 * Peak hours heatmap (today.md §1 wishlist / PENDING.md item 3's own
 * "Peak hours" line — the design decision doc 49 flagged as needed
 * before building anything, "heatmap vs. single busiest-hour stat",
 * now resolved: the app owner chose the full heatmap). Same "no
 * charting library exists anywhere in this project" call
 * OrdersBarChartView's own kdoc already made — this is another small
 * hand-written canvas View rather than a new dependency for one grid.
 *
 * Grid: 7 rows (Mon..Sun, this project's existing ISO day-of-week
 * convention) × 24 columns (hour 0..23). Cell color interpolates
 * between @color/outline (0 orders) and @color/anydrop_primary (the
 * single busiest cell in the data), by alpha — same two-color,
 * alpha-interpolated approach OrdersBarChartView uses for its own
 * empty-vs-filled bars, extended from a binary choice to a continuous
 * one since a heatmap's whole point is showing gradation, not just
 * presence/absence.
 *
 * Hour-of-day labels are shown sparsely (every 3 hours: 12a/3a/6a...)
 * rather than all 24, since 24 text labels across one screen width
 * would overlap on any phone. Day labels (one per row) are shown in
 * full since there are only 7 and vertical space per row is generous
 * enough to fit "Mon".."Sun" without truncation.
 */
class PeakHoursHeatmapView @JvmOverloads constructor(
    context: Context,
    attrs: AttributeSet? = null,
    defStyleAttr: Int = 0
) : View(context, attrs, defStyleAttr) {

    // Index 0 = Monday .. 6 = Sunday (day_of_week 1..7 minus 1), each a
    // 24-length array indexed by hour, matching the ISO convention the
    // backend already sends.
    private var grid: Array<IntArray> = Array(7) { IntArray(24) }
    private var maxCount: Int = 0

    private val emptyColor = ContextCompat.getColor(context, R.color.outline)
    private val heatColor = ContextCompat.getColor(context, R.color.anydrop_primary)

    private val cellPaint = Paint(Paint.ANTI_ALIAS_FLAG).apply {
        style = Paint.Style.FILL
    }
    private val dayLabelPaint = Paint(Paint.ANTI_ALIAS_FLAG).apply {
        color = ContextCompat.getColor(context, R.color.text_secondary)
        textSize = 10f * resources.displayMetrics.scaledDensity
        textAlign = Paint.Align.LEFT
    }
    private val hourLabelPaint = Paint(Paint.ANTI_ALIAS_FLAG).apply {
        color = ContextCompat.getColor(context, R.color.text_secondary)
        textSize = 9f * resources.displayMetrics.scaledDensity
        textAlign = Paint.Align.CENTER
    }

    private val dayLabels = arrayOf("Mon", "Tue", "Wed", "Thu", "Fri", "Sat", "Sun")
    // Every 3rd hour, 12-hour clock with a/p suffix (no colons/minutes —
    // there's no room for "12:00 AM" at this label density).
    private val hourLabelStep = 3

    fun setData(cells: List<InsightPeakHourCell>, newMaxCount: Int) {
        val newGrid = Array(7) { IntArray(24) }
        for (cell in cells) {
            val rowIndex = cell.dayOfWeek - 1
            if (rowIndex in 0..6 && cell.hour in 0..23) {
                newGrid[rowIndex][cell.hour] = cell.orderCount
            }
        }
        grid = newGrid
        maxCount = newMaxCount.coerceAtLeast(0)
        invalidate()
    }

    override fun onMeasure(widthMeasureSpec: Int, heightMeasureSpec: Int) {
        val density = resources.displayMetrics.density
        val hourLabelSpace = 16f * density
        val rowHeight = 20f * density
        val desiredHeight = (hourLabelSpace + rowHeight * 7).toInt()
        val width = MeasureSpec.getSize(widthMeasureSpec)
        setMeasuredDimension(width, desiredHeight)
    }

    override fun onDraw(canvas: Canvas) {
        super.onDraw(canvas)
        val density = resources.displayMetrics.density

        val dayLabelWidth = 28f * density
        val hourLabelSpace = 16f * density
        val gridLeft = dayLabelWidth
        val gridTop = hourLabelSpace
        val gridWidth = (width - gridLeft).coerceAtLeast(1f)
        val gridHeight = (height - gridTop).coerceAtLeast(1f)
        val rowHeight = gridHeight / 7f
        val colWidth = gridWidth / 24f
        val cellGap = 1f * density

        // Hour labels across the top, every 3rd hour only (see kdoc).
        var hour = 0
        while (hour < 24) {
            val label = formatHourLabel(hour)
            val centerX = gridLeft + colWidth * hour + colWidth / 2f
            canvas.drawText(label, centerX, hourLabelSpace - 4f * density, hourLabelPaint)
            hour += hourLabelStep
        }

        for (row in 0..6) {
            // Day label, vertically centered on its row.
            val rowCenterY = gridTop + rowHeight * row + rowHeight / 2f
            val textY = rowCenterY - (dayLabelPaint.ascent() + dayLabelPaint.descent()) / 2f
            canvas.drawText(dayLabels[row], 0f, textY, dayLabelPaint)

            for (col in 0..23) {
                val count = grid[row][col]
                cellPaint.color = colorForCount(count)
                val left = gridLeft + colWidth * col + cellGap / 2f
                val top = gridTop + rowHeight * row + cellGap / 2f
                val right = left + colWidth - cellGap
                val bottom = top + rowHeight - cellGap
                canvas.drawRoundRect(RectF(left, top, right, bottom), 2f * density, 2f * density, cellPaint)
            }
        }
    }

    /** Alpha-interpolates from [emptyColor] (0 orders) to full-opacity
     * [heatColor] (the busiest cell) — a zero-order cell still gets a
     * faint, visible tile rather than being invisible, same "reads as
     * a real zero, not a missing/broken cell" spirit as
     * OrdersBarChartView's own zero-order sliver. */
    private fun colorForCount(count: Int): Int {
        if (maxCount <= 0 || count <= 0) return emptyColor
        val fraction = (count.toFloat() / maxCount).coerceIn(0f, 1f)
        val alpha = (60 + fraction * 195).toInt().coerceIn(0, 255) // 60..255, never fully transparent
        return Color.argb(alpha, Color.red(heatColor), Color.green(heatColor), Color.blue(heatColor))
    }

    private fun formatHourLabel(hour: Int): String = when (hour) {
        0 -> "12a"
        12 -> "12p"
        in 1..11 -> "${hour}a"
        else -> "${hour - 12}p"
    }
}
