package com.anydrop.food.ui.orderstatus

import android.animation.ValueAnimator
import android.content.Intent
import android.os.Bundle
import android.os.CountDownTimer
import android.os.SystemClock
import android.view.View
import android.widget.TextView
import androidx.appcompat.app.AppCompatActivity
import androidx.lifecycle.lifecycleScope
import com.anydrop.food.R
import com.anydrop.food.databinding.ActivityOrderStatusBinding
import com.anydrop.food.network.ApiClient
import com.anydrop.food.network.OrderTrackResult
import com.anydrop.food.network.RefundInfo
import com.anydrop.food.ui.common.InAppNotifier
import com.anydrop.food.ui.home.HomeActivity
import com.anydrop.food.ui.orders.RateOrderDialog
import com.anydrop.food.util.PolylineDecoder
import com.anydrop.food.util.RouteGeometry
import com.google.android.gms.maps.CameraUpdateFactory
import com.google.android.gms.maps.GoogleMap
import com.google.android.gms.maps.OnMapReadyCallback
import com.google.android.gms.maps.model.BitmapDescriptorFactory
import com.google.android.gms.maps.model.LatLng
import com.google.android.gms.maps.model.LatLngBounds
import com.google.android.gms.maps.model.Marker
import com.google.android.gms.maps.model.MarkerOptions
import com.google.android.gms.maps.model.Polyline
import com.google.android.gms.maps.model.PolylineOptions
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import java.text.SimpleDateFormat
import java.util.Locale

/**
 * Phase 3 — Order status/tracking. Polls GET /orders/{id}/track every 5s
 * while the order is active (simple polling, per docs/03_Live_Tracking.md —
 * this screen shows status + rider contact + delivery OTP once assigned).
 *
 * I2 (docs/features.md Phase I) adds the visual stepper — see
 * [OrderStatusStepperView] for the 9-status-to-5-step mapping and how
 * cancelled/rejected orders are handled.
 *
 * Item 25 (Refund System) — the `refund` object lives on the full Order
 * (GET /orders/{id}), not the lightweight 5s /track poll, same reasoning
 * as `scheduled_for` above: it never changes on a timescale the 5s poll
 * needs to catch, so it's fetched once alongside scheduledFor in
 * loadOrderDetail() rather than every poll cycle. See renderRefund().
 *
 * Phase 3 R5 follow-up (deep-plan §14-15) — live tracking map added this
 * session. Two independent cadences, matching the deep-plan's split:
 *   - startPolling()'s existing 5s loop now also drives the rider
 *     marker, animated (not jumped) from its last position to the new
 *     one over roughly one poll interval — see animateRiderMarker().
 *   - a separate startRouteRecalcLoop() re-fetches/redraws the route
 *     line + refits the camera bounds, on the schedule described
 *     below. Kept independent of the 5s loop rather than "every Nth
 *     tick of the same loop" so the two cadences stay easy to reason
 *     about and tune separately.
 * Restaurant/delivery markers are added once, the first time each
 * becomes available, since both are static per order (see
 * [com.anydrop.food.network.TrackRestaurant]/[com.anydrop.food.network.TrackDelivery]
 * kdoc) — no reason to touch them again every poll.
 *
 * Plan doc 91 (Progress-Trim Route Line + Deviation-Based Recalc,
 * 04 Sep 2026) — two further pieces built this session, both driven by
 * the same [RouteGeometry.nearestPointOnPath] projection of the
 * rider's position onto the currently-drawn polyline:
 *   - Piece A (progress trim): [trimPolylineTo] shortens the drawn
 *     line to "remaining route from rider to destination" instead of
 *     redrawing the full original route every cycle. Hooked into
 *     [animateRiderMarker]'s existing per-frame `ValueAnimator`
 *     listener (the "smooth" option from the plan, confirmed over the
 *     simpler once-per-poll alternative) so the line visibly shortens
 *     in sync with the marker's motion rather than in visible 5s
 *     jumps.
 *   - Piece B (deviation-triggered recalc): [checkRouteDeviation],
 *     called once per 5s poll from [updateMap], starts a timer once
 *     the rider's actual (non-animated) position drifts more than
 *     `deviationThresholdM` off the drawn line, and fires an immediate
 *     [fetchAndDrawRoute] once that drift has persisted for
 *     `deviationSustainMs` — replacing the old fixed-timer-only recalc.
 *     [startRouteRecalcLoop] still runs as a fallback ceiling
 *     (`maxRecalcIntervalMs`) in case deviation is never detected (a
 *     route can go stale for reasons pure drift-distance won't catch,
 *     e.g. traffic-aware re-routing on Google's side).
 * All three numbers (`deviationThresholdM`/`deviationSustainMs`/
 * `maxRecalcIntervalMs`) are admin-configurable via `app_settings`,
 * refreshed from every successful [fetchAndDrawRoute] response — see
 * `route.php`'s kdoc — per this codebase's "server-configurable, not
 * hardcoded" convention for money/business-rule numbers elsewhere
 * (`rider_earning_share_percent` etc.), matching the person's own
 * preference when this was planned.
 */
