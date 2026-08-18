package com.anydrop.restaurant.ui.orders

import android.content.Intent
import android.os.Bundle
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import androidx.fragment.app.Fragment
import androidx.lifecycle.lifecycleScope
import androidx.recyclerview.widget.LinearLayoutManager
import com.anydrop.restaurant.R
import com.anydrop.restaurant.databinding.FragmentOrdersBinding
import com.anydrop.restaurant.network.AcceptBody
import com.anydrop.restaurant.network.ApiClient
import com.anydrop.restaurant.network.Order
import com.anydrop.restaurant.network.RejectBody
import com.anydrop.restaurant.network.StatusUpdateBody
import com.anydrop.restaurant.ui.common.InAppNotifier
import com.anydrop.restaurant.ui.dashboard.OrderAdapter
import com.anydrop.restaurant.ui.orderdetail.OrderDetailActivity
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

/**
 * Orders tab (§4 of the UI plan, §10 item 3). Three always-visible
 * sections replace the old New/Active/History tab filters: New
 * (status=pending), In progress (accepted/preparing/ready/rider_assigned/
 * picked_up/out_for_delivery — the old Tab.ACTIVE filter), Completed
 * today (delivered/cancelled/rejected — the old Tab.HISTORY filter,
 * collapsed by default). Each section gets its own RecyclerView + its
 * own OrderAdapter instance fixed to the matching CardMode, per
 * docs/restorent/NEXT_SESSION_PROMPT.md's suggested approach (one
 * shared item layout + adapter class, three instances, rather than
 * three separate item layouts).
 *
 * The operational-status pill (formerly this fragment's own
 * switchAcceptingOrders switch + summaryText) now lives in
 * MainActivity's shared top bar — this fragment no longer touches
 * operational status at all, only the "Today" stat strip (orders count/
 * earnings/avg prep), which is unrelated data from the same
 * getDashboard() call.
 *
 * Polling and the onResume()-refresh-on-return-from-detail behavior are
 * unchanged from the tab-filter version — just now driving three
 * status-filtered calls instead of one.
 */
class OrdersFragment : Fragment() {

    private var _binding: FragmentOrdersBinding? = null
    private val binding get() = _binding!!

    private val api by lazy { ApiClient.create(requireContext()) }

    private lateinit var newAdapter: OrderAdapter
    private lateinit var inProgressAdapter: OrderAdapter
    private lateinit var completedAdapter: OrderAdapter

    private var completedExpanded = false
    private var completedLoadedOnce = false

    // Order Management small addition — "loud sound on new order". Null
    // until the very first successful loadNew() so app-open (which always
    // sees whatever's already pending) never false-fires the alert; only a
    // later poll finding an id that wasn't in the previous snapshot counts
    // as genuinely new.
    // Bug fix (build error, 2026-08-18) — declared as read-only Set, not
    // MutableSet: every update below is a full reassignment
    // (`knownNewOrderIds = currentIds`, itself the result of `.toSet()`),
    // never an in-place mutation, so MutableSet was the wrong type and
    // failed to compile ("inferred type is Set<Int> but MutableSet<Int>?
    // was expected").
    private var knownNewOrderIds: Set<Int>? = null

    private companion object {
        const val POLL_INTERVAL_MS = 10000L
        const val STATUS_NEW = "pending"
        const val STATUS_IN_PROGRESS = "accepted,preparing,ready,rider_assigned,picked_up,out_for_delivery"
        const val STATUS_COMPLETED = "delivered,cancelled,rejected"
    }

    override fun onCreateView(
        inflater: LayoutInflater,
        container: ViewGroup?,
        savedInstanceState: Bundle?
    ): View {
        _binding = FragmentOrdersBinding.inflate(inflater, container, false)
        return binding.root
    }

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        super.onViewCreated(view, savedInstanceState)

        val openDetail: (Order) -> Unit = { order ->
            val intent = Intent(requireContext(), OrderDetailActivity::class.java)
            intent.putExtra(OrderDetailActivity.EXTRA_ORDER_ID, order.id)
            startActivity(intent)
        }

