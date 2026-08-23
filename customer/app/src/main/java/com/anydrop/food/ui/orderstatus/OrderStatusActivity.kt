package com.anydrop.food.ui.orderstatus

import android.content.Intent
import android.os.Bundle
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
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import java.text.SimpleDateFormat
import java.util.Locale

/**
 * Phase 3 — Order status/tracking. Polls GET /orders/{id}/track every 5s
 * while the order is active (simple polling, per docs/03_Live_Tracking.md —
 * the live map view itself is Phase 4 scope, this screen just shows status +
 * rider contact + delivery OTP once assigned).
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
 */
class OrderStatusActivity : AppCompatActivity() {

    companion object {
        const val EXTRA_ORDER_ID = "extra_order_id"
        private const val POLL_INTERVAL_MS = 5000L
        private val TERMINAL_STATUSES = setOf("delivered", "cancelled", "rejected", "refunded", "failed", "expired")
        private val CANCELLABLE_STATUSES = setOf("pending", "accepted")
    }

    private lateinit var binding: ActivityOrderStatusBinding
    private val api by lazy { ApiClient.create(this) }
    private var orderId: Int = 0
    private var polling = true

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

        // Covers reopening the app straight into an already-active order
        // (process was killed, or the poller was never started this
        // session) — idempotent/additive, see the service's kdoc.
        com.anydrop.food.notifications.OrderUpdatePollingService.start(this, orderId)

        loadOrderDetail()
        startPolling()
    }

    override fun onDestroy() {
        super.onDestroy()
        polling = false
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
        } else {
            binding.otpCard.visibility = View.GONE
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
}
