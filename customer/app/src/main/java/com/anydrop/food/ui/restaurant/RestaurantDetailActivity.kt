package com.anydrop.food.ui.restaurant

import android.Manifest
import android.content.pm.PackageManager
import android.location.Location
import android.location.LocationManager
import android.os.Bundle
import android.os.Looper
import androidx.activity.result.contract.ActivityResultContracts
import androidx.appcompat.app.AppCompatActivity
import androidx.core.content.ContextCompat
import androidx.core.view.children
import androidx.lifecycle.lifecycleScope
import androidx.recyclerview.widget.LinearLayoutManager
import coil.load
import com.google.android.material.chip.Chip
import com.anydrop.food.R
import com.anydrop.food.data.CartManager
import com.anydrop.food.data.FavoritesManager
import com.anydrop.food.databinding.ActivityRestaurantDetailBinding
import com.anydrop.food.network.ApiClient
import com.anydrop.food.network.RestaurantDetail
import com.anydrop.food.ui.cart.CartBottomSheetFragment
import com.anydrop.food.ui.common.InAppNotifier
import com.anydrop.food.ui.itemdetail.ItemDetailBottomSheetFragment
import kotlinx.coroutines.launch
import java.util.Locale

class RestaurantDetailActivity : AppCompatActivity() {

    // Cart items get added/removed from this screen's menu list — force an
    // immediate (non-debounced) sync on leaving, so "add item then switch
    // apps" isn't lost waiting on CartSyncManager's 1s debounce timer.
    override fun onStop() {
        super.onStop()
        com.anydrop.food.data.CartSyncManager.syncNow(this)
    }

    companion object {
        const val EXTRA_RESTAURANT_ID = "extra_restaurant_id"
        const val EXTRA_RESTAURANT_NAME = "extra_restaurant_name"
        const val EXTRA_RESTAURANT_COVER_URL = "extra_restaurant_cover_url"
        // ETA isn't part of the restaurants/menu.php restaurant block (ETA/distance are only
        // computed by the list endpoints against the customer's location), so Home still passes
        // it through as an extra when available; everything else self-fetches via getMenu().
        const val EXTRA_ETA_MINUTES = "extra_eta_minutes"
    }

    private lateinit var binding: ActivityRestaurantDetailBinding
    private val api by lazy { ApiClient.create(this) }
    private var restaurantId: Int = 0
    private var restaurantName: String = ""
    private lateinit var adapter: MenuAdapter
    private var isSaved: Boolean = false
    // Category name -> its header row's adapter position, built once per
    // menu load in buildCategoryTabs(); reused by both the horizontal chip
    // tab bar and the floating Menu jump button's popup list (§2.5) so the
    // two "jump to category" entry points always stay in sync.
    private var categoryPositions: List<Pair<String, Int>> = emptyList()

    // features.md §1 — the veg-mode-filtered categories as fetched, kept
    // separately from whatever's currently bound to the adapter so
    // MenuFiltersBottomSheet's filter/sort can be applied and re-applied
    // (e.g. re-opening the sheet after Apply) without re-fetching.
    private var loadedCategories: List<com.anydrop.food.network.MenuCategory> = emptyList()
    private var restaurantIsVegOnly: Boolean = false
    private var activeFilters: MenuFilters = MenuFilters()

    // features.md §6 — resolved lazily, non-blocking (see resolveLocationThenLoad()).
    // Kept here so a resolved fix survives the silent re-fetch / rebind and isn't
    // re-requested on things like onResume().
    private var resolvedLat: Double? = null
    private var resolvedLng: Double? = null

    // Bug 3 — dish id to scroll-to-and-glow once the menu is loaded and
    // bound, from a shared item link. Cleared after the first successful
    // attempt so a later unrelated re-submit (filters, GPS re-fetch) never
    // re-triggers the scroll/glow.
    private var pendingHighlightItemId: Int? = null

    private val locationPermissionLauncher =
        registerForActivityResult(ActivityResultContracts.RequestPermission()) { granted ->
            if (granted) fetchCurrentLocation()
            // Denied: no toast here — the screen already rendered without
            // distance/ETA via the immediate no-lat/lng loadMenu() call, so
            // this is a silent no-op rather than an interruption.
        }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityRestaurantDetailBinding.inflate(layoutInflater)
        setContentView(binding.root)