        newAdapter = OrderAdapter(
            context = requireContext(),
            mode = OrderAdapter.CardMode.NEW,
            onClick = openDetail,
            onAccept = { order, prepMinutes -> acceptOrder(order, prepMinutes) },
            onReject = { order, reason -> rejectOrder(order, reason) }
        )
        inProgressAdapter = OrderAdapter(
            context = requireContext(),
            mode = OrderAdapter.CardMode.IN_PROGRESS,
            onClick = openDetail,
            onMarkNextStep = { order -> markNextStep(order) }
        )
        completedAdapter = OrderAdapter(
            context = requireContext(),
            mode = OrderAdapter.CardMode.COMPLETED,
            onClick = openDetail
        )

        binding.newOrdersRecycler.layoutManager = LinearLayoutManager(requireContext())
        binding.newOrdersRecycler.adapter = newAdapter
        binding.inProgressOrdersRecycler.layoutManager = LinearLayoutManager(requireContext())
        binding.inProgressOrdersRecycler.adapter = inProgressAdapter
        binding.completedOrdersRecycler.layoutManager = LinearLayoutManager(requireContext())
        binding.completedOrdersRecycler.adapter = completedAdapter

        binding.swipeRefresh.setOnRefreshListener { loadAll() }
        binding.completedHeader.setOnClickListener { toggleCompleted() }