class OrderStatusActivity : AppCompatActivity(), OnMapReadyCallback {

    companion object {
        const val EXTRA_ORDER_ID = "extra_order_id"
        private const val POLL_INTERVAL_MS = 5000L

        // Plan doc 91 — startRouteRecalcLoop() now just checks, every
        // ROUTE_CHECK_INTERVAL_MS, whether maxRecalcIntervalMs has
        // elapsed since the last fetch (deviation-triggered or
        // fallback) — a cheap tick, not a fetch itself. The three
        // *_DEFAULT constants below seed the mutable fields of the
        // same name until the first route.php response overwrites them
        // with the admin-configured values; kept in sync with that
        // endpoint's own get_setting() fallbacks.
        private const val ROUTE_CHECK_INTERVAL_MS = 5_000L
        private const val DEVIATION_THRESHOLD_M_DEFAULT = 70.0
        private const val DEVIATION_SUSTAIN_MS_DEFAULT = 60_000L
        private const val MAX_RECALC_INTERVAL_MS_DEFAULT = 90_000L

        private val TERMINAL_STATUSES = setOf("delivered", "cancelled", "rejected", "refunded", "failed", "expired")
        private val CANCELLABLE_STATUSES = setOf("pending", "accepted")

        // A rider position is only worth plotting/routing while they're
        // actually mid-delivery — matches rider/location.php's own
        // active-delivery status set (Phase 3 R4/R5) and route.php's
        // leg-selection set, so all three stay in sync by construction
        // rather than by three separately-maintained lists.
        private val MAP_ACTIVE_STATUSES = setOf("rider_assigned", "picked_up", "out_for_delivery")
    }

    private lateinit var binding: ActivityOrderStatusBinding
    private val api by lazy { ApiClient.create(this) }
    private var orderId: Int = 0
    private var polling = true

    private var googleMap: GoogleMap? = null
    private var mapReady = false
    private var restaurantMarker: Marker? = null
    private var deliveryMarker: Marker? = null
    private var riderMarker: Marker? = null
    private var riderMarkerAnimator: ValueAnimator? = null
    private var routePolyline: Polyline? = null
    // Plan doc 91 Piece A — the decoded source points behind
    // [routePolyline], kept separately from the Polyline overlay
    // itself so trimming has the original vertex list to re-slice from
    // on every animation frame (a Polyline's own .points getter would
    // just hand back whatever was last set, i.e. the already-trimmed
    // list — not useful as a re-trim source).
    private var routePoints: List<LatLng>? = null
    private var restaurantLatLng: LatLng? = null
    private var deliveryLatLng: LatLng? = null
    private var mapEverShown = false

    // Plan doc 91 — admin-configurable numbers, refreshed from every
    // fetchAndDrawRoute() response; see route.php's kdoc for the
    // app_settings keys behind these.
    private var deviationThresholdM: Double = DEVIATION_THRESHOLD_M_DEFAULT
    private var deviationSustainMs: Long = DEVIATION_SUSTAIN_MS_DEFAULT
    private var maxRecalcIntervalMs: Long = MAX_RECALC_INTERVAL_MS_DEFAULT
    // Piece B's "deviated since" timer — null when the rider is
    // currently within threshold of the drawn line.
    private var deviatedSinceElapsedMs: Long? = null
    // SystemClock.elapsedRealtime() of the last route fetch (deviation
    // -triggered or fallback), so startRouteRecalcLoop()'s ceiling
    // check and checkRouteDeviation()'s immediate trigger don't fire a
    // redundant second fetch right on top of each other.
    private var lastRouteFetchElapsedMs: Long = 0L
    // Last track() response — kept around so onMapReady() (which can
    // fire after a poll has already landed) can draw the current state
    // immediately instead of waiting up to POLL_INTERVAL_MS for the
    // next poll.
    private var lastTrack: OrderTrackResult? = null

    // Delivery OTP resend (2026-09-11) — UX-only cooldown, same
    // pattern as the restaurant app's OrderDetailActivity.
    // pickupOtpResendTimer (see that class's kdoc). Real enforcement
    // is server-side (delivery-otp-resend.php's own 30s cooldown check).
    private var deliveryOtpResendTimer: CountDownTimer? = null
    private val deliveryOtpResendCooldownMillis = 30_000L

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityOrderStatusBinding.inflate(layoutInflater)
        setContentView(binding.root)

        orderId = intent.getIntExtra(EXTRA_ORDER_ID, 0)
        if (orderId == 0) {
            finish()
            return
        }

        binding.btnBackHome.setOnClickListener { goHome() }
        binding.btnCancelOrder.setOnClickListener { cancelOrder() }
        binding.btnResendDeliveryOtp.setOnClickListener { resendDeliveryOtp() }

