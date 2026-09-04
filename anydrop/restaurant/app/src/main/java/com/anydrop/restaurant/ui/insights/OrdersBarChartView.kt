package com.anydrop.restaurant.ui.insights

import android.content.Context
import android.graphics.Canvas
import android.graphics.Paint
import android.graphics.RectF
import android.util.AttributeSet
import android.view.View
import androidx.core.content.ContextCompat
import com.anydrop.restaurant.R
import com.anydrop.restaurant.network.InsightDailyChartPoint
import java.text.SimpleDateFormat
import java.util.Locale

/**
 * Insights tab §6: "Simple bar chart: orders per day (last 7 days)".
 * No charting library exists anywhere in this project (checked
 * build.gradle before writing this), so this is a small custom View
 * rather than pulling in a new dependency for one bar chart — same
 * "written once, reused" spirit as ShimmerFrameLayout in this same
 * package.
 *
 * Deliberately minimal: bars + a day-of-week label under each, and the
 * count printed above the tallest bars only when it fits. No axes, no
 * gridlines, no scroll/zoom — the plan doc's own word for this chart is
 * "simple."
 */
class OrdersBarChartView @JvmOverloads constructor(
    context: Context,
    attrs: AttributeSet? = null,
    defStyleAttr: Int = 0
) : View(context, attrs, defStyleAttr) {

    private var points: List<InsightDailyChartPoint> = emptyList()

    private val barPaint = Paint(Paint.ANTI_ALIAS_FLAG).apply {
        color = ContextCompat.getColor(context, R.color.anydrop_primary)
        style = Paint.Style.FILL
    }
    private val barPaintEmpty = Paint(Paint.ANTI_ALIAS_FLAG).apply {
        color = ContextCompat.getColor(context, R.color.outline)
        style = Paint.Style.FILL
    }
    private val labelPaint = Paint(Paint.ANTI_ALIAS_FLAG).apply {
        color = ContextCompat.getColor(context, R.color.text_secondary)
        textSize = 11f * resources.displayMetrics.scaledDensity
        textAlign = Paint.Align.CENTER
    }
    private val valuePaint = Paint(Paint.ANTI_ALIAS_FLAG).apply {
        color = ContextCompat.getColor(context, R.color.text_primary)
        textSize = 11f * resources.displayMetrics.scaledDensity
        textAlign = Paint.Align.CENTER
        isFakeBoldText = true
    }

    private val dayFormat = SimpleDateFormat("EEE", Locale.getDefault())
    private val isoFormat = SimpleDateFormat("yyyy-MM-dd", Locale.US)

    fun setData(newPoints: List<InsightDailyChartPoint>) {
        points = newPoints
        requestLayout()
        invalidate()
    }

    override fun onMeasure(widthMeasureSpec: Int, heightMeasureSpec: Int) {
        val desiredHeight = (140 * resources.displayMetrics.density).toInt()
        val width = MeasureSpec.getSize(widthMeasureSpec)
        setMeasuredDimension(width, desiredHeight)
    }

    override fun onDraw(canvas: Canvas) {
        super.onDraw(canvas)
        if (points.isEmpty()) return

        val density = resources.displayMetrics.density
        val labelSpace = 34f * density
        val valueSpace = 18f * density
        val chartTop = valueSpace
        val chartBottom = height - labelSpace
        val chartHeight = (chartBottom - chartTop).coerceAtLeast(1f)

        val maxCount = points.maxOf { it.orderCount }.coerceAtLeast(1)
        val barSlot = width.toFloat() / points.size
        val barWidth = barSlot * 0.5f

        points.forEachIndexed { index, point ->
            val centerX = barSlot * index + barSlot / 2f
            val barHeight = if (point.orderCount == 0) {
                2f * density // a thin sliver so a zero-order day still reads as a bar, not a gap
            } else {
                (point.orderCount.toFloat() / maxCount) * chartHeight
            }
            val top = chartBottom - barHeight
            val rect = RectF(centerX - barWidth / 2f, top, centerX + barWidth / 2f, chartBottom)
            canvas.drawRoundRect(rect, 4f * density, 4f * density, if (point.orderCount == 0) barPaintEmpty else barPaint)

            if (point.orderCount > 0) {
                canvas.drawText(point.orderCount.toString(), centerX, top - 4f * density, valuePaint)
            }

            val label = try {
                dayFormat.format(isoFormat.parse(point.date)!!)
            } catch (e: Exception) {
                point.date.takeLast(2)
            }
            canvas.drawText(label, centerX, chartBottom + labelSpace - 10f * density, labelPaint)
        }
    }
}