        loadAll()
        startPolling()
    }

    override fun onResume() {
        super.onResume()
        // Covers returning from OrderDetailActivity (this fragment's view
        // survives that, unlike a tab switch) — refresh so any status
        // change made there is reflected immediately.
        if (_binding != null) loadAll()
    }

    override fun onPause() {
        super.onPause()
        // Don't keep ringing/vibrating once the staff has left this screen
        // — the next poll after they return re-evaluates from scratch.
        com.anydrop.restaurant.ui.common.NewOrderAlertSound.stop()
    }

    override fun onDestroyView() {
        super.onDestroyView()
        com.anydrop.restaurant.ui.common.NewOrderAlertSound.stop()
        _binding = null
    }

    private fun toggleCompleted() {
        completedExpanded = !completedExpanded
        binding.completedContent.visibility = if (completedExpanded) View.VISIBLE else View.GONE
        binding.completedChevron.text = if (completedExpanded) "▾" else "▸"
        // Load lazily — no point fetching a section the staff never opens.
        if (completedExpanded && !completedLoadedOnce) {
            loadCompleted()
        }
    }

    private fun loadAll() {
        loadNew()
        loadInProgress()
        loadDashboardSummary()
        if (completedExpanded) loadCompleted()
    }

    private fun loadNew() {
        if (_binding == null) return
        binding.newSkeleton.visibility = if (newAdapter.itemCount == 0) View.VISIBLE else View.GONE
        viewLifecycleOwner.lifecycleScope.launch {
            try {
                val response = api.getOrders(status = STATUS_NEW)
                val orders = response.body()?.data?.data ?: emptyList()
                if (_binding == null) return@launch

                // Order Management small addition — "loud sound on new
                // order". Compare against the last-seen snapshot, not just
                // "orders.isNotEmpty()" — otherwise an untouched pending
                // order would keep re-triggering the alert on every single
                // 10s poll instead of firing once when it actually arrives.
                val currentIds = orders.map { it.id }.toSet()
                val previouslyKnown = knownNewOrderIds
                if (previouslyKnown != null && currentIds.any { it !in previouslyKnown }) {
                    com.anydrop.restaurant.ui.common.NewOrderAlertSound.play(requireContext())
                }
                knownNewOrderIds = currentIds

                newAdapter.submitList(orders)
                binding.newEmptyText.visibility = if (orders.isEmpty()) View.VISIBLE else View.GONE
            } catch (e: Exception) {
                InAppNotifier.show(activity, "Couldn't load orders", InAppNotifier.Type.ERROR)
            } finally {
                if (_binding != null) {
                    binding.newSkeleton.visibility = View.GONE
                    binding.swipeRefresh.isRefreshing = false
                }
            }
        }
    }

    private fun loadInProgress() {
        if (_binding == null) return
        binding.inProgressSkeleton.visibility = if (inProgressAdapter.itemCount == 0) View.VISIBLE else View.GONE
        viewLifecycleOwner.lifecycleScope.launch {
            try {
                val response = api.getOrders(status = STATUS_IN_PROGRESS)
                val orders = response.body()?.data?.data ?: emptyList()
                if (_binding == null) return@launch
                inProgressAdapter.submitList(orders)
                binding.inProgressEmptyText.visibility = if (orders.isEmpty()) View.VISIBLE else View.GONE
            } catch (e: Exception) {
                InAppNotifier.show(activity, "Couldn't load orders", InAppNotifier.Type.ERROR)
            } finally {
                if (_binding != null) binding.inProgressSkeleton.visibility = View.GONE
            }
        }
    }

    private fun loadCompleted() {
        if (_binding == null) return
        binding.completedSkeleton.visibility = if (completedAdapter.itemCount == 0) View.VISIBLE else View.GONE
        viewLifecycleOwner.lifecycleScope.launch {
            try {
                val response = api.getOrders(status = STATUS_COMPLETED)
                val orders = response.body()?.data?.data ?: emptyList()
                completedLoadedOnce = true
                if (_binding == null) return@launch
                completedAdapter.submitList(orders)
                binding.completedEmptyText.visibility = if (orders.isEmpty()) View.VISIBLE else View.GONE
            } catch (e: Exception) {
                InAppNotifier.show(activity, "Couldn't load orders", InAppNotifier.Type.ERROR)
            } finally {
                if (_binding != null) binding.completedSkeleton.visibility = View.GONE
            }
        }
    }

    private fun loadDashboardSummary() {
        viewLifecycleOwner.lifecycleScope.launch {
            try {
                val summary = api.getDashboard().body()?.data
                if (summary != null && _binding != null) {
                    binding.statOrdersValue.text = summary.today.ordersCount.toString()
                    binding.statEarningsValue.text = "₹${"%.0f".format(summary.today.earnings)}"
                    val avgPrep = summary.today.avgPrepMinutes
                    binding.statAvgPrepValue.text = if (avgPrep != null) {
                        getString(R.string.stat_avg_prep_format, avgPrep)
                    } else {
                        getString(R.string.stat_placeholder)
                    }
                }
            } catch (e: Exception) {
                // Non-critical — leave the stat strip blank if it fails, sections still work.
            }
        }
    }

    private fun acceptOrder(order: Order, prepMinutes: Int) {
        com.anydrop.restaurant.ui.common.NewOrderAlertSound.stop()
        viewLifecycleOwner.lifecycleScope.launch {
            try {
                val response = api.acceptOrder(order.id, AcceptBody(estimatedPrepMinutes = prepMinutes))
                if (response.isSuccessful && response.body()?.data != null) {
                    loadNew()
                    loadInProgress()
                } else {
                    InAppNotifier.show(activity, response.body()?.error ?: "Couldn't accept order", InAppNotifier.Type.ERROR)
                }
            } catch (e: Exception) {
                InAppNotifier.show(activity, "Network error", InAppNotifier.Type.ERROR)
            }
        }
    }

    private fun rejectOrder(order: Order, reason: String) {
        com.anydrop.restaurant.ui.common.NewOrderAlertSound.stop()
        viewLifecycleOwner.lifecycleScope.launch {
            try {
                val response = api.rejectOrder(order.id, RejectBody(reason))
                if (response.isSuccessful && response.body()?.data != null) {
                    loadNew()
                    if (completedExpanded) loadCompleted()
                } else {
                    InAppNotifier.show(activity, response.body()?.error ?: "Couldn't reject order", InAppNotifier.Type.ERROR)
                }
            } catch (e: Exception) {
                InAppNotifier.show(activity, "Network error", InAppNotifier.Type.ERROR)
            }
        }
    }

    private fun markNextStep(order: Order) {
        val nextStatus = when (order.status) {
            "accepted" -> "preparing"
            "preparing" -> "ready"
            else -> return // no restaurant-side action left (matches OrderDetailActivity.configureActions()'s else branch)
        }
        viewLifecycleOwner.lifecycleScope.launch {
            try {
                val response = api.updateStatus(order.id, StatusUpdateBody(nextStatus))
                if (response.isSuccessful && response.body()?.data != null) {
                    loadInProgress()
                } else {
                    InAppNotifier.show(activity, response.body()?.error ?: "Couldn't update status", InAppNotifier.Type.ERROR)
                }
            } catch (e: Exception) {
                InAppNotifier.show(activity, "Network error", InAppNotifier.Type.ERROR)
            }
        }
    }

    private fun startPolling() {
        viewLifecycleOwner.lifecycleScope.launch {
            while (true) {
                delay(POLL_INTERVAL_MS)
                loadAll()
            }
        }
    }
}
