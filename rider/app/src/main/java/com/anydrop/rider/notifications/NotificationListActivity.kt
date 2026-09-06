package com.anydrop.rider.notifications

import android.content.Intent
import android.os.Bundle
import android.view.View
import androidx.appcompat.app.AppCompatActivity
import androidx.lifecycle.lifecycleScope
import androidx.recyclerview.widget.LinearLayoutManager
import androidx.recyclerview.widget.RecyclerView
import com.anydrop.rider.databinding.ActivityNotificationsBinding
import com.anydrop.rider.network.ApiClient
import com.anydrop.rider.network.NotificationItem
import com.anydrop.rider.ui.common.InAppNotifier
import com.anydrop.rider.ui.dashboard.RiderDashboardActivity
import com.anydrop.rider.ui.documents.SubmitDocumentsActivity
import com.anydrop.rider.ui.earnings.EarningsActivity
import com.anydrop.rider.ui.pending.ApplicationStatusActivity
import kotlinx.coroutines.launch

/**
 * Notification bell list (deep-plan §23, docs 99-102). Reached only by
 * tapping RiderDashboardActivity's header bell. Same
 * submit/appendPage/infinite-scroll/swipe-refresh shape as the customer
 * app's own `NotificationListActivity` (confirmed template), but built
 * on `activity_notifications.xml`'s own IDs (doc 101/102's established
 * list-screen layout, not the customer app's `activity_simple_list.xml`
 * which doesn't exist in this app) and this app's `isSuccessful &&
 * success == true` response-checking convention (matching
 * `EarningsActivity`, rather than the customer app's more lenient
 * `.body()?.data` shortcut).
 *
 * Deep-link routing on tap uses the same `screen` values
 * `RiderNotificationHelper.contentIntentFor()` handles for the
 * system-tray notification (doc 105 extended that `when` to cover
 * `order_offer`/`order_status`/`earnings` alongside the original
 * `dashboard`/`submit_documents`; kept in sync here since this list
 * screen is the other place a notification's `screen` value gets
 * turned into navigation) — anything else (including missing/null)
 * still falls back to `ApplicationStatusActivity`, this app's own
 * "figure out where an account actually stands" router. Kept as its
 * own small `when` here rather than exposing `RiderNotificationHelper`'s
 * private `contentIntentFor()`, since this screen navigates directly
 * with `startActivity` rather than building a `PendingIntent`.
 */
class NotificationListActivity : AppCompatActivity() {

    private lateinit var binding: ActivityNotificationsBinding
    private val api by lazy { ApiClient.create(this) }
    private lateinit var adapter: NotificationAdapter

    private var currentPage = 1
    private var hasMore = true
    private var isLoadingPage = false

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityNotificationsBinding.inflate(layoutInflater)
        setContentView(binding.root)

        binding.btnBack.setOnClickListener { finish() }
        binding.btnMarkAllRead.setOnClickListener { markAllRead() }

        adapter = NotificationAdapter(onClick = { onNotificationClick(it) })
        val layoutManager = LinearLayoutManager(this)
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

    private fun onNotificationClick(item: NotificationItem) {
        if (!item.isRead) {
            adapter.markRead(item.id)
            lifecycleScope.launch {
                try {
                    api.markNotificationRead(id = item.id)
                } catch (e: Exception) {
                    // Non-fatal — local state already shows it as read; a
                    // stale server-side unread flag self-corrects next
                    // list fetch.
                }
            }
        }

        val screen = item.data?.get("screen") as? String
        val targetClass = when (screen) {
            "dashboard" -> RiderDashboardActivity::class.java
            "submit_documents" -> SubmitDocumentsActivity::class.java
            "order_offer", "order_status" -> RiderDashboardActivity::class.java
            "earnings" -> EarningsActivity::class.java
            else -> ApplicationStatusActivity::class.java
        }
        startActivity(Intent(this, targetClass))
    }

    private fun markAllRead() {
        lifecycleScope.launch {
            try {
                val response = api.markAllNotificationsRead()
                if (response.isSuccessful && response.body()?.success == true) {
                    adapter.markAllRead()
                } else {
                    InAppNotifier.show(this@NotificationListActivity, "Couldn't mark all as read", InAppNotifier.Type.ERROR)
                }
            } catch (e: Exception) {
                InAppNotifier.show(this@NotificationListActivity, "Couldn't mark all as read", InAppNotifier.Type.ERROR)
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
                    binding.notificationsEmptyState.visibility = if (items.isEmpty()) View.VISIBLE else View.GONE
                    binding.notificationsList.visibility = if (items.isEmpty()) View.GONE else View.VISIBLE

                    // Auto-mark-as-read: opening the bell is itself the
                    // "seen" signal — same behavior the customer app's
                    // own bell has.
                    if ((result?.unreadCount ?: 0) > 0) {
                        adapter.markAllRead()
                        lifecycleScope.launch {
                            try {
                                api.markAllNotificationsRead()
                            } catch (e: Exception) {
                                // Non-fatal — local state already shows
                                // read; a stale server-side unread flag
                                // self-corrects next list fetch.
                            }
                        }
                    }
                } else {
                    InAppNotifier.show(this@NotificationListActivity, "Couldn't load notifications", InAppNotifier.Type.ERROR)
                }
            } catch (e: Exception) {
                InAppNotifier.show(this@NotificationListActivity, "Couldn't load notifications", InAppNotifier.Type.ERROR)
            } finally {
                binding.notificationsSwipeRefresh.isRefreshing = false
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
