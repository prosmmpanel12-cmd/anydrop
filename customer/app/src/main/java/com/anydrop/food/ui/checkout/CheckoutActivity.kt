package com.anydrop.food.ui.checkout

import android.Manifest
import android.content.Intent
import android.content.pm.PackageManager
import android.location.Location
import android.location.LocationManager
import android.os.Bundle
import android.os.Looper
import android.view.View
import android.widget.RadioButton
import androidx.activity.result.contract.ActivityResultContracts
import androidx.appcompat.app.AppCompatActivity
import androidx.core.content.ContextCompat
import androidx.lifecycle.lifecycleScope
import com.anydrop.food.R
import com.anydrop.food.data.CartManager
import com.anydrop.food.databinding.ActivityCheckoutBinding
import com.anydrop.food.network.ApiClient
import com.anydrop.food.network.Address
import com.anydrop.food.network.CartItemLine
import com.anydrop.food.network.CartValidateBody
import com.anydrop.food.network.CreateOrderBody
import com.anydrop.food.network.CartTotals
import com.anydrop.food.ui.address.AddressEditorBottomSheet
import com.anydrop.food.ui.common.InAppNotifier
import com.anydrop.food.ui.common.ScheduleTimeSlotBottomSheet
import com.anydrop.food.ui.orderstatus.OrderStatusActivity
import kotlinx.coroutines.launch

/**
 * Phase 3 — Checkout: pick/add a delivery address, choose payment method,
 * see a server-computed bill (POST /cart/validate), then place the order
 * (POST /orders). Cart is only cleared once the order is confirmed created.
 *
 * §2.6 — address entry is now the shared structured AddressEditorBottomSheet
 * (type chips, house/flat, floor, landmark, receiver name+phone) instead of
 * a single free-text field. Checkout implements
 * AddressEditorBottomSheet.LocationRequester so the sheet's "Use current
 * location" button can reuse this Activity's existing GPS/Geocoder logic —
 * a bottom sheet has no Activity context of its own for permission prompts.
 */
class CheckoutActivity : AppCompatActivity(), AddressEditorBottomSheet.LocationRequester {

    companion object {
        /** Which restaurant's cart to check out — required, see CartManager's
         * multi-restaurant-cart kdoc. The cart sheet always passes this. */
        const val EXTRA_RESTAURANT_ID = "extra_restaurant_id"

        /** Error codes `price_cart()` (backend/lib/orders.php) puts in the
         * `warning` field when a coupon specifically is the problem —
         * distinct from other warnings (e.g. below_min_order_amount) that
         * aren't coupon-related and shouldn't surface as a coupon error. */
        private val COUPON_ERROR_CODES = setOf(
            "invalid_coupon", "coupon_min_order_not_met", "coupon_usage_limit_reached"
        )

        /** H4 fix (2026-08-10) — the `warning` code price_cart() sets when the
         * cart's item_total is under the restaurant's min_order_amount. Not a
         * coupon problem, so it's kept separate from COUPON_ERROR_CODES above;
         * checked directly wherever Place Order needs to be blocked. */
        private const val WARNING_BELOW_MIN_ORDER = "below_min_order_amount"
    }

    private lateinit var binding: ActivityCheckoutBinding
    private val api by lazy { ApiClient.create(this) }

    private var restaurantId: Int = 0
    private var addresses: List<Address> = emptyList()
    private var selectedAddressId: Int? = null

    // H5 — captured from renderBill() so openCouponsSheet() has a real
    // item_total to send; otherwise it's discarded after painting the UI.
    // Null is not wrong (endpoint just returns every coupon as eligible),
    // but sending it enables the "add ₹X more to unlock" messaging.
    private var lastKnownItemTotal: Double? = null

    // Set while a location fix is being resolved on behalf of an open
    // AddressEditorBottomSheet — cleared once the fix is delivered back to it.
    private var pendingSheetForLocation: AddressEditorBottomSheet? = null

    // I4 — CheckoutActivity only gets a bare restaurantId via
    // EXTRA_RESTAURANT_ID (see CartBottomSheetFragment), not a full
    // RestaurantDetail, so these aren't in scope until loadRestaurantHours()
    // resolves. Option (a) from the handover doc: one extra getMenu() call
    // purely to read them, rather than threading them through as Intent
    // extras from whichever screen launched Checkout. rowDeliveryTime itself
    // renders immediately off the cart's existing scheduledFor regardless of
    // whether this has returned yet — only *opening* the sheet to change the
    // pick needs the bounds, and by then this call has almost certainly
    // resolved.
    private var restaurantOpeningTime: String? = null
    private var restaurantClosingTime: String? = null

