package com.anydrop.rider.ui.home

import android.Manifest
import android.annotation.SuppressLint
import android.content.Intent
import android.content.pm.PackageManager
import android.os.Bundle
import android.os.Handler
import android.os.Looper
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import androidx.activity.result.contract.ActivityResultContracts
import androidx.appcompat.app.AlertDialog
import androidx.core.content.ContextCompat
import androidx.fragment.app.Fragment
import androidx.lifecycle.lifecycleScope
import com.anydrop.rider.R
import com.anydrop.rider.data.TokenManager
import com.anydrop.rider.databinding.DialogDeliveryOtpBinding
import com.anydrop.rider.databinding.FragmentHomeBinding
import com.anydrop.rider.network.ApiClient
import com.anydrop.rider.network.CurrentOrder
import com.anydrop.rider.network.DeliverOrderBody
import com.anydrop.rider.network.LocationBody
import com.anydrop.rider.network.Offer
import com.anydrop.rider.network.OnlineStatusBody
import com.anydrop.rider.network.RejectOrderBody
import com.anydrop.rider.network.parseApiError
import com.anydrop.rider.ui.common.InAppNotifier
import com.anydrop.rider.ui.main.RiderMainActivity
import com.anydrop.rider.ui.orderdetail.RiderOrderDetailActivity
import com.anydrop.rider.ui.pending.ApplicationStatusActivity
import com.anydrop.rider.service.RiderOrderPollingService
import com.google.android.gms.location.LocationServices
import com.google.android.gms.location.Priority
import com.google.android.gms.tasks.CancellationTokenSource
import kotlinx.coroutines.launch

/**
 * Bottom-nav shell (v24 Part 2) — Home tab. Ported from
 * RiderDashboardActivity (see git history / that class's own kdoc for
 * the full feature history: Phase 3 R3 assignment engine, R4
 * pickup/dropoff flow, the v24 background-polling bug fix). Logic is
 * unchanged; only the Activity-specific scaffolding changed:
 *
 * - onCreate/onResume/onPause → onViewCreated/onResume/onPause (a
 *   Fragment still gets onPause/onResume calls from its host Activity's
 *   own lifecycle, so the same poller start/stop-on-visibility shape
 *   carries over directly).
 * - `binding` is now the nullable `_binding`/`binding` pair Fragments
 *   need (cleared in onDestroyView) — see AccountFragment/restaurant
 *   app's own Fragments for the same pattern already used elsewhere in
 *   this project.
 * - `this` (Activity context) → `requireContext()`/`requireActivity()`
 *   where a Context/Activity was needed (ApiClient.create,
 *   InAppNotifier.show, permission checks, dialogs).
 * - `goToLogin()`/`goToStatusScreen()` — a Fragment can't finish() its
 *   host Activity's task the way the old Activity could; these now
 *   delegate to RiderMainActivity, which owns the actual navigation
 *   (see that class's own goToLogin/goToStatusScreen).
 * - Logout button and the documents-alert TextView/notification bell
 *   moved out of this fragment's layout into RiderMainActivity's shared
 *   top bar (fragment_home.xml no longer has btnLogout/btnDocumentsAlert/
 *   btnNotifications/notificationBadge/dashboardGreeting at all) — see
 *   RiderMainActivity's kdoc for why those specific four elements
 *   became shell-level instead of per-tab.
 *
 * Everything else — online/offline toggle, the assignment-engine
 * poller, offer accept/reject, pickup/deliver + OTP dialog — is
 * line-for-line the same as the Activity version.
 *
 * 2026-09-07 — Periodic location pings MOVED to RiderOrderPollingService.
 * This fragment used to run its own `locationPoller`/`locationPollRunnable`
 * Handler loop (30s idle / 7s with an active order), the exact same
 * "foreground-only, dies on backgrounding" gap RiderOrderPollingService
 * itself was built to fix for order-offer polling (see that class's own
 * kdoc) — a rider who locks their screen or switches apps mid-delivery
 * would stop updating their live location on the admin map / customer
 * tracking screen the instant onPause() fired, exactly the "rider ka
 * live tracking accuracy" gap flagged this session. RiderOrderPollingService
 * now runs the same adaptive-interval location loop itself, independent
 * of any Activity/Fragment lifecycle, so it keeps working whether this
 * screen is open, backgrounded, or the phone is locked. This fragment
 * keeps ONLY the one-shot `sendLocationThenGoOnline()` ping (needed
 * synchronously before the "go online" API call can succeed) — the
 * ongoing periodic pings are the service's job now, not this
 * fragment's, avoiding the two ever running redundantly in parallel
 * while this screen happens to be visible.
 */
class HomeFragment : Fragment() {

    private var _binding: FragmentHomeBinding? = null
    private val binding get() = _binding!!

