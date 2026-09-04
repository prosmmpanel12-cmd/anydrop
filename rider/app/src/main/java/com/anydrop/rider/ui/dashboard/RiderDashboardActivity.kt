package com.anydrop.rider.ui.dashboard

import android.Manifest
import android.annotation.SuppressLint
import android.content.Intent
import android.content.pm.PackageManager
import android.os.Bundle
import android.os.Handler
import android.os.Looper
import android.view.View
import androidx.activity.result.contract.ActivityResultContracts
import androidx.appcompat.app.AlertDialog
import androidx.appcompat.app.AppCompatActivity
import androidx.core.content.ContextCompat
import androidx.lifecycle.lifecycleScope
import com.anydrop.rider.R
import com.anydrop.rider.data.TokenManager
import com.anydrop.rider.databinding.ActivityRiderDashboardBinding
import com.anydrop.rider.databinding.DialogDeliveryOtpBinding
import com.anydrop.rider.network.ApiClient
import com.anydrop.rider.network.CurrentOrder
import com.anydrop.rider.network.DeliverOrderBody
import com.anydrop.rider.network.LocationBody
import com.anydrop.rider.network.Offer
import com.anydrop.rider.network.OnlineStatusBody
import com.anydrop.rider.network.RejectOrderBody
import com.anydrop.rider.network.parseApiError
import com.anydrop.rider.ui.common.InAppNotifier
import com.anydrop.rider.ui.login.LoginActivity
import com.anydrop.rider.ui.pending.ApplicationStatusActivity
import com.google.android.gms.location.LocationServices
import com.google.android.gms.location.Priority
import com.google.android.gms.tasks.CancellationTokenSource
import kotlinx.coroutines.launch

/**
 * Landing screen for approved riders (Phase 3, doc 83). Reached only
 * via ApplicationStatusActivity's redirect — see that screen's kdoc
 * for why the branch lives there instead of at every login/signup
 * call site.
 *
 * Scope for this slice, deliberately: online/offline switch + a static
 * "no active delivery" / "₹0 earnings" placeholder. No order data flows
 * here yet — that's R3 (assignment engine), not built. See
 * docs/rider/83_Plan_Phase3... "Explicitly out of scope".
 *
 * Location: going online requires a location already on file
 * server-side (status.php returns 422 location_required otherwise).
 * Rather than surface that as a confusing error, this screen requests
 * the permission + sends one location ping proactively the moment the
 * rider flips the switch on, then retries the online call. Reuses the
 * same ACCESS_FINE_LOCATION permission SignupActivity already requests
 * (already granted for most riders by the time they reach here).
 *
 * Foreground-only polling while online (see doc 83 "explicitly out of
 * scope" re: background service) — a periodic ping via postDelayed,
 * stopped in onPause so it never runs with the screen off/backgrounded.
 * 30s interval per the doc's stated default.
 *
 * Phase 3 R3 (doc 85) added the assignment engine on top: a second,
 * faster poller (5s) checks /rider/orders-available for an incoming
 * offer and /rider/orders-current for an in-progress delivery, and the
 * screen shows exactly one of three cards at a time — no active
 * delivery / an offer with Accept-Reject / the active delivery's
 * read-only details. Both pollers stop in onPause the same way the
 * location poller always has.
 */
class RiderDashboardActivity : AppCompatActivity() {

    private lateinit var binding: ActivityRiderDashboardBinding
    private lateinit var tokenManager: TokenManager
    private val api by lazy { ApiClient.create(this) }
    private val fusedLocationClient by lazy { LocationServices.getFusedLocationProviderClient(this) }

    private val locationPermissionLauncher =
        registerForActivityResult(ActivityResultContracts.RequestPermission()) { granted ->
            if (granted) {
                sendLocationThenGoOnline()
            } else {
                binding.onlineSwitch.isChecked = false
                InAppNotifier.show(this, getString(R.string.dashboard_location_permission_denied), InAppNotifier.Type.INFO)
            }
        }

