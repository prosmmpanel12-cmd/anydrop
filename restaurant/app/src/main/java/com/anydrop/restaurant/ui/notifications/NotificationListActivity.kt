package com.anydrop.restaurant.ui.notifications

import android.content.Intent
import android.os.Bundle
import android.view.View
import androidx.appcompat.app.AppCompatActivity
import androidx.lifecycle.lifecycleScope
import androidx.recyclerview.widget.LinearLayoutManager
import androidx.recyclerview.widget.RecyclerView
import com.anydrop.restaurant.R
import com.anydrop.restaurant.databinding.ActivityNotificationListBinding
import com.anydrop.restaurant.network.ApiClient
import com.anydrop.restaurant.network.NotificationItem
import com.anydrop.restaurant.ui.common.InAppNotifier
import com.anydrop.restaurant.ui.orderdetail.OrderDetailActivity
import kotlinx.coroutines.launch

/**
 * Notification bell list (Type 1 — system-generated only, docs/Status.md
 * 2026-08-20). Restaurant App mirror of the Customer App's
 * NotificationListActivity — same infinite-scroll + swipe-refresh shape,
 * btnAction repurposed as "mark all read" instead of "add", built against
 * activity_notification_list.xml (this app had no reusable simple-list
 * shell to reuse the way the Customer App's activity_simple_list.xml is).
 *
 * Tapping a row marks it read (server + local adapter patch, no full
 * reload) and deep-links using the notification's own `data` payload —
 * every Type 1 notification on this side currently sets {order_id,
 * screen: "order_status"} (see backend/api/v1/restaurant/orders-{accept,
 * reject,status}.php and orders/create.php, which fires the "new order"
 * notification), routed here to OrderDetailActivity — the Restaurant
 * App's equivalent of the Customer App's OrderStatusActivity. A
 * notification with no recognized screen/order_id just marks read and
 * doesn't navigate, rather than crashing on a missing extra.
 */
class NotificationListActivity : AppCompatActivity() {

    private lateinit var binding: ActivityNotificationListBinding
    private val api by lazy { ApiClient.create(this) }
    private lateinit var adapter: NotificationAdapter

    private var currentPage = 1
    private var hasMore = true
    private var isLoadingPage = false

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityNotificationListBinding.inflate(layoutInflater)
        setContentView(binding.root)

        binding.screenTitle.text = getString(R.string.notifications_title)
        binding.btnBack.setOnClickListener { finish() }

        binding.btnAction.setImageResource(R.drawable.ic_check_circle)
        binding.btnAction.contentDescription = getString(R.string.mark_all_read)
        binding.btnAction.visibility = View.VISIBLE
        binding.btnAction.setOnClickListener { markAllRead() }

        adapter = NotificationAdapter(onClick = { onNotificationClick(it) })
        val layoutManager = LinearLayoutManager(this)
        binding.contentList.layoutManager = layoutManager
        binding.contentList.adapter = adapter
        binding.contentList.addOnScrollListener(object : RecyclerView.OnScrollListener() {
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

        binding.emptyStateText.text = getString(R.string.empty_notifications)
        binding.swipeRefresh.setOnRefreshListener { loadFirstPage() }

        loadFirstPage()
    }

    private fun onNotificationClick(item: NotificationItem) {
        if (!item.isRead) {
            adapter.markRead(item.id)
            lifecycleScope.launch {
                try {
                    api.markNotificationRead(id = item.id)
                } catch (e: Exception) {
                    // Non-fatal — local state already shows it as read; a stale
                    // server-side unread flag will self-correct next list fetch.
                }
            }
        }

        val orderId = (item.data?.get("order_id") as? Double)?.toInt()
        val screen = item.data?.get("screen") as? String
        if (screen == "order_status" && orderId != null && orderId > 0) {
            val intent = Intent(this, OrderDetailActivity::class.java)
            intent.putExtra(OrderDetailActivity.EXTRA_ORDER_ID, orderId)
            startActivity(intent)
        }
    }

    private fun markAllRead() {
        lifecycleScope.launch {
            try {
                api.markAllNotificationsRead()
                loadFirstPage()
            } catch (e: Exception) {
                InAppNotifier.show(this@NotificationListActivity, "Couldn't mark all as read", InAppNotifier.Type.ERROR)
            }
        }
    }

    private fun loadFirstPage() {
        currentPage = 1
        hasMore = true
        binding.swipeRefresh.isRefreshing = true
        lifecycleScope.launch {
            try {
                val result = api.getNotifications(page = currentPage).body()?.data
                val items = result?.items ?: emptyList()
                hasMore = result?.hasMore ?: false
                adapter.submit(items)
                binding.emptyState.visibility = if (items.isEmpty()) View.VISIBLE else View.GONE
                binding.contentList.visibility = if (items.isEmpty()) View.GONE else View.VISIBLE
            } catch (e: Exception) {
                InAppNotifier.show(this@NotificationListActivity, "Couldn't load notifications", InAppNotifier.Type.ERROR)
            } finally {
                binding.swipeRefresh.isRefreshing = false
            }
        }
    }

    private fun loadNextPage() {
        isLoadingPage = true
        lifecycleScope.launch {
            try {
                val nextPage = currentPage + 1
                val result = api.getNotifications(page = nextPage).body()?.data
                val items = result?.items ?: emptyList()
                if (items.isNotEmpty()) {
                    adapter.appendPage(items)
                    currentPage = nextPage
                }
                hasMore = result?.hasMore ?: false
            } catch (e: Exception) {
                // Silent — same "pull-to-refresh to retry" reasoning as the
                // rest of this app's soft-fail pagination calls.
            } finally {
                isLoadingPage = false
            }
        }
    }
}
