package com.anydrop.food.ui.restaurant

import android.os.Bundle
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import com.google.android.material.bottomsheet.BottomSheetDialogFragment
import com.google.gson.Gson
import com.google.gson.reflect.TypeToken
import com.anydrop.food.R
import com.anydrop.food.databinding.FragmentMenuFiltersBinding
import com.anydrop.food.network.MenuItem

enum class MenuSortOption { NONE, PRICE_LOW_HIGH, PRICE_HIGH_LOW }

/**
 * Filter/sort selection made in [MenuFiltersBottomSheet], applied by the
 * caller (RestaurantDetailActivity) against its own already-fetched menu —
 * see [MenuFilters.apply] below, kept next to the state it operates on so
 * the two never drift apart.
 */
data class MenuFilters(
    val sortOption: MenuSortOption = MenuSortOption.NONE,
    val highlyReordered: Boolean = false,
    val spicy: Boolean = false,
    val kidsChoice: Boolean = false
) {
    val isDefault: Boolean
        get() = sortOption == MenuSortOption.NONE && !highlyReordered && !spicy && !kidsChoice

    /** Whether a single item survives this filter's checkbox-style chips
     * (sort is applied separately, per category, by the caller). */
    fun matches(item: MenuItem): Boolean {
        if (highlyReordered && !item.isBestseller) return false
        if (spicy && !item.isSpicy) return false
        if (kidsChoice && !item.isKidsChoice) return false
        return true
    }
}

/**
 * features.md §1 — "Filters and Sorting" sheet opened from the menu
 * screen's "Filters" pill. Operates entirely on the already-fetched menu
 * (client-side, no new API — per features.md's own note), so it needs the
 * flat item list purely to compute a live "Apply (N)" result count as chips
 * are toggled; the actual filtering/sorting of the visible list happens
 * back in RestaurantDetailActivity once Apply is tapped.
 *
 * [MenuItem] serialized to JSON in the args Bundle — same "plain data class
 * through a Bundle" approach ItemDetailBottomSheetFragment already uses.
 */
class MenuFiltersBottomSheet private constructor() : BottomSheetDialogFragment() {

    companion object {
        private const val ARG_ITEMS_JSON = "items_json"
        private const val ARG_IS_PURE_VEG = "is_pure_veg"
        private const val ARG_SORT = "sort"
        private const val ARG_HIGHLY_REORDERED = "highly_reordered"
        private const val ARG_SPICY = "spicy"
        private const val ARG_KIDS_CHOICE = "kids_choice"

        fun newInstance(items: List<MenuItem>, isPureVeg: Boolean, current: MenuFilters): MenuFiltersBottomSheet {
            val sheet = MenuFiltersBottomSheet()
            sheet.arguments = Bundle().apply {
                putString(ARG_ITEMS_JSON, Gson().toJson(items))
                putBoolean(ARG_IS_PURE_VEG, isPureVeg)
                putString(ARG_SORT, current.sortOption.name)
                putBoolean(ARG_HIGHLY_REORDERED, current.highlyReordered)
                putBoolean(ARG_SPICY, current.spicy)
                putBoolean(ARG_KIDS_CHOICE, current.kidsChoice)
            }
            return sheet
        }
    }

    /** Set by the caller before showing — invoked with the chosen filters on Apply. */
    var onApply: ((MenuFilters) -> Unit)? = null

    private var _binding: FragmentMenuFiltersBinding? = null
    private val binding get() = _binding!!

    private var allItems: List<MenuItem> = emptyList()
    private var state = MenuFilters()

    override fun onCreateView(
        inflater: LayoutInflater,
        container: ViewGroup?,
        savedInstanceState: Bundle?
    ): View {
        _binding = FragmentMenuFiltersBinding.inflate(inflater, container, false)
        return binding.root
    }

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        super.onViewCreated(view, savedInstanceState)

        val args = requireArguments()
        val itemsJson = args.getString(ARG_ITEMS_JSON)
        allItems = if (!itemsJson.isNullOrBlank()) {
            val type = object : TypeToken<List<MenuItem>>() {}.type
            Gson().fromJson(itemsJson, type)
        } else {
            emptyList()
        }

        state = MenuFilters(
            sortOption = runCatching { MenuSortOption.valueOf(args.getString(ARG_SORT) ?: "NONE") }
                .getOrDefault(MenuSortOption.NONE),
            highlyReordered = args.getBoolean(ARG_HIGHLY_REORDERED),
            spicy = args.getBoolean(ARG_SPICY),
            kidsChoice = args.getBoolean(ARG_KIDS_CHOICE)
        )

        binding.pureVegNote.visibility = if (args.getBoolean(ARG_IS_PURE_VEG)) View.VISIBLE else View.GONE

        binding.btnCloseFilters.setOnClickListener { dismiss() }

        binding.sortPriceLowHigh.setOnClickListener {
            state = state.copy(
                sortOption = if (state.sortOption == MenuSortOption.PRICE_LOW_HIGH) MenuSortOption.NONE else MenuSortOption.PRICE_LOW_HIGH
            )
            refreshUi()
        }
        binding.sortPriceHighLow.setOnClickListener {
            state = state.copy(
                sortOption = if (state.sortOption == MenuSortOption.PRICE_HIGH_LOW) MenuSortOption.NONE else MenuSortOption.PRICE_HIGH_LOW
            )
            refreshUi()
        }
        binding.chipHighlyReordered.setOnClickListener {
            state = state.copy(highlyReordered = !state.highlyReordered)
            refreshUi()
        }
        binding.chipSpicy.setOnClickListener {
            state = state.copy(spicy = !state.spicy)
            refreshUi()
        }
        binding.chipKidsChoice.setOnClickListener {
            state = state.copy(kidsChoice = !state.kidsChoice)
            refreshUi()
        }

        binding.btnClearAll.setOnClickListener {
            state = MenuFilters()
            refreshUi()
        }

        binding.btnApplyFilters.setOnClickListener {
            onApply?.invoke(state)
            dismiss()
        }

        refreshUi()
    }

    private fun refreshUi() {
        setToggleState(binding.sortPriceLowHigh, state.sortOption == MenuSortOption.PRICE_LOW_HIGH)
        setToggleState(binding.sortPriceHighLow, state.sortOption == MenuSortOption.PRICE_HIGH_LOW)
        setToggleState(binding.chipHighlyReordered, state.highlyReordered)
        setToggleState(binding.chipSpicy, state.spicy)
        setToggleState(binding.chipKidsChoice, state.kidsChoice)

        val resultCount = allItems.count { state.matches(it) }
        binding.btnApplyFilters.text = getString(R.string.filters_apply_format, resultCount)
        binding.btnApplyFilters.isEnabled = resultCount > 0
    }

    private fun setToggleState(view: android.widget.TextView, selected: Boolean) {
        // Same convention as HomeActivity's filter chips — background-only
        // toggle, text stays text_primary (bg_chip_selected's fill is a
        // light peach tint, not solid brand color, so white text would be
        // unreadable on it).
        view.setBackgroundResource(if (selected) R.drawable.bg_chip_selected else R.drawable.bg_chip_unselected)
    }

    override fun onDestroyView() {
        super.onDestroyView()
        _binding = null
    }
}
