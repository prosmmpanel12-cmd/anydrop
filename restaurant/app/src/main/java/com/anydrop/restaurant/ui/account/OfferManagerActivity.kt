package com.anydrop.restaurant.ui.account

import android.view.View
import android.widget.ArrayAdapter
import androidx.appcompat.app.AppCompatActivity
import android.os.Bundle
import androidx.lifecycle.lifecycleScope
import androidx.recyclerview.widget.LinearLayoutManager
import com.anydrop.restaurant.R
import com.anydrop.restaurant.databinding.ActivityOfferManagerBinding
import com.anydrop.restaurant.databinding.DialogAddOfferBinding
import com.anydrop.restaurant.network.ApiClient
import com.anydrop.restaurant.network.FoodTag
import com.anydrop.restaurant.network.MenuItem
import com.anydrop.restaurant.network.OfferCreateBody
import com.anydrop.restaurant.network.OfferUpdateBody
import com.anydrop.restaurant.network.PromoOffer
import com.anydrop.restaurant.ui.common.InAppNotifier
import com.google.android.material.bottomsheet.BottomSheetDialog
import com.google.android.material.chip.Chip
import com.google.android.material.datepicker.MaterialDatePicker
import com.google.android.material.tabs.TabLayout
import com.google.android.material.timepicker.MaterialTimePicker
import com.google.android.material.timepicker.TimeFormat
import kotlinx.coroutines.launch
import java.text.SimpleDateFormat
import java.util.Calendar
import java.util.Locale
import java.util.TimeZone

/**
 * "Offers" screen (doc 20 §1/§12/§14; docs/29 "Not built" item 1,
 * finished session 7 — see docs/restorent/handover_session7.md).
 * Restaurant-created auto-applied promos, distinct from the code-entry
 * Coupon concept CouponManagerActivity manages — see PromoOffer's own
 * kdoc in Models.kt for why the two are separate.
 *
 * Same overall shell as CouponManagerActivity (header/SwipeRefreshLayout/
 * RecyclerView/+Create, BottomSheetDialog add/edit form) plus one thing
 * coupons doesn't need: a 4-tab Active/Scheduled/Expired/Paused bucketed
 * view over a single unfiltered list — offers-list.php returns
 * everything in one call, [bucketFor] does the bucketing client-side,
 * and [loadOffers] keeps the full list in [allOffers] so switching tabs
 * never needs a re-fetch.
 */
class OfferManagerActivity : AppCompatActivity() {

    private lateinit var binding: ActivityOfferManagerBinding
    private val api by lazy { ApiClient.create(this) }
    private lateinit var adapter: OfferAdapter

    /** Full unfiltered list from the last successful loadOffers() call —
     * bucketed per the currently selected tab and handed to the adapter
     * a slice at a time. Re-bucketed (no re-fetch) on every tab switch. */
    private var allOffers: List<PromoOffer> = emptyList()
    private var currentTab = 0 // 0=Active, 1=Scheduled, 2=Expired, 3=Paused — matches offerTabs' TabItem order

    private val dayLabels = linkedMapOf(
        1 to R.string.day_short_mon, 2 to R.string.day_short_tue, 3 to R.string.day_short_wed,
        4 to R.string.day_short_thu, 5 to R.string.day_short_fri, 6 to R.string.day_short_sat,
        7 to R.string.day_short_sun
    )

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityOfferManagerBinding.inflate(layoutInflater)
        setContentView(binding.root)

        adapter = OfferAdapter(
            onEditClick = { offer -> showEditOfferDialog(offer) },
            onPauseResumeClick = { offer -> togglePauseResume(offer) },
            onViewClick = { offer -> showViewOfferDialog(offer) }
        )
        binding.offerList.layoutManager = LinearLayoutManager(this)
        binding.offerList.adapter = adapter

        binding.offerTabs.addOnTabSelectedListener(object : TabLayout.OnTabSelectedListener {
            override fun onTabSelected(tab: TabLayout.Tab) {
                currentTab = tab.position
                renderCurrentTab()
            }
            override fun onTabUnselected(tab: TabLayout.Tab) {}
            override fun onTabReselected(tab: TabLayout.Tab) {}
        })

        binding.btnBack.setOnClickListener { finish() }
        binding.btnAddOffer.setOnClickListener { showAddOfferDialog() }
        binding.swipeRefresh.setOnRefreshListener { loadOffers() }

