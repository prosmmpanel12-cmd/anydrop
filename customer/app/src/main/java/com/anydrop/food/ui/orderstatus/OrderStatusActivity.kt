package com.anydrop.food.ui.orderstatus

import android.content.Intent
import android.os.Bundle
import android.view.View
import androidx.appcompat.app.AppCompatActivity
import androidx.lifecycle.lifecycleScope
import com.anydrop.food.R
import com.anydrop.food.databinding.ActivityOrderStatusBinding
import com.anydrop.food.network.ApiClient
import com.anydrop.food.network.OrderTrackResult
import com.anydrop.food.ui.common.InAppNotifier
import com.anydrop.food.ui.home.HomeActivity
import com.anydrop.food.ui.orders.RateOrderDialog
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

/**
 * Phase 3 — Order status/tracking. Polls GET /orders/{id}/track every 5s
 * while the order is active (simple polling, per docs/03_Live_Tracking.md —
 * the live map view itself is Phase 4 scope, this screen just shows status +
 * rider contact + delivery OTP once assigned).
 *
 * I2 (docs/features.md Phase I) adds the visual stepper — see
 * [OrderStatusStepperView] for the 9-status-to-5-step mapping and how
 * cancelled/rejected orders are handled.
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