    private lateinit var tokenManager: TokenManager
    private val api by lazy { ApiClient.create(requireContext()) }
    private val fusedLocationClient by lazy { LocationServices.getFusedLocationProviderClient(requireContext()) }

    private val locationPermissionLauncher =
        registerForActivityResult(ActivityResultContracts.RequestPermission()) { granted ->
            if (granted) {
                sendLocationThenGoOnline()
            } else {
                _binding?.onlineSwitch?.isChecked = false
                InAppNotifier.show(activity, getString(R.string.dashboard_location_permission_denied), InAppNotifier.Type.INFO)
            }
        }

    private var suppressSwitchListener = false

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
    private var activeOrder: CurrentOrder? = null

    override fun onCreateView(
        inflater: LayoutInflater,
        container: ViewGroup?,
        savedInstanceState: Bundle?
    ): View {
        _binding = FragmentHomeBinding.inflate(inflater, container, false)
        return binding.root
    }

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        super.onViewCreated(view, savedInstanceState)
        tokenManager = TokenManager(requireContext())

        renderOnlineState(tokenManager.getIsOnline())

        binding.onlineSwitch.setOnCheckedChangeListener { _, checked ->
            if (suppressSwitchListener) return@setOnCheckedChangeListener
            if (checked) attemptGoOnline() else setOnlineStatus(false)
        }

        binding.btnAcceptOffer.setOnClickListener { currentOffer?.let { acceptOffer(it) } }
        binding.btnRejectOffer.setOnClickListener { currentOffer?.let { rejectOffer(it) } }

        binding.dashboardEarningsCard.setOnClickListener {
            (activity as? RiderMainActivity)?.goToEarningsTab()
        }

        binding.btnViewOrderDetail.setOnClickListener {
            activeOrder?.let { order ->
                startActivity(
                    Intent(requireContext(), RiderOrderDetailActivity::class.java)
                        .putExtra(RiderOrderDetailActivity.EXTRA_ORDER_ID, order.id)
                )
            }
        }
        binding.btnMarkPickedUp.setOnClickListener { activeOrder?.let { markPickedUp(it) } }
        binding.btnMarkDelivered.setOnClickListener {
            val order = activeOrder ?: return@setOnClickListener
            if (order.deliveryOtpRequired) {
                showDeliveryOtpDialog(order)
            } else {
                deliverOrder(order, otp = "")
            }
        }