        // Google Maps' MapView needs its own lifecycle forwarded from the
        // Activity's — same requirement MapPinDropActivity's kdoc
        // documents for its own MapView.
        binding.trackingMapView.onCreate(savedInstanceState)
        binding.trackingMapView.getMapAsync(this)

        // Covers reopening the app straight into an already-active order
        // (process was killed, or the poller was never started this
        // session) — idempotent/additive, see the service's kdoc.
        com.anydrop.food.notifications.OrderUpdatePollingService.start(this, orderId)

        loadOrderDetail()
        startPolling()
        startRouteRecalcLoop()
    }

    override fun onResume() {
        super.onResume()
        binding.trackingMapView.onResume()
    }

    override fun onPause() {
        super.onPause()
        binding.trackingMapView.onPause()
    }

    override fun onStart() {
        super.onStart()
        binding.trackingMapView.onStart()
    }

    override fun onStop() {
        super.onStop()
        binding.trackingMapView.onStop()
    }

    override fun onLowMemory() {
        super.onLowMemory()
        binding.trackingMapView.onLowMemory()
    }

    override fun onSaveInstanceState(outState: Bundle) {
        super.onSaveInstanceState(outState)
        binding.trackingMapView.onSaveInstanceState(outState)
    }

    override fun onDestroy() {
        super.onDestroy()
        polling = false
        riderMarkerAnimator?.cancel()
        deliveryOtpResendTimer?.cancel()
        binding.trackingMapView.onDestroy()
    }

    /** Fired once by the Maps SDK when the underlying GoogleMap is ready.
     * render()'s own map-update calls all check [mapReady] first, so
     * whichever of (map ready) / (first track poll landing) happens
     * second is the one that actually draws the initial state — no
     * ordering assumption between the two async events. */
    override fun onMapReady(map: GoogleMap) {
        googleMap = map
        mapReady = true
        map.uiSettings.isZoomControlsEnabled = false
        map.uiSettings.isMyLocationButtonEnabled = false
        lastTrack?.let { updateMap(it) }
    }

    private fun goHome() {
        startActivity(Intent(this, HomeActivity::class.java).addFlags(Intent.FLAG_ACTIVITY_CLEAR_TOP or Intent.FLAG_ACTIVITY_NEW_TASK))
        finish()
    }

    /** I4 / Item 25 — `scheduled_for` and `refund` both live on the full
     * Order (GET /orders/{id}), not on the lightweight OrderTrackResult
     * the 5s poll uses, and neither changes on a timescale that poll
     * needs to catch (an order is scheduled once at placement; a refund's
     * own status changes happen admin-side, at human speed, not within a
     * single session in practice). So this is a one-shot fetch on load
     * rather than something render() needs to re-check every poll cycle.
     * Silent failure on the whole call — same reasoning as
     * maybePromptRating(), these are supplementary, not core to the
     * screen; a refund that hasn't loaded yet just means the card stays
     * hidden until the next screen open. */
    private fun loadOrderDetail() {
        lifecycleScope.launch {
            try {
                val order = api.getOrder(orderId).body()?.data?.order

                val timeText = com.anydrop.food.util.ScheduledTimeFormatter.formatTime(order?.scheduledFor)
                if (timeText != null) {
                    binding.scheduledForText.visibility = View.VISIBLE
                    binding.scheduledForText.text = getString(R.string.order_scheduled_for_format, timeText)
                }

                renderRefund(order?.refund)
            } catch (e: Exception) {
                // Silent — see kdoc above.
            }
        }
    }

    /** Item 25 — populates refundCard from the order's `refund` object
     * (null when the order has no refund row, the normal case — card
     * stays hidden). Mirrors backend/lib/orders.php format_order()'s
     * shape exactly: amount/reason/status/method/reference/
     * expected_by_date/timeline (recall.md section 19's required fields). */
    private fun renderRefund(refund: RefundInfo?) {
        if (refund == null) {
            binding.refundCard.visibility = View.GONE
            return
        }
        binding.refundCard.visibility = View.VISIBLE

        val (statusLabel, statusColor) = when (refund.status) {
            "requested" -> getString(R.string.refund_status_requested) to R.color.info_fg
            "under_review" -> getString(R.string.refund_status_under_review) to R.color.info_fg
            "approved" -> getString(R.string.refund_status_approved) to R.color.info_fg
            "processing" -> getString(R.string.refund_status_processing) to R.color.info_fg
            "refunded" -> getString(R.string.refund_status_refunded) to R.color.success_fg
            "rejected" -> getString(R.string.refund_status_rejected) to R.color.error_fg
            else -> refund.status to R.color.text_primary
        }
        binding.refundStatusText.text = statusLabel
        binding.refundStatusText.setTextColor(getColorCompat(statusColor))

        binding.refundAmountText.text = getString(R.string.refund_amount_format, formatAmount(refund.amount))

        // Rejected orders show *why it was rejected*, not the original
        // request reason — that's the actionable info at that point.
        if (refund.status == "rejected" && !refund.rejectReason.isNullOrBlank()) {
            binding.refundReasonText.text = getString(R.string.refund_reject_reason_format, refund.rejectReason)
        } else {
            binding.refundReasonText.text = getString(R.string.refund_reason_format, refund.reason)
        }

        binding.refundMethodText.text = when (refund.method) {
            "wallet" -> getString(R.string.refund_method_wallet)
            else -> getString(R.string.refund_method_manual_upi_bank_transfer) // default/'manual_upi_bank_transfer' — see doc 23/migration 42
        }

        val expectedText = formatExpectedDate(refund.expectedByDate)
        if (expectedText != null && refund.status != "refunded" && refund.status != "rejected") {
            binding.refundExpectedText.visibility = View.VISIBLE
            binding.refundExpectedText.text = getString(R.string.refund_expected_by_format, expectedText)
        } else {
            binding.refundExpectedText.visibility = View.GONE
        }

        if (!refund.reference.isNullOrBlank()) {
            binding.refundReferenceText.visibility = View.VISIBLE
            binding.refundReferenceText.text = getString(R.string.refund_reference_format, refund.reference)
        } else {
            binding.refundReferenceText.visibility = View.GONE
        }

        renderRefundTimeline(refund)
    }

    /** Builds one line per timeline entry the refund has actually passed
     * through — format_order() only includes stages that happened
     * (array_filter server-side), so this list is naturally 1-5 rows,
     * no placeholder rows for stages not yet reached. Plain TextViews
     * added programmatically; a RecyclerView would be overkill here. */
    private fun renderRefundTimeline(refund: RefundInfo) {
        val container = binding.refundTimelineContainer
        container.removeAllViews()
        refund.timeline.forEach { entry ->
            val label = when (entry.status) {
                "requested" -> getString(R.string.refund_status_requested)
                "approved" -> getString(R.string.refund_status_approved)
                "processing" -> getString(R.string.refund_status_processing)
                "refunded" -> getString(R.string.refund_status_refunded)
                "rejected" -> getString(R.string.refund_status_rejected)
                else -> entry.status
            }
            val row = TextView(this).apply {
                text = "• $label — ${formatTimelineTimestamp(entry.at)}"
                setTextColor(getColorCompat(R.color.text_secondary))
                textSize = 13f
            }
            container.addView(row)
        }
    }

    private fun formatAmount(amount: Double): String {
        // Whole-rupee display when there are no paise, same convention
        // used elsewhere in this app's order/cart totals.
        return if (amount == amount.toLong().toDouble()) amount.toLong().toString()
        else String.format(Locale.getDefault(), "%.2f", amount)
    }

    /** `expected_by_date` is a plain SQL DATE ("yyyy-MM-dd"), unlike the
     * datetime timeline entries below — separate parse format needed. */
    private fun formatExpectedDate(raw: String?): String? {
        if (raw.isNullOrBlank()) return null
        return try {
            val parsed = SimpleDateFormat("yyyy-MM-dd", Locale.getDefault()).parse(raw)
            parsed?.let { SimpleDateFormat("d MMM yyyy", Locale.getDefault()).format(it) }
        } catch (e: Exception) {
            null
        }
    }

    private fun formatTimelineTimestamp(raw: String): String {
        return try {
            val parsed = SimpleDateFormat("yyyy-MM-dd HH:mm:ss", Locale.getDefault()).parse(raw)
            parsed?.let { SimpleDateFormat("d MMM, h:mm a", Locale.getDefault()).format(it) } ?: raw
        } catch (e: Exception) {
            raw
        }
    }

    private fun getColorCompat(colorRes: Int): Int =
        androidx.core.content.ContextCompat.getColor(this, colorRes)

    private fun startPolling() {
        lifecycleScope.launch {
            while (polling) {
                try {
                    val track = api.trackOrder(orderId).body()?.data
                    if (track != null) render(track)
                } catch (e: Exception) {
                    // Silent — next poll cycle will retry; keep showing last-known status.
                }
                if (!polling) break
                delay(POLL_INTERVAL_MS)
            }
        }
    }

    private fun render(track: OrderTrackResult) {
        lastTrack = track
        updateMap(track)

        binding.orderCodeText.text = getString(R.string.order_placed_title)
        binding.statusText.text = statusLabel(track.status)

        if (track.etaMinutes != null) {
            binding.etaText.visibility = View.VISIBLE
            binding.etaText.text = "ETA ~${track.etaMinutes} min"
        } else {
            binding.etaText.visibility = View.GONE
        }

        if (track.rider != null) {
            binding.riderCard.visibility = View.VISIBLE
            binding.riderNameText.text = track.rider.name ?: "Rider assigned"
            binding.riderMobileText.text = track.rider.mobile ?: ""
        } else {
            binding.riderCard.visibility = View.GONE
        }

        if (!track.otp.isNullOrBlank()) {
            binding.otpCard.visibility = View.VISIBLE
            binding.otpText.text = track.otp
            // Only reset the resend button when no cooldown is running —
            // otherwise a 5s poll landing mid-cooldown would stomp the
            // countdown text/disabled state the timer is currently
            // driving. Same reasoning as the restaurant app's
            // renderPickupOtp(), just guarded instead of unconditional
            // since this screen re-renders every poll cycle rather than
            // once per explicit reload.
            if (deliveryOtpResendTimer == null) {
                binding.btnResendDeliveryOtp.isEnabled = true
                binding.btnResendDeliveryOtp.text = getString(R.string.btn_resend_delivery_otp)
            }
        } else {
            binding.otpCard.visibility = View.GONE
            // otpCard's own visibility already gates btnResendDeliveryOtp
            // (it lives inside that same card), but a cooldown timer
            // still running for an OTP that just disappeared (order left
            // rider_assigned/out_for_delivery) should stop rather than
            // keep ticking against a hidden button.
            deliveryOtpResendTimer?.cancel()
            deliveryOtpResendTimer = null
        }

        binding.btnCancelOrder.visibility = if (track.status in CANCELLABLE_STATUSES) View.VISIBLE else View.GONE

        // I2 — stepper only makes sense for the 5-step happy path;
        // stepIndexFor() returns null for cancelled/rejected (see
        // OrderStatusStepperView kdoc), so hide it for those instead of
        // forcing a "cancelled" state onto the timeline.
        val stepIndex = OrderStatusStepperView.stepIndexFor(track.status)
        if (stepIndex != null) {
            binding.statusStepper.visibility = View.VISIBLE
            binding.statusStepper.setStatus(stepIndex)
        } else {
            binding.statusStepper.visibility = View.GONE
        }

        if (track.status in TERMINAL_STATUSES) {
            polling = false
            if (track.status == "delivered") {
                maybePromptRating(hasRider = track.rider != null)
            }
        }
    }

    // ---- Live tracking map (Phase 3 R5 follow-up, deep-plan §14-15) ----

    private fun shouldShowMap(track: OrderTrackResult): Boolean {
        return track.status in MAP_ACTIVE_STATUSES && track.rider?.lat != null && track.rider.lng != null
    }

    /** Called from every 5s render() — adds the static restaurant/
     * delivery markers the first time coordinates for them show up,
     * and moves the rider marker (animated, never jumped) to its
     * latest position. Route line + camera refit are NOT done here —
     * those run on the separate, slower loop started by
     * startRouteRecalcLoop(), per this class's kdoc. */
    private fun updateMap(track: OrderTrackResult) {
        if (!shouldShowMap(track)) {
            binding.trackingMapView.visibility = View.GONE
            return
        }
        binding.trackingMapView.visibility = View.VISIBLE

        val map = googleMap
        if (!mapReady || map == null) return // onMapReady's own lastTrack replay will catch up once it fires

        if (restaurantMarker == null && track.restaurant?.lat != null && track.restaurant.lng != null) {
            val pos = LatLng(track.restaurant.lat, track.restaurant.lng)
            restaurantLatLng = pos
            restaurantMarker = map.addMarker(
                MarkerOptions().position(pos).title(track.restaurant.name ?: "Restaurant")
                    .icon(BitmapDescriptorFactory.defaultMarker(BitmapDescriptorFactory.HUE_ORANGE))
            )
        }
        if (deliveryMarker == null && track.delivery?.lat != null && track.delivery.lng != null) {
            val pos = LatLng(track.delivery.lat, track.delivery.lng)
            deliveryLatLng = pos
            deliveryMarker = map.addMarker(
                MarkerOptions().position(pos).title("Delivery address")
                    .icon(BitmapDescriptorFactory.defaultMarker(BitmapDescriptorFactory.HUE_AZURE))
            )
        }

        // track.rider is non-null with non-null lat/lng — guaranteed by
        // shouldShowMap()'s check above, which already returned early
        // otherwise.
        val newPos = LatLng(track.rider!!.lat!!, track.rider.lng!!)
        val existing = riderMarker
        if (existing == null) {
            riderMarker = map.addMarker(
                MarkerOptions().position(newPos).title(track.rider.name ?: "Your rider")
                    .icon(BitmapDescriptorFactory.defaultMarker(BitmapDescriptorFactory.HUE_GREEN))
            )
            // First marker placement — also trim once immediately in
            // case a route was already drawn before the rider's first
            // position arrived (e.g. this activity resumed mid-delivery).
            trimPolylineTo(newPos)
        } else {
            animateRiderMarker(existing, existing.position, newPos)
        }

        // Piece B (plan doc 91) — deviation check runs off the rider's
        // real, non-animated position (this 5s poll value), not the
        // interpolated per-frame position trimPolylineTo() uses below —
        // deviation is about where the rider's GPS actually is, not
        // where the marker is mid-tween.
        checkRouteDeviation(newPos)

        if (!mapEverShown) {
            mapEverShown = true
            refitCameraBounds()
        }
    }

    /** deep-plan §14's "Android interpolates marker between A and B" —
     * server only writes a new point roughly once per poll, so this
     * tweens the marker smoothly across the interval instead of
     * snapping it, without needing any extra location data from the
     * server. Duration matches POLL_INTERVAL_MS so the marker arrives
     * at B right around when the next poll (and next A→B animation)
     * would start. Plain linear lerp on lat/lng — accurate enough over
     * the short hops one 5s poll interval covers at delivery speeds;
     * not a great-circle interpolation, which would only matter over
     * much longer distances than this ever animates. */
    private fun animateRiderMarker(marker: Marker, from: LatLng, to: LatLng) {
        riderMarkerAnimator?.cancel()
        riderMarkerAnimator = ValueAnimator.ofFloat(0f, 1f).apply {
            duration = POLL_INTERVAL_MS
            addUpdateListener { anim ->
                val t = anim.animatedValue as Float
                val lat = from.latitude + (to.latitude - from.latitude) * t
                val lng = from.longitude + (to.longitude - from.longitude) * t
                val pos = LatLng(lat, lng)
                marker.position = pos
                // Plan doc 91 Piece A — piggyback the progress-trim
                // recompute onto this same per-frame callback the
                // marker lerp already fires, so the route line
                // visibly shortens continuously in sync with the
                // marker's motion (the "smooth" option, confirmed over
                // trimming only once per 5s poll).
                trimPolylineTo(pos)
            }
            start()
        }
    }

    /** Plan doc 91 Piece A — redraws [routePolyline] as the remaining
     * sub-path from [pos] onward, using [routePoints] (the untrimmed
     * source list) as the basis so every call re-slices from the full
     * route rather than compounding trims onto an already-trimmed
     * list. No-ops if there's no route drawn yet, or if [pos] is too
     * far from the line to trim meaningfully — that "rider is nowhere
     * near the line" case is exactly what [checkRouteDeviation] exists
     * to handle instead (a fresh route, not a misleading trim). */
    private fun trimPolylineTo(pos: LatLng) {
        val points = routePoints ?: return
        val polyline = routePolyline ?: return
        val nearest = RouteGeometry.nearestPointOnPath(points, pos) ?: return
        if (nearest.distanceMeters > deviationThresholdM) return
        polyline.points = RouteGeometry.trimToNearest(points, nearest)
    }

    /** Plan doc 91 Piece B — called once per 5s poll (from [updateMap])
     * with the rider's real reported position. Starts a "deviated
     * since" timer the first time the rider is found more than
     * [deviationThresholdM] off the currently-drawn line; if that
     * drift is still present [deviationSustainMs] later, fires an
     * immediate route recalc rather than waiting for
     * [startRouteRecalcLoop]'s fallback ceiling. A momentary blip back
     * within threshold (GPS jitter, a brief stop slightly off the
     * drawn line) resets the timer rather than accumulating toward
     * it — matches the plan's own "sustained drift, not any single
     * off-route sample" framing. */
    private fun checkRouteDeviation(riderPos: LatLng) {
        val points = routePoints ?: return
        val nearest = RouteGeometry.nearestPointOnPath(points, riderPos) ?: return

        if (nearest.distanceMeters <= deviationThresholdM) {
            deviatedSinceElapsedMs = null
            return
        }

        val now = SystemClock.elapsedRealtime()
        val since = deviatedSinceElapsedMs
        if (since == null) {
            deviatedSinceElapsedMs = now
        } else if (now - since >= deviationSustainMs) {
            deviatedSinceElapsedMs = null
            lifecycleScope.launch { fetchAndDrawRoute() }
        }
    }

    /** Fits the camera to whichever of restaurant/delivery/rider
     * markers currently exist. Only called on the map's first
     * appearance and again from the slower route-recalc loop — NOT on
     * every 5s rider-position update, since re-fitting bounds every
     * few seconds would fight the marker animation above and feel
     * jumpy rather than smooth. Between those refits the rider marker
     * can drift toward/past the visible edge; a manual "recenter"
     * button would be the natural fix but is future work, not this
     * slice. */
    private fun refitCameraBounds() {
        val map = googleMap ?: return
        val points = listOfNotNull(restaurantLatLng, deliveryLatLng, riderMarker?.position)
        if (points.isEmpty()) return
        if (points.size == 1) {
            map.moveCamera(CameraUpdateFactory.newLatLngZoom(points[0], 15f))
            return
        }
        val boundsBuilder = LatLngBounds.Builder()
        points.forEach { boundsBuilder.include(it) }
        try {
            map.moveCamera(CameraUpdateFactory.newLatLngBounds(boundsBuilder.build(), 80))
        } catch (e: Exception) {
            // newLatLngBounds can throw if the map hasn't laid out yet
            // (zero width/height) — harmless to skip this one refit,
            // the next route-recalc cycle tries again.
        }
    }

    /** Plan doc 91 Piece B's fallback ceiling loop. Ticks every
     * [ROUTE_CHECK_INTERVAL_MS] (cheap — just a clock comparison, not a
     * fetch) and only actually calls [fetchAndDrawRoute] once
     * [maxRecalcIntervalMs] has elapsed since the last fetch, whichever
     * triggered it — this loop's own ceiling, or [checkRouteDeviation]'s
     * earlier deviation-triggered call. That shared [lastRouteFetchElapsedMs]
     * timestamp is what keeps the two trigger paths from double-firing
     * a fetch right on top of each other.
     *
     * Kept independent of the 5s rider-position poll in startPolling()
     * so the two cadences don't have to share one interval. Runs for
     * the Activity's full lifetime and just no-ops when the map isn't
     * currently shown (checked via [lastTrack] each cycle) rather than
     * being started/stopped in step with the map's own visibility —
     * simpler than plumbing a start/stop signal across two independent
     * loops for what's already a cheap no-op check. */
    private fun startRouteRecalcLoop() {
        lifecycleScope.launch {
            while (polling) {
                val track = lastTrack
                if (track != null && shouldShowMap(track) && mapReady) {
                    val elapsedSinceLastFetch = SystemClock.elapsedRealtime() - lastRouteFetchElapsedMs
                    if (elapsedSinceLastFetch >= maxRecalcIntervalMs) {
                        fetchAndDrawRoute()
                    }
                }
                delay(ROUTE_CHECK_INTERVAL_MS)
            }
        }
    }

    private suspend fun fetchAndDrawRoute() {
        val map = googleMap ?: return
        // Marked at call time (not just on success) so a slow/failed
        // network call doesn't leave the ceiling loop free to retry on
        // every single ROUTE_CHECK_INTERVAL_MS tick while one request
        // is already in flight.
        lastRouteFetchElapsedMs = SystemClock.elapsedRealtime()
        try {
            val result = api.getOrderRoute(orderId).body()?.data ?: return
            // Plan doc 91 — pick up any admin change to the three
            // deviation-recalc numbers on every successful fetch,
            // rather than only reading them once at Activity start.
            deviationThresholdM = result.deviationThresholdM
            deviationSustainMs = result.deviationSustainSeconds * 1000L
            maxRecalcIntervalMs = result.maxRecalcIntervalSeconds * 1000L
            // Fresh route means Piece A's trim baseline resets to
            // index 0 and Piece B's deviation timer clears — both
            // documented edge cases from the plan.
            deviatedSinceElapsedMs = null

            routePolyline?.remove()
            routePolyline = null
            routePoints = null
            if (!result.polyline.isNullOrBlank()) {
                val points = PolylineDecoder.decode(result.polyline)
                if (points.size >= 2) {
                    routePoints = points
                    routePolyline = map.addPolyline(
                        PolylineOptions().addAll(points).width(10f).color(getColorCompat(R.color.anydrop_primary))
                    )
                }
            }
            // Route recalc is also this loop's cue to refit the camera
            // (see refitCameraBounds() kdoc for why that doesn't happen
            // on every 5s marker update).
            refitCameraBounds()
        } catch (e: Exception) {
            // Network hiccup — same silent-retry-next-cycle convention
            // as startPolling()'s own try/catch; the previously-drawn
            // route (if any) just stays on screen untouched.
        }
    }

    /** Part 13 — fires once, right when the poll loop first sees "delivered"
     * (loop exits right after, so render() won't be called again for this
     * order). Skips the prompt if the order was already rated — e.g. the
     * user backed out and came back to this screen after rating from
     * Order History instead. */
    private fun maybePromptRating(hasRider: Boolean) {
        lifecycleScope.launch {
            try {
                val alreadyRated = api.getReview(orderId).body()?.data?.review != null
                if (alreadyRated) return@launch

                val restaurantName = api.getOrder(orderId).body()?.data?.order?.restaurantName
                    ?: getString(R.string.order_placed_title)

                RateOrderDialog.show(
                    activity = this@OrderStatusActivity,
                    orderId = orderId,
                    restaurantName = restaurantName,
                    hasRider = hasRider
                )
            } catch (e: Exception) {
                // Silent — rating prompt is a nice-to-have, not worth
                // interrupting the delivered-order screen for.
            }
        }
    }

    private fun statusLabel(status: String): String = when (status) {
        "pending" -> getString(R.string.order_status_pending)
        "accepted" -> getString(R.string.order_status_accepted)
        "preparing" -> getString(R.string.order_status_preparing)
        "ready" -> getString(R.string.order_status_ready)
        "rider_assigned" -> getString(R.string.order_status_rider_assigned)
        "picked_up" -> getString(R.string.order_status_picked_up)
        "out_for_delivery" -> getString(R.string.order_status_out_for_delivery)
        "delivered" -> getString(R.string.order_status_delivered)
        "cancelled" -> getString(R.string.order_status_cancelled)
        "rejected" -> getString(R.string.order_status_rejected)
        else -> status
    }

    private fun cancelOrder() {
        binding.btnCancelOrder.isEnabled = false
        lifecycleScope.launch {
            try {
                val response = api.cancelOrder(orderId)
                if (response.isSuccessful) {
                    InAppNotifier.show(this@OrderStatusActivity, "Order cancelled", InAppNotifier.Type.INFO)
                    binding.statusText.text = statusLabel("cancelled")
                    binding.btnCancelOrder.visibility = View.GONE
                    // Item 25 — cancelling a paid order auto-creates a
                    // `requested` refund server-side (orders/cancel.php).
                    // Re-fetch so that card appears in this same session
                    // instead of only on next screen open.
                    renderRefund(api.getOrder(orderId).body()?.data?.order?.refund)
                } else {
                    // Same root-cause fix as CheckoutActivity's placeOrder() —
                    // response.body() is null on this non-2xx branch; the real
                    // error code is only in errorBody(). See ApiErrorParser's kdoc.
                    val errCode = com.anydrop.food.network.ApiErrorParser.parse(response).code
                    InAppNotifier.show(this@OrderStatusActivity, errCode ?: "Couldn't cancel order", InAppNotifier.Type.ERROR)
                    binding.btnCancelOrder.isEnabled = true
                }
            } catch (e: Exception) {
                InAppNotifier.show(this@OrderStatusActivity, "Network error while cancelling.", InAppNotifier.Type.ERROR)
                binding.btnCancelOrder.isEnabled = true
            }
        }
    }

    /** Delivery OTP Resend (2026-09-11) — customer-side counterpart to
     *  the restaurant app's OrderDetailActivity.resendPickupOtp(). Same
     *  two-method shape as that class; see its kdoc. Note this app's
     *  error parser (ApiErrorParser.parse()) returns a raw
     *  `Map<String, Any?>` for `data` rather than a typed
     *  ParsedApiError with its own retryAfterSeconds field like the
     *  restaurant/rider apps — the cooldown-vs-generic-failure message
     *  choice below only needs the error `code`, so the map's numeric
     *  value isn't read here, but it's available at `info.data
     *  ["retry_after_seconds"]` (a Double, per Gson's raw-map decoding)
     *  if a future screen wants to display the exact wait time. */
    private fun resendDeliveryOtp() {
        binding.btnResendDeliveryOtp.isEnabled = false
        lifecycleScope.launch {
            try {
                val response = api.resendDeliveryOtp(orderId)
                if (response.isSuccessful && response.body()?.success == true) {
                    InAppNotifier.show(this@OrderStatusActivity, getString(R.string.delivery_otp_resend_sent), InAppNotifier.Type.SUCCESS)
                    startDeliveryOtpResendCooldown()
                } else {
                    val info = com.anydrop.food.network.ApiErrorParser.parse(response)
                    val message = if (info.code == "resend_cooldown") {
                        getString(R.string.delivery_otp_resend_cooldown_message)
                    } else {
                        getString(R.string.delivery_otp_resend_failed)
                    }
                    InAppNotifier.show(this@OrderStatusActivity, message, InAppNotifier.Type.ERROR)
                    // Cooldown or a stale/invalid_state response both mean
                    // "don't let them hammer the button" just as much as a
                    // successful send does — restart the same 30s window
                    // either way, matching the resend's own server cooldown.
                    startDeliveryOtpResendCooldown()
                }
            } catch (e: Exception) {
                binding.btnResendDeliveryOtp.isEnabled = true
                InAppNotifier.show(this@OrderStatusActivity, "Network error", InAppNotifier.Type.ERROR)
            }
        }
    }

    private fun startDeliveryOtpResendCooldown() {
        deliveryOtpResendTimer?.cancel()
        binding.btnResendDeliveryOtp.isEnabled = false
        deliveryOtpResendTimer = object : CountDownTimer(deliveryOtpResendCooldownMillis, 1_000L) {
            override fun onTick(millisUntilFinished: Long) {
                val secondsLeft = (millisUntilFinished / 1000L) + 1
                binding.btnResendDeliveryOtp.text = getString(R.string.delivery_otp_resend_countdown, secondsLeft)
            }

            override fun onFinish() {
                deliveryOtpResendTimer = null
                binding.btnResendDeliveryOtp.isEnabled = true
                binding.btnResendDeliveryOtp.text = getString(R.string.btn_resend_delivery_otp)
            }
        }.start()
    }
}