    // Phase 3 R4 (deep-plan §12) — interval tightens to
    // LOCATION_POLL_INTERVAL_ACTIVE_MS while activeOrder is non-null (an
    // in-progress delivery a customer may be watching) and relaxes back
    // to LOCATION_POLL_INTERVAL_MS otherwise. Deep-plan §12's full table
    // (60s/10s/5-7s/20s/3-5s by more granular state) is deferred — this
    // two-tier version is the minimum needed for rider_locations to
    // actually get written during a delivery, not the full tuned model;
    // an app_settings-driven version of the full table is future work,
    // same as deep-plan §12's own "should become Admin-configurable"
    // note flags it as.
    private val locationPoller = Handler(Looper.getMainLooper())
    private val locationPollRunnable = object : Runnable {
        override fun run() {
            sendLocationPing()
            val interval = if (activeOrder != null) LOCATION_POLL_INTERVAL_ACTIVE_MS else LOCATION_POLL_INTERVAL_MS
            locationPoller.postDelayed(this, interval)
        }
    }
    private var suppressSwitchListener = false

    // Phase 3 R3 (doc 85) — offer/current-order polling + offer countdown.
    private val dashboardPoller = Handler(Looper.getMainLooper())
    private val dashboardPollRunnable = object : Runnable {
        override fun run() {
            pollDashboardState()
            dashboardPoller.postDelayed(this, DASHBOARD_POLL_INTERVAL_MS)
        }
    }
    private val offerCountdown = Handler(Looper.getMainLooper())
    private var offerCountdownRunnable: Runnable? = null
    private var hasActiveOrder = false
    private var currentOffer: Offer? = null
    // Pickup/drop-off flow (this session) — the currently-shown active
    // order, needed by the pickup/deliver button handlers (order id +
    // deliveryOtpRequired) which renderCurrentOrder() alone doesn't keep
    // around anywhere else.
    private var activeOrder: CurrentOrder? = null

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityRiderDashboardBinding.inflate(layoutInflater)
        setContentView(binding.root)

        tokenManager = TokenManager(this)

        if (!tokenManager.isLoggedIn()) {
            goToLogin()
            return
        }

        binding.dashboardGreeting.text = getString(
            R.string.dashboard_greeting_format,
            tokenManager.getRiderName() ?: ""
        )
        renderOnlineState(tokenManager.getIsOnline())

        binding.btnLogout.setOnClickListener {
            tokenManager.clear()
            goToLogin()
        }

        binding.onlineSwitch.setOnCheckedChangeListener { _, checked ->
            if (suppressSwitchListener) return@setOnCheckedChangeListener
            if (checked) attemptGoOnline() else setOnlineStatus(false)
        }

        binding.btnAcceptOffer.setOnClickListener { currentOffer?.let { acceptOffer(it) } }
        binding.btnRejectOffer.setOnClickListener { currentOffer?.let { rejectOffer(it) } }

        binding.btnMarkPickedUp.setOnClickListener { activeOrder?.let { markPickedUp(it) } }
        binding.btnMarkDelivered.setOnClickListener {
            val order = activeOrder ?: return@setOnClickListener
            if (order.deliveryOtpRequired) {
                showDeliveryOtpDialog(order)
            } else {
                // No OTP needed (e.g. COD with otp_required_for_cod off) —
                // call deliver directly with an empty code, matching the
                // delivery_otp !== null guard in orders-deliver.php.
                // No dialog to dismiss, no invalid_otp path to handle.
                deliverOrder(order, otp = "")
                // deliverOrder's own InAppNotifier + pollDashboardState handle
                // the rest; no extra work needed from the click listener.
            }
        }