        loadOffers()
    }

    private fun loadOffers() {
        lifecycleScope.launch {
            try {
                val response = api.getOffers()
                val offers = response.body()?.data?.offers
                binding.swipeRefresh.isRefreshing = false
                if (response.isSuccessful && offers != null) {
                    allOffers = offers
                    renderCurrentTab()
                } else {
                    InAppNotifier.show(this@OfferManagerActivity, getString(R.string.offer_update_failed), InAppNotifier.Type.ERROR)
                }
            } catch (e: Exception) {
                binding.swipeRefresh.isRefreshing = false
                InAppNotifier.show(this@OfferManagerActivity, getString(R.string.offer_update_failed), InAppNotifier.Type.ERROR)
            }
        }
    }

    private fun renderCurrentTab() {
        val today = SimpleDateFormat("yyyy-MM-dd", Locale.US).format(java.util.Date())
        val bucketed = allOffers.filter { bucketFor(it, today) == currentTab }
        adapter.submitList(bucketed)
        binding.emptyState.visibility = if (bucketed.isEmpty()) View.VISIBLE else View.GONE
    }

    /**
     * Active/Scheduled/Expired/Paused bucketing (doc 20 §14). paused or
     * admin-disabled offers always land in the Paused tab regardless of
     * their date range — a disabled offer has no other tab that makes
     * sense for it, and folding it into Paused (rather than adding a
     * 5th tab) keeps the UI matching the mock exactly. Deliberately
     * ignores [PromoOffer.isCurrentlyActive] here — that flag also
     * accounts for start_time/end_time/weekdays (today's happy-hour
     * window), which would make an offer flicker between tabs by time
     * of day; bucketing is about which *tab* an offer belongs in for
     * management purposes, a coarser, date-only question.
     */
    private fun bucketFor(offer: PromoOffer, todayYyyyMmDd: String): Int {
        if (offer.status == "paused" || offer.status == "disabled") return 3 // Paused
        val endDate = offer.endDate
        val startDate = offer.startDate
        return when {
            endDate != null && endDate < todayYyyyMmDd -> 2 // Expired
            startDate != null && startDate > todayYyyyMmDd -> 1 // Scheduled
            else -> 0 // Active
        }
    }

    /**
     * status='disabled' offers can't be resumed by the restaurant at all
     * (offers-update.php rejects with 403 offer_disabled_by_admin) — but
     * per OfferAdapter's own guard the Pause/Resume button is already
     * hidden entirely for those, so this 403 branch is defense-in-depth
     * for a request the UI shouldn't be able to construct, not the
     * primary guard against it.
     */
    private fun togglePauseResume(offer: PromoOffer) {
        val newStatus = if (offer.status == "paused") "active" else "paused"
        lifecycleScope.launch {
            try {
                val response = api.updateOffer(offer.id, OfferUpdateBody(status = newStatus))
                val updated = response.body()?.data?.offer
                if (response.isSuccessful && updated != null) {
                    val successMsg = if (newStatus == "active") R.string.offer_resumed_success else R.string.offer_paused_success
                    InAppNotifier.show(this@OfferManagerActivity, getString(successMsg), InAppNotifier.Type.SUCCESS)
                    loadOffers()
                } else if (response.code() == 403) {
                    InAppNotifier.show(this@OfferManagerActivity, getString(R.string.offer_disabled_by_admin_message), InAppNotifier.Type.ERROR)
                    loadOffers() // resync — the card's own guard is now stale, refresh so it hides the button
                } else {
                    InAppNotifier.show(this@OfferManagerActivity, getString(R.string.offer_pause_resume_failed), InAppNotifier.Type.ERROR)
                }
            } catch (e: Exception) {
                InAppNotifier.show(this@OfferManagerActivity, getString(R.string.offer_pause_resume_failed), InAppNotifier.Type.ERROR)
            }
        }
    }

    // ---- Add ----

    private fun showAddOfferDialog() {
        val dialogBinding = DialogAddOfferBinding.inflate(layoutInflater)
        dialogBinding.offerDialogTitle.text = getString(R.string.offer_add_title)

        setUpOfferTypeToggle(dialogBinding)
        setUpScopeToggle(dialogBinding)
        setUpPickers(dialogBinding)
        setUpDatePicker(dialogBinding.startDateLayout, dialogBinding.inputStartDate, R.string.offer_hint_start_date, "offer_start_date_picker")
        setUpDatePicker(dialogBinding.endDateLayout, dialogBinding.inputEndDate, R.string.offer_hint_end_date, "offer_end_date_picker")
        setUpTimePicker(dialogBinding.startTimeLayout, dialogBinding.inputStartTime, "offer_start_time_picker")
        setUpTimePicker(dialogBinding.endTimeLayout, dialogBinding.inputEndTime, "offer_end_time_picker")
        buildWeekdayChips(dialogBinding, preselected = emptySet())

        // Trigger both listeners once for the default checked state
        // (chipTypeQuantityDeal / chipScopeItem, both checked="true" in
        // the XML) so the mechanic/picker visibility matches what's
        // actually selected before the user touches anything.
        applyOfferTypeVisibility(dialogBinding, checkedTypeChipId = dialogBinding.chipTypeQuantityDeal.id)
        applyScopeVisibility(dialogBinding, checkedScopeChipId = dialogBinding.chipScopeItem.id)

        val addDialog = BottomSheetDialog(this)
        addDialog.setContentView(dialogBinding.root)
        dialogBinding.btnOfferDialogCancel.setOnClickListener { addDialog.dismiss() }
        dialogBinding.btnOfferDialogSave.setOnClickListener {
            if (submitNewOffer(dialogBinding)) addDialog.dismiss()
        }
        addDialog.show()
    }

    // ---- Edit ----

    /**
     * offer_type/scope/menu_item_id/food_category_id and every mechanic
     * field are create-only (offers-update.php's kdoc — "delete and
     * recreate instead"), so edit mode hides sections 2/3 entirely in
     * favor of [DialogAddOfferBinding.editOfferTypeLabel], same pattern
     * dialog_add_coupon.xml's editDiscountTypeLabel uses. Section 5's
     * maxDiscountLayout is still editable here, but its visibility is
     * driven directly off [offer]'s own (locked) type rather than the
     * now-hidden chip listener.
     */
    private fun showEditOfferDialog(offer: PromoOffer) {
        val dialogBinding = DialogAddOfferBinding.inflate(layoutInflater)
        dialogBinding.offerDialogTitle.text = getString(R.string.offer_edit_title)

        dialogBinding.inputOfferTitle.setText(offer.title)

        dialogBinding.offerTypeHint.visibility = View.GONE
        dialogBinding.offerTypeGroup.visibility = View.GONE
        dialogBinding.mechanicQtyPriceGroup.visibility = View.GONE
        dialogBinding.mechanicQtyGetGroup.visibility = View.GONE
        dialogBinding.mechanicPercentGroup.visibility = View.GONE
        dialogBinding.mechanicFlatGroup.visibility = View.GONE
        dialogBinding.editOfferTypeLabel.visibility = View.VISIBLE
        dialogBinding.editOfferTypeLabel.text = getString(R.string.offer_edit_type_locked_fmt, offerTypeDisplayName(offer.offerType))

        dialogBinding.offerScopeHint.visibility = View.GONE
        dialogBinding.offerScopeGroup.visibility = View.GONE
        dialogBinding.menuItemPickerLayout.visibility = View.GONE
        dialogBinding.foodCategoryPickerLayout.visibility = View.GONE

        // percent_discount is the only type that reads max_discount_amount
        // — same reasoning dialog_add_coupon.xml's edit path documents.
        dialogBinding.maxDiscountLayout.visibility = if (offer.offerType == "percent_discount") View.VISIBLE else View.GONE
        offer.maxDiscountAmount?.let { dialogBinding.inputOfferMaxDiscount.setText(formatEditableAmount(it)) }

        if (offer.minOrderAmount > 0) {
            dialogBinding.inputOfferMinOrder.setText(formatEditableAmount(offer.minOrderAmount))
        }

        val eligibilityChip = when (offer.customerEligibility) {
            "new_customer" -> dialogBinding.chipEligibilityNew
            "existing_customer" -> dialogBinding.chipEligibilityExisting
            else -> dialogBinding.chipEligibilityAll
        }
        eligibilityChip.isChecked = true

        setUpDatePicker(dialogBinding.startDateLayout, dialogBinding.inputStartDate, R.string.offer_hint_start_date, "offer_start_date_picker")
        setUpDatePicker(dialogBinding.endDateLayout, dialogBinding.inputEndDate, R.string.offer_hint_end_date, "offer_end_date_picker")
        setUpTimePicker(dialogBinding.startTimeLayout, dialogBinding.inputStartTime, "offer_start_time_picker")
        setUpTimePicker(dialogBinding.endTimeLayout, dialogBinding.inputEndTime, "offer_end_time_picker")
        offer.startDate?.let { applyDateValue(dialogBinding.startDateLayout, dialogBinding.inputStartDate, it) }
        offer.endDate?.let { applyDateValue(dialogBinding.endDateLayout, dialogBinding.inputEndDate, it) }
        offer.startTime?.let { applyTimeValue(dialogBinding.startTimeLayout, dialogBinding.inputStartTime, it) }
        offer.endTime?.let { applyTimeValue(dialogBinding.endTimeLayout, dialogBinding.inputEndTime, it) }

        val preselectedDays = (offer.weekdays ?: "")
            .split(",")
            .mapNotNull { it.trim().toIntOrNull() }
            .toSet()
        buildWeekdayChips(dialogBinding, preselected = preselectedDays)

        offer.dailyLimit?.let { dialogBinding.inputDailyLimit.setText(it.toString()) }
        offer.totalLimit?.let { dialogBinding.inputTotalLimit.setText(it.toString()) }
        offer.perCustomerLimit?.let { dialogBinding.inputPerCustomerLimit.setText(it.toString()) }

        val editDialog = BottomSheetDialog(this)
        editDialog.setContentView(dialogBinding.root)
        dialogBinding.btnOfferDialogCancel.setOnClickListener { editDialog.dismiss() }
        dialogBinding.btnOfferDialogSave.setOnClickListener {
            if (submitOfferEdit(offer.id, dialogBinding)) editDialog.dismiss()
        }
        editDialog.show()
    }

    /**
     * Read-only view (session 6's recommendation, unchanged by this
     * session) — simplest implementation for the "View" button that
     * currently has no defined destination anywhere else in this app.
     * Reuses the edit dialog with every field disabled and the Save
     * button hidden rather than inventing a new detail screen; flag for
     * the app owner if a real detail screen is wanted for v2.
     */
    private fun showViewOfferDialog(offer: PromoOffer) {
        val dialogBinding = DialogAddOfferBinding.inflate(layoutInflater)
        dialogBinding.offerDialogTitle.text = offer.title

        dialogBinding.inputOfferTitle.setText(offer.title)
        dialogBinding.inputOfferTitle.isEnabled = false

        dialogBinding.offerTypeHint.visibility = View.GONE
        dialogBinding.offerTypeGroup.visibility = View.GONE
        dialogBinding.mechanicQtyPriceGroup.visibility = View.GONE
        dialogBinding.mechanicQtyGetGroup.visibility = View.GONE
        dialogBinding.mechanicPercentGroup.visibility = View.GONE
        dialogBinding.mechanicFlatGroup.visibility = View.GONE
        dialogBinding.editOfferTypeLabel.visibility = View.VISIBLE
        dialogBinding.editOfferTypeLabel.text = getString(R.string.offer_edit_type_locked_fmt, offerTypeDisplayName(offer.offerType))

        dialogBinding.offerScopeHint.visibility = View.GONE
        dialogBinding.offerScopeGroup.visibility = View.GONE
        dialogBinding.menuItemPickerLayout.visibility = View.GONE
        dialogBinding.foodCategoryPickerLayout.visibility = View.GONE

        dialogBinding.maxDiscountLayout.visibility = if (offer.offerType == "percent_discount") View.VISIBLE else View.GONE
        offer.maxDiscountAmount?.let { dialogBinding.inputOfferMaxDiscount.setText(formatEditableAmount(it)) }
        if (offer.minOrderAmount > 0) {
            dialogBinding.inputOfferMinOrder.setText(formatEditableAmount(offer.minOrderAmount))
        }

        val eligibilityChip = when (offer.customerEligibility) {
            "new_customer" -> dialogBinding.chipEligibilityNew
            "existing_customer" -> dialogBinding.chipEligibilityExisting
            else -> dialogBinding.chipEligibilityAll
        }
        eligibilityChip.isChecked = true

        offer.startDate?.let { applyDateValue(dialogBinding.startDateLayout, dialogBinding.inputStartDate, it) }
        offer.endDate?.let { applyDateValue(dialogBinding.endDateLayout, dialogBinding.inputEndDate, it) }
        offer.startTime?.let { applyTimeValue(dialogBinding.startTimeLayout, dialogBinding.inputStartTime, it) }
        offer.endTime?.let { applyTimeValue(dialogBinding.endTimeLayout, dialogBinding.inputEndTime, it) }

        val preselectedDays = (offer.weekdays ?: "")
            .split(",")
            .mapNotNull { it.trim().toIntOrNull() }
            .toSet()
        buildWeekdayChips(dialogBinding, preselected = preselectedDays)

        offer.dailyLimit?.let { dialogBinding.inputDailyLimit.setText(it.toString()) }
        offer.totalLimit?.let { dialogBinding.inputTotalLimit.setText(it.toString()) }
        offer.perCustomerLimit?.let { dialogBinding.inputPerCustomerLimit.setText(it.toString()) }

        // Disable every remaining editable field — read-only means
        // read-only, not just "Save is hidden."
        listOf(
            dialogBinding.inputOfferMinOrder, dialogBinding.inputOfferMaxDiscount,
            dialogBinding.inputStartDate, dialogBinding.inputEndDate,
            dialogBinding.inputStartTime, dialogBinding.inputEndTime,
            dialogBinding.inputDailyLimit, dialogBinding.inputTotalLimit, dialogBinding.inputPerCustomerLimit
        ).forEach { it.isEnabled = false }
        dialogBinding.eligibilityGroup.isEnabled = false
        for (i in 0 until dialogBinding.eligibilityGroup.childCount) {
            (dialogBinding.eligibilityGroup.getChildAt(i) as? Chip)?.isEnabled = false
        }
        for (i in 0 until dialogBinding.weekdaysChipGroup.childCount) {
            (dialogBinding.weekdaysChipGroup.getChildAt(i) as? Chip)?.isEnabled = false
        }
        dialogBinding.startDateLayout.isEndIconVisible = false
        dialogBinding.endDateLayout.isEndIconVisible = false
        dialogBinding.startTimeLayout.isEndIconVisible = false
        dialogBinding.endTimeLayout.isEndIconVisible = false

        dialogBinding.btnOfferDialogSave.visibility = View.GONE

        val viewDialog = BottomSheetDialog(this)
        viewDialog.setContentView(dialogBinding.root)
        dialogBinding.btnOfferDialogCancel.setOnClickListener { viewDialog.dismiss() }
        viewDialog.show()
    }

    // ---- Offer-type toggle (add-mode only) ----

    private fun setUpOfferTypeToggle(dialogBinding: DialogAddOfferBinding) {
        dialogBinding.offerTypeGroup.setOnCheckedStateChangeListener { _, checkedIds ->
            val checkedId = checkedIds.firstOrNull() ?: return@setOnCheckedStateChangeListener
            applyOfferTypeVisibility(dialogBinding, checkedId)
        }
    }

    private fun applyOfferTypeVisibility(dialogBinding: DialogAddOfferBinding, checkedTypeChipId: Int) {
        val isQtyPrice = checkedTypeChipId == dialogBinding.chipTypeQuantityDeal.id || checkedTypeChipId == dialogBinding.chipTypeBuyXForY.id
        val isQtyGet = checkedTypeChipId == dialogBinding.chipTypeBuyXGetY.id
        val isPercent = checkedTypeChipId == dialogBinding.chipTypePercent.id
        val isFlat = checkedTypeChipId == dialogBinding.chipTypeFlat.id
        val isQuantityMechanic = isQtyPrice || isQtyGet // quantity_deal | buy_x_for_y | buy_x_get_y

        dialogBinding.mechanicQtyPriceGroup.visibility = if (isQtyPrice) View.VISIBLE else View.GONE
        dialogBinding.mechanicQtyGetGroup.visibility = if (isQtyGet) View.VISIBLE else View.GONE
        dialogBinding.mechanicPercentGroup.visibility = if (isPercent) View.VISIBLE else View.GONE
        dialogBinding.maxDiscountLayout.visibility = if (isPercent) View.VISIBLE else View.GONE
        dialogBinding.mechanicFlatGroup.visibility = if (isFlat) View.VISIBLE else View.GONE

        refreshScopeChipsForType(dialogBinding, isQuantityMechanic)
    }

    /**
     * offers-create.php rejects scope=restaurant outright for the three
     * quantity-mechanic types (a quantity deal only makes sense against
     * a specific item or category) — chipScopeRestaurant is removed
     * from the group entirely for those types (not just hidden, so
     * app:selectionRequired can't leave it silently checked-but-gone)
     * and restored for the other three. Re-selects chipScopeItem first
     * if chipScopeRestaurant was the one checked when it's removed, so
     * the group is never left with nothing checked.
     */
    private fun refreshScopeChipsForType(dialogBinding: DialogAddOfferBinding, isQuantityMechanic: Boolean) {
        val group = dialogBinding.offerScopeGroup
        val restaurantChip = dialogBinding.chipScopeRestaurant
        val alreadyRemoved = group.indexOfChild(restaurantChip) == -1
        if (isQuantityMechanic) {
            if (!alreadyRemoved) {
                if (restaurantChip.isChecked) dialogBinding.chipScopeItem.isChecked = true
                group.removeView(restaurantChip)
            }
        } else if (alreadyRemoved) {
            group.addView(restaurantChip)
        }
    }

    // ---- Scope toggle (add-mode only) ----

    private fun setUpScopeToggle(dialogBinding: DialogAddOfferBinding) {
        dialogBinding.offerScopeGroup.setOnCheckedStateChangeListener { _, checkedIds ->
            val checkedId = checkedIds.firstOrNull() ?: return@setOnCheckedStateChangeListener
            applyScopeVisibility(dialogBinding, checkedId)
        }
    }

    private fun applyScopeVisibility(dialogBinding: DialogAddOfferBinding, checkedScopeChipId: Int) {
        when (checkedScopeChipId) {
            dialogBinding.chipScopeItem.id -> {
                dialogBinding.menuItemPickerLayout.visibility = View.VISIBLE
                dialogBinding.foodCategoryPickerLayout.visibility = View.GONE
            }
            dialogBinding.chipScopeCategory.id -> {
                dialogBinding.menuItemPickerLayout.visibility = View.GONE
                dialogBinding.foodCategoryPickerLayout.visibility = View.VISIBLE
            }
            else -> { // restaurant
                dialogBinding.menuItemPickerLayout.visibility = View.GONE
                dialogBinding.foodCategoryPickerLayout.visibility = View.GONE
            }
        }
    }

    // ---- Menu item / food category pickers (add-mode only) ----

    /**
     * Fetched fresh every time the add dialog opens (no class-level
     * cache) — this screen's own list is small and rarely opened enough
     * for the extra round trip to matter, and it keeps the picker
     * always current with whatever the Menu tab looks like right now.
     * inputMenuItemPicker/inputFoodCategoryPicker are plain dropdown-
     * style AutoCompleteTextViews (first use of this style in the app,
     * per the handover) — the picked row's numeric id is tracked via
     * the view's own `tag` on selection, same tag-holds-wire-value
     * convention CouponManagerActivity's applyValidUntilValue() uses for
     * its date field, since OfferCreateBody.menuItemId/foodCategoryId
     * need the id, not the display name shown in the field.
     */
    private fun setUpPickers(dialogBinding: DialogAddOfferBinding) {
        lifecycleScope.launch {
            try {
                val response = api.getMenuItems()
                val items: List<MenuItem> = if (response.isSuccessful) response.body()?.data?.items.orEmpty() else emptyList()
                val arrayAdapter = ArrayAdapter(this@OfferManagerActivity, android.R.layout.simple_list_item_1, items.map { it.name })
                dialogBinding.inputMenuItemPicker.setAdapter(arrayAdapter)
                dialogBinding.inputMenuItemPicker.setOnItemClickListener { _, _, position, _ ->
                    dialogBinding.inputMenuItemPicker.tag = items[position].id
                }
            } catch (e: Exception) {
                // Dropdown just stays unpopulated — not fatal, the
                // restaurant can still retry by reopening the dialog.
            }
        }
        lifecycleScope.launch {
            try {
                val response = api.getFoodTags()
                val tags: List<FoodTag> = if (response.isSuccessful) response.body()?.data?.tags.orEmpty() else emptyList()
                val arrayAdapter = ArrayAdapter(this@OfferManagerActivity, android.R.layout.simple_list_item_1, tags.map { it.name })
                dialogBinding.inputFoodCategoryPicker.setAdapter(arrayAdapter)
                dialogBinding.inputFoodCategoryPicker.setOnItemClickListener { _, _, position, _ ->
                    dialogBinding.inputFoodCategoryPicker.tag = tags[position].id
                }
            } catch (e: Exception) {
                // Same as above.
            }
        }
    }

    // ---- Weekday chips (built at runtime, both add and edit/view) ----

    /** Same loop EditProfileActivity.buildDayChips() uses for
     * restaurants.working_days — same 1=Mon..7=Sun convention, same
     * day_short_mon..day_short_sun string resources, reused rather than
     * duplicated. Unlike that screen (one Activity instance, a
     * class-level dayChips map), this dialog is freshly inflated every
     * open, so the day number is stashed on each Chip's own `tag`
     * instead and read back by iterating weekdaysChipGroup's children
     * directly at submit time — same "tag holds the id" idiom
     * [setUpPickers] uses for the item/category pickers. */
    private fun buildWeekdayChips(dialogBinding: DialogAddOfferBinding, preselected: Set<Int>) {
        dialogBinding.weekdaysChipGroup.removeAllViews()
        dayLabels.forEach { (dayNumber, labelRes) ->
            val chip = Chip(this).apply {
                text = getString(labelRes)
                tag = dayNumber
                isCheckable = true
                isChecked = dayNumber in preselected
                textSize = 12f
            }
            dialogBinding.weekdaysChipGroup.addView(chip)
        }
    }

    private fun readSelectedWeekdaysCsv(dialogBinding: DialogAddOfferBinding): String? {
        val selected = (0 until dialogBinding.weekdaysChipGroup.childCount)
            .mapNotNull { dialogBinding.weekdaysChipGroup.getChildAt(it) as? Chip }
            .filter { it.isChecked }
            .mapNotNull { it.tag as? Int }
            .sorted()
        return if (selected.isEmpty()) null else selected.joinToString(",") // blank = every day, per offer_hint_weekdays
    }

    // ---- Date / time pickers (date-only — start_date/end_date are DATE
    // columns, no chained time picker unlike CouponManagerActivity's
    // valid_until; start_time/end_time are separate TIME-only fields
    // for the happy-hour window) ----

    private val dateWireFormat = SimpleDateFormat("yyyy-MM-dd", Locale.US)
    private val dateDisplayFormat = SimpleDateFormat("d MMM yyyy", Locale.getDefault())
    private val timeWireFormat = SimpleDateFormat("HH:mm:ss", Locale.US)
    private val timeDisplayFormat = SimpleDateFormat("h:mm a", Locale.getDefault())

    private fun setUpDatePicker(
        layout: com.google.android.material.textfield.TextInputLayout,
        field: com.google.android.material.textfield.TextInputEditText,
        hintRes: Int,
        fragmentTag: String
    ) {
        layout.setEndIconOnClickListener {
            field.tag = null
            field.text = null
            layout.isEndIconVisible = false
        }
        layout.isEndIconVisible = field.tag != null

        field.setOnClickListener {
            // Same double-tap guard CouponManagerActivity's
            // setUpValidUntilPicker() uses — a second tap before the
            // first picker's show() transaction commits throws
            // IllegalStateException.
            if (supportFragmentManager.findFragmentByTag(fragmentTag) != null) return@setOnClickListener
            val datePicker = MaterialDatePicker.Builder.datePicker()
                .setTitleText(getString(hintRes))
                .build()
            datePicker.addOnPositiveButtonClickListener { utcMillis ->
                val utcCal = Calendar.getInstance(TimeZone.getTimeZone("UTC"))
                utcCal.timeInMillis = utcMillis
                // MaterialDatePicker works in UTC millis regardless of
                // device timezone (documented behavior) — read the
                // calendar fields directly rather than round-tripping
                // through a local Calendar, since there's no time-of-day
                // component here to combine with (unlike the coupon
                // picker's date+time chain).
                val wire = String.format(
                    Locale.US, "%04d-%02d-%02d",
                    utcCal.get(Calendar.YEAR), utcCal.get(Calendar.MONTH) + 1, utcCal.get(Calendar.DAY_OF_MONTH)
                )
                applyDateValue(layout, field, wire)
            }
            datePicker.show(supportFragmentManager, fragmentTag)
        }
    }

    private fun applyDateValue(layout: com.google.android.material.textfield.TextInputLayout, field: com.google.android.material.textfield.TextInputEditText, wireValue: String) {
        field.tag = wireValue
        val display = try {
            dateDisplayFormat.format(dateWireFormat.parse(wireValue)!!)
        } catch (e: Exception) {
            wireValue
        }
        field.setText(display)
        layout.isEndIconVisible = true
    }

    private fun setUpTimePicker(
        layout: com.google.android.material.textfield.TextInputLayout,
        field: com.google.android.material.textfield.TextInputEditText,
        fragmentTag: String
    ) {
        layout.setEndIconOnClickListener {
            field.tag = null
            field.text = null
            layout.isEndIconVisible = false
        }
        layout.isEndIconVisible = field.tag != null

        field.setOnClickListener {
            if (supportFragmentManager.findFragmentByTag(fragmentTag) != null) return@setOnClickListener
            val timePicker = MaterialTimePicker.Builder()
                .setTimeFormat(TimeFormat.CLOCK_12H)
                .build()
            timePicker.addOnPositiveButtonClickListener {
                val wire = String.format(Locale.US, "%02d:%02d:00", timePicker.hour, timePicker.minute)
                applyTimeValue(layout, field, wire)
            }
            timePicker.show(supportFragmentManager, fragmentTag)
        }
    }

    private fun applyTimeValue(layout: com.google.android.material.textfield.TextInputLayout, field: com.google.android.material.textfield.TextInputEditText, wireValue: String) {
        field.tag = wireValue
        val display = try {
            timeDisplayFormat.format(timeWireFormat.parse(wireValue)!!)
        } catch (e: Exception) {
            wireValue
        }
        field.setText(display)
        layout.isEndIconVisible = true
    }

    private fun formatEditableAmount(value: Double): String {
        return if (value == value.toLong().toDouble()) value.toLong().toString() else value.toString()
    }

    private fun offerTypeDisplayName(offerType: String): String = when (offerType) {
        "quantity_deal" -> getString(R.string.offer_type_quantity_deal)
        "buy_x_for_y" -> getString(R.string.offer_type_buy_x_for_y)
        "buy_x_get_y" -> getString(R.string.offer_type_buy_x_get_y)
        "percent_discount" -> getString(R.string.offer_type_percent_discount)
        "flat_discount" -> getString(R.string.offer_type_flat_discount)
        else -> getString(R.string.offer_type_free_delivery)
    }

    // ---- Submit ----

    /** Same true/false-on-validation-passed contract as
     * CouponManagerActivity.submitNewCoupon() — see that function's
     * kdoc for why: an early return here still needs to keep the bottom
     * sheet open (rather than the default AlertDialog-style auto-
     * dismiss-on-invalid-input) so the restaurant can fix what's wrong. */
    private fun submitNewOffer(dialogBinding: DialogAddOfferBinding): Boolean {
        val title = dialogBinding.inputOfferTitle.text?.toString()?.trim().orEmpty()
        if (title.isEmpty()) {
            InAppNotifier.show(this, getString(R.string.offer_create_failed), InAppNotifier.Type.ERROR)
            return false
        }

        val offerType = when (dialogBinding.offerTypeGroup.checkedChipId) {
            dialogBinding.chipTypeBuyXForY.id -> "buy_x_for_y"
            dialogBinding.chipTypeBuyXGetY.id -> "buy_x_get_y"
            dialogBinding.chipTypePercent.id -> "percent_discount"
            dialogBinding.chipTypeFlat.id -> "flat_discount"
            dialogBinding.chipTypeFreeDelivery.id -> "free_delivery"
            else -> "quantity_deal"
        }

        val scope = when (dialogBinding.offerScopeGroup.checkedChipId) {
            dialogBinding.chipScopeCategory.id -> "category"
            dialogBinding.chipScopeRestaurant.id -> "restaurant"
            else -> "item"
        }

        val menuItemId = if (scope == "item") dialogBinding.inputMenuItemPicker.tag as? Int else null
        val foodCategoryId = if (scope == "category") dialogBinding.inputFoodCategoryPicker.tag as? Int else null
        if (scope == "item" && menuItemId == null) {
            InAppNotifier.show(this, getString(R.string.offer_create_failed), InAppNotifier.Type.ERROR)
            return false
        }
        if (scope == "category" && foodCategoryId == null) {
            InAppNotifier.show(this, getString(R.string.offer_create_failed), InAppNotifier.Type.ERROR)
            return false
        }

        var requiredQty: Int? = null
        var getQty: Int? = null
        var offerPrice: Double? = null
        var discountPercent: Double? = null
        var discountFlat: Double? = null

        when (offerType) {
            "quantity_deal", "buy_x_for_y" -> {
                requiredQty = dialogBinding.inputRequiredQty1.text?.toString()?.trim()?.toIntOrNull()
                offerPrice = dialogBinding.inputOfferPrice.text?.toString()?.trim()?.toDoubleOrNull()
                if (requiredQty == null || requiredQty < 1 || offerPrice == null || offerPrice <= 0) {
                    InAppNotifier.show(this, getString(R.string.offer_create_failed), InAppNotifier.Type.ERROR)
                    return false
                }
            }
            "buy_x_get_y" -> {
                requiredQty = dialogBinding.inputRequiredQty2.text?.toString()?.trim()?.toIntOrNull()
                getQty = dialogBinding.inputGetQty.text?.toString()?.trim()?.toIntOrNull()
                if (requiredQty == null || requiredQty < 1 || getQty == null || getQty < 1) {
                    InAppNotifier.show(this, getString(R.string.offer_create_failed), InAppNotifier.Type.ERROR)
                    return false
                }
            }
            "percent_discount" -> {
                discountPercent = dialogBinding.inputDiscountPercent.text?.toString()?.trim()?.toDoubleOrNull()
                if (discountPercent == null || discountPercent <= 0 || discountPercent > 100) {
                    InAppNotifier.show(this, getString(R.string.offer_create_failed), InAppNotifier.Type.ERROR)
                    return false
                }
            }
            "flat_discount" -> {
                discountFlat = dialogBinding.inputDiscountFlat.text?.toString()?.trim()?.toDoubleOrNull()
                if (discountFlat == null || discountFlat <= 0) {
                    InAppNotifier.show(this, getString(R.string.offer_create_failed), InAppNotifier.Type.ERROR)
                    return false
                }
            }
            // free_delivery needs none of the above.
        }

        val minOrder = dialogBinding.inputOfferMinOrder.text?.toString()?.trim()?.toDoubleOrNull()
        val maxDiscount = if (offerType == "percent_discount") {
            dialogBinding.inputOfferMaxDiscount.text?.toString()?.trim()?.toDoubleOrNull()
        } else null
        val eligibility = when (dialogBinding.eligibilityGroup.checkedChipId) {
            dialogBinding.chipEligibilityNew.id -> "new_customer"
            dialogBinding.chipEligibilityExisting.id -> "existing_customer"
            else -> "all"
        }
        val startDate = dialogBinding.inputStartDate.tag as? String
        val endDate = dialogBinding.inputEndDate.tag as? String
        val startTime = dialogBinding.inputStartTime.tag as? String
        val endTime = dialogBinding.inputEndTime.tag as? String
        val weekdays = readSelectedWeekdaysCsv(dialogBinding)
        val dailyLimit = dialogBinding.inputDailyLimit.text?.toString()?.trim()?.toIntOrNull()
        val totalLimit = dialogBinding.inputTotalLimit.text?.toString()?.trim()?.toIntOrNull()
        val perCustomerLimit = dialogBinding.inputPerCustomerLimit.text?.toString()?.trim()?.toIntOrNull()

        lifecycleScope.launch {
            try {
                val response = api.createOffer(
                    OfferCreateBody(
                        offerType = offerType,
                        title = title,
                        scope = scope,
                        menuItemId = menuItemId,
                        foodCategoryId = foodCategoryId,
                        requiredQty = requiredQty,
                        getQty = getQty,
                        offerPrice = offerPrice,
                        discountPercent = discountPercent,
                        discountFlat = discountFlat,
                        maxDiscountAmount = maxDiscount,
                        minOrderAmount = minOrder,
                        customerEligibility = eligibility,
                        startDate = startDate,
                        endDate = endDate,
                        startTime = startTime,
                        endTime = endTime,
                        weekdays = weekdays,
                        dailyLimit = dailyLimit,
                        totalLimit = totalLimit,
                        perCustomerLimit = perCustomerLimit
                    )
                )
                val created = response.body()?.data?.offer
                if (response.isSuccessful && created != null) {
                    InAppNotifier.show(this@OfferManagerActivity, getString(R.string.offer_created), InAppNotifier.Type.SUCCESS)
                    loadOffers()
                } else {
                    InAppNotifier.show(this@OfferManagerActivity, getString(R.string.offer_create_failed), InAppNotifier.Type.ERROR)
                }
            } catch (e: Exception) {
                InAppNotifier.show(this@OfferManagerActivity, getString(R.string.offer_create_failed), InAppNotifier.Type.ERROR)
            }
        }
        return true
    }

    /**
     * Edit-terms submit — only the fields OfferUpdateBody actually
     * exposes (offer_type/scope/mechanic fields are create-only, see
     * showEditOfferDialog()'s kdoc). Sends the dialog's full current
     * form state every time, same reasoning
     * CouponManagerActivity.submitCouponEdit() documents: offers-
     * update.php is array_key_exists-gated (not null-gated) per field,
     * so every value present here is exactly what the dialog showed,
     * whether the restaurant changed it or not — min_order_amount in
     * particular is always sent as a number (never omitted), matching
     * offers-update.php's own max(0.0, ...) cast with no null branch. */
    private fun submitOfferEdit(offerId: Int, dialogBinding: DialogAddOfferBinding): Boolean {
        val title = dialogBinding.inputOfferTitle.text?.toString()?.trim().orEmpty()
        if (title.isEmpty()) {
            InAppNotifier.show(this, getString(R.string.offer_update_failed), InAppNotifier.Type.ERROR)
            return false
        }

        val minOrder = dialogBinding.inputOfferMinOrder.text?.toString()?.trim()?.toDoubleOrNull() ?: 0.0
        val maxDiscount = if (dialogBinding.maxDiscountLayout.visibility == View.VISIBLE) {
            dialogBinding.inputOfferMaxDiscount.text?.toString()?.trim()?.toDoubleOrNull()
        } else null
        val startDate = dialogBinding.inputStartDate.tag as? String
        val endDate = dialogBinding.inputEndDate.tag as? String
        val startTime = dialogBinding.inputStartTime.tag as? String
        val endTime = dialogBinding.inputEndTime.tag as? String
        val weekdays = readSelectedWeekdaysCsv(dialogBinding)
        val dailyLimit = dialogBinding.inputDailyLimit.text?.toString()?.trim()?.toIntOrNull()
        val totalLimit = dialogBinding.inputTotalLimit.text?.toString()?.trim()?.toIntOrNull()
        val perCustomerLimit = dialogBinding.inputPerCustomerLimit.text?.toString()?.trim()?.toIntOrNull()

        lifecycleScope.launch {
            try {
                val response = api.updateOffer(
                    offerId,
                    OfferUpdateBody(
                        title = title,
                        minOrderAmount = minOrder,
                        maxDiscountAmount = maxDiscount,
                        startDate = startDate,
                        endDate = endDate,
                        startTime = startTime,
                        endTime = endTime,
                        weekdays = weekdays,
                        dailyLimit = dailyLimit,
                        totalLimit = totalLimit,
                        perCustomerLimit = perCustomerLimit
                    )
                )
                val updated = response.body()?.data?.offer
                if (response.isSuccessful && updated != null) {
                    InAppNotifier.show(this@OfferManagerActivity, getString(R.string.offer_updated), InAppNotifier.Type.SUCCESS)
                    loadOffers()
                } else {
                    InAppNotifier.show(this@OfferManagerActivity, getString(R.string.offer_update_failed), InAppNotifier.Type.ERROR)
                }
            } catch (e: Exception) {
                InAppNotifier.show(this@OfferManagerActivity, getString(R.string.offer_update_failed), InAppNotifier.Type.ERROR)
            }
        }
        return true
    }
}
