package com.anydrop.food.ui.home

import android.Manifest
import android.content.ActivityNotFoundException
import android.content.Intent
import android.content.pm.PackageManager
import android.location.Location
import android.location.LocationManager
import android.os.Bundle
import android.os.Handler
import android.os.Looper
import android.speech.RecognizerIntent
import android.text.Editable
import android.text.TextWatcher
import androidx.activity.result.contract.ActivityResultContracts
import androidx.appcompat.app.AppCompatActivity
import androidx.core.content.ContextCompat
import androidx.lifecycle.lifecycleScope
import androidx.recyclerview.widget.LinearLayoutManager
import coil.load
import com.anydrop.food.R
import com.anydrop.food.data.ActiveAddressManager
import com.anydrop.food.data.AppConfigCache
import com.anydrop.food.data.CartManager
import com.anydrop.food.data.CartSyncManager
import com.anydrop.food.data.VegModeManager
import com.anydrop.food.databinding.ActivityHomeBinding
import com.anydrop.food.network.ApiClient
import com.anydrop.food.ui.address.AddressEditorBottomSheet
import com.anydrop.food.ui.common.InAppNotifier
import com.anydrop.food.network.FoodCategory
import com.anydrop.food.network.PopularItem
import com.anydrop.food.network.PromoBanner
import com.anydrop.food.network.Restaurant
import com.anydrop.food.network.SearchItem
import com.anydrop.food.notifications.DailyEngagementScheduler
import com.anydrop.food.notifications.NotificationHelper
import com.anydrop.food.ui.cart.CartBottomSheetFragment
import com.anydrop.food.ui.common.NotificationPermissionDialog
import com.anydrop.food.ui.login.LoginActivity
import com.anydrop.food.ui.itemdetail.ItemDetailBottomSheetFragment
import com.anydrop.food.ui.restaurant.RestaurantDetailActivity
import com.anydrop.food.ui.search.SearchFilters
import com.anydrop.food.ui.search.SearchFiltersBottomSheet
import com.anydrop.food.ui.search.SearchResultsAdapter
import com.anydrop.food.network.toMenuItem
import kotlinx.coroutines.Job
import kotlinx.coroutines.launch

/**
 * §1.6 — Home implements AddressEditorBottomSheet.LocationRequester so the
 * top-bar "Delivering to your location" bar has a working stop-gap: tap it
 * → opens the same structured address editor Checkout uses, pre-triggering
 * "Use current location" so the GPS fix + Geocoder line are already filled
 * in. Reuses Checkout's exact GPS/Geocoder pattern (last-known-location
 * first, single fresh update as fallback) — no new logic invented here.
 * Full map-based picker is still Phase 4 scope; this just replaces "does
 * nothing" with something useful in the meantime.
 */
class HomeActivity : AppCompatActivity(), AddressEditorBottomSheet.LocationRequester {

    // Home's Popular row / search results can also mutate the cart directly
    // — same "don't lose an unsynced change to a backgrounded app" reasoning
    // as RestaurantDetailActivity.onStop().
    override fun onStop() {
        super.onStop()
        CartSyncManager.syncNow(this)
    }

    private lateinit var binding: ActivityHomeBinding
    private val api by lazy { ApiClient.create(this) }

    private lateinit var restaurantAdapter: RestaurantAdapter
    private lateinit var searchAdapter: SearchResultsAdapter
    private lateinit var categoryAdapter: FoodCategoryAdapter
    private lateinit var promoBannerAdapter: PromoBannerAdapter
    private lateinit var popularItemsAdapter: PopularItemsAdapter

    // Promo carousel auto-advance (§2.2, ~4s per slide). Cancelled in
    // onDestroy — this Handler is not lifecycle-aware on its own.
    private val promoAutoAdvanceHandler = Handler(Looper.getMainLooper())
    private var promoAutoAdvanceRunnable: Runnable? = null

    /** null = browsing all restaurants; a slug = "Pizza"/"Rolls" chip is active. */
    private var activeCategorySlug: String? = null
    /** null = no tag filter; one of near_fast / pure_veg / under_200 / open_now / rating_4. */
    private var activeFilter: String? = null

    private val searchHandler = Handler(Looper.getMainLooper())
    private var searchRunnable: Runnable? = null

    // I3 (docs/features.md Phase I) — search filters state. Raw = the
    // latest search response after vegOnly (kept separate so toggling
    // filters re-applies against the full result set instead of an
    // already-filtered one). Sticky across searches on purpose — a chosen
    // "Under ₹200" filter carries over if the user tweaks their search
    // text, same as VegModeManager's site-wide toggle; only "Clear All"
    // inside the sheet resets it.
    private var searchFilters = SearchFilters()
    private var rawSearchRestaurants: List<Restaurant> = emptyList()
    private var rawSearchItems: List<SearchItem> = emptyList()
    private var currentQuery: String = ""

    /**
     * The single in-flight coroutine that's currently populating
     * `restaurantList` (whichever of loadRestaurants / loadCategoryItems /
     * runSearchOrReload triggered it). Every one of those functions cancels
     * this job before launching its own — without this, a slow "plain
     * restaurants" request that was already in flight when a filter chip
     * was tapped could finish AFTER the filtered request and overwrite the
     * filtered result with the unfiltered one, which is exactly the "filter
     * flashes then reverts" bug. Only the most recently launched request is
     * ever allowed to update the UI now.
     */
    private var contentLoadJob: Job? = null

    // §1.6 — set while a location fix is being resolved on behalf of the
    // location-bar-triggered AddressEditorBottomSheet; same pattern as
    // CheckoutActivity's pendingSheetForLocation.
    private var pendingSheetForLocation: AddressEditorBottomSheet? = null

    private val locationPermissionLauncher =
        registerForActivityResult(ActivityResultContracts.RequestPermission()) { granted ->
            if (granted) fetchCurrentLocation() else {
                InAppNotifier.show(this, "Location permission denied", InAppNotifier.Type.INFO)
                pendingSheetForLocation = null
            }
        }

    // Voice search entry point (§1.3 / §2.8) — stubbed to Android's built-in
    // RecognizerIntent so no backend/ML work is needed. Result text is dropped
    // straight into searchInput, which already drives search via its own
    // TextWatcher, so no separate search-trigger call is needed here.
    private val voiceSearchLauncher = registerForActivityResult(
        ActivityResultContracts.StartActivityForResult()
    ) { result ->
        val text = result.data
            ?.getStringArrayListExtra(RecognizerIntent.EXTRA_RESULTS)
            ?.firstOrNull()
        if (!text.isNullOrBlank()) {
            binding.searchInput.setText(text)
            binding.searchInput.setSelection(text.length)
        }
    }

    // H6 — location bar now opens LocationPickerActivity (screenshot 12)
    // instead of jumping straight to AddressEditorBottomSheet. Re-resolves
    // the active address on any result (not just RESULT_OK) since the
    // picker's "Add Address" flow inside it can also change the active
    // address via its own AddressEditorBottomSheet.onSaved.
    private val locationPickerLauncher = registerForActivityResult(
        ActivityResultContracts.StartActivityForResult()
    ) { resolveActiveAddressThenLoad(forceRefresh = true) }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityHomeBinding.inflate(layoutInflater)
        setContentView(binding.root)

