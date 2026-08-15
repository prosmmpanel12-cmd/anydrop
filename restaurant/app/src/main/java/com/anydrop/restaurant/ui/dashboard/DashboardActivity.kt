package com.anydrop.restaurant.ui.dashboard

import android.content.Intent
import android.os.Bundle
import android.view.View
import androidx.appcompat.app.AppCompatActivity
import androidx.core.content.ContextCompat
import androidx.lifecycle.lifecycleScope
import androidx.recyclerview.widget.LinearLayoutManager
import com.anydrop.restaurant.R
import com.anydrop.restaurant.data.TokenManager
import com.anydrop.restaurant.databinding.ActivityDashboardBinding
import com.anydrop.restaurant.network.ApiClient
import com.anydrop.restaurant.network.OperationalStatusUpdateBody
import com.anydrop.restaurant.ui.common.InAppNotifier
import com.anydrop.restaurant.ui.login.LoginActivity
import com.anydrop.restaurant.ui.orderdetail.OrderDetailActivity
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

/**
 * Phase 3 — Restaurant dashboard: three tabs backed by GET /restaurant/orders?status=...
 * New: pending. Active: accepted/preparing/ready/rider_assigned/picked_up/out_for_delivery.
 * History: delivered/cancelled/rejected. Polls every 10s so new orders show up
 * without the staff having to pull-to-refresh constantly.
 */
class DashboardActivity : AppCompatActivity() {

    private lateinit var binding: ActivityDashboardBinding
    private val api by lazy { ApiClient.create(this) }
    private lateinit var tokenManager: TokenManager
    private lateinit var adapter: OrderAdapter

    private var currentTab = Tab.NEW
    private var polling = true
    // Part B — guards the switch's listener while we're setting its state
    // programmatically (initial load from dashboard, or reverting after a
    // failed toggle), so that doesn't itself fire another API call.
    private var suppressToggleListener = false

    private enum class Tab(val statusFilter: String) {
        NEW("pending"),
        ACTIVE("accepted,preparing,ready,rider_assigned,picked_up,out_for_delivery"),
        HISTORY("delivered,cancelled,rejected")
    }

    companion object {
        private const val POLL_INTERVAL_MS = 10000L
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityDashboardBinding.inflate(layoutInflater)
        setContentView(binding.root)

        tokenManager = TokenManager(this)
        if (!tokenManager.isLoggedIn()) {
            goToLogin()
            return
        }

        adapter = OrderAdapter(this) { order ->
            val intent = Intent(this, OrderDetailActivity::class.java)
            intent.putExtra(OrderDetailActivity.EXTRA_ORDER_ID, order.id)
            startActivity(intent)
        }
        binding.ordersRecycler.layoutManager = LinearLayoutManager(this)
        binding.ordersRecycler.adapter = adapter

        binding.swipeRefresh.setOnRefreshListener { loadOrders() }
        binding.tabNew.setOnClickListener { selectTab(Tab.NEW) }
        binding.tabActive.setOnClickListener { selectTab(Tab.ACTIVE) }
        binding.tabHistory.setOnClickListener { selectTab(Tab.HISTORY) }
        binding.btnLogout.setOnClickListener { logout() }
        binding.btnMenuManagement.setOnClickListener {
            startActivity(Intent(this, com.anydrop.restaurant.ui.menu.MenuManagementActivity::class.java))
        }
        binding.switchAcceptingOrders.setOnCheckedChangeListener { _, isChecked ->
            if (!suppressToggleListener) toggleAcceptingOrders(isChecked)
        }

        selectTab(Tab.NEW)
        loadDashboardSummary()
        startPolling()
    }

    override fun onResume() {
        super.onResume()
        polling = true
        loadOrders()
        loadDashboardSummary()
    }

    override fun onPause() {
        super.onPause()
        polling = false
    }

