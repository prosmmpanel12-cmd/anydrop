package com.anydrop.food.ui.itemdetail

import android.os.Bundle
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import androidx.core.widget.doAfterTextChanged
import androidx.lifecycle.lifecycleScope
import coil.load
import com.google.android.material.bottomsheet.BottomSheetDialogFragment
import com.google.android.material.chip.Chip
import com.google.gson.Gson
import com.anydrop.food.R
import com.anydrop.food.data.CartManager
import com.anydrop.food.data.CartSyncManager
import com.anydrop.food.data.FavoritesManager
import com.anydrop.food.databinding.FragmentItemDetailBinding
import com.anydrop.food.databinding.ItemAddonRowBinding
import com.anydrop.food.network.ApiClient
import com.anydrop.food.network.MenuItem
import com.anydrop.food.ui.restaurant.RestaurantDetailActivity

/**
 * §2.6 / 1.9 — the dish-customization sheet. This is the tap target for
 * every dish-card body across the app now (Restaurant Detail's own menu,
 * Home's Popular row, Search results) — see docs/Status.md's "part 14"
 * entry for the full history of why this didn't exist until now.
 *
 * Scope, matching what's actually wired up server-side (see
 * `docs/07_Phase_3.7_Bug_Tracker.md` §2.6 vs. what's actually built):
 * - Addons are a **flat checkbox list**, not grouped with a max-select cap
 *   — `menu_item_addon_groups` was never built, only plain `menu_item_addons`
 *   exists. Flagged clearly in CartManager.kt's kdoc too.
 * - `item.addons` is only populated when this sheet is opened from
 *   Restaurant Detail's own menu (`MenuItem` there carries real `addons`).
 *   Sheets opened from Home's Popular row or Search results pass a
 *   `MenuItem` built via `PopularItem.toMenuItem()`/`SearchItem.toMenuItem()`,
 *   which default `addons` to empty — those sheets show qty + cooking
 *   request only, no addon checkboxes, and [noAddonsNote] explains why.
 *
 * [MenuItem] is a plain (non-Parcelable) data class — serialized to JSON via
 * Gson into the fragment's arguments Bundle, same "plain data class through
 * a Bundle" approach every other sheet in this codebase already uses for
 * its own simple args (see AddressEditorBottomSheet.newInstance()).
 */
class ItemDetailBottomSheetFragment private constructor() : BottomSheetDialogFragment() {

    companion object {
        private const val ARG_RESTAURANT_ID = "restaurant_id"
        private const val ARG_RESTAURANT_NAME = "restaurant_name"
        private const val ARG_ITEM_JSON = "item_json"
        private const val ARG_INITIAL_SAVED = "initial_saved"
        private const val COOKING_REQUEST_MAX_LEN = 200

        /** Scheme/host for per-dish share links (bug 3) — handled by
         * RestaurantDetailActivity's own intent-filter (see AndroidManifest),
         * so tapping a shared link opens straight to this dish, scrolled
         * into view and briefly glowing. No real web domain behind this —
         * it's an app-only deep link, same spirit as most food-delivery
         * apps' "open in app" share links. */
        private const val SHARE_LINK_SCHEME = "anydrop"
        private const val SHARE_LINK_HOST = "item"

        fun buildShareLink(restaurantId: Int, itemId: Int): String =
            "$SHARE_LINK_SCHEME://$SHARE_LINK_HOST?rid=$restaurantId&iid=$itemId"

        // Quick-select presets (§2.6) — tapping one appends its text into
        // the cooking-request field rather than replacing it, so a customer
        // can combine "Less spicy" + a typed note.
        private val QUICK_SELECT_PRESETS = listOf(
            "No onion or garlic",
            "Less spicy",
            "Extra spicy",
            "No cutlery"
        )

        /**
         * [currentSavedOverride] lets the caller pass in whatever bookmark
         * state its own list adapter is currently showing for this item
         * (e.g. `MenuAdapter.currentSavedState(item.id)`), which may be
         * newer than `item.isSaved`'s snapshot from whenever the list was
         * last fetched — otherwise re-opening this sheet after saving from
         * the card (or vice versa) shows a stale icon. Falls back to
         * `item.isSaved` when the caller doesn't have a fresher value.
         */
        fun newInstance(
            restaurantId: Int,
            restaurantName: String,
            item: MenuItem,
            currentSavedOverride: Boolean? = null
        ): ItemDetailBottomSheetFragment {
            val sheet = ItemDetailBottomSheetFragment()
            sheet.arguments = Bundle().apply {
                putInt(ARG_RESTAURANT_ID, restaurantId)
                putString(ARG_RESTAURANT_NAME, restaurantName)
                putString(ARG_ITEM_JSON, Gson().toJson(item))
                putBoolean(ARG_INITIAL_SAVED, currentSavedOverride ?: item.isSaved)
            }
            return sheet
        }
    }