        // Cart-persistence restore (closes the "cart empties on app
        // restart" gap) — HomeActivity.onCreate only runs once per fresh
        // process (standard launch mode, only reachable post-login via
        // SplashActivity), so this is exactly "once per app start, once
        // logged in." Async: the synchronous updateCartBadge() call later
        // in this method covers the instant-open case (badge shows 0 for a
        // moment), and the onComplete callback here refreshes it again once
        // the server snapshot lands — same pattern the rest of Home uses
        // for its network-backed sections (categories/restaurants/etc.).
        CartSyncManager.restoreFromServer(this) { updateCartBadge() }

        restaurantAdapter = RestaurantAdapter(
            lifecycleScope = lifecycleScope,
            onClick = { restaurant -> openRestaurant(restaurant) },
            isCarouselVisible = { cardRoot -> isViewWithinScrollBounds(cardRoot) }
        )
        searchAdapter = SearchResultsAdapter(
            lifecycleScope = lifecycleScope,
            onRestaurantClick = { restaurant -> openRestaurant(restaurant) },
            onDishClick = { item ->
                openItemDetailSheet(
                    item.restaurantId, item.restaurantName, item.toMenuItem(),
                    currentSaved = searchAdapter.currentSavedState(item.id),
                    onSaveStateChanged = { newState -> searchAdapter.setSavedState(item.id, newState) },
                    onCartLineChanged = { searchAdapter.refreshCartUi(item.id) }
                )
            },
            onCartChanged = { updateCartBadge() }
        )
        binding.restaurantList.layoutManager = LinearLayoutManager(this)
        binding.restaurantList.adapter = restaurantAdapter

        categoryAdapter = FoodCategoryAdapter { category -> onCategoryTapped(category) }
        binding.categoryList.layoutManager =
            LinearLayoutManager(this, LinearLayoutManager.HORIZONTAL, false)
        binding.categoryList.adapter = categoryAdapter

        promoBannerAdapter = PromoBannerAdapter { banner -> onPromoBannerTapped(banner) }
        binding.promoCarousel.adapter = promoBannerAdapter

        popularItemsAdapter = PopularItemsAdapter(
            lifecycleScope = lifecycleScope,
            onDishClick = { item ->
                openItemDetailSheet(
                    item.restaurantId, item.restaurantName, item.toMenuItem(),
                    currentSaved = popularItemsAdapter.currentSavedState(item.id),
                    onSaveStateChanged = { newState -> popularItemsAdapter.setSavedState(item.id, newState) },
                    onCartLineChanged = { popularItemsAdapter.refreshCartUi(item.id) }
                )
            },
            onCartChanged = { updateCartBadge() }
        )
        binding.popularItemsList.layoutManager =
            LinearLayoutManager(this, LinearLayoutManager.HORIZONTAL, false)
        binding.popularItemsList.adapter = popularItemsAdapter

        setupExploreTiles()
        setupCollapsingHeader()

        binding.swipeRefresh.setOnRefreshListener { reloadCurrentView() }

        // §2.3 — retry button on the "not available in your area yet" state.
        // Re-runs the same plain load; if the account's location has since
        // moved into a served area (or a restaurant just launched nearby),
        // this naturally recovers into the normal Home content.
        binding.btnServiceAreaRetry.setOnClickListener { loadRestaurants() }

        binding.btnCart.setOnClickListener {
            // Bug fix (2026-08-10) — badge used to go stale after removing/
            // clearing items inside the sheet until Home's onResume() next
            // fired (a shown BottomSheetDialogFragment doesn't pause/resume
            // the host Activity). See CartBottomSheetFragment.onCartChanged kdoc.
            CartBottomSheetFragment().apply {
                onCartChanged = { updateCartBadge() }
            }.show(supportFragmentManager, "cart")
        }

        binding.btnProfile.setOnClickListener {
            startActivity(Intent(this, com.anydrop.food.ui.profile.ProfileActivity::class.java))
        }

        // §1.6 stop-gap superseded by H6 — location bar now opens the
        // Location Picker screen (saved addresses list + tap-to-activate +
        // current-location + add) instead of jumping straight to the
        // add/edit form. resolveActiveAddressThenLoad re-runs on return from
        // it via locationPickerLauncher above, which picks up whatever the
        // picker screen changed (activated a saved address, or added+saved
        // a new one through its own editor sheet).
        binding.deliveryLocationText.setOnClickListener {
            locationPickerLauncher.launch(
                Intent(this, com.anydrop.food.ui.address.LocationPickerActivity::class.java)
            )
        }

        setupSearch()
        setupVoiceSearch()
        setupSearchFilters()
        setupVegToggle()
        setupFilterChips()
        loadCategories()
        applyActiveAddressUi(ActiveAddressManager.get(this))
        resolveActiveAddressThenLoad(forceRefresh = false)
        loadPopularItems()
        updateCartBadge()
        loadPromoBanners()