    // bugs.md #2.4 — set fresh the first time placeOrder() runs after this
    // Activity was created (or after a completed attempt fully failed and
    // the user is trying again from scratch), reused as-is for the
    // duration of one in-flight place-order call so a client-side retry of
    // that same call (not currently automatic, but this makes it safe if
    // one's ever added) doesn't get treated as a new attempt server-side.
    private var pendingIdempotencyKey: String? = null

    private val locationPermissionLauncher =
        registerForActivityResult(ActivityResultContracts.RequestPermission()) { granted ->
            if (granted) fetchCurrentLocation() else {
                InAppNotifier.show(this, "Location permission denied", InAppNotifier.Type.INFO)
                pendingSheetForLocation = null
            }
        }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityCheckoutBinding.inflate(layoutInflater)
        setContentView(binding.root)

        restaurantId = intent.getIntExtra(EXTRA_RESTAURANT_ID, 0)
        val cart = CartManager.getCart(restaurantId)
        if (restaurantId == 0 || cart == null || cart.totalItemCount() == 0) {
            finish()
            return
        }

        binding.btnBack.setOnClickListener { finish() }
        binding.btnAddAddress.setOnClickListener { openAddressEditor() }
        binding.btnPlaceOrder.setOnClickListener { placeOrder() }
        binding.btnApplyCoupon.setOnClickListener { applyCoupon() }
        binding.btnRemoveCoupon.setOnClickListener { removeCoupon() }
        binding.rowViewAllOffers.setOnClickListener { openCouponsSheet() }
        binding.rowDeliveryTime.setOnClickListener { openScheduleSheet() }

        // Pre-fill in case a coupon was already applied earlier in this
        // checkout session (e.g. user backed out and returned) — CartManager
        // is an in-memory singleton, so appliedCouponCode survives that.
        binding.inputCouponCode.setText(cart.appliedCouponCode.orEmpty())

        // I4 — reflects whatever scheduledFor is already on the cart (picked
        // on restaurant-detail, or from an earlier pass through this same
        // Activity) before the hours call below has necessarily returned.
        renderDeliveryTimeRow()