        refreshFromServer()
        pollDashboardState()
        refreshEarnings()
    }

    override fun onResume() {
        super.onResume()
        dashboardPoller.postDelayed(dashboardPollRunnable, DASHBOARD_POLL_INTERVAL_MS)
    }

    override fun onPause() {
        super.onPause()
        dashboardPoller.removeCallbacks(dashboardPollRunnable)
        offerCountdownRunnable?.let { offerCountdown.removeCallbacks(it) }
    }

    override fun onDestroyView() {
        super.onDestroyView()
        _binding = null
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
                        tokenManager.updateStatus(result.status, result.rider.rejectionReason)
                        (activity as? RiderMainActivity)?.goToStatusScreen()
                        return@launch
                    }
                    tokenManager.setIsOnline(result.rider.isOnline)
                    tokenManager.updateDocumentsStatus(result.rider.documentsStatus)
                    renderOnlineState(result.rider.isOnline)
                    (activity as? RiderMainActivity)?.renderDocumentsEntryPoint()
                    renderCodBlockedBanner(result.rider.codBlocked, result.rider.codLimit)
                    if (result.rider.isOnline) {
                        RiderOrderPollingService.start(requireContext())
                    }
                } else {
                    val parsed = parseApiError(response.errorBody())
                    if (parsed.code == "account_suspended") {
                        tokenManager.clear()
                        (activity as? RiderMainActivity)?.goToLogin()
                    }
                }
            } catch (e: Exception) {
                // Transient network failure — cached state already rendered.
            }
        }
    }

    /** Called on load and again right after a successful delivery — NOT
     *  on every 5s dashboardPoller tick, which would be a DB read for a
     *  figure that's static between deliveries. */
    private fun refreshEarnings() {
        lifecycleScope.launch {
            try {
                val response = api.getEarningsSummary()
                if (response.isSuccessful && response.body()?.success == true) {
                    val result = response.body()?.data ?: return@launch
                    _binding?.dashboardEarningsValue?.text = getString(
                        R.string.dashboard_earnings_amount_format, result.todayTotal
                    )
                }
            } catch (e: Exception) {
                // Transient network failure — leave the rendered state as-is.
            }
        }
    }

    private fun attemptGoOnline() {
        val granted = ContextCompat.checkSelfPermission(requireContext(), Manifest.permission.ACCESS_FINE_LOCATION) ==
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
                    InAppNotifier.show(activity, getString(R.string.dashboard_location_required), InAppNotifier.Type.INFO)
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
                InAppNotifier.show(activity, getString(R.string.dashboard_location_required), InAppNotifier.Type.INFO)
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
                        activity,
                        getString(if (isOnline) R.string.dashboard_went_online else R.string.dashboard_went_offline),
                        InAppNotifier.Type.SUCCESS
                    )
                    if (isOnline) {
                        pollDashboardState()
                        RiderOrderPollingService.start(requireContext())
                    } else {
                        clearOffer()
                        if (!hasActiveOrder) showNoActiveDelivery()
                        RiderOrderPollingService.stop(requireContext())
                    }
                } else {
                    val parsed = parseApiError(response.errorBody())
                    setSwitchChecked(false)
                    if (parsed.code == "location_required") {
                        InAppNotifier.show(activity, getString(R.string.dashboard_location_required), InAppNotifier.Type.INFO)
                    } else if (parsed.code == "account_suspended") {
                        tokenManager.clear()
                        (activity as? RiderMainActivity)?.goToLogin()
                    } else {
                        InAppNotifier.show(activity, getString(R.string.error_network), InAppNotifier.Type.ERROR)
                    }
                }
            } catch (e: Exception) {
                setSwitchLoading(false)
                setSwitchChecked(false)
                InAppNotifier.show(activity, getString(R.string.error_network), InAppNotifier.Type.ERROR)
            }
        }
    }

    private fun setSwitchChecked(checked: Boolean) {
        val b = _binding ?: return
        suppressSwitchListener = true
        b.onlineSwitch.isChecked = checked
        suppressSwitchListener = false
    }

    private fun renderOnlineState(online: Boolean) {
        setSwitchChecked(online)
        val b = _binding ?: return
        if (online) {
            b.onlineStatusTitle.text = getString(R.string.dashboard_online_title)
            b.onlineStatusSubtitle.text = getString(R.string.dashboard_online_subtitle)
        } else {
            b.onlineStatusTitle.text = getString(R.string.dashboard_offline_title)
            b.onlineStatusSubtitle.text = getString(R.string.dashboard_offline_subtitle)
        }
    }

    /** Deep Plan Phase 4 (2026-09-09) — shows/hides the persistent
     *  cash-hold-limit banner per /rider/me's cod_blocked flag. Purely
     *  a reflection of the server-side block lib/dispatch.php's
     *  find_eligible_riders() already enforces — this never decides
     *  eligibility itself, only reports it. */
    private fun renderCodBlockedBanner(codBlocked: Boolean, codLimit: Double) {
        val b = _binding ?: return
        b.codBlockedBanner.visibility = if (codBlocked) View.VISIBLE else View.GONE
        if (codBlocked) {
            b.codBlockedBannerText.text = getString(R.string.dashboard_cod_blocked_banner, codLimit)
        }
    }

    private fun setSwitchLoading(loading: Boolean) {
        val b = _binding ?: return
        b.onlineSwitch.isEnabled = !loading
        b.onlineSwitchProgress.visibility = if (loading) View.VISIBLE else View.GONE
    }

    /** Checks current-order first (an active delivery always wins the
     *  card), then falls back to checking for a new offer only if
     *  there's no active delivery. Skips entirely while offline. */
    private fun pollDashboardState() {
        if (!::tokenManager.isInitialized) return
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
        val b = _binding ?: return

        b.noActiveDeliveryCard.visibility = View.GONE
        b.offerCard.visibility = View.GONE
        b.currentOrderCard.visibility = View.VISIBLE

        b.currentOrderStatusPill.text = when (order.status) {
            "picked_up" -> getString(R.string.status_picked_up)
            "out_for_delivery" -> getString(R.string.status_out_for_delivery)
            else -> getString(R.string.status_rider_assigned)
        }
        b.currentOrderRestaurant.text = order.restaurantName
        b.currentOrderAddress.text = order.deliveryAddress?.let {
            "Deliver to: $it"
        } ?: order.restaurantAddress
        val paymentLabel = if (order.paymentMethod == "cod") "COD ₹${order.grandTotal.toInt()}" else "Paid"
        b.currentOrderMeta.text = "Order #${order.orderCode} • $paymentLabel"

        b.btnMarkPickedUp.visibility =
            if (order.status == "rider_assigned") View.VISIBLE else View.GONE
        b.btnMarkDelivered.visibility =
            if (order.status == "out_for_delivery") View.VISIBLE else View.GONE
    }

    private fun renderOffer(offer: Offer) {
        currentOffer = offer
        val b = _binding ?: return
        b.noActiveDeliveryCard.visibility = View.GONE
        b.currentOrderCard.visibility = View.GONE
        b.offerCard.visibility = View.VISIBLE

        b.offerRestaurant.text = offer.restaurantName
        val distancePart = offer.distanceKm?.let { "${it} km • " } ?: ""
        val paymentPart = if (offer.paymentMethod == "cod") "COD ₹${offer.grandTotal.toInt()}" else "Paid"
        b.offerDetails.text = "$distancePart${offer.itemCount} items • $paymentPart"

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
                _binding?.offerTimer?.text = String.format("0:%02d", remaining)
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
        _binding?.offerCard?.visibility = View.GONE
    }

    private fun showNoActiveDelivery() {
        val b = _binding ?: return
        b.offerCard.visibility = View.GONE
        b.currentOrderCard.visibility = View.GONE
        b.noActiveDeliveryCard.visibility = View.VISIBLE
    }

    private fun acceptOffer(offer: Offer) {
        val b = _binding ?: return
        b.btnAcceptOffer.isEnabled = false
        b.btnRejectOffer.isEnabled = false
        lifecycleScope.launch {
            try {
                val response = api.acceptOrder(offer.orderId)
                if (response.isSuccessful && response.body()?.success == true) {
                    InAppNotifier.show(activity, getString(R.string.dashboard_offer_accepted), InAppNotifier.Type.SUCCESS)
                    clearOffer()
                    pollDashboardState()
                } else {
                    InAppNotifier.show(activity, getString(R.string.dashboard_offer_expired), InAppNotifier.Type.INFO)
                    clearOffer()
                    pollDashboardState()
                }
            } catch (e: Exception) {
                InAppNotifier.show(activity, getString(R.string.error_network), InAppNotifier.Type.ERROR)
            } finally {
                _binding?.btnAcceptOffer?.isEnabled = true
                _binding?.btnRejectOffer?.isEnabled = true
            }
        }
    }

    private fun rejectOffer(offer: Offer) {
        val b = _binding ?: return
        b.btnAcceptOffer.isEnabled = false
        b.btnRejectOffer.isEnabled = false
        lifecycleScope.launch {
            try {
                api.rejectOrder(offer.orderId, RejectOrderBody())
            } catch (e: Exception) {
                // Best-effort — clearing locally either way is correct.
            }
            clearOffer()
            pollDashboardState()
            _binding?.btnAcceptOffer?.isEnabled = true
            _binding?.btnRejectOffer?.isEnabled = true
        }
    }

    private fun markPickedUp(order: CurrentOrder) {
        _binding?.btnMarkPickedUp?.isEnabled = false
        lifecycleScope.launch {
            try {
                val response = api.pickupOrder(order.id)
                if (response.isSuccessful && response.body()?.success == true) {
                    InAppNotifier.show(activity, getString(R.string.pickup_confirmed), InAppNotifier.Type.SUCCESS)
                } else {
                    val parsed = parseApiError(response.errorBody())
                    if (parsed.code != "invalid_state") {
                        InAppNotifier.show(activity, getString(R.string.error_network), InAppNotifier.Type.ERROR)
                    }
                }
            } catch (e: Exception) {
                InAppNotifier.show(activity, getString(R.string.error_network), InAppNotifier.Type.ERROR)
            } finally {
                _binding?.btnMarkPickedUp?.isEnabled = true
                pollDashboardState()
            }
        }
    }

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
                    InAppNotifier.show(activity, getString(R.string.delivery_confirmed), InAppNotifier.Type.SUCCESS)
                    onDone?.invoke()
                    pollDashboardState()
                    refreshEarnings()
                } else {
                    val parsed = parseApiError(response.errorBody())
                    when (parsed.code) {
                        "invalid_otp" -> {
                            onInvalidOtp?.invoke(parsed.attemptsRemaining)
                        }
                        "otp_max_attempts_exceeded" -> {
                            InAppNotifier.show(activity, getString(R.string.error_delivery_otp_locked), InAppNotifier.Type.ERROR)
                            onDone?.invoke()
                        }
                        "invalid_state" -> {
                            onDone?.invoke()
                            pollDashboardState()
                        }
                        else -> {
                            InAppNotifier.show(activity, getString(R.string.error_network), InAppNotifier.Type.ERROR)
                            onDone?.invoke()
                            pollDashboardState()
                        }
                    }
                }
            } catch (e: Exception) {
                InAppNotifier.show(activity, getString(R.string.error_network), InAppNotifier.Type.ERROR)
                onDone?.invoke()
                pollDashboardState()
            }
        }
    }

    private fun showDeliveryOtpDialog(order: CurrentOrder) {
        val dialogBinding = DialogDeliveryOtpBinding.inflate(layoutInflater)

        val dialog = AlertDialog.Builder(requireContext())
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
                        dialog.dismiss()
                    }
                )
            }
        }

        dialog.show()
    }

    companion object {
        private const val DASHBOARD_POLL_INTERVAL_MS = 5_000L
    }
}
