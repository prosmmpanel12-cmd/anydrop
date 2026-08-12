package com.anydrop.food.ui.search

import android.os.Bundle
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import android.widget.TextView
import com.google.android.material.bottomsheet.BottomSheetDialogFragment
import com.google.android.material.chip.Chip
import com.google.gson.Gson
import com.google.gson.reflect.TypeToken
import com.anydrop.food.R
import com.anydrop.food.databinding.FragmentSearchFiltersBinding
import com.anydrop.food.network.Restaurant
import com.anydrop.food.network.SearchItem

enum class SearchPriceRange { ANY, UNDER_200, R200_400, R400_600, ABOVE_600 }
enum class SearchRatingOption { ANY, R4_0, R4_3, R4_5 }
enum class SearchDistanceOption { ANY, KM_2, KM_5, KM_10 }

/**
 * I3 (docs/features.md Phase I) — Search results filter state: cuisine,
 * price, rating, and nearby/distance. Applied by the caller (HomeActivity)
 * against its own already-fetched search response — same client-side
 * pattern as [com.anydrop.food.ui.restaurant.MenuFilters] (Feature 1),
 * "no new API needed" per features.md's own note, since search.php already
 * returns cuisine_tags/rating_avg/distance_km on restaurants and
 * price/restaurant_rating/distance_km on items.
 *
 * Price only makes sense against an actual dish price, so it's a no-op on
 * restaurant rows (a restaurant card doesn't have one price) — restaurant
 * cards pass the price filter unconditionally. Cuisine, on the other hand,
 * only exists on the restaurant record (`cuisine_tags`), not on
 * [SearchItem] itself — matching an item against cuisine needs the caller
 * to pass in its restaurant's tags (looked up from the same search
 * response's restaurant list). If an item's restaurant isn't in that list
 * (a genuinely cross-restaurant dish match whose restaurant didn't match
 * the query directly) and a cuisine filter is active, the item is excluded
 * rather than guessed at — no data to verify it against.
 */
data class SearchFilters(
    val cuisines: Set<String> = emptySet(),
    val priceRange: SearchPriceRange = SearchPriceRange.ANY,
    val minRating: SearchRatingOption = SearchRatingOption.ANY,
    val maxDistance: SearchDistanceOption = SearchDistanceOption.ANY
) {
    val isDefault: Boolean
        get() = cuisines.isEmpty() && priceRange == SearchPriceRange.ANY &&
            minRating == SearchRatingOption.ANY && maxDistance == SearchDistanceOption.ANY

    private fun priceOk(price: Double): Boolean = when (priceRange) {
        SearchPriceRange.ANY -> true
        SearchPriceRange.UNDER_200 -> price < 200
        SearchPriceRange.R200_400 -> price in 200.0..400.0
        SearchPriceRange.R400_600 -> price in 400.0..600.0
        SearchPriceRange.ABOVE_600 -> price > 600
    }

    private fun ratingOk(rating: Double): Boolean = when (minRating) {
        SearchRatingOption.ANY -> true
        SearchRatingOption.R4_0 -> rating >= 4.0
        SearchRatingOption.R4_3 -> rating >= 4.3
        SearchRatingOption.R4_5 -> rating >= 4.5
    }

    private fun distanceOk(distanceKm: Double?): Boolean = when (maxDistance) {
        SearchDistanceOption.ANY -> true
        SearchDistanceOption.KM_2 -> distanceKm != null && distanceKm <= 2.0
        SearchDistanceOption.KM_5 -> distanceKm != null && distanceKm <= 5.0
        SearchDistanceOption.KM_10 -> distanceKm != null && distanceKm <= 10.0
    }

    private fun cuisineOk(tags: List<String>): Boolean =
        cuisines.isEmpty() || tags.any { it in cuisines }

    fun matches(restaurant: Restaurant): Boolean {
        val tags = restaurant.cuisineTags.orEmpty().split(",").map { it.trim() }.filter { it.isNotBlank() }
        return cuisineOk(tags) && ratingOk(restaurant.ratingAvg) && distanceOk(restaurant.distanceKm)
    }

    fun matches(item: SearchItem, itemCuisineTags: List<String>): Boolean =
        cuisineOk(itemCuisineTags) && priceOk(item.price) &&
            ratingOk(item.restaurantRating) && distanceOk(item.distanceKm)
}

/**
 * The cuisine chip list is dynamic (built from whatever's actually in this
 * search's results, like MenuFiltersBottomSheet's live "Apply (N)" count
 * being computed off the passed-in list) rather than a fixed set, since
 * cuisines vary search to search. [restaurants]/[items] are only used to
 * compute that live result count and the cuisine option list — the actual
 * filtering of the visible list happens back in HomeActivity once Apply is
 * tapped, same division of responsibility as Feature 1's sheet.
 */
