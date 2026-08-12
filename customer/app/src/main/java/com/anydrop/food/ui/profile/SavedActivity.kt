package com.anydrop.food.ui.profile

import android.content.Intent
import android.os.Bundle
import androidx.appcompat.app.AppCompatActivity
import androidx.lifecycle.lifecycleScope
import androidx.recyclerview.widget.LinearLayoutManager
import com.google.android.material.tabs.TabLayout
import com.anydrop.food.R
import com.anydrop.food.databinding.ActivitySavedBinding
import com.anydrop.food.network.ApiClient
import com.anydrop.food.network.FavoriteItem
import com.anydrop.food.network.FavoriteRestaurant
import com.anydrop.food.ui.common.InAppNotifier
import com.anydrop.food.ui.restaurant.RestaurantDetailActivity
import kotlinx.coroutines.launch

/**
 * Profile → Saved (§2.7). getFavorites() returns both restaurants and
 * items in a single call — no separate endpoints or pagination needed.
 * Two tabs share one load; switching tabs just toggles which RecyclerView
 * is visible (no ViewPager2/fragments — simpler for a fixed 2-tab screen).
 */
class SavedActivity : AppCompatActivity() {

    private lateinit var binding: ActivitySavedBinding
    private val api by lazy { ApiClient.create(this) }

    private lateinit var restaurantAdapter: SavedRestaurantAdapter
    private lateinit var dishAdapter: SavedDishAdapter

    private var restaurants: List<FavoriteRestaurant> = emptyList()
    private var dishes: List<FavoriteItem> = emptyList()
    private var selectedTab = 0 // 0 = restaurants, 1 = dishes

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivitySavedBinding.inflate(layoutInflater)
        setContentView(binding.root)

        binding.btnBack.setOnClickListener { finish() }

        restaurantAdapter = SavedRestaurantAdapter(
            lifecycleScope = lifecycleScope,
            onClick = { restaurant ->
                val intent = Intent(this, RestaurantDetailActivity::class.java)
                intent.putExtra(RestaurantDetailActivity.EXTRA_RESTAURANT_ID, restaurant.id)
                intent.putExtra(RestaurantDetailActivity.EXTRA_RESTAURANT_NAME, restaurant.name)
                intent.putExtra(RestaurantDetailActivity.EXTRA_RESTAURANT_COVER_URL, restaurant.coverUrl)
                startActivity(intent)
            },
            onRemoved = { removed ->
                restaurants = restaurants.filter { it.id != removed.id }
                updateEmptyStateForCurrentTab()
            }
        )
        dishAdapter = SavedDishAdapter(
            lifecycleScope = lifecycleScope,
            onClick = { item ->
                val intent = Intent(this, RestaurantDetailActivity::class.java)
                intent.putExtra(RestaurantDetailActivity.EXTRA_RESTAURANT_ID, item.restaurantId)
                intent.putExtra(RestaurantDetailActivity.EXTRA_RESTAURANT_NAME, item.restaurantName.orEmpty())
                startActivity(intent)
            },
            onRemoved = { removed ->
                dishes = dishes.filter { it.id != removed.id }
                updateEmptyStateForCurrentTab()
            }
        )

        binding.restaurantsTabList.layoutManager = LinearLayoutManager(this)
        binding.restaurantsTabList.adapter = restaurantAdapter
        binding.dishesTabList.layoutManager = LinearLayoutManager(this)
        binding.dishesTabList.adapter = dishAdapter

        binding.savedTabs.addTab(binding.savedTabs.newTab().setText(getString(R.string.saved_restaurants_tab)))
        binding.savedTabs.addTab(binding.savedTabs.newTab().setText(getString(R.string.saved_dishes_tab)))
        binding.savedTabs.addOnTabSelectedListener(object : TabLayout.OnTabSelectedListener {
            override fun onTabSelected(tab: TabLayout.Tab) {
                selectedTab = tab.position
                applyTabVisibility()
            }
            override fun onTabUnselected(tab: TabLayout.Tab) {}
            override fun onTabReselected(tab: TabLayout.Tab) {}
        })

        loadFavorites()
    }

    private fun loadFavorites() {
        lifecycleScope.launch {
            try {
                val result = api.getFavorites().body()?.data
                restaurants = result?.restaurants ?: emptyList()
                dishes = result?.items ?: emptyList()
                restaurantAdapter.submit(restaurants)
                dishAdapter.submit(dishes)
                applyTabVisibility()
            } catch (e: Exception) {
                InAppNotifier.show(this@SavedActivity, "Couldn't load saved items", InAppNotifier.Type.ERROR)
            }
        }
    }

    private fun applyTabVisibility() {
        binding.restaurantsTabList.visibility = if (selectedTab == 0) android.view.View.VISIBLE else android.view.View.GONE
        binding.dishesTabList.visibility = if (selectedTab == 1) android.view.View.VISIBLE else android.view.View.GONE
        updateEmptyStateForCurrentTab()
    }

    private fun updateEmptyStateForCurrentTab() {
        // Re-submit in case an item was removed from the other tab's list
        // since the adapters were last bound (onRemoved mutates the source
        // lists here, not the adapters directly).
        restaurantAdapter.submit(restaurants)
        dishAdapter.submit(dishes)

        val isEmpty = if (selectedTab == 0) restaurants.isEmpty() else dishes.isEmpty()
        binding.savedEmptyStateText.text = getString(
            if (selectedTab == 0) R.string.empty_saved_restaurants else R.string.empty_saved_dishes
        )
        binding.savedEmptyState.visibility = if (isEmpty) android.view.View.VISIBLE else android.view.View.GONE
        if (selectedTab == 0) {
            binding.restaurantsTabList.visibility = if (isEmpty) android.view.View.GONE else android.view.View.VISIBLE
        } else {
            binding.dishesTabList.visibility = if (isEmpty) android.view.View.GONE else android.view.View.VISIBLE
        }
    }
}
