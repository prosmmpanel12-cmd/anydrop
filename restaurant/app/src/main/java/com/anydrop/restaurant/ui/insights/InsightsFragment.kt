package com.anydrop.restaurant.ui.insights

import android.os.Bundle
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import androidx.fragment.app.Fragment
import androidx.lifecycle.lifecycleScope
import com.anydrop.restaurant.R
import com.anydrop.restaurant.databinding.FragmentInsightsBinding
import com.anydrop.restaurant.databinding.ItemInsightTopItemBinding
import com.anydrop.restaurant.network.ApiClient
import com.anydrop.restaurant.network.InsightsResult
import com.anydrop.restaurant.ui.common.InAppNotifier
import kotlinx.coroutines.launch

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

        binding.rangeToggleGroup.addOnButtonCheckedListener { _, checkedId, isChecked ->
            if (!isChecked) return@addOnButtonCheckedListener
            currentRange = when (checkedId) {
                R.id.rangeToday -> "today"
                R.id.rangeMonth -> "month"
                else -> "week"
            }
            loadInsights()
        }

        binding.swipeRefresh.setOnRefreshListener { loadInsights() }

        loadInsights()
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
    }
}