class SearchFiltersBottomSheet private constructor() : BottomSheetDialogFragment() {

    companion object {
        private const val ARG_RESTAURANTS_JSON = "restaurants_json"
        private const val ARG_ITEMS_JSON = "items_json"
        private const val ARG_HAS_LOCATION = "has_location"
        private const val ARG_CUISINES = "cuisines"
        private const val ARG_PRICE = "price"
        private const val ARG_RATING = "rating"
        private const val ARG_DISTANCE = "distance"

        fun newInstance(
            restaurants: List<Restaurant>,
            items: List<SearchItem>,
            hasLocation: Boolean,
            current: SearchFilters
        ): SearchFiltersBottomSheet {
            val sheet = SearchFiltersBottomSheet()
            sheet.arguments = Bundle().apply {
                putString(ARG_RESTAURANTS_JSON, Gson().toJson(restaurants))
                putString(ARG_ITEMS_JSON, Gson().toJson(items))
                putBoolean(ARG_HAS_LOCATION, hasLocation)
                putStringArrayList(ARG_CUISINES, ArrayList(current.cuisines))
                putString(ARG_PRICE, current.priceRange.name)
                putString(ARG_RATING, current.minRating.name)
                putString(ARG_DISTANCE, current.maxDistance.name)
            }
            return sheet
        }
    }

    var onApply: ((SearchFilters) -> Unit)? = null

    private var _binding: FragmentSearchFiltersBinding? = null
    private val binding get() = _binding!!

    private var allRestaurants: List<Restaurant> = emptyList()
    private var allItems: List<SearchItem> = emptyList()
    // restaurant_id -> its cuisine tags, so item rows (which don't carry
    // cuisine_tags themselves) can still be checked against the cuisine
    // filter — see SearchFilters kdoc.
    private var cuisineTagsByRestaurantId: Map<Int, List<String>> = emptyMap()
    private var state = SearchFilters()

    override fun onCreateView(
        inflater: LayoutInflater,
        container: ViewGroup?,
        savedInstanceState: Bundle?
    ): View {
        _binding = FragmentSearchFiltersBinding.inflate(inflater, container, false)
        return binding.root
    }

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        super.onViewCreated(view, savedInstanceState)

        val args = requireArguments()
        val gson = Gson()
        val restaurantsJson = args.getString(ARG_RESTAURANTS_JSON)
        allRestaurants = if (!restaurantsJson.isNullOrBlank()) {
            gson.fromJson(restaurantsJson, object : TypeToken<List<Restaurant>>() {}.type)
        } else emptyList()
        val itemsJson = args.getString(ARG_ITEMS_JSON)
        allItems = if (!itemsJson.isNullOrBlank()) {
            gson.fromJson(itemsJson, object : TypeToken<List<SearchItem>>() {}.type)
        } else emptyList()

        cuisineTagsByRestaurantId = allRestaurants.associate { r ->
            r.id to r.cuisineTags.orEmpty().split(",").map { it.trim() }.filter { it.isNotBlank() }
        }

        state = SearchFilters(
            cuisines = args.getStringArrayList(ARG_CUISINES)?.toSet().orEmpty(),
            priceRange = runCatching { SearchPriceRange.valueOf(args.getString(ARG_PRICE) ?: "ANY") }
                .getOrDefault(SearchPriceRange.ANY),
            minRating = runCatching { SearchRatingOption.valueOf(args.getString(ARG_RATING) ?: "ANY") }
                .getOrDefault(SearchRatingOption.ANY),
            maxDistance = runCatching { SearchDistanceOption.valueOf(args.getString(ARG_DISTANCE) ?: "ANY") }
                .getOrDefault(SearchDistanceOption.ANY)
        )

        buildCuisineChips()

        val hasLocation = args.getBoolean(ARG_HAS_LOCATION)
        binding.nearbyPillsRow.visibility = if (hasLocation) View.VISIBLE else View.GONE
        binding.nearbyUnavailableNote.visibility = if (hasLocation) View.GONE else View.VISIBLE

        binding.btnCloseSearchFilters.setOnClickListener { dismiss() }

        binding.nearby2km.setOnClickListener { toggleDistance(SearchDistanceOption.KM_2) }
        binding.nearby5km.setOnClickListener { toggleDistance(SearchDistanceOption.KM_5) }
        binding.nearby10km.setOnClickListener { toggleDistance(SearchDistanceOption.KM_10) }