    /** Set by the caller before showing — called whenever this sheet's
     * "Add item"/"Remove item" button changes the cart (added, quantity
     * updated, or removed by dragging the stepper to 0), so the host
     * screen (Restaurant Detail's own card + its floating cart button,
     * Home's cart badge) can refresh itself. Same exposed-var pattern as
     * AddressEditorBottomSheet.onSaved. */
    var onAdded: (() -> Unit)? = null

    /** Set by the caller before showing — called whenever the bookmark is
     * toggled *inside* this sheet, so the card that opened it can update
     * its own bookmark icon immediately instead of only fixing itself up
     * on the next full rebind (bug: "save karke close karo to bahar wapis
     * unsaved dikhta hai"). */
    var onSaveStateChanged: ((Boolean) -> Unit)? = null

    private var _binding: FragmentItemDetailBinding? = null
    private val binding get() = _binding!!

    private lateinit var item: MenuItem
    private var restaurantId: Int = 0
    private var restaurantName: String = ""
    private var quantity: Int = 1
    private var isSaved: Boolean = false
    private val selectedAddonIds = mutableSetOf<Int>()

    override fun onCreateView(
        inflater: LayoutInflater,
        container: ViewGroup?,
        savedInstanceState: Bundle?
    ): View {
        _binding = FragmentItemDetailBinding.inflate(inflater, container, false)
        return binding.root
    }

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        super.onViewCreated(view, savedInstanceState)

        restaurantId = requireArguments().getInt(ARG_RESTAURANT_ID)
        restaurantName = requireArguments().getString(ARG_RESTAURANT_NAME).orEmpty()
        item = Gson().fromJson(requireArguments().getString(ARG_ITEM_JSON), MenuItem::class.java)

        // Opening the sheet on a dish that's already in this restaurant's
        // cart pre-fills its existing customization/quantity, so re-tapping
        // a dish to tweak it doesn't silently reset what was already chosen.
        val existingLine = CartManager.getCart(restaurantId)?.lines?.get(item.id)
        quantity = existingLine?.quantity ?: 1
        selectedAddonIds.addAll(existingLine?.addonIds.orEmpty())

        binding.itemDetailClose.setOnClickListener { dismiss() }

        bindItemHeader()
        bindBookmarkAndShare()
        buildAddonRows()
        buildQuickSelectChips()
        bindCookingRequest(existingLine?.specialInstructions)
        bindQtyStepper()
        bindViewFullMenuLink()

        binding.btnAddItem.setOnClickListener { addToCart() }

