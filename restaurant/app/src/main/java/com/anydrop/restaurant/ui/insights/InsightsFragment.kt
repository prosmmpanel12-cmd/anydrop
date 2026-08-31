package com.anydrop.restaurant.ui.insights

import android.content.Intent
import android.os.Bundle
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import androidx.core.content.FileProvider
import androidx.fragment.app.Fragment
import androidx.lifecycle.lifecycleScope
import com.anydrop.restaurant.R
import com.anydrop.restaurant.databinding.FragmentInsightsBinding
import com.anydrop.restaurant.databinding.ItemInsightTopItemBinding
import com.anydrop.restaurant.network.ApiClient
import com.anydrop.restaurant.network.InsightsResult
import com.anydrop.restaurant.ui.common.InAppNotifier
import com.google.android.material.datepicker.MaterialDatePicker
import com.google.android.material.dialog.MaterialAlertDialogBuilder
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import java.io.File
import java.io.FileOutputStream
import java.text.SimpleDateFormat
import java.util.Calendar
import java.util.Locale
import java.util.TimeZone

/**
 * Insights tab — real implementation (§10 item 6), replacing the
 * placeholder that shipped with the bottom-nav shell. Backend:
 * restaurant/insights.php (new — see that file's own header for the
 * full data-shape rationale).
 *
 * Follows OrdersFragment's exact load/skeleton/error shape: skeleton
 * visible until the first successful response, swipeRefresh drives a
 * reload, InAppNotifier surfaces failures. No ViewModel layer — same
 * as every other screen in this app, the fragment calls ApiClient
 * directly.
 *
 * Top-5 items list is a plain LinearLayout with rows inflated
 * directly (not a RecyclerView) since it's capped at 5 rows by the
 * backend query itself — a RecyclerView would be pure overhead here.
 */
class InsightsFragment : Fragment() {

    private var _binding: FragmentInsightsBinding? = null
    private val binding get() = _binding!!

    private val api by lazy { ApiClient.create(requireContext()) }

    private var currentRange = "week"

    // Wire-format date (matches insights.php's expected YYYY-MM-DD, same
    // convention ClosureScheduleActivity's wireDateFormat uses) — UTC
    // explicitly set, same reasoning as that class's kdoc: MaterialDatePicker
    // returns UTC millis regardless of device timezone, so formatting in
    // the device's local zone can roll the date back/forward a day near
    // midnight.
    private val wireDateFormat = SimpleDateFormat("yyyy-MM-dd", Locale.US).apply {
        timeZone = TimeZone.getTimeZone("UTC")
    }

    override fun onCreateView(
        inflater: LayoutInflater,
        container: ViewGroup?,
        savedInstanceState: Bundle?
    ): View {
        _binding = FragmentInsightsBinding.inflate(inflater, container, false)
        return binding.root
    }

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        super.onViewCreated(view, savedInstanceState)

        // Rebuilt as a plain 3-TextView segmented control (see
        // fragment_insights.xml's comment) — MaterialButtonToggleGroup's
        // checked-listener API no longer applies, so selection is
        // handled directly via View.isSelected.
        val rangeSegments = listOf(
            binding.rangeToday to "today",
            binding.rangeWeek to "week",
            binding.rangeMonth to "month"
        )
        rangeSegments.forEach { (segment, rangeKey) ->
            segment.setOnClickListener {
                if (currentRange == rangeKey) return@setOnClickListener
                currentRange = rangeKey
                rangeSegments.forEach { (v, key) -> v.isSelected = (key == rangeKey) }
                loadInsights()
            }
        }

        binding.swipeRefresh.setOnRefreshListener { loadInsights() }

        binding.btnExportInsights.setOnClickListener { showExportChoiceDialog() }