        // Bug 3 — a shared dish link (anydrop://item?rid=..&iid=.., see
        // ItemDetailBottomSheetFragment.buildShareLink()) opens this
        // Activity via ACTION_VIEW instead of the normal "tap a
        // restaurant card" extras. pendingHighlightItemId is consumed once
        // the menu finishes loading (see applyFiltersAndSubmit()) to
        // scroll to + glow that dish.
        val deepLinkUri = intent.data?.takeIf { intent.action == android.content.Intent.ACTION_VIEW }
        if (deepLinkUri != null) {
            restaurantId = deepLinkUri.getQueryParameter("rid")?.toIntOrNull() ?: 0
            pendingHighlightItemId = deepLinkUri.getQueryParameter("iid")?.toIntOrNull()
        } else {
            restaurantId = intent.getIntExtra(EXTRA_RESTAURANT_ID, 0)
        }
        val name = intent.getStringExtra(EXTRA_RESTAURANT_NAME).orEmpty()
        restaurantName = name
        binding.detailRestaurantName.text = name
        val coverUrl = intent.getStringExtra(EXTRA_RESTAURANT_COVER_URL)
        if (!coverUrl.isNullOrBlank()) {
            binding.coverImage.load(coverUrl) {
                placeholder(R.drawable.ic_restaurant)
                error(R.drawable.ic_restaurant)
                crossfade(true)
                listener(
                    onSuccess = { _, _ ->
                        binding.coverImage.imageTintList = null
                        binding.coverImage.setPadding(0, 0, 0, 0)
                    }
                )
            }
        }

        binding.btnBack.setOnClickListener { finish() }

        adapter = MenuAdapter(
            restaurantId,
            restaurantName,
            lifecycleScope,
            onDishClick = { item ->
                val sheet = ItemDetailBottomSheetFragment.newInstance(
                    restaurantId, restaurantName, item, adapter.currentSavedState(item.id)
                )
                sheet.onAdded = {
                    // Bug fix: the card's own qty stepper used to only
                    // refresh via updateCartButton() (the floating "View
                    // Cart" pill), never itself — so it could show a
                    // different quantity than what the sheet had just set.
                    adapter.refreshCartUi(item.id)
                    updateCartButton()
                }
                sheet.onSaveStateChanged = { newState -> adapter.setSavedState(item.id, newState) }
                sheet.show(supportFragmentManager, "item_detail")
            }
        ) { updateCartButton() }
        binding.menuList.layoutManager = LinearLayoutManager(this)
        binding.menuList.adapter = adapter

        binding.btnViewCart.setOnClickListener {
            // Bug fix (2026-08-10) — same stale-badge issue as Home's cart
            // button; see CartBottomSheetFragment.onCartChanged kdoc.
            CartBottomSheetFragment().apply {
                onCartChanged = { updateCartButton() }
            }.show(supportFragmentManager, "cart")
        }

        binding.detailBookmark.setOnClickListener {
            FavoritesManager.toggle(
                context = this,
                scope = lifecycleScope,
                favoriteType = "restaurant",
                favoriteId = restaurantId,
                currentlySaved = isSaved,
                onResult = { newState ->
                    isSaved = newState
                    binding.detailBookmark.setImageResource(
                        if (newState) R.drawable.ic_bookmark_filled else R.drawable.ic_bookmark_outline
                    )
                }
            )
        }

        // features.md §2 — no public per-restaurant deep link exists yet, so
        // this shares a plain-text summary via the system share sheet, same
        // approach as ItemDetailBottomSheetFragment's new share button.
        binding.detailShare.setOnClickListener {
            val shareText = getString(R.string.restaurant_share_text_format, restaurantName)
            val intent = android.content.Intent(android.content.Intent.ACTION_SEND).apply {
                type = "text/plain"
                putExtra(android.content.Intent.EXTRA_TEXT, shareText)
            }
            startActivity(android.content.Intent.createChooser(intent, getString(R.string.restaurant_share_chooser_title)))
        }

        binding.btnOpenFilters.setOnClickListener { openFiltersSheet() }