        refreshFromServer()
        pollDashboardState()
        refreshEarnings()
    }

    override fun onResume() {
        super.onResume()
        if (tokenManager.getIsOnline()) {
            locationPoller.post(locationPollRunnable)
        }
        dashboardPoller.postDelayed(dashboardPollRunnable, DASHBOARD_POLL_INTERVAL_MS)
    }

    override fun onPause() {
        super.onPause()
        locationPoller.removeCallbacks(locationPollRunnable)
        dashboardPoller.removeCallbacks(dashboardPollRunnable)
        offerCountdownRunnable?.let { offerCountdown.removeCallbacks(it) }
    }

    /** Bootstraps from /rider/me so the switch reflects the server's actual
     *  state (not just what was cached at last login/refresh), and catches
     *  the case where status changed since login (e.g. suspended). */
    private fun refreshFromServer() {
        lifecycleScope.launch {
            try {
                val response = api.getMe()
                if (response.isSuccessful && response.body()?.success == true) {
                    val result = response.body()?.data ?: return@launch
                    if (result.status != "approved") {
                        // No longer approved (e.g. suspended since login) —
                        // TokenManager + ApplicationStatusActivity handle
                        // every non-approved case, including logout on
                        // suspension; just hand off.
                        tokenManager.updateStatus(result.status, result.rider.rejectionReason)
                        goToStatusScreen()
                        return@launch
                    }
                    tokenManager.setIsOnline(result.rider.isOnline)
                    renderOnlineState(result.rider.isOnline)
                    if (result.rider.isOnline) {
                        locationPoller.removeCallbacks(locationPollRunnable)
                        locationPoller.post(locationPollRunnable)
                    }
                } else {
                    val parsed = parseApiError(response.errorBody())
                    if (parsed.code == "account_suspended") {
                        tokenManager.clear()
                        goToLogin()
                    }
                    // Any other error: leave the cached/rendered state as-is,
                    // don't disrupt the screen for a transient failure.
                }
            } catch (e: Exception) {
                // Transient network failure — cached state already rendered.
            }
        }
    }

    /** Deep-plan §19-20 — today's earnings card, previously a static ₹0
     *  placeholder (see activity_rider_dashboard.xml's own comment).
     *  Called on load and again right after a successful delivery
     *  (deliverOrder()'s success branch) since that's the only moment
     *  the number can actually change — NOT on every 5s dashboardPoller
     *  tick, which would be a DB read for a figure that's static between
     *  deliveries. */
    private fun refreshEarnings() {
        lifecycleScope.launch {
            try {
                val response = api.getEarningsSummary()
                if (response.isSuccessful && response.body()?.success == true) {
                    val result = response.body()?.data ?: return@launch
                    binding.dashboardEarningsValue.text = getString(
                        R.string.dashboard_earnings_amount_format, result.todayTotal
                    )
                }
                // Any failure: leave whatever was last rendered (starts as
                // the static placeholder string on first load) — same
                // "don't disrupt the screen for a transient failure" stance
                // refreshFromServer() already takes.
            } catch (e: Exception) {
                // Transient network failure — leave the rendered state as-is.
            }
        }
    }

    private fun attemptGoOnline() {
        val granted = ContextCompat.checkSelfPermission(this, Manifest.permission.ACCESS_FINE_LOCATION) ==
            PackageManager.PERMISSION_GRANTED
        if (granted) {
            sendLocationThenGoOnline()
        } else {
            locationPermissionLauncher.launch(Manifest.permission.ACCESS_FINE_LOCATION)
        }
    }

    @SuppressLint("MissingPermission")
    private fun sendLocationThenGoOnline() {
        setSwitchLoading(true)
        val cancellationSource = CancellationTokenSource()
        fusedLocationClient.getCurrentLocation(Priority.PRIORITY_BALANCED_POWER_ACCURACY, cancellationSource.token)
            .addOnSuccessListener { location ->
                if (location == null) {
                    setSwitchLoading(false)
                    setSwitchChecked(false)
                    InAppNotifier.show(this, getString(R.string.dashboard_location_required), InAppNotifier.Type.INFO)
                    return@addOnSuccessListener
                }
                lifecycleScope.launch {
                    try {
                        api.updateLocation(LocationBody(location.latitude, location.longitude))
                    } catch (e: Exception) {
                        // Best-effort — status.php will reject with location_required
                        // below if this genuinely didn't land, and we surface that.
                    }
                    setOnlineStatus(true)
                }
            }
            .addOnFailureListener {
                setSwitchLoading(false)
                setSwitchChecked(false)
                InAppNotifier.show(this, getString(R.string.dashboard_location_required), InAppNotifier.Type.INFO)
            }
    }

    private fun sendLocationPing() {
        if (ContextCompat.checkSelfPermission(this, Manifest.permission.ACCESS_FINE_LOCATION) !=
            PackageManager.PERMISSION_GRANTED
        ) {
            return
        }
        sendLocationPingInternal()
    }

    @SuppressLint("MissingPermission")
    private fun sendLocationPingInternal() {
        // Read once up front rather than inside the async callback — avoids
        // a race where activeOrder changes (e.g. delivery completes) between
        // the location fetch starting and the ping actually being sent;
        // either value is a legitimate snapshot of "was there an active
        // order roughly when this ping was taken", and location.php treats
        // a stale/foreign order_id as a silent no-op regardless.
        val orderId = activeOrder?.id
        val cancellationSource = CancellationTokenSource()
        fusedLocationClient.getCurrentLocation(Priority.PRIORITY_BALANCED_POWER_ACCURACY, cancellationSource.token)
            .addOnSuccessListener { location ->
                if (location != null) {
                    val speedKmh = if (location.hasSpeed()) (location.speed * 3.6).toDouble() else null
                    lifecycleScope.launch {
                        try {
                            api.updateLocation(
                                LocationBody(location.latitude, location.longitude, orderId, speedKmh)
                            )
                        } catch (e: Exception) {
                            // Silent — next poll tries again.
                        }
                    }
                }
            }
    }

    private fun setOnlineStatus(online: Boolean) {
        setSwitchLoading(true)
        lifecycleScope.launch {
            try {
                val response = api.setOnlineStatus(OnlineStatusBody(online))
                setSwitchLoading(false)
                if (response.isSuccessful && response.body()?.success == true) {
                    val isOnline = response.body()?.data?.isOnline ?: online
                    tokenManager.setIsOnline(isOnline)
                    renderOnlineState(isOnline)
                    setSwitchChecked(isOnline)
                    InAppNotifier.show(
                        this@RiderDashboardActivity,
                        getString(if (isOnline) R.string.dashboard_went_online else R.string.dashboard_went_offline),
                        InAppNotifier.Type.SUCCESS
                    )
                    if (isOnline) {
                        locationPoller.removeCallbacks(locationPollRunnable)
                        locationPoller.post(locationPollRunnable)
                        pollDashboardState()
                    } else {
                        locationPoller.removeCallbacks(locationPollRunnable)
                        clearOffer()
                        if (!hasActiveOrder) showNoActiveDelivery()
                    }
                } else {
                    val parsed = parseApiError(response.errorBody())
                    setSwitchChecked(false)
                    if (parsed.code == "location_required") {
                        InAppNotifier.show(this@RiderDashboardActivity, getString(R.string.dashboard_location_required), InAppNotifier.Type.INFO)
                    } else if (parsed.code == "account_suspended") {
                        tokenManager.clear()
                        goToLogin()
                    } else {
                        InAppNotifier.show(this@RiderDashboardActivity, getString(R.string.error_network), InAppNotifier.Type.ERROR)
                    }
                }
            } catch (e: Exception) {
                setSwitchLoading(false)
                setSwitchChecked(false)
                InAppNotifier.show(this@RiderDashboardActivity, getString(R.string.error_network), InAppNotifier.Type.ERROR)
            }
        }
    }

    /** Updates the switch without re-triggering setOnCheckedChangeListener
     *  (avoids a network-call loop when we set it programmatically). */
    private fun setSwitchChecked(checked: Boolean) {
        suppressSwitchListener = true
        binding.onlineSwitch.isChecked = checked
        suppressSwitchListener = false
    }

    private fun renderOnlineState(online: Boolean) {
        setSwitchChecked(online)
        if (online) {
            binding.onlineStatusTitle.text = getString(R.string.dashboard_online_title)
            binding.onlineStatusSubtitle.text = getString(R.string.dashboard_online_subtitle)
        } else {
            binding.onlineStatusTitle.text = getString(R.string.dashboard_offline_title)
            binding.onlineStatusSubtitle.text = getString(R.string.dashboard_offline_subtitle)
        }
    }

    private fun setSwitchLoading(loading: Boolean) {
        binding.onlineSwitch.isEnabled = !loading
        binding.onlineSwitchProgress.visibility = if (loading) View.VISIBLE else View.GONE
    }

    /** Phase 3 R3 (doc 85) — checks current-order first (an active
     *  delivery always wins the card), then falls back to checking for
     *  a new offer only if there's no active delivery. Skips entirely
     *  while offline — the backend never offers an offline rider
     *  anything, no point polling for it. */
    private fun pollDashboardState() {
        if (!tokenManager.getIsOnline() && !hasActiveOrder) return
        lifecycleScope.launch {
            try {
                val currentResponse = api.getCurrentOrder()
                val order = if (currentResponse.isSuccessful) currentResponse.body()?.data?.order else null
                if (order != null) {
                    hasActiveOrder = true
                    clearOffer()
                    renderCurrentOrder(order)
                    return@launch
                }
                hasActiveOrder = false

                if (!tokenManager.getIsOnline()) {
                    showNoActiveDelivery()
                    return@launch
                }
                val offerResponse = api.getAvailableOffer()
                val offer = if (offerResponse.isSuccessful) offerResponse.body()?.data?.offer else null
                if (offer != null) {
                    renderOffer(offer)
                } else {
                    clearOffer()
                    showNoActiveDelivery()
                }
            } catch (e: Exception) {
                // Transient — next poll tries again, leave current UI as-is.
            }
        }
    }

    private fun renderCurrentOrder(order: CurrentOrder) {
        activeOrder = order

        binding.noActiveDeliveryCard.visibility = View.GONE
        binding.offerCard.visibility = View.GONE
        binding.currentOrderCard.visibility = View.VISIBLE

        binding.currentOrderStatusPill.text = when (order.status) {
            "picked_up" -> getString(R.string.status_picked_up)
            "out_for_delivery" -> getString(R.string.status_out_for_delivery)
            else -> getString(R.string.status_rider_assigned)
        }
        binding.currentOrderRestaurant.text = order.restaurantName
        binding.currentOrderAddress.text = order.deliveryAddress?.let {
            "Deliver to: $it"
        } ?: order.restaurantAddress
        val paymentLabel = if (order.paymentMethod == "cod") "COD ₹${order.grandTotal.toInt()}" else "Paid"
        binding.currentOrderMeta.text = "Order #${order.orderCode} • $paymentLabel"

        // Show exactly one action button depending on status. Both are GONE
        // by default in the layout; always set both explicitly so that a
        // re-render after a status change clears the previous visible one.
        binding.btnMarkPickedUp.visibility =
            if (order.status == "rider_assigned") View.VISIBLE else View.GONE
        binding.btnMarkDelivered.visibility =
            if (order.status == "out_for_delivery") View.VISIBLE else View.GONE
    }

    private fun renderOffer(offer: Offer) {
        currentOffer = offer
        binding.noActiveDeliveryCard.visibility = View.GONE
        binding.currentOrderCard.visibility = View.GONE
        binding.offerCard.visibility = View.VISIBLE

        binding.offerRestaurant.text = offer.restaurantName
        val distancePart = offer.distanceKm?.let { "${it} km • " } ?: ""
        val paymentPart = if (offer.paymentMethod == "cod") "COD ₹${offer.grandTotal.toInt()}" else "Paid"
        binding.offerDetails.text = "$distancePart${offer.itemCount} items • $paymentPart"

        offerCountdownRunnable?.let { offerCountdown.removeCallbacks(it) }
        var remaining = offer.expiresInSeconds
        val runnable = object : Runnable {
            override fun run() {
                if (remaining <= 0 || currentOffer?.assignmentId != offer.assignmentId) {
                    if (currentOffer?.assignmentId == offer.assignmentId) {
                        clearOffer()
                        pollDashboardState()
                    }
                    return
                }
                binding.offerTimer.text = String.format("0:%02d", remaining)
                remaining -= 1
                offerCountdown.postDelayed(this, 1000L)
            }
        }
        offerCountdownRunnable = runnable
        offerCountdown.post(runnable)
    }

    private fun clearOffer() {
        currentOffer = null
        offerCountdownRunnable?.let { offerCountdown.removeCallbacks(it) }
        binding.offerCard.visibility = View.GONE
    }

    private fun showNoActiveDelivery() {
        binding.offerCard.visibility = View.GONE
        binding.currentOrderCard.visibility = View.GONE
        binding.noActiveDeliveryCard.visibility = View.VISIBLE
    }

    private fun acceptOffer(offer: Offer) {
        binding.btnAcceptOffer.isEnabled = false
        binding.btnRejectOffer.isEnabled = false
        lifecycleScope.launch {
            try {
                val response = api.acceptOrder(offer.orderId)
                if (response.isSuccessful && response.body()?.success == true) {
                    InAppNotifier.show(this@RiderDashboardActivity, getString(R.string.dashboard_offer_accepted), InAppNotifier.Type.SUCCESS)
                    clearOffer()
                    pollDashboardState()
                } else {
                    // offer_expired (already taken/timed out) or anything
                    // else — either way this offer is no longer live for
                    // us, drop it and go back to polling for the next one.
                    InAppNotifier.show(this@RiderDashboardActivity, getString(R.string.dashboard_offer_expired), InAppNotifier.Type.INFO)
                    clearOffer()
                    pollDashboardState()
                }
            } catch (e: Exception) {
                InAppNotifier.show(this@RiderDashboardActivity, getString(R.string.error_network), InAppNotifier.Type.ERROR)
            } finally {
                binding.btnAcceptOffer.isEnabled = true
                binding.btnRejectOffer.isEnabled = true
            }
        }
    }

    private fun rejectOffer(offer: Offer) {
        binding.btnAcceptOffer.isEnabled = false
        binding.btnRejectOffer.isEnabled = false
        lifecycleScope.launch {
            try {
                api.rejectOrder(offer.orderId, RejectOrderBody())
            } catch (e: Exception) {
                // Best-effort — clearing locally either way is correct:
                // even if this call didn't land, the rider chose not to
                // take it, and expire_stale_offers() will clean the row
                // up server-side once its timeout passes regardless.
            }
            clearOffer()
            pollDashboardState()
            binding.btnAcceptOffer.isEnabled = true
            binding.btnRejectOffer.isEnabled = true
        }
    }

    /** Calls orders-pickup.php. On success, re-polls to pick up the new
     *  out_for_delivery status and swap the button. On 409 invalid_state
     *  (order moved on via another path — unlikely but race-safe), re-polls
     *  silently rather than surfacing a confusing error; the card will just
     *  re-render with whatever the server now says. */
    private fun markPickedUp(order: CurrentOrder) {
        binding.btnMarkPickedUp.isEnabled = false
        lifecycleScope.launch {
            try {
                val response = api.pickupOrder(order.id)
                if (response.isSuccessful && response.body()?.success == true) {
                    InAppNotifier.show(
                        this@RiderDashboardActivity,
                        getString(R.string.pickup_confirmed),
                        InAppNotifier.Type.SUCCESS
                    )
                } else {
                    val parsed = parseApiError(response.errorBody())
                    if (parsed.code != "invalid_state") {
                        // invalid_state is a silent re-poll (see kdoc above).
                        // Anything else (unexpected server error) warrants a toast.
                        InAppNotifier.show(
                            this@RiderDashboardActivity,
                            getString(R.string.error_network),
                            InAppNotifier.Type.ERROR
                        )
                    }
                }
            } catch (e: Exception) {
                InAppNotifier.show(
                    this@RiderDashboardActivity,
                    getString(R.string.error_network),
                    InAppNotifier.Type.ERROR
                )
            } finally {
                // Always re-poll regardless of outcome so the card reflects
                // the server's current truth. Re-enable the button before
                // polling; renderCurrentOrder() will hide it if status moved on.
                binding.btnMarkPickedUp.isEnabled = true
                pollDashboardState()
            }
        }
    }

    /** Shared deliver call used by both the OTP dialog (with a real code) and
     *  the no-OTP fast path (empty string). Not called directly from the
     *  click listener — routed via showDeliveryOtpDialog or the fast path
     *  in the btnMarkDelivered listener depending on deliveryOtpRequired.
     *
     *  onInvalidOtp — called on the main thread when the server returns
     *    invalid_otp (401). The dialog keeps itself open to let the rider
     *    retry; the lambda shows the inline error with attemptsRemaining.
     *  onDone — called on the main thread for every other outcome (success,
     *    locked, network error, invalid_state). The dialog uses this to
     *    dismiss itself once we know we're not staying open for a retry. */
    private fun deliverOrder(
        order: CurrentOrder,
        otp: String,
        onInvalidOtp: ((attemptsRemaining: Int?) -> Unit)? = null,
        onDone: (() -> Unit)? = null
    ) {
        lifecycleScope.launch {
            try {
                val response = api.deliverOrder(order.id, DeliverOrderBody(otp))
                if (response.isSuccessful && response.body()?.success == true) {
                    InAppNotifier.show(
                        this@RiderDashboardActivity,
                        getString(R.string.delivery_confirmed),
                        InAppNotifier.Type.SUCCESS
                    )
                    onDone?.invoke()
                    pollDashboardState()
                    refreshEarnings()
                } else {
                    val parsed = parseApiError(response.errorBody())
                    when (parsed.code) {
                        "invalid_otp" -> {
                            // Keep the dialog open — onInvalidOtp shows the
                            // inline error and re-enables the Confirm button.
                            onInvalidOtp?.invoke(parsed.attemptsRemaining)
                            // Do NOT call onDone — dialog must stay visible.
                        }
                        "otp_max_attempts_exceeded" -> {
                            // Order stays out_for_delivery server-side (deep-plan
                            // §16: never change status on a bad OTP). No re-poll
                            // needed — the card is already correct.
                            InAppNotifier.show(
                                this@RiderDashboardActivity,
                                getString(R.string.error_delivery_otp_locked),
                                InAppNotifier.Type.ERROR
                            )
                            onDone?.invoke()
                        }
                        "invalid_state" -> {
                            // Order moved on via another path — silent re-poll
                            // to resync the card.
                            onDone?.invoke()
                            pollDashboardState()
                        }
                        else -> {
                            InAppNotifier.show(
                                this@RiderDashboardActivity,
                                getString(R.string.error_network),
                                InAppNotifier.Type.ERROR
                            )
                            onDone?.invoke()
                            pollDashboardState()
                        }
                    }
                }
            } catch (e: Exception) {
                InAppNotifier.show(
                    this@RiderDashboardActivity,
                    getString(R.string.error_network),
                    InAppNotifier.Type.ERROR
                )
                onDone?.invoke()
                pollDashboardState()
            }
        }
    }

    /** Inflates dialog_delivery_otp.xml, shows the OTP entry dialog, and
     *  calls deliverOrder() on Confirm. Three outcomes per the handover spec:
     *  - success            → dismiss + toast + re-poll (deliverOrder handles)
     *  - invalid_otp        → keep dialog open, show attemptsRemaining inline
     *  - locked/error/409   → dismiss, InAppNotifier (deliverOrder handles)
     *
     *  Uses a simple single-field layout (not the boxed 6-digit grid from
     *  activity_otp_verify.xml) because the rider is typing a code the
     *  customer reads aloud — manual entry, not SMS autofill.
     *
     *  dialog_delivery_otp.xml has no buttons of its own (just the field +
     *  inline error) — Confirm/Cancel come from AlertDialog.Builder's
     *  standard positive/negative buttons. The positive button's click
     *  listener is overridden after show() so it can stay open on an
     *  invalid-OTP retry instead of auto-dismissing (the default behavior
     *  of a button set via setPositiveButton).
     *
     *  Dismiss timing: deliverOrder is a coroutine; onInvalidOtp/onDone fire
     *  on the main thread after the Retrofit call completes, so the dialog
     *  is dismissed from onDone rather than eagerly on click. */
    private fun showDeliveryOtpDialog(order: CurrentOrder) {
        val dialogBinding = DialogDeliveryOtpBinding.inflate(layoutInflater)

        val dialog = AlertDialog.Builder(this)
            .setTitle(R.string.delivery_otp_dialog_title)
            .setView(dialogBinding.root)
            .setCancelable(true)
            .setPositiveButton(R.string.btn_mark_delivered, null)
            .setNegativeButton(android.R.string.cancel, null)
            .create()

        dialog.setOnShowListener {
            val confirmButton = dialog.getButton(AlertDialog.BUTTON_POSITIVE)
            confirmButton.setOnClickListener {
                val otp = dialogBinding.inputDeliveryOtp.text?.toString()?.trim() ?: ""
                if (otp.isEmpty()) {
                    dialogBinding.deliveryOtpError.text = getString(R.string.error_delivery_otp_empty)
                    dialogBinding.deliveryOtpError.visibility = View.VISIBLE
                    return@setOnClickListener
                }

                confirmButton.isEnabled = false
                dialogBinding.deliveryOtpError.visibility = View.GONE

                deliverOrder(
                    order = order,
                    otp = otp,
                    onInvalidOtp = { attemptsRemaining ->
                        // Keep dialog open — rider can retry.
                        confirmButton.isEnabled = true
                        val msg = if (attemptsRemaining != null) {
                            getString(R.string.error_delivery_otp_invalid_format, attemptsRemaining)
                        } else {
                            getString(R.string.error_delivery_otp_invalid)
                        }
                        dialogBinding.deliveryOtpError.text = msg
                        dialogBinding.deliveryOtpError.visibility = View.VISIBLE
                    },
                    onDone = {
                        // Called for every outcome except invalid_otp (success,
                        // locked, network error, invalid_state). dismiss() here
                        // is always safe — deliverOrder has already posted its
                        // own InAppNotifier/re-poll before invoking onDone.
                        dialog.dismiss()
                    }
                )
            }
        }

        dialog.show()
    }

    private fun goToStatusScreen() {
        val intent = Intent(this, ApplicationStatusActivity::class.java).apply {
            flags = Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TASK
        }
        startActivity(intent)
        finish()
    }

    private fun goToLogin() {
        val intent = Intent(this, LoginActivity::class.java).apply {
            flags = Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TASK
        }
        startActivity(intent)
        finish()
    }

    companion object {
        private const val LOCATION_POLL_INTERVAL_MS = 30_000L
        // Phase 3 R4 (deep-plan §12) — tighter interval used only while
        // activeOrder is non-null. 7s sits inside deep-plan §12's
        // "heading to restaurant" (~10s) / "heading to customer" (~5-7s)
        // band as a single reasonable middle value for this slice; the
        // full per-leg table is deferred, see locationPollRunnable's kdoc.
        private const val LOCATION_POLL_INTERVAL_ACTIVE_MS = 7_000L
        private const val DASHBOARD_POLL_INTERVAL_MS = 5_000L
    }
}