        loadInsights()
    }

    /** First choice: export the range already selected on screen, or
     * pick a custom from/to range instead. Kept as a simple two-item
     * AlertDialog rather than a bottom sheet — same "simplest widget
     * that does the job" call ClosureScheduleActivity's reason-input
     * dialog made, no existing custom-dialog pattern on this screen to
     * match instead. */
    private fun showExportChoiceDialog() {
        val rangeLabel = when (currentRange) {
            "today" -> getString(R.string.insights_range_today)
            "month" -> getString(R.string.insights_range_month)
            else -> getString(R.string.insights_range_week)
        }
        val options = arrayOf(
            getString(R.string.insights_export_choice_current_range, rangeLabel),
            getString(R.string.insights_export_choice_custom_range)
        )
        MaterialAlertDialogBuilder(requireContext())
            .setTitle(R.string.insights_export_choice_title)
            .setItems(options) { _, which ->
                if (which == 0) {
                    exportCsv(range = currentRange, from = null, to = null)
                } else {
                    showCustomRangePicker()
                }
            }
            .show()
    }

    /** Built-in range picker (not two separate single-date pickers like
     * ClosureScheduleActivity's start/end fields) — a single "pick a
     * range" gesture is the more natural fit for a one-shot export
     * action with no form fields around it to tab between. */
    private fun showCustomRangePicker() {
        val picker = MaterialDatePicker.Builder.dateRangePicker()
            .setTitleText(getString(R.string.insights_export_range_picker_title))
            .build()
        picker.addOnPositiveButtonClickListener { selection ->
            val cal = Calendar.getInstance(TimeZone.getTimeZone("UTC"))
            cal.timeInMillis = selection.first
            val from = wireDateFormat.format(cal.time)
            cal.timeInMillis = selection.second
            val to = wireDateFormat.format(cal.time)
            exportCsv(range = "custom", from = from, to = to)
        }
        picker.show(childFragmentManager, "insights_export_range_picker")
    }

    /** Downloads the CSV via the @Streaming endpoint into cacheDir,
     * then hands it to the Android share-sheet through this app's
     * FileProvider. No progress bar/dialog — same "just a Toast on
     * either end" level of ceremony every other background action on
     * this screen already uses (loadInsights's own error Toast), and
     * a CSV export of at most 500 rows is fast enough that a spinner
     * would mostly just flash. */
    private fun exportCsv(range: String, from: String?, to: String?) {
        viewLifecycleOwner.lifecycleScope.launch {
            try {
                val response = api.exportInsightsCsv(range = range, from = from, to = to)
                if (_binding == null) return@launch
                val body = response.body()
                if (!response.isSuccessful || body == null) {
                    InAppNotifier.show(activity, getString(R.string.insights_export_failed), InAppNotifier.Type.ERROR)
                    return@launch
                }

                val fileName = "anydrop_insights_${range}_${System.currentTimeMillis()}.csv"
                val uri = withContext(Dispatchers.IO) {
                    val exportsDir = File(requireContext().cacheDir, "exports").apply { mkdirs() }
                    val file = File(exportsDir, fileName)
                    body.byteStream().use { input ->
                        FileOutputStream(file).use { output -> input.copyTo(output) }
                    }
                    FileProvider.getUriForFile(
                        requireContext(),
                        "${requireContext().packageName}.fileprovider",
                        file
                    )
                }
                if (_binding == null) return@launch

                val shareIntent = Intent(Intent.ACTION_SEND).apply {
                    type = "text/csv"
                    putExtra(Intent.EXTRA_STREAM, uri)
                    addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION)
                }
                startActivity(Intent.createChooser(shareIntent, getString(R.string.insights_export_share_title)))
            } catch (e: Exception) {
                if (_binding != null) {
                    InAppNotifier.show(activity, getString(R.string.insights_export_failed), InAppNotifier.Type.ERROR)
                }
            }
        }
    }

    override fun onDestroyView() {
        super.onDestroyView()
        _binding = null
    }

    private fun loadInsights() {
        if (_binding == null) return
        val hasContent = binding.insightsContent.visibility == View.VISIBLE
        // Only show the skeleton on first load — a range-switch or
        // pull-to-refresh with existing content on screen just keeps
        // showing that content until the new response lands, same
        // "don't flash back to skeleton on a re-fetch" behavior
        // OrdersFragment's own sections already follow.
        if (!hasContent) {
            binding.insightsSkeleton.visibility = View.VISIBLE
        }

        viewLifecycleOwner.lifecycleScope.launch {
            try {
                val response = api.getInsights(range = currentRange)
                val result = response.body()?.data
                if (_binding == null) return@launch

                if (response.isSuccessful && result != null) {
                    renderInsights(result)
                    binding.insightsContent.visibility = View.VISIBLE
                } else {
                    InAppNotifier.show(activity, getString(R.string.insights_load_error), InAppNotifier.Type.ERROR)
                }
            } catch (e: Exception) {
                if (_binding != null) {
                    InAppNotifier.show(activity, getString(R.string.insights_load_error), InAppNotifier.Type.ERROR)
                }
            } finally {
                if (_binding != null) {
                    binding.insightsSkeleton.visibility = View.GONE
                    binding.swipeRefresh.isRefreshing = false
                }
            }
        }
    }

    private fun renderInsights(result: InsightsResult) {
        val b = binding

        b.statOrdersValue.text = result.stats.totalOrders.toString()
        b.statEarningsValue.text = "₹${"%.0f".format(result.stats.totalEarnings)}"
        b.statAovValue.text = "₹${"%.0f".format(result.stats.averageOrderValue)}"
        b.statCancellationValue.text = "${"%.1f".format(result.stats.cancellationRatePercent)}%"

        b.ordersBarChart.setData(result.dailyChart)

        b.topItemsList.removeAllViews()
        if (result.topItems.isEmpty()) {
            b.topItemsEmptyText.visibility = View.VISIBLE
        } else {
            b.topItemsEmptyText.visibility = View.GONE
            result.topItems.forEachIndexed { index, item ->
                val rowBinding = ItemInsightTopItemBinding.inflate(
                    LayoutInflater.from(requireContext()), b.topItemsList, false
                )
                rowBinding.rankBadge.text = (index + 1).toString()
                rowBinding.itemName.text = item.name
                rowBinding.itemRevenue.text = "₹${"%.0f".format(item.revenue)} in revenue"
                rowBinding.itemQuantity.text = "${item.quantitySold} sold"
                b.topItemsList.addView(rowBinding.root)
            }
        }

        b.repeatCustomersText.text = getString(
            R.string.insights_repeat_customers_body,
            result.repeatCustomers.count,
            result.repeatCustomers.distinctCustomersInRange
        )

        b.peakHoursHeatmap.setData(result.peakHours.cells, result.peakHours.maxCount)
        val slot = result.peakHours.peakSlot
        b.peakHoursCaption.text = if (slot != null) {
            getString(
                R.string.insights_peak_hours_caption,
                slot.dayName,
                formatHourRange(slot.hour),
                slot.orderCount
            )
        } else {
            getString(R.string.insights_peak_hours_empty)
        }
    }

    /** "7 PM - 8 PM" from a 0-23 hour — kept here rather than in the
     * heatmap view itself since the view's own hour labels use the
     * compact "7p" form (see PeakHoursHeatmapView's kdoc); the caption
     * has room to spell it out fully. */
    private fun formatHourRange(hour: Int): String {
        fun label(h: Int): String {
            val period = if (h < 12) "AM" else "PM"
            val display = when (h % 12) { 0 -> 12; else -> h % 12 }
            return "$display $period"
        }
        return "${label(hour)} - ${label((hour + 1) % 24)}"
    }
}