        // features.md §6 — menu loads immediately without lat/lng so the
        // screen renders fast; resolveLocationThenLoad() kicks off GPS
        // resolution in parallel and silently re-fetches once (if) it lands.
        loadMenu()
        resolveLocationThenLoad()
        updateCartButton()
    }

    override fun onResume() {
        super.onResume()
        updateCartButton()
    }

    private fun loadMenu() {
        lifecycleScope.launch {
            try {
                val response = api.getMenu(restaurantId, resolvedLat, resolvedLng)
                val restaurant = response.body()?.data?.restaurant
                if (restaurant != null) {
                    bindRestaurantDetail(restaurant)
                }
                var categories = response.body()?.data?.categories ?: emptyList()
                if (com.anydrop.food.data.VegModeManager.isVegOnly(this@RestaurantDetailActivity)) {
                    categories = categories
                        .map { it.copy(items = it.items.filter { item -> item.isVeg }) }
                        .filter { it.items.isNotEmpty() }
                }
                loadedCategories = categories
                restaurantIsVegOnly = restaurant?.isVegOnly ?: false
                // Any filters/sort chosen before this (re)load — e.g. pull-to-refresh isn't
                // wired on this screen today, but keeps behavior correct if that's ever added —
                // stay applied rather than silently resetting. applyFiltersAndSubmit() also
                // rebuilds the category tabs from the *filtered* list so the jump-to-category
                // positions never drift from what the adapter is actually showing.
                applyFiltersAndSubmit()
            } catch (e: Exception) {
                binding.menuEmptyState.visibility = android.view.View.VISIBLE
                InAppNotifier.show(this@RestaurantDetailActivity, "Couldn't load the menu.", InAppNotifier.Type.ERROR)
            }
        }
    }

    /**
     * features.md §6 — non-blocking GPS resolve, mirroring HomeActivity's
     * fetchCurrentLocation()/onLocationResolved() pattern (permission check,
     * last-known-location first, single-update fallback). Unlike Home's
     * address-editor flow this never blocks or shows a toast on failure —
     * the menu already loaded without lat/lng, so a missing/denied fix just
     * means the distance/ETA row and "N offers" strip stay hidden.
     */
    private fun resolveLocationThenLoad() {
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
        if (!hasGps && !hasNetwork) return // silent — see kdoc above

        val provider = if (hasGps) LocationManager.GPS_PROVIDER else LocationManager.NETWORK_PROVIDER
        try {
            val lastKnown = locationManager.getLastKnownLocation(provider)
            if (lastKnown != null) {
                onLocationResolved(lastKnown)
            } else {
                locationManager.requestSingleUpdate(provider, { location -> onLocationResolved(location) }, Looper.getMainLooper())
            }
        } catch (e: SecurityException) {
            // silent — see kdoc above
        }
    }

    private fun onLocationResolved(location: Location) {
        resolvedLat = location.latitude
        resolvedLng = location.longitude
        // Silent re-fetch: cheap (single GET), and applyFiltersAndSubmit()'s
        // resubmit is already idempotent, so re-binding a second time is
        // low-risk — same "just re-fetch, it's cheap" spirit as onStop()'s
        // cart sync.
        loadMenu()
    }

    /**
     * features.md §1 — opens the Filters and Sorting sheet with the current
     * menu (flattened across categories, veg-mode already applied upstream
     * in loadMenu()) and the currently active selection so re-opening the
     * sheet shows what's already applied.
     */
    private fun openFiltersSheet() {
        val flatItems = loadedCategories.flatMap { it.items }
        val sheet = MenuFiltersBottomSheet.newInstance(flatItems, restaurantIsVegOnly, activeFilters)
        sheet.onApply = { filters ->
            activeFilters = filters
            applyFiltersAndSubmit()
        }
        sheet.show(supportFragmentManager, "menu_filters")
    }

    /**
     * Applies [activeFilters] on top of [loadedCategories]: dietary/top-pick
     * chips filter items within each category (dropping categories left with
     * none), price sort re-orders items within each category rather than
     * flattening the list — keeps the existing category-header structure
     * intact instead of a bigger restructure for this pass. Also re-syncs
     * the category tab bar / jump button against whatever's actually shown,
     * so jumpToCategory() positions never drift from the adapter's rows.
     */
    private fun applyFiltersAndSubmit() {
        var result = loadedCategories.map { category ->
            category.copy(items = category.items.filter { activeFilters.matches(it) })
        }
        if (activeFilters.sortOption != MenuSortOption.NONE) {
            result = result.map { category ->
                val sortedItems = when (activeFilters.sortOption) {
                    MenuSortOption.PRICE_LOW_HIGH -> category.items.sortedBy { it.price }
                    MenuSortOption.PRICE_HIGH_LOW -> category.items.sortedByDescending { it.price }
                    MenuSortOption.NONE -> category.items
                }
                category.copy(items = sortedItems)
            }
        }
        result = result.filter { it.items.isNotEmpty() }

        adapter.submit(result)
        buildCategoryTabs(result)
        binding.menuEmptyState.visibility = if (result.isNotEmpty()) android.view.View.GONE else android.view.View.VISIBLE

        // Filters-pill visual state — highlight it while any non-default
        // filter/sort is active, same background-toggle convention used
        // everywhere else in this codebase (HomeActivity's chips, the sort
        // pills inside the sheet itself).
        binding.btnOpenFilters.setBackgroundResource(
            if (activeFilters.isDefault) R.drawable.bg_chip_unselected else R.drawable.bg_chip_selected
        )

        pendingHighlightItemId?.let { itemId ->
            scrollToItemAndGlow(itemId)
            pendingHighlightItemId = null
        }
    }

    /**
     * Bug 3 — the landing behavior for a shared dish link: scroll this
     * screen straight to the dish (same NestedScrollView-offset approach as
     * [jumpToCategory]) and give it a brief glow so it's obvious which one
     * the link pointed at, rather than just leaving the customer to spot it
     * in the full menu themselves.
     */
    private fun scrollToItemAndGlow(itemId: Int) {
        val position = adapter.findItemPosition(itemId) ?: return
        binding.menuList.post {
            val targetView = binding.menuList.findViewHolderForAdapterPosition(position)?.itemView
                ?: return@post
            val location = IntArray(2)
            targetView.getLocationInWindow(location)
            val scrollLocation = IntArray(2)
            binding.scrollContainer.getLocationInWindow(scrollLocation)
            val targetY = binding.scrollContainer.scrollY + (location[1] - scrollLocation[1])
            binding.scrollContainer.smoothScrollTo(0, targetY)

            targetView.postDelayed({ glow(targetView) }, 250)
        }
    }

    /** Warm-orange overlay that fades out over ~1.1s — a `foreground` on the
     * row so the dish's own background/text stay untouched underneath. */
    private fun glow(view: android.view.View) {
        val overlay = android.graphics.drawable.ColorDrawable(getColor(R.color.anydrop_primary_container))
        overlay.alpha = 220
        view.foreground = overlay
        android.animation.ObjectAnimator.ofInt(overlay, "alpha", 220, 0).apply {
            duration = 1100
            startDelay = 350
            addUpdateListener { view.invalidate() }
            addListener(object : android.animation.AnimatorListenerAdapter() {
                override fun onAnimationEnd(animation: android.animation.Animator) {
                    if (view.foreground == overlay) view.foreground = null
                }
            })
            start()
        }
    }

    /**
     * Horizontal tab bar (§2.1) built from the categories already in the menu response
     * (restaurant-owner-defined, already ordered by sort_order server-side — no backend
     * change needed). Tapping a chip jumps the scroll position to that category's header row.
     * Hidden when there's only one non-empty category, since there's nothing to jump between.
     */
    private fun buildCategoryTabs(categories: List<com.anydrop.food.network.MenuCategory>) {
        binding.categoryTabsGroup.removeAllViews()
        var rowPosition = 0
        val positions = mutableListOf<Pair<String, Int>>()
        categories.forEach { category ->
            if (category.items.isNotEmpty()) {
                positions.add(category.name to rowPosition)
                rowPosition += 1 + category.items.size
            }
        }
        categoryPositions = positions

        if (positions.size <= 1) {
            binding.categoryTabsScroll.visibility = android.view.View.GONE
            binding.btnMenuJump.visibility = android.view.View.GONE
            return
        }

        binding.categoryTabsScroll.visibility = android.view.View.VISIBLE
        binding.btnMenuJump.visibility = android.view.View.VISIBLE
        binding.btnMenuJump.setOnClickListener { showCategoryJumpMenu() }
        positions.forEachIndexed { index, (name, headerPosition) ->
            val chip = Chip(this).apply {
                text = name
                isCheckable = true
                isChecked = index == 0
                isClickable = true
                textSize = 13f
                setChipBackgroundColorResource(R.color.surface)
                setTextColor(getColor(R.color.text_primary))
                chipStrokeWidth = 1.5f
                setChipStrokeColorResource(R.color.anydrop_primary)
                setOnClickListener {
                    binding.categoryTabsGroup.children.forEach { view ->
                        (view as? Chip)?.isChecked = (view === it)
                    }
                    jumpToCategory(headerPosition)
                }
            }
            binding.categoryTabsGroup.addView(chip)
        }
    }

    /**
     * Popup list of every menu category, opened from the floating Menu
     * button (§2.5) — lets a customer jump straight to any category
     * without scrolling through the horizontal chip tab bar first, which
     * is the point of this button on a menu long enough to need it.
     * Selecting an entry reuses the same jumpToCategory() the chip tabs
     * use, and keeps the chip row's checked state in sync so both jump
     * entry points always agree on which category is "current".
     */
    private fun showCategoryJumpMenu() {
        val popup = android.widget.PopupMenu(this, binding.btnMenuJump)
        categoryPositions.forEachIndexed { index, (name, _) ->
            popup.menu.add(0, index, index, name)
        }
        popup.setOnMenuItemClickListener { menuItem ->
            val (_, headerPosition) = categoryPositions[menuItem.itemId]
            binding.categoryTabsGroup.children.forEachIndexed { index, view ->
                (view as? Chip)?.isChecked = (index == menuItem.itemId)
            }
            jumpToCategory(headerPosition)
            true
        }
        popup.show()
    }

    private fun jumpToCategory(headerPosition: Int) {
        // menuList has nestedScrollingEnabled=false inside a NestedScrollView, which makes it
        // lay out all its children up front (no internal scrolling of its own) — so the header
        // row's view is already available to look up and scroll the parent NestedScrollView to.
        binding.menuList.post {
            val targetView = binding.menuList.findViewHolderForAdapterPosition(headerPosition)?.itemView
            if (targetView != null) {
                val location = IntArray(2)
                targetView.getLocationInWindow(location)
                val scrollLocation = IntArray(2)
                binding.scrollContainer.getLocationInWindow(scrollLocation)
                val targetY = binding.scrollContainer.scrollY + (location[1] - scrollLocation[1])
                binding.scrollContainer.smoothScrollTo(0, targetY)
            }
        }
    }

    private fun bindRestaurantDetail(restaurant: RestaurantDetail) {
        restaurantName = restaurant.name
        binding.detailRestaurantName.text = restaurant.name
        binding.detailCuisines.text = restaurant.cuisineTags ?: restaurant.address ?: ""
        binding.detailRating.text = String.format(Locale.getDefault(), "%.1f", restaurant.ratingAvg)

        // "By Xk+" ratings-count label (bug 1.1) — formats e.g. 1500 -> "By 1.5K+", 800 -> "By 800+"
        binding.detailRatingCount.text = if (restaurant.ratingCount > 0) {
            val count = restaurant.ratingCount
            val formatted = if (count >= 1000) {
                String.format(Locale.getDefault(), "%.1fK+", count / 1000.0)
            } else {
                "$count+"
            }
            "By $formatted"
        } else {
            ""
        }

        // features.md §6 — Pure Veg badge, driven directly off isVegOnly
        // (not the generic tags list — see detailTagsGroup filtering below).
        binding.detailPureVegRow.visibility =
            if (restaurant.isVegOnly) android.view.View.VISIBLE else android.view.View.GONE

        // features.md §6 — distance/address row. Hidden entirely while
        // distanceKm is null (no GPS fix yet / denied / older cached
        // response) rather than showing a bare address per the reference
        // screenshot's combined "2.7 km · Sardarpura" line.
        val distanceKm = restaurant.distanceKm
        if (distanceKm != null) {
            val locality = restaurant.address?.substringBefore(",")?.trim().orEmpty()
            binding.detailLocationText.text = getString(
                R.string.detail_distance_format,
                String.format(Locale.getDefault(), "%.1f", distanceKm),
                locality
            )
            binding.detailLocationRow.visibility = android.view.View.VISIBLE
        } else {
            binding.detailLocationRow.visibility = android.view.View.GONE
        }

        // features.md §6 — ETA row. restaurant.etaMinutes (from this
        // screen's own GPS-resolved menu.php call) wins over the
        // EXTRA_ETA_MINUTES intent extra (Home's older pre-GPS value)
        // whenever both are present, since it reflects this screen's own
        // location fix rather than whatever Home had at card-render time.
        val intentEta = intent.getIntExtra(EXTRA_ETA_MINUTES, -1).takeIf { it > 0 }
        val eta = restaurant.etaMinutes ?: intentEta
        if (eta != null) {
            binding.detailEta.text = getString(R.string.detail_eta_format, eta)
            binding.detailEtaRow.visibility = android.view.View.VISIBLE
        } else {
            binding.detailEtaRow.visibility = android.view.View.GONE
        }
        binding.detailEtaRow.setOnClickListener {
            InAppNotifier.show(this, getString(R.string.coming_soon), InAppNotifier.Type.INFO)
        }

        if (!restaurant.offerBadgeText.isNullOrBlank()) {
            binding.detailOfferBadge.text = restaurant.offerBadgeText
            binding.detailOfferBadge.visibility = android.view.View.VISIBLE
        } else {
            binding.detailOfferBadge.visibility = android.view.View.GONE
        }

        binding.detailTagsGroup.removeAllViews()
        // features.md §6 — "pure_veg" excluded here since it's already shown
        // by the dedicated badge above (detailPureVegRow); double-showing it
        // as a plain chip too would be redundant.
        val tags = restaurant.tags.orEmpty().filter { it.slug != "pure_veg" }
        if (tags.isNotEmpty()) {
            binding.detailTagsGroup.visibility = android.view.View.VISIBLE
            tags.forEach { tag ->
                // Checkmark-on-light-green-pill restyle (reference
                // screenshot 09) — every remaining tag here is one of the
                // "verified perk" style tags (frequently_reordered,
                // no_packaging_charges; pure_veg already filtered out
                // above), so all of them get the checkmark treatment
                // rather than picking per-slug. Reuses the same
                // ic_check_circle / success_fg / success_bg combo as the
                // "Highly reordered" pill on menu item cards
                // (item_menu_item.xml) instead of introducing new assets.
                val chip = Chip(this).apply {
                    text = tag.name
                    isClickable = false
                    isCheckable = false
                    textSize = 11f
                    chipMinHeight = 28f * resources.displayMetrics.density
                    setChipBackgroundColorResource(R.color.success_bg)
                    setTextColor(getColor(R.color.success_fg))
                    chipStrokeWidth = 0f
                    chipIcon = ContextCompat.getDrawable(this@RestaurantDetailActivity, R.drawable.ic_check_circle)
                    isChipIconVisible = true
                    chipIconTint = android.content.res.ColorStateList.valueOf(getColor(R.color.success_fg))
                    chipIconSize = 14f * resources.displayMetrics.density
                }
                binding.detailTagsGroup.addView(chip)
            }
        } else {
            binding.detailTagsGroup.visibility = android.view.View.GONE
        }

        // features.md §6 — offer strip, e.g. "3 offers ⌄". Opens
        // OffersBottomSheetFragment with the same offers list on tap.
        val offers = restaurant.offers.orEmpty()
        if (offers.isNotEmpty()) {
            binding.detailOffersDivider.visibility = android.view.View.VISIBLE
            binding.detailOffersStrip.visibility = android.view.View.VISIBLE
            binding.detailOffersCount.text = getString(
                R.string.detail_offers_count_format,
                offers.size,
                if (offers.size == 1) "" else "s"
            )
            binding.detailOffersStrip.setOnClickListener {
                OffersBottomSheetFragment.newInstance(offers).show(supportFragmentManager, "offers")
            }
        } else {
            binding.detailOffersDivider.visibility = android.view.View.GONE
            binding.detailOffersStrip.visibility = android.view.View.GONE
        }

        isSaved = restaurant.isSaved
        binding.detailBookmark.setImageResource(
            if (isSaved) R.drawable.ic_bookmark_filled else R.drawable.ic_bookmark_outline
        )

        if (!restaurant.coverUrl.isNullOrBlank()) {
            binding.coverImage.load(restaurant.coverUrl) {
                placeholder(R.drawable.ic_restaurant)
                error(R.drawable.ic_restaurant)
                crossfade(true)
                listener(
                    onSuccess = { _, _ ->
                        binding.coverImage.imageTintList = null
                        binding.coverImage.setPadding(0, 0, 0, 0)
                    }
                )
            }
        }
    }

    private fun updateCartButton() {
        val count = CartManager.getCart(restaurantId)?.totalItemCount() ?: 0
        if (count > 0) {
            binding.btnViewCart.visibility = android.view.View.VISIBLE
            binding.btnViewCart.text = "View Cart · $count item${if (count > 1) "s" else ""}"
        } else {
            binding.btnViewCart.visibility = android.view.View.GONE
        }
    }
}