        refreshTotal()
    }

    private fun bindItemHeader() {
        binding.itemDetailName.text = item.name
        binding.itemDetailDescription.text = item.description ?: ""
        binding.itemDetailDescription.visibility =
            if (item.description.isNullOrBlank()) View.GONE else View.VISIBLE
        binding.itemDetailVegBadge.setBackgroundResource(
            if (item.isVeg) R.drawable.bg_badge_veg else R.drawable.bg_badge_nonveg
        )
        if (!item.imageUrl.isNullOrBlank()) {
            binding.itemDetailImage.load(ApiClient.baseUrlForStaticFiles(requireContext()) + item.imageUrl) {
                placeholder(R.drawable.ic_restaurant)
                error(R.drawable.ic_restaurant)
                crossfade(true)
            }
        } else {
            binding.itemDetailImage.setImageResource(R.drawable.ic_restaurant)
        }
    }

    /** features.md §2 — bookmark toggles via [FavoritesManager] (same
     * "menu_item" favoriteType every other dish-card bookmark in the app
     * uses); share opens the system share sheet with a plain-text summary
     * since there's no public per-dish deep link to share instead. */
    private fun bindBookmarkAndShare() {
        isSaved = requireArguments().getBoolean(ARG_INITIAL_SAVED, item.isSaved)
        binding.itemDetailBookmark.setImageResource(
            if (isSaved) R.drawable.ic_bookmark_filled else R.drawable.ic_bookmark_outline
        )
        binding.itemDetailBookmark.setOnClickListener {
            FavoritesManager.toggle(
                context = requireContext(),
                scope = lifecycleScope,
                favoriteType = "menu_item",
                favoriteId = item.id,
                currentlySaved = isSaved,
                onResult = { newState ->
                    isSaved = newState
                    binding.itemDetailBookmark.setImageResource(
                        if (newState) R.drawable.ic_bookmark_filled else R.drawable.ic_bookmark_outline
                    )
                    // Push the new state straight back to whichever card
                    // opened this sheet — this is the "save" button fix:
                    // both the in-sheet icon and the outer card icon now
                    // update together instead of the card only catching up
                    // on its next unrelated rebind.
                    onSaveStateChanged?.invoke(newState)
                }
            )
        }
        binding.itemDetailShare.setOnClickListener {
            val link = buildShareLink(restaurantId, item.id)
            val shareText = getString(R.string.item_share_text_format, item.name, restaurantName, link)
            val intent = android.content.Intent(android.content.Intent.ACTION_SEND).apply {
                type = "text/plain"
                putExtra(android.content.Intent.EXTRA_TEXT, shareText)
            }
            startActivity(android.content.Intent.createChooser(intent, getString(R.string.item_share_chooser_title)))
        }
    }

    private fun buildAddonRows() {
        binding.addonsContainer.removeAllViews()
        if (item.addons.isEmpty()) {
            binding.addonsHeading.visibility = View.GONE
            binding.addonsContainer.visibility = View.GONE
            binding.noAddonsNote.visibility = View.VISIBLE
            return
        }
        binding.addonsHeading.visibility = View.VISIBLE
        binding.addonsContainer.visibility = View.VISIBLE
        binding.noAddonsNote.visibility = View.GONE

        item.addons.forEach { addon ->
            val rowBinding = ItemAddonRowBinding.inflate(
                LayoutInflater.from(requireContext()), binding.addonsContainer, false
            )
            rowBinding.addonCheckbox.text = addon.name
            rowBinding.addonPrice.text = "+₹${addon.price.toInt()}"
            rowBinding.addonCheckbox.isChecked = addon.id in selectedAddonIds
            rowBinding.addonCheckbox.setOnCheckedChangeListener { _, isChecked ->
                if (isChecked) selectedAddonIds.add(addon.id) else selectedAddonIds.remove(addon.id)
                refreshTotal()
            }
            binding.addonsContainer.addView(rowBinding.root)
        }
    }

    private fun buildQuickSelectChips() {
        binding.quickSelectChipsContainer.removeAllViews()
        QUICK_SELECT_PRESETS.forEach { preset ->
            val chip = Chip(requireContext()).apply {
                text = preset
                isCheckable = false
                isClickable = true
                textSize = 12f
                setChipBackgroundColorResource(R.color.surface)
                setTextColor(requireContext().getColor(R.color.text_primary))
                chipStrokeWidth = 1.5f
                setChipStrokeColorResource(R.color.anydrop_primary)
                val marginPx = (6 * resources.displayMetrics.density).toInt()
                (layoutParams as? android.widget.LinearLayout.LayoutParams)?.setMargins(0, 0, marginPx, 0)
                setOnClickListener { appendCookingRequest(preset) }
            }
            val params = android.widget.LinearLayout.LayoutParams(
                android.widget.LinearLayout.LayoutParams.WRAP_CONTENT,
                android.widget.LinearLayout.LayoutParams.WRAP_CONTENT
            )
            params.marginEnd = (6 * resources.displayMetrics.density).toInt()
            binding.quickSelectChipsContainer.addView(chip, params)
        }
    }

    private fun appendCookingRequest(preset: String) {
        val current = binding.cookingRequestInput.text?.toString().orEmpty()
        val combined = if (current.isBlank()) {
            preset
        } else if (current.contains(preset, ignoreCase = true)) {
            return // already present — don't duplicate on a second tap
        } else {
            "$current, $preset"
        }
        binding.cookingRequestInput.setText(combined.take(COOKING_REQUEST_MAX_LEN))
        binding.cookingRequestInput.setSelection(binding.cookingRequestInput.text?.length ?: 0)
    }

    private fun bindCookingRequest(existingNote: String?) {
        binding.cookingRequestInput.setText(existingNote.orEmpty())
        updateCookingRequestCounter()
        binding.cookingRequestInput.doAfterTextChanged { updateCookingRequestCounter() }
    }

    private fun updateCookingRequestCounter() {
        val len = binding.cookingRequestInput.text?.length ?: 0
        binding.cookingRequestCounter.text = getString(
            R.string.item_cooking_request_counter_format, len, COOKING_REQUEST_MAX_LEN
        )
    }

    private fun bindQtyStepper() {
        binding.itemDetailQty.text = quantity.toString()
        binding.itemDetailQtyDecrease.setOnClickListener {
            // Bug fix: 1 -> 0 is how you un-select a dish from inside this
            // sheet (mirrors the outer card's stepper, which already
            // disappears back into an "Add" button at 0) — used to be
            // clamped at a minimum of 1, so there was no way to remove the
            // dish without backing out and using a different control.
            if (quantity > 0) {
                quantity -= 1
                binding.itemDetailQty.text = quantity.toString()
                refreshTotal()
            }
        }
        binding.itemDetailQtyIncrease.setOnClickListener {
            quantity += 1
            binding.itemDetailQty.text = quantity.toString()
            refreshTotal()
        }
    }

    private fun bindViewFullMenuLink() {
        binding.itemDetailViewFullMenu.text =
            getString(R.string.item_view_full_menu_format, restaurantName)
        binding.itemDetailViewFullMenu.setOnClickListener {
            startActivity(
                android.content.Intent(requireContext(), RestaurantDetailActivity::class.java)
                    .putExtra(RestaurantDetailActivity.EXTRA_RESTAURANT_ID, restaurantId)
                    .putExtra(RestaurantDetailActivity.EXTRA_RESTAURANT_NAME, restaurantName)
            )
            dismiss()
        }
    }

    /** unit price (base + selected addons), shown both as the per-unit line
     * and multiplied by quantity into the sticky Add button's live total. */
    private fun unitPrice(): Double =
        item.price + item.addons.filter { it.id in selectedAddonIds }.sumOf { it.price }

    private fun refreshTotal() {
        val unit = unitPrice()
        binding.itemDetailPrice.text = "₹${unit.toInt()}"
        if (quantity <= 0) {
            // Dragged down to 0 — button now offers to remove the dish
            // from the cart instead of showing a ₹0 "Add item" total.
            binding.btnAddItem.text = getString(R.string.item_remove_button)
        } else {
            val total = unit * quantity
            binding.btnAddItem.text = getString(R.string.item_add_button_format, total.toInt())
        }
    }

    private fun addToCart() {
        if (quantity <= 0) {
            CartManager.removeLine(restaurantId, item.id)
        } else {
            val note = binding.cookingRequestInput.text?.toString()?.trim()?.take(COOKING_REQUEST_MAX_LEN)
            CartManager.setCustomized(
                restaurantId = restaurantId,
                item = item,
                quantity = quantity,
                addonIds = selectedAddonIds.toList(),
                specialInstructions = note,
                restaurantName = restaurantName
            )
        }
        CartSyncManager.scheduleSync(requireContext())
        onAdded?.invoke()
        dismiss()
    }

    override fun onDestroyView() {
        super.onDestroyView()
        _binding = null
    }
}
