package com.anydrop.food.ui.offers

import android.content.Intent
import android.os.Bundle
import android.view.View
import androidx.appcompat.app.AppCompatActivity
import androidx.lifecycle.lifecycleScope
import androidx.recyclerview.widget.LinearLayoutManager
import com.anydrop.food.data.ActiveAddressManager
import com.anydrop.food.databinding.ActivityOfferScreenBinding
import com.anydrop.food.network.ApiClient
import com.anydrop.food.network.OfferBrowseItem
import com.anydrop.food.network.OfferBrowseRestaurant
import com.anydrop.food.network.OffersBrowseResult
import com.anydrop.food.network.toMenuItem
import com.anydrop.food.ui.itemdetail.ItemDetailBottomSheetFragment
import com.anydrop.food.ui.restaurant.RestaurantDetailActivity
import kotlinx.coroutines.launch

/**
 * "Offers" category chip destination (docs/33/34/35) — every restaurant
 * with a currently browsable offer, grouped under its own header, backed
 * by GET /home/offers-browse.php. Reached from HomeActivity's synthetic
 * "Offers" category chip (see HomeActivity.onCategoryTapped()'s
 * "__offers__" early branch) — this screen is never itself the "active"
 * category filter.
 */
class OfferScreenActivity : AppCompatActivity() {

    private lateinit var binding: ActivityOfferScreenBinding
    private val api by lazy { ApiClient.create(this) }
    private lateinit var adapter: OfferBrowseAdapter

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityOfferScreenBinding.inflate(layoutInflater)
        setContentView(binding.root)

        binding.btnBack.setOnClickListener { finish() }

        adapter = OfferBrowseAdapter(
            onRestaurantClick = { restaurant -> openRestaurant(restaurant) },
            onDishClick = { restaurant, item -> openItemDetailSheet(restaurant, item) },
            onCartChanged = { /* no cart badge on this screen — see activity_offer_screen.xml */ }
        )
        binding.offersList.layoutManager = LinearLayoutManager(this)
        binding.offersList.adapter = adapter

        binding.swipeRefresh.setOnRefreshListener { loadOffers() }

        loadOffers()
    }

    private fun openRestaurant(restaurant: OfferBrowseRestaurant) {
        startActivity(
            Intent(this, RestaurantDetailActivity::class.java)
                .putExtra(RestaurantDetailActivity.EXTRA_RESTAURANT_ID, restaurant.id)
                .putExtra(RestaurantDetailActivity.EXTRA_RESTAURANT_NAME, restaurant.name)
        )
    }

    private fun openItemDetailSheet(restaurant: OfferBrowseRestaurant, item: OfferBrowseItem) {
        val sheet = ItemDetailBottomSheetFragment.newInstance(
            restaurant.id,
            restaurant.name,
            item.toMenuItem()
        )
        sheet.onAdded = {
            adapter.refreshCartUi(item.id)
        }
        sheet.show(supportFragmentManager, "item_detail")
    }

    private fun loadOffers() {
        lifecycleScope.launch {
            try {
                val active = ActiveAddressManager.get(this@OfferScreenActivity)
                val result: OffersBrowseResult? =
                    api.getOffersBrowse(lat = active?.latitude, lng = active?.longitude).body()?.data
                adapter.submit(result ?: OffersBrowseResult())
                binding.offersEmptyState.visibility = if (adapter.isEmpty()) View.VISIBLE else View.GONE
            } catch (e: Exception) {
                // Non-fatal — same soft-fail pattern as HomeActivity's own
                // network calls (loadPromoBanners, etc.); the empty state
                // covers both "no offers" and "request failed" here since
                // there's no separate error state on this screen.
                binding.offersEmptyState.visibility = if (adapter.isEmpty()) View.VISIBLE else View.GONE
            } finally {
                binding.swipeRefresh.isRefreshing = false
            }
        }
    }
}
