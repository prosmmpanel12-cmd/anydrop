package com.anydrop.rider.notifications

import android.content.Intent
import android.os.Bundle
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import androidx.fragment.app.Fragment
import androidx.lifecycle.lifecycleScope
import androidx.recyclerview.widget.LinearLayoutManager
import androidx.recyclerview.widget.RecyclerView
import com.anydrop.rider.databinding.FragmentNotificationsBinding
import com.anydrop.rider.network.ApiClient
import com.anydrop.rider.network.NotificationItem
import com.anydrop.rider.ui.common.InAppNotifier
import com.anydrop.rider.ui.documents.SubmitDocumentsActivity
import com.anydrop.rider.ui.main.RiderMainActivity
import com.anydrop.rider.ui.pending.ApplicationStatusActivity
import kotlinx.coroutines.launch

/**
 * Bottom-nav shell (v24 Part 2) — Alerts tab. Ported from
 * NotificationListActivity (see that class's own kdoc for the deep-link
 * `screen` value table — unchanged here). Logic unchanged; no more
 * btnBack, `binding` is the nullable Fragment pair.
 *
 * Deep-link routing on tap: "dashboard"/"order_offer"/"order_status"
 * now switch to the Home tab (via RiderMainActivity.goToHomeTab())
 * instead of starting a new RiderDashboardActivity instance, which no
 * longer exists as a launchable screen — see RiderMainActivity's kdoc.
 * "earnings" switches to the Earnings tab the same way. Everything else
 * (submit_documents, unmapped/null) is unchanged — those are still
 * separate Activities, not tabs.
 */
class NotificationsFragment : Fragment() {

    private var _binding: FragmentNotificationsBinding? = null
    private val binding get() = _binding!!

    private val api by lazy { ApiClient.create(requireContext()) }
    private lateinit var adapter: NotificationAdapter

    private var currentPage = 1
    private var hasMore = true
    private var isLoadingPage = false

    override fun onCreateView(
        inflater: LayoutInflater,
        container: ViewGroup?,
        savedInstanceState: Bundle?
    ): View {
        _binding = FragmentNotificationsBinding.inflate(inflater, container, false)
        return binding.root
    }

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        super.onViewCreated(view, savedInstanceState)

        binding.btnMarkAllRead.setOnClickListener { markAllRead() }

        adapter = NotificationAdapter(onClick = { onNotificationClick(it) })
        val layoutManager = LinearLayoutManager(requireContext())
        binding.notificationsList.layoutManager = layoutManager
        binding.notificationsList.adapter = adapter
        binding.notificationsList.addOnScrollListener(object : RecyclerView.OnScrollListener() {
            override fun onScrolled(recyclerView: RecyclerView, dx: Int, dy: Int) {
                super.onScrolled(recyclerView, dx, dy)
                if (dy <= 0 || isLoadingPage || !hasMore) return
                val visibleItemCount = layoutManager.childCount
                val totalItemCount = layoutManager.itemCount
                val firstVisible = layoutManager.findFirstVisibleItemPosition()
                if (visibleItemCount + firstVisible >= totalItemCount - 3) {
                    loadNextPage()
                }
            }
        })

        binding.notificationsSwipeRefresh.setOnRefreshListener { loadFirstPage() }

        loadFirstPage()
    }

    override fun onDestroyView() {
        super.onDestroyView()
        _binding = null
    }

    private fun onNotificationClick(item: NotificationItem) {
        if (!item.isRead) {
            adapter.markRead(item.id)
            lifecycleScope.launch {
                try {
                    api.markNotificationRead(id = item.id)
                } catch (e: Exception) {
                    // Non-fatal — local state already shows it as read.
                }
            }
        }

        val screen = item.data?.get("screen") as? String
        val mainActivity = activity as? RiderMainActivity
        when (screen) {
            "dashboard", "order_offer", "order_status" -> mainActivity?.goToHomeTab()
            "earnings" -> mainActivity?.goToEarningsTab()
            "submit_documents" -> startActivity(Intent(requireContext(), SubmitDocumentsActivity::class.java))
            else -> startActivity(Intent(requireContext(), ApplicationStatusActivity::class.java))
        }
    }

    private fun markAllRead() {
        lifecycleScope.launch {
            try {
                val response = api.markAllNotificationsRead()
                if (response.isSuccessful && response.body()?.success == true) {
                    adapter.markAllRead()
                } else {
                    InAppNotifier.show(activity, "Couldn't mark all as read", InAppNotifier.Type.ERROR)
                }
            } catch (e: Exception) {
                InAppNotifier.show(activity, "Couldn't mark all as read", InAppNotifier.Type.ERROR)
            }
        }
    }

    private fun loadFirstPage() {
        currentPage = 1
        hasMore = true
        binding.notificationsSwipeRefresh.isRefreshing = true
        lifecycleScope.launch {
            try {
                val response = api.getNotifications(page = currentPage)
                if (response.isSuccessful && response.body()?.success == true) {
                    val result = response.body()?.data
                    val items = result?.items ?: emptyList()
                    hasMore = result?.hasMore ?: false
                    adapter.submit(items)
                    _binding?.notificationsEmptyState?.visibility = if (items.isEmpty()) View.VISIBLE else View.GONE
                    _binding?.notificationsList?.visibility = if (items.isEmpty()) View.GONE else View.VISIBLE

                    if ((result?.unreadCount ?: 0) > 0) {
                        adapter.markAllRead()
                        lifecycleScope.launch {
                            try {
                                api.markAllNotificationsRead()
                            } catch (e: Exception) {
                                // Non-fatal — local state already shows read.
                            }
                        }
                    }
                } else {
                    InAppNotifier.show(activity, "Couldn't load notifications", InAppNotifier.Type.ERROR)
                }
            } catch (e: Exception) {
                InAppNotifier.show(activity, "Couldn't load notifications", InAppNotifier.Type.ERROR)
            } finally {
                _binding?.notificationsSwipeRefresh?.isRefreshing = false
            }
        }
    }

    private fun loadNextPage() {
        isLoadingPage = true
        lifecycleScope.launch {
            try {
                val nextPage = currentPage + 1
                val response = api.getNotifications(page = nextPage)
                if (response.isSuccessful && response.body()?.success == true) {
                    val result = response.body()?.data
                    val items = result?.items ?: emptyList()
                    if (items.isNotEmpty()) {
                        adapter.appendPage(items)
                        currentPage = nextPage
                    }
                    hasMore = result?.hasMore ?: false
                }
            } catch (e: Exception) {
                // Silent — same "pull-to-refresh to retry" reasoning as
                // this app's other paginated screens.
            } finally {
                isLoadingPage = false
            }
        }
    }
}