        binding.priceUnder200.setOnClickListener { togglePrice(SearchPriceRange.UNDER_200) }
        binding.price200To400.setOnClickListener { togglePrice(SearchPriceRange.R200_400) }
        binding.price400To600.setOnClickListener { togglePrice(SearchPriceRange.R400_600) }
        binding.priceAbove600.setOnClickListener { togglePrice(SearchPriceRange.ABOVE_600) }

        binding.rating40.setOnClickListener { toggleRating(SearchRatingOption.R4_0) }
        binding.rating43.setOnClickListener { toggleRating(SearchRatingOption.R4_3) }
        binding.rating45.setOnClickListener { toggleRating(SearchRatingOption.R4_5) }

        binding.btnClearAllSearchFilters.setOnClickListener {
            state = SearchFilters()
            refreshUi()
        }

        binding.btnApplySearchFilters.setOnClickListener {
            onApply?.invoke(state)
            dismiss()
        }

        refreshUi()
    }

    private fun buildCuisineChips() {
        val cuisines = allRestaurants
            .flatMap { it.cuisineTags.orEmpty().split(",").map { tag -> tag.trim() } }
            .filter { it.isNotBlank() }
            .distinct()
            .sorted()

        if (cuisines.isEmpty()) {
            binding.cuisineHeading.visibility = View.GONE
            binding.cuisineChipGroup.visibility = View.GONE
            return
        }

        binding.cuisineHeading.visibility = View.VISIBLE
        binding.cuisineChipGroup.visibility = View.VISIBLE
        binding.cuisineChipGroup.removeAllViews()
        cuisines.forEach { cuisine ->
            val chip = Chip(requireContext()).apply {
                text = cuisine
                isCheckable = true
                isChecked = cuisine in state.cuisines
                textSize = 12f
                setOnCheckedChangeListener { _, isChecked ->
                    state = state.copy(
                        cuisines = if (isChecked) state.cuisines + cuisine else state.cuisines - cuisine
                    )
                    refreshResultCount()
                }
            }
            binding.cuisineChipGroup.addView(chip)
        }
    }

    private fun toggleDistance(option: SearchDistanceOption) {
        state = state.copy(maxDistance = if (state.maxDistance == option) SearchDistanceOption.ANY else option)
        refreshUi()
    }

    private fun togglePrice(option: SearchPriceRange) {
        state = state.copy(priceRange = if (state.priceRange == option) SearchPriceRange.ANY else option)
        refreshUi()
    }

    private fun toggleRating(option: SearchRatingOption) {
        state = state.copy(minRating = if (state.minRating == option) SearchRatingOption.ANY else option)
        refreshUi()
    }

    private fun refreshUi() {
        setToggleState(binding.nearby2km, state.maxDistance == SearchDistanceOption.KM_2)
        setToggleState(binding.nearby5km, state.maxDistance == SearchDistanceOption.KM_5)
        setToggleState(binding.nearby10km, state.maxDistance == SearchDistanceOption.KM_10)

        setToggleState(binding.priceUnder200, state.priceRange == SearchPriceRange.UNDER_200)
        setToggleState(binding.price200To400, state.priceRange == SearchPriceRange.R200_400)
        setToggleState(binding.price400To600, state.priceRange == SearchPriceRange.R400_600)
        setToggleState(binding.priceAbove600, state.priceRange == SearchPriceRange.ABOVE_600)

        setToggleState(binding.rating40, state.minRating == SearchRatingOption.R4_0)
        setToggleState(binding.rating43, state.minRating == SearchRatingOption.R4_3)
        setToggleState(binding.rating45, state.minRating == SearchRatingOption.R4_5)

        refreshResultCount()
    }

    /** Live "Apply (N)" count — restaurants + items that would still show,
     * matching Feature 1's sheet. A restaurant and its own dish rows can
     * both count here (mirrors how HomeActivity's search results already
     * mix restaurant cards and dish rows in one list). */
    private fun refreshResultCount() {
        val restaurantMatches = allRestaurants.count { state.matches(it) }
        val itemMatches = allItems.count { item ->
            val tags = cuisineTagsByRestaurantId[item.restaurantId].orEmpty()
            state.matches(item, tags)
        }
        val resultCount = restaurantMatches + itemMatches
        binding.btnApplySearchFilters.text = getString(R.string.filters_apply_format, resultCount)
        binding.btnApplySearchFilters.isEnabled = resultCount > 0
    }

    private fun setToggleState(view: TextView, selected: Boolean) {
        view.setBackgroundResource(if (selected) R.drawable.bg_chip_selected else R.drawable.bg_chip_unselected)
    }

    override fun onDestroyView() {
        super.onDestroyView()
        _binding = null
    }
}
