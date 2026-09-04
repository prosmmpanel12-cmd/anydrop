package com.anydrop.food.ui.profile

import android.content.Intent
import android.os.Bundle
import androidx.appcompat.app.AppCompatActivity
import androidx.lifecycle.lifecycleScope
import androidx.recyclerview.widget.LinearLayoutManager
import androidx.recyclerview.widget.RecyclerView
import com.anydrop.food.R
import com.anydrop.food.databinding.ActivitySimpleListBinding
import com.anydrop.food.network.ApiClient
import com.anydrop.food.ui.common.InAppNotifier
import com.anydrop.food.ui.orderstatus.OrderStatusActivity
import kotlinx.coroutines.launch

/**
 * Profile → Order History (§2.7). Uses the orders/list.php +
 * getOrderHistory() groundwork laid in the previous session — this is the
 * first thing to actually call them. Infinite-scroll pagination (has_more
 * from the response drives whether another page is fetched).
 */
class OrderHistoryActivity : AppCompatActivity() {

    private lateinit var binding: ActivitySimpleListBinding
    private val api by lazy { ApiClient.create(this) }
    private lateinit var adapter: OrderHistoryAdapter

    private var currentPage = 1
    private var hasMore = true
    private var isLoadingPage = false

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivitySimpleListBinding.inflate(layoutInflater)
        setContentView(binding.root)

        binding.screenTitle.text = getString(R.string.order_history_title)
        binding.btnBack.setOnClickListener { finish() }
        binding.btnAction.visibility = android.view.View.GONE

        adapter = OrderHistoryAdapter(onClick = { order ->
            val intent = Intent(this, OrderStatusActivity::class.java)
            intent.putExtra(OrderStatusActivity.EXTRA_ORDER_ID, order.id)
            startActivity(intent)
        })
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

        binding.emptyStateText.text = getString(R.string.empty_orders)
        binding.swipeRefresh.setOnRefreshListener { loadFirstPage() }

        loadFirstPage()
    }

    private fun loadFirstPage() {
        currentPage = 1
        hasMore = true
        binding.swipeRefresh.isRefreshing = true
        lifecycleScope.launch {
            try {
                val result = api.getOrderHistory(page = currentPage).body()?.data
                val orders = result?.orders ?: emptyList()
                hasMore = result?.hasMore ?: false
                adapter.submit(orders)
                binding.emptyState.visibility = if (orders.isEmpty()) android.view.View.VISIBLE else android.view.View.GONE
                binding.contentList.visibility = if (orders.isEmpty()) android.view.View.GONE else android.view.View.VISIBLE
            } catch (e: Exception) {
                InAppNotifier.show(this@OrderHistoryActivity, "Couldn't load order history", InAppNotifier.Type.ERROR)
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
                val result = api.getOrderHistory(page = nextPage).body()?.data
                val orders = result?.orders ?: emptyList()
                if (orders.isNotEmpty()) {
                    adapter.appendPage(orders)
                    currentPage = nextPage
                }
                hasMore = result?.hasMore ?: false
            } catch (e: Exception) {
                // Silent — infinite scroll failures aren't worth interrupting the user for;
                // they can pull-to-refresh to retry from page 1.
            } finally {
                isLoadingPage = false
            }
        }
    }
}