        // Custom animated bell popup first (screenshot reference), which
        // itself triggers the real POST_NOTIFICATIONS request on "Yes".
        // Shown once per app open, on Home.
        NotificationHelper.ensureChannels(this)
        NotificationPermissionDialog.showOnce(this)
        // Phase J — supersedes the old MealReminderScheduler (2 fixed
        // lunch/dinner slots, 2 hardcoded copy strings) with 5 daily slots
        // drawing from the 40-50 template pool, deduped via
        // EngagementNotificationHistory. See DailyEngagementScheduler's kdoc.
        DailyEngagementScheduler.scheduleDailyEngagement(this)
    }

    override fun onResume() {
        super.onResume()
        updateCartBadge()
        // Bug fix (2026-08-10, H2) — a restaurant bookmarked on another
        // screen (e.g. RestaurantDetailActivity reached from a cart card)
        // used to only show as saved here after Home's data fully reloaded.
        // Local-only, no network — see FavoritesManager.isSaved kdoc.
        restaurantAdapter.refreshSavedStates()
        searchAdapter.refreshSavedStates()
    }

    override fun onDestroy() {
        super.onDestroy()
        promoAutoAdvanceRunnable?.let { promoAutoAdvanceHandler.removeCallbacks(it) }
    }

    // ---- AddressEditorBottomSheet.LocationRequester (§1.6) ----
    // Identical pattern to CheckoutActivity: last-known-location first,
    // single fresh update as fallback, Geocoder for a readable address line.

    override fun requestLocationForAddressEditor(sheet: AddressEditorBottomSheet) {
        pendingSheetForLocation = sheet
        val fineGranted = ContextCompat.checkSelfPermission(
            this, Manifest.permission.ACCESS_FINE_LOCATION
        ) == PackageManager.PERMISSION_GRANTED
        if (fineGranted) {
            fetchCurrentLocation()
        } else {
            locationPermissionLauncher.launch(Manifest.permission.ACCESS_FINE_LOCATION)
        }
    }

    private fun fetchCurrentLocation() {
        val locationManager = getSystemService(LOCATION_SERVICE) as LocationManager
        val hasGps = locationManager.isProviderEnabled(LocationManager.GPS_PROVIDER)
        val hasNetwork = locationManager.isProviderEnabled(LocationManager.NETWORK_PROVIDER)
        if (!hasGps && !hasNetwork) {
            InAppNotifier.show(this, "Turn on location services to use this", InAppNotifier.Type.INFO)
            pendingSheetForLocation = null
            return
        }

        val provider = if (hasGps) LocationManager.GPS_PROVIDER else LocationManager.NETWORK_PROVIDER
        try {
            val lastKnown = locationManager.getLastKnownLocation(provider)
            if (lastKnown != null) {
                onLocationResolved(lastKnown)
            } else {
                locationManager.requestSingleUpdate(provider, { location -> onLocationResolved(location) }, Looper.getMainLooper())
            }
        } catch (e: SecurityException) {
            InAppNotifier.show(this, "Location permission needed", InAppNotifier.Type.INFO)
            pendingSheetForLocation = null
        }
    }

    private fun onLocationResolved(location: Location) {
        val sheet = pendingSheetForLocation
        pendingSheetForLocation = null
        var addressLine: String? = null
        try {
            val geocoder = android.location.Geocoder(this, java.util.Locale.getDefault())
            @Suppress("DEPRECATION")
            val results = geocoder.getFromLocation(location.latitude, location.longitude, 1)
            addressLine = results?.firstOrNull()?.getAddressLine(0)
        } catch (e: Exception) {
            // Non-fatal — the sheet still gets lat/lng even without a readable address line.
        }
        if (sheet != null && sheet.isAdded) {
            sheet.applyResolvedLocation(location.latitude, location.longitude, addressLine)
            InAppNotifier.show(this, "Current location filled in — edit if needed", InAppNotifier.Type.SUCCESS)
        }
    }

    // ---- Active delivery address (part 9 handover) ----
    // Home has no cached address the first time it's ever opened on a
    // device, so this resolves one from the server (the account's
    // `is_default` address, same convention AddAddressBody's "first
    // address becomes default" comment already establishes) and caches it
    // via ActiveAddressManager for every subsequent open. A logged-in user
    // with zero saved addresses still gets the pre-part-9 behaviour —
    // loadRestaurants() with no lat/lng, nothing filtered out — rather than
    // being blocked from browsing until they add one.

    private fun applyActiveAddressUi(active: ActiveAddressManager.ActiveAddress?) {
        binding.deliveryLocationText.text = if (active != null) {
            "${active.label} — ${active.shortText}"
        } else {
            getString(R.string.delivery_location_placeholder)
        }
    }

    private fun resolveActiveAddressThenLoad(forceRefresh: Boolean) {
        val cached = ActiveAddressManager.get(this)
        if (cached != null && !forceRefresh) {
            loadRestaurants()
            return
        }
        lifecycleScope.launch {
            try {
                val addresses = api.getAddresses().body()?.data?.addresses.orEmpty()
                val picked = addresses.firstOrNull { it.isDefault } ?: addresses.firstOrNull()
                if (picked != null) {
                    ActiveAddressManager.set(this@HomeActivity, picked)
                    applyActiveAddressUi(ActiveAddressManager.get(this@HomeActivity))
                }
            } catch (e: Exception) {
                // Non-fatal — Home still loads unfiltered below, same as
                // before this address concept existed.
            }
            loadRestaurants()
        }
    }

    private fun openRestaurant(restaurant: Restaurant) {
        startActivity(
            Intent(this, RestaurantDetailActivity::class.java)
                .putExtra(RestaurantDetailActivity.EXTRA_RESTAURANT_ID, restaurant.id)
                .putExtra(RestaurantDetailActivity.EXTRA_RESTAURANT_NAME, restaurant.name)
                .putExtra(RestaurantDetailActivity.EXTRA_RESTAURANT_COVER_URL, restaurant.coverUrl)
                .putExtra(RestaurantDetailActivity.EXTRA_ETA_MINUTES, restaurant.etaMinutes ?: -1)
        )
    }

    private fun openRestaurantById(id: Int, name: String) {
        startActivity(
            Intent(this, RestaurantDetailActivity::class.java)
                .putExtra(RestaurantDetailActivity.EXTRA_RESTAURANT_ID, id)
                .putExtra(RestaurantDetailActivity.EXTRA_RESTAURANT_NAME, name)
        )
    }

    // Opens the dish-customization sheet (§2.6/1.9) for a dish tapped from
    // Home's Popular row or Search results — both pass a MenuItem built via
    // their own toMenuItem() converter, which carries no addons (those rows'
    // source models don't include them), so the sheet shows qty + cooking
    // request only for these two entry points. onAdded refreshes the cart
    // badge the same way every other cart mutation on this screen does.
    private fun openItemDetailSheet(
        restaurantId: Int,
        restaurantName: String,
        item: com.anydrop.food.network.MenuItem,
        currentSaved: Boolean,
        onSaveStateChanged: (Boolean) -> Unit,
        onCartLineChanged: () -> Unit
    ) {
        val sheet = ItemDetailBottomSheetFragment.newInstance(restaurantId, restaurantName, item, currentSaved)
        sheet.onAdded = {
            // Bug fix: this card's own stepper used to only find out about
            // a cart change via updateCartBadge() (Home's overall badge),
            // never itself — so it could show a different quantity than
            // what was just set inside the sheet.
            onCartLineChanged()
            updateCartBadge()
        }
        sheet.onSaveStateChanged = onSaveStateChanged
        sheet.show(supportFragmentManager, "item_detail")
    }

    private fun updateCartBadge() {
        val count = CartManager.totalItemCount()
        if (count > 0) {
            binding.cartBadge.text = if (count > 99) "99+" else count.toString()
            if (binding.cartBadge.visibility != android.view.View.VISIBLE) {
                binding.cartBadge.visibility = android.view.View.VISIBLE
                binding.cartBadge.scaleX = 0f
                binding.cartBadge.scaleY = 0f
                binding.cartBadge.animate().scaleX(1f).scaleY(1f).setDuration(200).start()
            }
        } else {
            binding.cartBadge.visibility = android.view.View.GONE
        }
    }

    // ---- Promo carousel (§2.2) ----

    /**
     * Preferred promo source: GET /home/promo-banners.php. Falls back to the
     * old single static banner (showPromoBanner(), splash-config home_promo_*
     * fields) if the list is empty or the request fails — that fallback path
     * is unchanged from before this carousel existed.
     */
    private fun loadPromoBanners() {
        lifecycleScope.launch {
            try {
                val response = api.getPromoBanners()
                val banners: List<PromoBanner> = response.body()?.data?.banners ?: emptyList()
                if (banners.isEmpty()) {
                    showPromoBanner()
                    return@launch
                }
                binding.promoCarouselContainer.visibility = android.view.View.VISIBLE
                binding.promoBannerContainer.visibility = android.view.View.GONE
                promoBannerAdapter.submit(banners)
                // Dot indicators removed per request — the carousel still
                // auto-advances and is swipeable, it just no longer shows the
                // small dots under the banner.
                binding.promoCarouselDots.visibility = android.view.View.GONE
                if (banners.size > 1) startPromoAutoAdvance(banners.size)
            } catch (e: Exception) {
                // Non-fatal — fall back to the static single banner.
                showPromoBanner()
            }
        }
    }

    /** ~4s auto-advance, loops back to the first slide at the end. */
    private fun startPromoAutoAdvance(count: Int) {
        promoAutoAdvanceRunnable?.let { promoAutoAdvanceHandler.removeCallbacks(it) }
        val runnable = object : Runnable {
            override fun run() {
                val next = (binding.promoCarousel.currentItem + 1) % count
                binding.promoCarousel.setCurrentItem(next, true)
                promoAutoAdvanceHandler.postDelayed(this, 4000)
            }
        }
        promoAutoAdvanceRunnable = runnable
        promoAutoAdvanceHandler.postDelayed(runnable, 4000)
    }

    /** Tap-through per target_type: none (no-op) / restaurant / category / url. */
    private fun onPromoBannerTapped(banner: PromoBanner) {
        when (banner.targetType) {
            "restaurant" -> {
                val id = banner.targetValue?.toIntOrNull() ?: return
                openRestaurantById(id, "")
            }
            "category" -> {
                val slug = banner.targetValue ?: return
                clearSearchInputProgrammatically()
                activeCategorySlug = slug
                categoryAdapter.setSelected(slug)
                binding.sectionTitle.text = slug.replaceFirstChar { it.uppercase() }
                loadCategoryItems(FoodCategory(0, slug, slug, null))
            }
            "url" -> {
                val url = banner.targetValue
                if (!url.isNullOrBlank()) {
                    try {
                        startActivity(Intent(Intent.ACTION_VIEW, android.net.Uri.parse(url)))
                    } catch (e: Exception) {
                        InAppNotifier.show(this, "Couldn't open that link.", InAppNotifier.Type.ERROR)
                    }
                }
            }
            else -> { /* "none" — visual-only banner, no tap action. */ }
        }
    }

    /** Promo banner content is fully server-driven — same splash-config API used at startup. */
    private fun showPromoBanner() {
        val config = AppConfigCache.splashConfig
        if (config?.homePromoEnabled == true) {
            binding.promoBannerContainer.visibility = android.view.View.VISIBLE
            binding.promoBannerTitle.text = config.homePromoTitle.orEmpty()
            binding.promoBannerSubtitle.text = config.homePromoSubtitle.orEmpty()
            if (!config.homePromoImageUrl.isNullOrBlank()) {
                binding.promoBannerImage.load(config.homePromoImageUrl) { crossfade(true) }
            }
            InAppNotifier.show(
                this,
                config.homePromoTitle ?: "New offer available",
                InAppNotifier.Type.OFFER,
                imageUrl = config.homePromoImageUrl
            )
            NotificationHelper.showOfferNotification(
                this,
                title = config.homePromoTitle ?: "New offer on Anydrop",
                message = config.homePromoSubtitle ?: "Tap to see today's deals",
                imageUrl = config.homePromoImageUrl
            )
        } else {
            binding.promoBannerContainer.visibility = android.view.View.GONE
        }
    }

    // ---- Popular dishes near you (§2.4) ----

    /**
     * Curated cross-restaurant dish row, Home-default view only (hidden
     * during search / category-browse / same as the restaurant list itself
     * switching to searchAdapter in those modes — see setVisibility calls
     * at each of those call sites). Row + its title stay hidden if the
     * endpoint returns nothing, rather than showing an empty section.
     */
    private fun loadPopularItems() {
        lifecycleScope.launch {
            try {
                val response = api.getPopularItems()
                val vegOnly = VegModeManager.isVegOnly(this@HomeActivity)
                var items: List<PopularItem> = response.body()?.data?.items ?: emptyList()
                if (vegOnly) items = items.filter { it.isVeg }
                popularItemsAdapter.submit(items)
                setPopularItemsVisible(items.isNotEmpty() && isBrowsingDefaultHome())
            } catch (e: Exception) {
                // Non-fatal — row just stays hidden if it fails to load.
                setPopularItemsVisible(false)
            }
        }
    }

    private fun isBrowsingDefaultHome(): Boolean =
        currentQuery.length < 2 && activeCategorySlug == null && activeFilter == null

    private fun setPopularItemsVisible(visible: Boolean) {
        val v = if (visible) android.view.View.VISIBLE else android.view.View.GONE
        binding.popularItemsTitle.visibility = v
        binding.popularItemsList.visibility = v
    }

    // ---- Explore More tiles (§2.3) ----

    // §2.4 / H3 (bug-tracker Phase B item 3, 2026-08-10) — the earlier
    // scroll-collapse/expand animation for collapsibleFilters (filter chip
    // row) accumulated several stacked bug-fix patches across past sessions
    // (v6/v7 corrections, grace periods, separate expand/collapse
    // accumulator thresholds — see git history if that logic is ever needed
    // for reference), each fixing a glitch the previous fix introduced.
    // App owner's call: remove the animated collapse/expand behavior
    // entirely rather than patch it again — collapsibleFilters now just
    // stays permanently visible, same as categoryList above it. Simpler,
    // fewer moving parts, less risk.
    //   - btnBackToTop is unaffected — it's still driven by distance-from-
    //     top (isFarFromTop) below, independent of the filter row.
    private var collapsibleFiltersExpandedHeight = -1

    private val collapseTriggerPx by lazy { dpToPx(24) }
    private val expandTriggerPx by lazy { dpToPx(12) }
    private val nearTopThresholdPx by lazy { dpToPx(8) }

    // btnBackToTop's own state. Bug fix: this used to show as soon as
    // scrollY passed a tiny (~48dp) distance from the top, in EITHER scroll
    // direction — so it popped up after barely scrolling down. It should
    // only become eligible once the user is genuinely deep in the list, and
    // then only actually appear when they scroll back UP a little (the
    // Swiggy/Zomato pattern), not just from being far down.
    private var isFarFromTop = false
    private val deepScrollThresholdPx by lazy { resources.displayMetrics.heightPixels }
    private var backToTopLastY = 0
    private var backToTopDirectionAnchorY = 0
    private var backToTopLastDirection = 0 // -1 up, 1 down, 0 none yet

    // Restaurant-card carousel visibility check (bug fix — see class-level
    // RestaurantAdapter.isCarouselVisible kdoc for the root cause). Reuses
    // this same scroll listener rather than adding a second one, per the
    // handover's explicit instruction, but with its own throttle — the
    // header's direction-change gating is the wrong shape for this (the
    // carousel check needs to run on steady one-directional scrolling too,
    // just not on every single pixel of it).
    private var carouselCheckAnchorY = 0
    private val carouselCheckThrottlePx by lazy { dpToPx(40) }

    private fun dpToPx(dp: Int): Int =
        (dp * resources.displayMetrics.density).toInt()

    /**
     * True if [view] (a restaurantList card root) currently overlaps
     * homeNestedScroll's visible viewport. Used both at bind-time
     * (RestaurantAdapter.VH.bind(), since a card can already be on-screen
     * the moment it's bound — there's no recycling here to guarantee
     * otherwise) and from the scroll listener below.
     */
    private fun isViewWithinScrollBounds(view: android.view.View): Boolean {
        val scrollBounds = android.graphics.Rect()
        if (!binding.homeNestedScroll.getLocalVisibleRect(scrollBounds)) return false
        val scrollY = binding.homeNestedScroll.scrollY
        // getLocalVisibleRect is in homeNestedScroll's own coordinate
        // space (i.e. already scroll-adjusted to "what's currently on
        // screen" — top is always 0), so compare it directly against the
        // card's top/bottom measured relative to homeNestedScroll's
        // scrolled content, not raw window coordinates.
        val cardTop = getYRelativeToScrollContainer(view)
        if (cardTop == null) return false
        val cardBottom = cardTop + view.height
        val viewportTop = scrollY
        val viewportBottom = scrollY + scrollBounds.height()
        return cardBottom > viewportTop && cardTop < viewportBottom
    }

    private fun getYRelativeToScrollContainer(view: android.view.View): Int? {
        var y = 0
        var current: android.view.View = view
        while (true) {
            val parent = current.parent as? android.view.View ?: return null
            y += current.top
            if (parent.id == binding.homeNestedScroll.id) return y
            current = parent
        }
    }

    /**
     * Walks every currently-bound restaurantList row and starts/stops its
     * carousel based on real on-screen position — see
     * RestaurantAdapter.isCarouselVisible's kdoc for why this can't rely on
     * onAttachedToWindow/onDetachedFromWindow alone on this particular
     * screen. Throttled by the caller (only invoked once scrollY has moved
     * carouselCheckThrottlePx since the last check) rather than on every
     * scroll callback, per the same "don't re-run this on every pixel"
     * concern setupCollapsingHeader already solves for its own logic.
     */
    private fun updateCarouselVisibility() {
        val list = binding.restaurantList
        for (i in 0 until list.childCount) {
            val child = list.getChildAt(i) ?: continue
            val holder = list.getChildViewHolder(child) as? RestaurantAdapter.VH ?: continue
            holder.binding.restaurantCarousel.setVisibleToUser(isViewWithinScrollBounds(child))
        }
    }

    private fun setupCollapsingHeader() {
        val filters = binding.collapsibleFilters
        filters.post {
            if (collapsibleFiltersExpandedHeight <= 0) {
                collapsibleFiltersExpandedHeight = filters.height
                // Reserve that same height at the top of the scrollable
                // content (see filterOverlaySpacer's own layout comment) so
                // the overlay never covers/crops the promo banner below it.
                val spacer = binding.filterOverlaySpacer
                val params = spacer.layoutParams
                params.height = collapsibleFiltersExpandedHeight
                spacer.layoutParams = params
            }
        }

        binding.btnBackToTop.setOnClickListener {
            binding.homeNestedScroll.smoothScrollTo(0, 0)
        }

        binding.homeNestedScroll.setOnScrollChangeListener(
            androidx.core.widget.NestedScrollView.OnScrollChangeListener { _, _, scrollY, _, _ ->
                // Carousel visibility check runs regardless of header
                // measurement/animation state below — it's an independent
                // concern with its own throttle, not gated by the header's
                // direction-change logic. Steady one-directional scrolling
                // (e.g. a long fling down the list) must still re-check
                // periodically, which the header's gating wouldn't do once
                // direction stops changing.
                if (kotlin.math.abs(scrollY - carouselCheckAnchorY) >= carouselCheckThrottlePx) {
                    carouselCheckAnchorY = scrollY
                    updateCarouselVisibility()
                }

                // btnBackToTop — own direction/anchor tracking, independent of the header's
                // (below) so it keeps working even before the header is
                // measured. Only becomes eligible past deepScrollThresholdPx
                // (~one screen height — "kaafi scroll"), and only actually
                // shows on a real scroll-UP gesture past expandTriggerPx —
                // scrolling down further, even past the threshold, must NOT
                // show it. Once shown, resuming a real scroll-down hides it
                // again; reaching near-top always hides it too.
                val backDirection = when {
                    scrollY > backToTopLastY -> 1
                    scrollY < backToTopLastY -> -1
                    else -> backToTopLastDirection
                }
                if (backDirection != 0 && backDirection != backToTopLastDirection) {
                    backToTopDirectionAnchorY = backToTopLastY
                    backToTopLastDirection = backDirection
                }
                backToTopLastY = scrollY

                if (isFarFromTop && scrollY <= nearTopThresholdPx) {
                    isFarFromTop = false
                    animateBackToTop(visible = false)
                } else if (!isFarFromTop &&
                    scrollY > deepScrollThresholdPx &&
                    backDirection == -1 &&
                    (backToTopDirectionAnchorY - scrollY) >= expandTriggerPx
                ) {
                    isFarFromTop = true
                    animateBackToTop(visible = true)
                } else if (isFarFromTop &&
                    backDirection == 1 &&
                    (scrollY - backToTopDirectionAnchorY) >= collapseTriggerPx
                ) {
                    isFarFromTop = false
                    animateBackToTop(visible = false)
                }

                // collapsibleFilters no longer collapses/expands on scroll
                // (H3, 2026-08-10) — it just stays visible, so there's
                // nothing left to do here for the filter row.
            }
        )
    }

    /**
     * Slides down to appear, up to disappear. Driven by [isFarFromTop] from
     * the scroll listener above — no longer tied to collapsibleFilters'
     * own state (see isFarFromTop's field comment).
     */
    private fun animateBackToTop(visible: Boolean) {
        val fab = binding.btnBackToTop
        if (visible) {
            fab.visibility = android.view.View.VISIBLE
            fab.translationY = -fab.height.toFloat().coerceAtLeast(-dpToPx(48).toFloat())
            fab.animate().translationY(0f).setDuration(180L)
                .setInterpolator(android.view.animation.DecelerateInterpolator()).start()
        } else {
            fab.animate().translationY(-fab.height.toFloat().coerceAtLeast(-dpToPx(48).toFloat()))
                .setDuration(150L)
                .setInterpolator(android.view.animation.DecelerateInterpolator())
                .withEndAction { fab.visibility = android.view.View.GONE }
                .start()
        }
    }

    private fun setupExploreTiles() {
        val tiles = listOf(
            ExploreTile(
                id = "offers",
                title = getString(R.string.explore_offers),
                subtitle = getString(R.string.explore_offers_subtitle),
                iconRes = R.drawable.ic_offer_tag,
                isComingSoon = false
            ),
            ExploreTile(
                id = "top10",
                title = getString(R.string.explore_top10),
                subtitle = getString(R.string.explore_top10_subtitle),
                iconRes = R.drawable.ic_top_ranked,
                isComingSoon = false
            ),
            ExploreTile(
                id = "train",
                title = getString(R.string.explore_train),
                subtitle = getString(R.string.coming_soon),
                iconRes = R.drawable.ic_train,
                isComingSoon = true
            ),
            ExploreTile(
                id = "collections",
                title = getString(R.string.explore_collections),
                subtitle = getString(R.string.coming_soon),
                iconRes = R.drawable.ic_collections,
                isComingSoon = true
            )
        )
        binding.exploreTileList.layoutManager =
            LinearLayoutManager(this, LinearLayoutManager.HORIZONTAL, false)
        binding.exploreTileList.adapter = ExploreTileAdapter(tiles) { tile -> onExploreTileTapped(tile) }
    }

    private fun onExploreTileTapped(tile: ExploreTile) {
        when (tile.id) {
            "offers" -> {
                // Reuses the same restaurant-list flow as a filter chip tap —
                // clears search/category state, filters by offer_badge_text.
                clearSearchInputProgrammatically()
                activeCategorySlug = null
                categoryAdapter.setSelected(null)
                activeFilter = "has_offer"
                binding.sectionTitle.text = getString(R.string.explore_offers)
                binding.restaurantList.adapter = restaurantAdapter
                setPopularItemsVisible(false)
                loadRestaurants()
            }
            "top10" -> {
                clearSearchInputProgrammatically()
                activeCategorySlug = null
                categoryAdapter.setSelected(null)
                activeFilter = null
                binding.sectionTitle.text = getString(R.string.explore_top10)
                binding.restaurantList.adapter = restaurantAdapter
                setPopularItemsVisible(false)
                loadRestaurants(sort = "rating", perPage = 10)
            }
            else -> {
                // "Food on train" / "Collections" — visually present, no real
                // feature behind them yet per the spec's explicit scope call.
                InAppNotifier.show(this, getString(R.string.coming_soon), InAppNotifier.Type.INFO)
            }
        }
    }

    // ---- Category chip row (Pizza / Rolls / Burger...) ----

    private fun loadCategories() {
        lifecycleScope.launch {
            try {
                val response = api.getHomeCategories()
                val list: List<FoodCategory> = response.body()?.data ?: emptyList()
                categoryAdapter.submit(list)
            } catch (e: Exception) {
                // Non-fatal — the row just stays empty if categories fail to load.
            }
        }
    }

    private fun onCategoryTapped(category: FoodCategory) {
        // Tapping the already-active category again clears it back to "All".
        activeCategorySlug = if (activeCategorySlug == category.slug) null else category.slug
        categoryAdapter.setSelected(activeCategorySlug)
        clearSearchInputProgrammatically()
        if (activeCategorySlug == null) {
            binding.sectionTitle.text = getString(R.string.restaurants_near_you)
            binding.restaurantList.adapter = restaurantAdapter
            loadRestaurants()
            setPopularItemsVisible(!popularItemsAdapter.isEmpty())
        } else {
            binding.sectionTitle.text = category.name
            loadCategoryItems(category)
            setPopularItemsVisible(false)
        }
    }

    private fun loadCategoryItems(category: FoodCategory) {
        contentLoadJob?.cancel()
        binding.swipeRefresh.isRefreshing = true
        contentLoadJob = lifecycleScope.launch {
            try {
                val vegOnly = VegModeManager.isVegOnly(this@HomeActivity)
                val response = api.getCategoryItems(
                    slug = category.slug,
                    vegOnly = if (vegOnly) "1" else null,
                    // Bug: category (e.g. "Thali") and a filter chip (e.g.
                    // "Under ₹200") looked like they combined — chip stayed
                    // highlighted with its × — but this call never sent
                    // `activeFilter` at all, so the chip was purely cosmetic
                    // once a category was active. Now forwarded to the
                    // backend the same way loadRestaurants() already does.
                    filter = activeFilter
                )
                binding.swipeRefresh.isRefreshing = false
                val items: List<SearchItem> = response.body()?.data?.items ?: emptyList()
                binding.restaurantList.adapter = searchAdapter
                searchAdapter.submit(emptyList(), items)
                setEmptyState(items.isEmpty(), getString(R.string.empty_search_results))
            } catch (e: kotlinx.coroutines.CancellationException) {
                // A newer request superseded this one — not a real error,
                // and the newer job is already updating the UI itself.
                throw e
            } catch (e: Exception) {
                binding.swipeRefresh.isRefreshing = false
                setEmptyState(true, getString(R.string.empty_search_results))
                InAppNotifier.show(this@HomeActivity, "Couldn't load items. Check your connection.", InAppNotifier.Type.ERROR)
            }
        }
    }

    // ---- Search ----

    /**
     * Clears the search box from code (category tap, promo-banner tap,
     * Explore tile tap) WITHOUT letting the debounced search TextWatcher
     * fire afterwards. This was the real root cause of "category filter
     * flashes correctly then reverts a moment later, only a manual pull-to-
     * refresh brings it back": `searchInput.setText("")` triggers
     * `afterTextChanged`, which schedules a `runSearchOrReload("")` 400ms
     * later. That callback has no idea a category/filter was just selected —
     * it unconditionally resets the section title, swaps back to
     * `restaurantAdapter`, and calls the plain `loadRestaurants()`, silently
     * overwriting the just-applied filtered view a moment after the tap.
     *
     * First attempt at this fix only cancelled the *previously pending*
     * `searchRunnable` — but `setText("")` still fires the watcher, which
     * immediately schedules a brand-new runnable 400ms out regardless, so
     * the revert still happened just delayed by one recreation. The actual
     * fix is `isProgrammaticSearchClear`: the watcher checks it first and
     * does nothing at all (no reschedule) while it's true.
     */
    private var isProgrammaticSearchClear = false

    private fun clearSearchInputProgrammatically() {
        searchRunnable?.let { searchHandler.removeCallbacks(it) }
        isProgrammaticSearchClear = true
        binding.searchInput.setText("")
        isProgrammaticSearchClear = false
        currentQuery = ""
        // I3 — every caller of this function is leaving search mode for
        // something else (category tap, offers tile, promo banner, etc.),
        // and isProgrammaticSearchClear suppresses the watcher that would
        // otherwise reach runSearchOrReload's own GONE branch — so hide the
        // filters pill here once, rather than at each of those call sites.
        binding.btnSearchFilters.visibility = android.view.View.GONE
    }

    private fun setupSearch() {
        binding.searchInput.addTextChangedListener(object : TextWatcher {
            override fun beforeTextChanged(s: CharSequence?, start: Int, count: Int, after: Int) {}
            override fun onTextChanged(s: CharSequence?, start: Int, before: Int, count: Int) {}
            override fun afterTextChanged(s: Editable?) {
                if (isProgrammaticSearchClear) return
                val text = s?.toString()?.trim().orEmpty()
                currentQuery = text
                if (text.isNotEmpty() && activeCategorySlug != null) {
                    activeCategorySlug = null
                    categoryAdapter.setSelected(null)
                }
                searchRunnable?.let { searchHandler.removeCallbacks(it) }
                val runnable = Runnable { runSearchOrReload(text) }
                searchRunnable = runnable
                // Debounce so we don't hit the API on every keystroke.
                searchHandler.postDelayed(runnable, 400)
            }
        })
    }

    private fun setupVoiceSearch() {
        binding.btnVoiceSearch.setOnClickListener {
            val intent = Intent(RecognizerIntent.ACTION_RECOGNIZE_SPEECH).apply {
                putExtra(
                    RecognizerIntent.EXTRA_LANGUAGE_MODEL,
                    RecognizerIntent.LANGUAGE_MODEL_FREE_FORM
                )
                putExtra(RecognizerIntent.EXTRA_PROMPT, getString(R.string.voice_search_prompt))
            }
            try {
                voiceSearchLauncher.launch(intent)
            } catch (e: ActivityNotFoundException) {
                InAppNotifier.show(this, getString(R.string.voice_search_unavailable), InAppNotifier.Type.ERROR)
            }
        }
    }

    private fun runSearchOrReload(query: String) {
        if (query.length < 2) {
            binding.sectionTitle.text = getString(R.string.restaurants_near_you)
            binding.filterScroll.visibility = android.view.View.VISIBLE
            binding.categoryList.visibility = android.view.View.VISIBLE
            binding.btnSearchFilters.visibility = android.view.View.GONE
            binding.restaurantList.adapter = restaurantAdapter
            loadRestaurants()
            setPopularItemsVisible(!popularItemsAdapter.isEmpty())
            return
        }
        binding.sectionTitle.text = getString(R.string.search_results_for, query)
        binding.filterScroll.visibility = android.view.View.GONE
        binding.categoryList.visibility = android.view.View.GONE
        binding.restaurantList.adapter = searchAdapter
        setPopularItemsVisible(false)
        contentLoadJob?.cancel()
        binding.swipeRefresh.isRefreshing = true
        contentLoadJob = lifecycleScope.launch {
            try {
                // Search returns BOTH the matching restaurant(s) AND every
                // menu item whose name matches — each item tagged with its
                // own restaurant_id/restaurant_name, including dishes from
                // OTHER restaurants for the same query ("Also available at").
                // I3 — lat/lng now passed so search.php's distance_km comes
                // back populated (it was already accepting these params,
                // just never sent from here), which is what the "nearby"
                // filter option filters against.
                val activeAddress = ActiveAddressManager.get(this@HomeActivity)
                val response = api.search(
                    query = query,
                    lat = activeAddress?.latitude,
                    lng = activeAddress?.longitude
                )
                binding.swipeRefresh.isRefreshing = false
                val body = response.body()?.data
                val restaurants = body?.restaurants ?: emptyList()
                val items = body?.items ?: emptyList()

                val vegOnly = VegModeManager.isVegOnly(this@HomeActivity)
                rawSearchRestaurants = if (vegOnly) restaurants.filter { it.isVegOnly } else restaurants
                rawSearchItems = if (vegOnly) items.filter { it.isVeg } else items

                binding.btnSearchFilters.visibility =
                    if (rawSearchRestaurants.isEmpty() && rawSearchItems.isEmpty()) android.view.View.GONE
                    else android.view.View.VISIBLE

                applySearchFiltersAndSubmit()
            } catch (e: kotlinx.coroutines.CancellationException) {
                throw e
            } catch (e: Exception) {
                binding.swipeRefresh.isRefreshing = false
                setEmptyState(true, getString(R.string.empty_search_results))
                InAppNotifier.show(this@HomeActivity, "Search failed. Check your connection.", InAppNotifier.Type.ERROR)
            }
        }
    }

    /** I3 — applies [searchFilters] against the latest raw search response
     * and re-submits to [searchAdapter]. Called after a new search comes
     * back, and again from [setupSearchFilters]'s onApply once the sheet
     * closes, so toggling filters never needs a fresh network call. */
    private fun applySearchFiltersAndSubmit() {
        val cuisineTagsByRestaurantId = rawSearchRestaurants.associate { r ->
            r.id to r.cuisineTags.orEmpty().split(",").map { it.trim() }.filter { it.isNotBlank() }
        }
        val filteredRestaurants = rawSearchRestaurants.filter { searchFilters.matches(it) }
        val filteredItems = rawSearchItems.filter { item ->
            searchFilters.matches(item, cuisineTagsByRestaurantId[item.restaurantId].orEmpty())
        }
        searchAdapter.submit(filteredRestaurants, filteredItems)
        setEmptyState(searchAdapter.isEmpty(), getString(R.string.empty_search_results))
    }

    /** I3 — wires the "Filters" pill shown while browsing search results. */
    private fun setupSearchFilters() {
        binding.btnSearchFilters.setOnClickListener {
            val activeAddress = ActiveAddressManager.get(this@HomeActivity)
            val hasLocation = activeAddress?.latitude != null && activeAddress.longitude != null
            val sheet = SearchFiltersBottomSheet.newInstance(
                restaurants = rawSearchRestaurants,
                items = rawSearchItems,
                hasLocation = hasLocation,
                current = searchFilters
            )
            sheet.onApply = { newFilters ->
                searchFilters = newFilters
                applySearchFiltersAndSubmit()
            }
            sheet.show(supportFragmentManager, "search_filters")
        }
    }

    private fun reloadCurrentView() {
        when {
            currentQuery.length >= 2 -> runSearchOrReload(currentQuery)
            activeCategorySlug != null -> {
                val category = FoodCategory(0, binding.sectionTitle.text.toString(), activeCategorySlug!!, null)
                loadCategoryItems(category)
            }
            else -> loadRestaurants()
        }
    }

    // ---- Veg toggle (Zomato-style, default ON) ----

    private fun setupVegToggle() {
        applyVegToggleUi(VegModeManager.isVegOnly(this), animate = false)
        binding.vegToggleContainer.setOnClickListener {
            val newValue = !VegModeManager.isVegOnly(this)
            VegModeManager.setVegOnly(this, newValue)
            applyVegToggleUi(newValue, animate = true)
            reloadCurrentView()
            loadPopularItems()
        }
    }

    private fun applyVegToggleUi(vegOnly: Boolean, animate: Boolean) {
        binding.vegToggleTrack.setBackgroundResource(
            if (vegOnly) R.drawable.bg_veg_toggle_track_on else R.drawable.bg_veg_toggle_track_off
        )
        binding.vegToggleDot.setBackgroundResource(
            if (vegOnly) R.drawable.dot_veg_fill else R.drawable.dot_nonveg_fill
        )
        val trackWidthPx = (46 * resources.displayMetrics.density)
        val thumbWidthPx = (20 * resources.displayMetrics.density)
        val padding = (3 * resources.displayMetrics.density)
        val targetX = if (vegOnly) (trackWidthPx - thumbWidthPx - padding * 2) else 0f
        if (animate) {
            binding.vegToggleThumb.animate().translationX(targetX).setDuration(180).start()
        } else {
            binding.vegToggleThumb.translationX = targetX
        }
        binding.vegToggleLabel.setTextColor(
            getColor(if (vegOnly) R.color.veg_green else R.color.text_secondary)
        )
    }

    // ---- Filter chips (All / Near & Fast / Under ₹200 / Pure Veg / Open now / Rating) ----

    private fun setupFilterChips() {
        val chips = listOf(
            binding.chipAll to null,
            binding.chipNearFast to "near_fast",
            binding.chipUnder200 to "under_200",
            binding.chipPureVeg to "pure_veg",
            binding.chipOpenNow to "open_now",
            binding.chipRating to "rating_4"
        )

        // Bug 1.2: shows a close (×) icon on whichever chip is currently
        // active (skips "All" — nothing to clear there) so clearing a
        // filter is discoverable instead of only "tap All" working.
        fun applyChipSelectionUi() {
            chips.forEach { (c, filterValue) ->
                val isSelected = filterValue == activeFilter
                c.setBackgroundResource(
                    if (isSelected) R.drawable.bg_chip_selected else R.drawable.bg_chip_unselected
                )
                val showClose = isSelected && filterValue != null
                c.setCompoundDrawablesWithIntrinsicBounds(
                    0, 0, if (showClose) R.drawable.ic_close else 0, 0
                )
                c.compoundDrawablePadding =
                    if (showClose) (6 * resources.displayMetrics.density).toInt() else 0
            }
        }

        chips.forEach { (chip, filterValue) ->
            chip.setOnClickListener {
                // Cancel any debounced search callback still waiting to fire —
                // otherwise a stale search/reload from before the tap could
                // still land after this filter's request and stomp on it.
                searchRunnable?.let { searchHandler.removeCallbacks(it) }
                // Tapping the already-active filter chip again clears it back
                // to "All" — the close icon above visually signals this.
                activeFilter = if (activeFilter == filterValue) null else filterValue
                applyChipSelectionUi()
                // Bug: previously this always switched to restaurantAdapter
                // and called loadRestaurants(), even while a category chip
                // (e.g. "Thali") was active — the filter chip would show as
                // selected (with its × icon) but silently do nothing, since
                // loadCategoryItems() never received it and this call
                // replaced the category view outright the moment a filter
                // was tapped. A category and a filter chip can be active
                // together now — route to whichever request actually
                // applies both.
                val activeCategory = activeCategorySlug
                if (activeCategory != null) {
                    loadCategoryItems(FoodCategory(0, binding.sectionTitle.text.toString(), activeCategory, null))
                } else {
                    binding.restaurantList.adapter = restaurantAdapter
                    // Bug 1.8: hide the Popular row the instant a real filter is
                    // applied (not just next time loadPopularItems() happens to
                    // run) — reappears via the same check when "All" is tapped.
                    setPopularItemsVisible(isBrowsingDefaultHome() && !popularItemsAdapter.isEmpty())
                    loadRestaurants()
                }
            }
        }

        applyChipSelectionUi()
    }

    private fun loadRestaurants(sort: String? = null, perPage: Int? = null) {
        contentLoadJob?.cancel()
        binding.swipeRefresh.isRefreshing = true
        contentLoadJob = lifecycleScope.launch {
            try {
                val vegOnly = VegModeManager.isVegOnly(this@HomeActivity)
                // part 9 handover — this is the actual fix: send the active
                // address's lat/lng so restaurants/list.php's delivery-radius
                // filter (already live server-side since part 9) has
                // something to filter against. Null/null when no address is
                // resolved yet — matches the "don't hide behind an
                // unresolved fix" fallback the backend already relies on.
                val activeAddress = ActiveAddressManager.get(this@HomeActivity)
                val response = api.getRestaurants(
                    lat = activeAddress?.latitude,
                    lng = activeAddress?.longitude,
                    filter = activeFilter,
                    vegOnly = if (vegOnly) "1" else null,
                    sort = sort ?: "rating",
                    perPage = perPage
                )
                binding.swipeRefresh.isRefreshing = false
                val list: List<Restaurant> = response.body()?.data?.data ?: emptyList()
                restaurantAdapter.submit(list)
                // Initial-load visibility pass — the scroll listener only
                // fires on an actual scroll event, but restaurantList lays
                // out every row up front (the anti-pattern this whole fix
                // exists for), so the first screenful of cards is already
                // "on screen" the instant this data lands, before the user
                // has scrolled at all. Without this, those first cards'
                // binds already correctly deferred starting their timer
                // (see RestaurantAdapter.isCarouselVisible's default), but
                // nothing would ever tell them they're actually visible
                // until a scroll happened. Posted so it runs after this
                // submit()'s layout pass has actually measured the rows.
                binding.restaurantList.post { updateCarouselVisibility() }
                // §2.3 — an empty result on the plain, unfiltered/unsearched
                // Home feed (not a filter chip, not a category, not a veg-only
                // toggle narrowing things down) is treated as "this area isn't
                // served yet" rather than a generic empty state — a blank list
                // on first open reads as broken, not as "we haven't launched
                // here." A filtered/categorised/searched empty result still
                // uses the plain empty-state message below, since that's a
                // real "no matches" case, not an unserved area.
                val isUnfilteredDefaultView = isBrowsingDefaultHome() && !vegOnly
                if (list.isEmpty() && isUnfilteredDefaultView) {
                    setServiceAreaUnavailable(true)
                    setEmptyState(false, getString(R.string.empty_restaurants))
                } else {
                    setServiceAreaUnavailable(false)
                    setEmptyState(list.isEmpty(), getString(R.string.empty_restaurants))
                }
            } catch (e: kotlinx.coroutines.CancellationException) {
                throw e
            } catch (e: Exception) {
                binding.swipeRefresh.isRefreshing = false
                setServiceAreaUnavailable(false)
                setEmptyState(true, getString(R.string.empty_restaurants))
                InAppNotifier.show(
                    this@HomeActivity,
                    "Couldn't load restaurants. Pull down to retry.",
                    InAppNotifier.Type.ERROR
                )
            }
        }
    }

    private fun setEmptyState(isEmpty: Boolean, message: String) {
        binding.emptyState.visibility = if (isEmpty) android.view.View.VISIBLE else android.view.View.GONE
        binding.emptyStateText.text = message
    }

    // §2.3 — toggles the full-screen "not available in your area yet" state,
    // which replaces swipeRefresh's whole scrolling content (banner,
    // categories, filters, restaurant list) rather than layering inside it,
    // since a genuinely unserved area has nothing useful to browse at all.
    private fun setServiceAreaUnavailable(show: Boolean) {
        binding.serviceAreaUnavailable.visibility = if (show) android.view.View.VISIBLE else android.view.View.GONE
        binding.swipeRefresh.visibility = if (show) android.view.View.GONE else android.view.View.VISIBLE
    }
}