    private fun selectTab(tab: Tab) {
        currentTab = tab
        val active = ContextCompat.getColor(this, R.color.text_primary)
        val inactive = ContextCompat.getColor(this, R.color.text_secondary)

        binding.tabNew.background = if (tab == Tab.NEW) getDrawable(R.drawable.bg_chip_selected) else null
        binding.tabActive.background = if (tab == Tab.ACTIVE) getDrawable(R.drawable.bg_chip_selected) else null
        binding.tabHistory.background = if (tab == Tab.HISTORY) getDrawable(R.drawable.bg_chip_selected) else null

        binding.tabNew.setTextColor(if (tab == Tab.NEW) active else inactive)
        binding.tabActive.setTextColor(if (tab == Tab.ACTIVE) active else inactive)
        binding.tabHistory.setTextColor(if (tab == Tab.HISTORY) active else inactive)

        loadOrders()
    }

    private fun loadOrders() {
        lifecycleScope.launch {
            try {
                val response = api.getOrders(status = currentTab.statusFilter)
                val orders = response.body()?.data?.data ?: emptyList()
                adapter.submitList(orders)
                binding.emptyText.visibility = if (orders.isEmpty()) View.VISIBLE else View.GONE
            } catch (e: Exception) {
                InAppNotifier.show(this@DashboardActivity, "Couldn't load orders", InAppNotifier.Type.ERROR)
            } finally {
                binding.swipeRefresh.isRefreshing = false
            }
        }
    }

    private fun loadDashboardSummary() {
        lifecycleScope.launch {
            try {
                val summary = api.getDashboard().body()?.data
                if (summary != null) {
                    binding.summaryText.text = getString(
                        R.string.today_summary,
                        summary.today.ordersCount,
                        "%.0f".format(summary.today.earnings)
                    )
                    // Part B — initialize the switch from the restaurant's
                    // actual current state; suppressed so this doesn't
                    // re-trigger a status-update call.
                    suppressToggleListener = true
                    binding.switchAcceptingOrders.isChecked = summary.operationalStatus == "open"
                    suppressToggleListener = false
                }
            } catch (e: Exception) {
                // Non-critical — leave summary blank if it fails, orders list still works.
            }
        }
    }

    /** Part B (docs/16) — "open" while accepting orders, "busy" while
     * paused, per the handover doc's recommended plain ON/OFF scope (no
     * reason/ETA message yet — flagged there as an open question, not
     * decided). On failure, reverts the switch to its pre-toggle state
     * rather than leaving the UI showing something the backend didn't
     * actually apply. */
    private fun toggleAcceptingOrders(turningOn: Boolean) {
        binding.switchAcceptingOrders.isEnabled = false
        val newStatus = if (turningOn) "open" else "busy"
        lifecycleScope.launch {
            try {
                val response = api.updateOperationalStatus(OperationalStatusUpdateBody(newStatus))
                if (!response.isSuccessful || response.body()?.data == null) {
                    revertToggle(turningOn)
                    InAppNotifier.show(this@DashboardActivity, getString(R.string.status_update_failed), InAppNotifier.Type.ERROR)
                }
            } catch (e: Exception) {
                revertToggle(turningOn)
                InAppNotifier.show(this@DashboardActivity, getString(R.string.status_update_failed), InAppNotifier.Type.ERROR)
            } finally {
                binding.switchAcceptingOrders.isEnabled = true
            }
        }
    }

    private fun revertToggle(failedTurningOn: Boolean) {
        suppressToggleListener = true
        binding.switchAcceptingOrders.isChecked = !failedTurningOn
        suppressToggleListener = false
    }

    private fun startPolling() {
        lifecycleScope.launch {
            while (true) {
                delay(POLL_INTERVAL_MS)
                if (polling) {
                    loadOrders()
                    loadDashboardSummary()
                }
            }
        }
    }

    private fun logout() {
        tokenManager.clear()
        goToLogin()
    }

    private fun goToLogin() {
        startActivity(
            Intent(this, LoginActivity::class.java)
                .addFlags(Intent.FLAG_ACTIVITY_CLEAR_TOP or Intent.FLAG_ACTIVITY_NEW_TASK)
        )
        finish()
    }
}