        loadAddresses()
        loadBill()
        loadRestaurantHours()
    }

    /**
     * I4 — fetches the restaurant's opening_time/closing_time purely to
     * bound ScheduleTimeSlotBottomSheet's slot list (see field kdoc above).
     * Non-fatal on failure: the sheet just falls back to treating the
     * restaurant as having no configured hours (every slot for the rest of
     * today offered) if this hasn't resolved, or failed, by the time the
     * customer taps rowDeliveryTime — the server is the real source of
     * truth at Place Order regardless.
     */
    private fun loadRestaurantHours() {
        lifecycleScope.launch {
            try {
                val restaurant = api.getMenu(restaurantId).body()?.data?.restaurant
                restaurantOpeningTime = restaurant?.openingTime
                restaurantClosingTime = restaurant?.closingTime
            } catch (e: Exception) {
                // Non-fatal — see kdoc above.
            }
        }
    }

    /**
     * I4 — "Deliver Now" while the cart has no scheduledFor, or
     * "Today, h:mm a" once it does. Mirrors
     * RestaurantDetailActivity.renderEtaRowText()'s scheduled-state format;
     * both now share ScheduledTimeFormatter instead of duplicating the
     * parse/format logic (see that file's kdoc for the handover note that
     * triggered the factor-out).
     */
    private fun renderDeliveryTimeRow() {
        val scheduledFor = CartManager.getCart(restaurantId)?.scheduledFor
        val timeText = com.anydrop.food.util.ScheduledTimeFormatter.formatTime(scheduledFor)
        binding.deliveryTimeText.text = if (timeText != null) {
            getString(R.string.detail_eta_scheduled_format, timeText)
        } else {
            getString(R.string.schedule_deliver_now)
        }
    }

    /** I4 — same sheet, same usage pattern as RestaurantDetailActivity's
     * ETA row: pass the restaurant's hours + the cart's current pick, apply
     * whatever comes back straight onto the cart, re-render, sync. */
    private fun openScheduleSheet() {
        val sheet = ScheduleTimeSlotBottomSheet.newInstance(
            openingTime = restaurantOpeningTime,
            closingTime = restaurantClosingTime,
            currentSelection = CartManager.getCart(restaurantId)?.scheduledFor
        )
        sheet.onSelected = { picked ->
            CartManager.getCart(restaurantId)?.scheduledFor = picked
            renderDeliveryTimeRow()
            com.anydrop.food.data.CartSyncManager.scheduleSync(this@CheckoutActivity)
        }
        sheet.show(supportFragmentManager, "schedule_time")
    }

    private fun openAddressEditor(editing: Address? = null) {
        val sheet = if (editing != null) {
            AddressEditorBottomSheet.newInstance(editing)
        } else {
            AddressEditorBottomSheet.newInstance(isFirstAddress = addresses.isEmpty())
        }
        sheet.onSaved = { loadAddresses() }
        sheet.show(supportFragmentManager, "address_editor")
    }

    // H5 — "View all offers & coupons" row. Browse/pick UI in front of the
    // existing coupon code box: tapping an eligible coupon just fills the
    // code and re-uses applyCoupon()'s existing validate-and-apply flow.
    private fun openCouponsSheet() {
        val cart = CartManager.getCart(restaurantId)
        val sheet = CouponsListBottomSheetFragment.newInstance(
            restaurantId = restaurantId,
            itemTotal = lastKnownItemTotal,
            appliedCode = cart?.appliedCouponCode
        )
        sheet.onCouponSelected = { coupon ->
            binding.inputCouponCode.setText(coupon.code)
            applyCoupon()
        }
        sheet.show(supportFragmentManager)
    }

    // ---- AddressEditorBottomSheet.LocationRequester ----

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
            // Fastest available fix: last known location first, falls back to a fresh single update.
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

    // §2.6 — carries the dish-customization sheet's selected addons and
    // cooking-request note through to /cart/validate and /orders. addonIds
    // is sent as null (not an empty list) when there's nothing selected,
    // matching CartItemLine's existing nullable convention for optional
    // fields the backend already treats as "no addons" either way.
    private fun cartLines(): List<CartItemLine> =
        (CartManager.getCart(restaurantId)?.getLines() ?: emptyList())
            .map {
                CartItemLine(
                    menuItemId = it.item.id,
                    addonIds = it.addonIds.ifEmpty { null },
                    specialInstructions = it.specialInstructions,
                    quantity = it.quantity
                )
            }

    private fun loadAddresses() {
        lifecycleScope.launch {
            try {
                val res = api.getAddresses().body()?.data?.addresses ?: emptyList()
                addresses = res
                renderAddresses()
            } catch (e: Exception) {
                InAppNotifier.show(this@CheckoutActivity, "Couldn't load saved addresses.", InAppNotifier.Type.ERROR)
            }
        }
    }

    private fun renderAddresses() {
        binding.addressGroup.removeAllViews()
        if (addresses.isEmpty()) {
            selectedAddressId = null
            return
        }
        addresses.forEachIndexed { index, address ->
            val radio = RadioButton(this).apply {
                text = "${address.label ?: "Address"} — ${address.fullAddress}"
                isChecked = index == 0 || address.isDefault
                setTextColor(getColor(R.color.text_primary))
                setOnClickListener {
                    selectedAddressId = address.id
                    binding.addressGroup.children().forEach { v -> if (v is RadioButton && v !== this) v.isChecked = false }
                }
                setOnLongClickListener {
                    openAddressEditor(editing = address)
                    true
                }
            }
            if (radio.isChecked) selectedAddressId = address.id
            binding.addressGroup.addView(radio)
        }
    }

    private fun android.view.ViewGroup.children() = (0 until childCount).map { getChildAt(it) }

    private fun loadBill() {
        lifecycleScope.launch {
            try {
                val response = api.validateCart(
                    CartValidateBody(
                        restaurantId = restaurantId,
                        items = cartLines(),
                        couponCode = CartManager.getCart(restaurantId)?.appliedCouponCode,
                        scheduledFor = CartManager.getCart(restaurantId)?.scheduledFor
                    )
                )
                val totals = response.body()?.data
                if (totals != null) {
                    renderBill(totals)
                    renderCouponState(totals)
                } else {
                    InAppNotifier.show(this@CheckoutActivity, "Couldn't calculate the bill.", InAppNotifier.Type.ERROR)
                }
            } catch (e: Exception) {
                InAppNotifier.show(this@CheckoutActivity, "Network error while loading the bill.", InAppNotifier.Type.ERROR)
            }
        }
    }

    /**
     * Coupon entry (Phase 3.7 gap fix) — this used to live in the old
     * single-cart bottom sheet's `applyCoupon()`; moved here since a coupon
     * now applies to one restaurant's checkout, not the whole
     * multi-restaurant cart (see CartManager.kt kdoc). Re-uses the same
     * POST /cart/validate call `loadBill()` already makes — sending the
     * candidate code through validation is both how we check it's real
     * *and* how we get the discounted total back in one round trip.
     */
    private fun applyCoupon() {
        val code = binding.inputCouponCode.text?.toString()?.trim().orEmpty()
        if (code.isEmpty()) {
            InAppNotifier.show(this, "Enter a coupon code", InAppNotifier.Type.INFO)
            return
        }
        binding.btnApplyCoupon.isEnabled = false
        lifecycleScope.launch {
            try {
                val response = api.validateCart(
                    CartValidateBody(
                        restaurantId = restaurantId,
                        items = cartLines(),
                        couponCode = code,
                        scheduledFor = CartManager.getCart(restaurantId)?.scheduledFor
                    )
                )
                val totals = response.body()?.data
                binding.btnApplyCoupon.isEnabled = true
                if (totals == null) {
                    InAppNotifier.show(this@CheckoutActivity, "Couldn't apply coupon.", InAppNotifier.Type.ERROR)
                    return@launch
                }
                if (totals.warning != null && totals.warning in COUPON_ERROR_CODES) {
                    // Bad code — don't let it silently sit on the cart and
                    // get sent again with the order.
                    CartManager.getCart(restaurantId)?.appliedCouponCode = null
                    com.anydrop.food.data.CartSyncManager.scheduleSync(this@CheckoutActivity)
                    binding.couponErrorText.text = couponErrorMessage(totals.warning)
                    binding.couponErrorText.visibility = View.VISIBLE
                    renderBill(totals)
                    renderCouponState(totals)
                } else {
                    CartManager.getCart(restaurantId)?.appliedCouponCode = code
                    com.anydrop.food.data.CartSyncManager.scheduleSync(this@CheckoutActivity)
                    binding.couponErrorText.visibility = View.GONE
                    renderBill(totals)
                    renderCouponState(totals)
                }
            } catch (e: Exception) {
                binding.btnApplyCoupon.isEnabled = true
                InAppNotifier.show(this@CheckoutActivity, "Network error while applying coupon.", InAppNotifier.Type.ERROR)
            }
        }
    }

    private fun removeCoupon() {
        CartManager.getCart(restaurantId)?.appliedCouponCode = null
        com.anydrop.food.data.CartSyncManager.scheduleSync(this@CheckoutActivity)
        binding.inputCouponCode.setText("")
        binding.couponErrorText.visibility = View.GONE
        loadBill()
    }

    /** Shows the entry row or the "applied" row depending on whether the
     * restaurant's cart currently has a coupon that actually discounted
     * something (a code can be set but not yet re-validated — we only
     * trust discount_amount, the server-computed number). */
    private fun renderCouponState(totals: CartTotals) {
        val appliedCode = CartManager.getCart(restaurantId)?.appliedCouponCode
        if (appliedCode != null && totals.discountAmount > 0.0) {
            binding.couponEntryRow.visibility = View.GONE
            binding.couponAppliedRow.visibility = View.VISIBLE
            binding.couponAppliedText.text =
                getString(R.string.coupon_applied, "%.0f".format(totals.discountAmount))
        } else {
            binding.couponEntryRow.visibility = View.VISIBLE
            binding.couponAppliedRow.visibility = View.GONE
        }
    }

    private fun couponErrorMessage(code: String): String = when (code) {
        "coupon_min_order_not_met" -> getString(R.string.coupon_min_order_not_met)
        "coupon_usage_limit_reached" -> getString(R.string.coupon_usage_limit_reached)
        else -> getString(R.string.coupon_invalid)
    }

    private fun renderBill(totals: CartTotals) {
        lastKnownItemTotal = totals.itemTotal
        setRow(binding.rowItemTotal.root, getString(R.string.lbl_item_total), totals.itemTotal)
        setRow(binding.rowDiscount.root, getString(R.string.lbl_discount), -totals.discountAmount, hideIfZero = true)
        setRow(binding.rowDelivery.root, getString(R.string.lbl_delivery_charge), totals.deliveryCharge)
        setRow(binding.rowPlatformFee.root, getString(R.string.lbl_platform_fee), totals.platformFee)
        setRow(binding.rowTax.root, getString(R.string.lbl_tax), totals.taxAmount)
        binding.grandTotalText.text = "₹${"%.2f".format(totals.grandTotal)}"

        // H4 defense-in-depth: below_min_order_amount now comes back with the
        // real (non-zero) bill from a fixed price_cart(), but keep this guard
        // so a ₹0 total is never silently treated as a placeable order no
        // matter which warning (if any) accompanies it — surface it and block
        // Place Order instead of leaving the user to find out on submit.
        val belowMinOrder = totals.warning == WARNING_BELOW_MIN_ORDER
        val zeroTotalWithUnknownWarning =
            totals.grandTotal == 0.0 && totals.warning != null && totals.warning !in COUPON_ERROR_CODES
        if (belowMinOrder || zeroTotalWithUnknownWarning) {
            val minOrder = totals.minOrderAmount
            binding.belowMinOrderText.text = if (belowMinOrder && minOrder != null) {
                val shortfall = (minOrder - totals.itemTotal).coerceAtLeast(0.0)
                getString(R.string.below_min_order_amount_with_amount, shortfall)
            } else {
                getString(R.string.below_min_order_amount)
            }
            binding.belowMinOrderText.visibility = View.VISIBLE
            binding.btnPlaceOrder.isEnabled = false
        } else {
            binding.belowMinOrderText.visibility = View.GONE
            binding.btnPlaceOrder.isEnabled = true
        }
    }

    private fun setRow(row: View, label: String, amount: Double, hideIfZero: Boolean = false) {
        val labelView = row.findViewById<android.widget.TextView>(R.id.billLineLabel)
        val valueView = row.findViewById<android.widget.TextView>(R.id.billLineValue)
        labelView.text = label
        valueView.text = "₹${"%.2f".format(amount)}"
        row.visibility = if (hideIfZero && amount == 0.0) View.GONE else View.VISIBLE
    }

    private fun placeOrder() {
        val addressId = selectedAddressId
        if (CartManager.getCart(restaurantId)?.totalItemCount() ?: 0 == 0) {
            InAppNotifier.show(this, "Your cart is empty", InAppNotifier.Type.INFO)
            return
        }
        if (!binding.btnPlaceOrder.isEnabled) {
            // Guards against a stale-enabled tap slipping through — the
            // button is disabled by renderBill() whenever below_min_order_amount
            // (or any unrecognized zero-total warning) is active, see H4.
            return
        }
        if (addressId == null) {
            InAppNotifier.show(this, "Add or select a delivery address first", InAppNotifier.Type.INFO)
            return
        }

        val paymentMethod = if (binding.radioUpi.isChecked) "upi" else "cod"
        val instructions = binding.inputInstructions.text?.toString()?.trim().orEmpty().ifEmpty { null }

        // bugs.md #2.4 — reuse the same key if one's already pending for
        // this attempt (defensive; the button-disable above should already
        // prevent a second concurrent tap from reaching here), otherwise
        // mint a fresh one. Cleared in both the error branches below so a
        // *new* tap after a fully-failed attempt is treated as a genuinely
        // new order server-side, not a retry of the failed one.
        val idempotencyKey = pendingIdempotencyKey ?: java.util.UUID.randomUUID().toString().also {
            pendingIdempotencyKey = it
        }

        binding.btnPlaceOrder.isEnabled = false
        lifecycleScope.launch {
            try {
                val response = api.createOrder(
                    CreateOrderBody(
                        restaurantId = restaurantId,
                        items = cartLines(),
                        deliveryAddressId = addressId,
                        paymentMethod = paymentMethod,
                        couponCode = CartManager.getCart(restaurantId)?.appliedCouponCode,
                        deliveryInstructions = instructions,
                        // I4 — null for a normal "Deliver Now" order; server
                        // re-validates independently regardless (same-day,
                        // 20-min lead, within open hours), see the
                        // scheduled_time_* 422 handling below.
                        scheduledFor = CartManager.getCart(restaurantId)?.scheduledFor,
                        idempotencyKey = idempotencyKey
                    )
                )
                val result = response.body()?.data
                if (response.isSuccessful && result != null) {
                    // Only this restaurant's cart clears — any other
                    // restaurant's cart the customer also has going stays
                    // exactly as it was (multi-restaurant cart, see
                    // CartManager.kt). removeCart() deletes the whole
                    // RestaurantCart object (and cleans up
                    // pendingScheduledFor), so scheduledFor doesn't need a
                    // separate reset here — it goes with the rest of the cart.
                    CartManager.removeCart(restaurantId)
                    // Phase J — belt-and-suspenders alongside
                    // CartAbandonmentWorker's own live re-check: if a timer
                    // happens to already be pending (rare — would need the
                    // app to have been backgrounded then reopened into
                    // Checkout without CartAbandonmentScheduler.cancel()
                    // having fired, which AnydropApplication's onStart
                    // already handles) cancel it explicitly here too.
                    com.anydrop.food.notifications.CartAbandonmentScheduler.cancel(this@CheckoutActivity)
                    // syncNow (not scheduleSync) — order placement is a hard
                    // exit point, no guarantee this Activity survives long
                    // enough for a 1s-debounced sync to fire.
                    com.anydrop.food.data.CartSyncManager.syncNow(this@CheckoutActivity)
                    val intent = Intent(this@CheckoutActivity, OrderStatusActivity::class.java)
                    intent.putExtra(OrderStatusActivity.EXTRA_ORDER_ID, result.order.id)
                    startActivity(intent)
                    // 2026-08-21 — starts the background poller that fires
                    // system notifications for "Order accepted", "Preparing
                    // your order", etc. even once this Activity (and the
                    // OrderStatusActivity it's about to open) are gone.
                    // See OrderUpdatePollingService's kdoc for why this is
                    // scoped differently from the restaurant app's
                    // always-on version.
                    com.anydrop.food.notifications.OrderUpdatePollingService.start(this@CheckoutActivity, result.order.id)
                    finish()
                } else {
                    // Bug fix (2026-08-10, alongside H4) — response.body() is
                    // always null here since this branch only runs on a
                    // non-2xx status; the real error lives in errorBody().
                    // See ApiErrorParser's kdoc for the full root cause.
                    val errInfo = com.anydrop.food.network.ApiErrorParser.parse(response)
                    val message = when (errInfo.code) {
                        "below_min_order_amount" -> {
                            val minOrder = (errInfo.data["min_order_amount"] as? Double) ?: 0.0
                            val itemTotal = (errInfo.data["item_total"] as? Double) ?: 0.0
                            val shortfall = (minOrder - itemTotal).coerceAtLeast(0.0)
                            getString(R.string.below_min_order_amount_with_amount, shortfall)
                        }
                        "invalid_coupon" -> getString(R.string.coupon_invalid)
                        "coupon_min_order_not_met" -> getString(R.string.coupon_min_order_not_met)
                        "coupon_usage_limit_reached" -> getString(R.string.coupon_usage_limit_reached)
                        // Bug fix (2026-08-13) — these two used to fall through
                        // to the generic `else -> errInfo.code` branch, showing
                        // the raw error code string to the customer instead of
                        // a real message.
                        "restaurant_closed" -> getString(R.string.restaurant_closed_error)
                        "restaurant_not_accepting_orders" -> getString(R.string.restaurant_paused_error)
                        // I4 — one generic message covering all four
                        // scheduled_time_* codes from validate_scheduled_for()
                        // rather than a message per code (design decision left
                        // open in the handover doc; either was fine).
                        "scheduled_time_not_today",
                        "scheduled_time_too_soon",
                        "scheduled_time_outside_open_hours",
                        "invalid_scheduled_time" -> getString(R.string.schedule_time_unavailable)
                        null -> getString(R.string.could_not_place_order)
                        else -> errInfo.code
                    }
                    InAppNotifier.show(this@CheckoutActivity, message, InAppNotifier.Type.ERROR)
                    pendingIdempotencyKey = null
                    binding.btnPlaceOrder.isEnabled = true
                }
            } catch (e: Exception) {
                InAppNotifier.show(this@CheckoutActivity, "Network error while placing the order.", InAppNotifier.Type.ERROR)
                // bugs.md #2.4 — deliberately NOT cleared here. A network
                // exception (timeout, connection drop) is exactly the case
                // this key exists for: the request may have actually landed
                // server-side. Keeping the same key means the user's next
                // tap is treated as a retry of this attempt (server returns
                // the already-created order) rather than creating a second
                // one — only a clean error *response* (validation failure,
                // coupon rejected, etc.) above means nothing was created,
                // which is when it's safe to mint a fresh key next time.
                binding.btnPlaceOrder.isEnabled = true
            }
        }
    }
}
