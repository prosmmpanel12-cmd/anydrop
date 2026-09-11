package com.anydrop.restaurant.ui.orderdetail

import android.os.Bundle
import android.os.CountDownTimer
import android.view.View
import android.widget.TextView
import androidx.appcompat.app.AppCompatActivity
import androidx.lifecycle.lifecycleScope
import com.anydrop.restaurant.R
import com.anydrop.restaurant.databinding.ActivityOrderDetailBinding
import com.anydrop.restaurant.network.AcceptBody
import com.anydrop.restaurant.network.ApiClient
import com.anydrop.restaurant.network.Order
import com.anydrop.restaurant.network.RejectBody
import com.anydrop.restaurant.network.StatusUpdateBody
import com.anydrop.restaurant.network.parseApiError
import com.anydrop.restaurant.service.OrderNotificationHelper
import com.anydrop.restaurant.ui.common.InAppNotifier
import com.anydrop.restaurant.ui.common.PrepTimeDialog
import com.anydrop.restaurant.util.ScheduledTimeFormatter
import kotlinx.coroutines.launch

/**
 * Phase 3 — Order detail with the accept/reject/preparing/ready action flow.
 * Valid transitions enforced server-side too (see backend/api/v1/restaurant/orders-*.php);
 * this screen just shows the one relevant action button for the order's current status.
 */
class OrderDetailActivity : AppCompatActivity() {

    companion object {
        const val EXTRA_ORDER_ID = "extra_order_id"
    }

    private lateinit var binding: ActivityOrderDetailBinding
    private val api by lazy { ApiClient.create(this) }
    private var orderId: Int = 0
    private var currentOrder: Order? = null

    // Pickup OTP resend (2026-09-11) — UX-only cooldown, mirrors the
    // rider app's RequestPayoutActivity.bankOtpResendTimer pattern
    // (see that class's own kdoc). Real enforcement is server-side
    // (pickup-otp-resend.php's own 30s cooldown check).
    private var pickupOtpResendTimer: CountDownTimer? = null
    private val pickupOtpResendCooldownMillis = 30_000L

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        // Opening any order counts as "went in and did something" — stop
        // OrderNotificationHelper's ringing loop (see its kdoc) whether
        // this was opened from the ringing screen, the plain notification,
        // or just normal in-app navigation.
        OrderNotificationHelper.stopRingingLoop(applicationContext)
        binding = ActivityOrderDetailBinding.inflate(layoutInflater)
        setContentView(binding.root)

        orderId = intent.getIntExtra(EXTRA_ORDER_ID, 0)
        if (orderId == 0) {
            finish()
            return
        }

        binding.btnBack.setOnClickListener { finish() }
        binding.btnCancelReject.setOnClickListener { binding.rejectGroup.visibility = View.GONE }
        binding.btnConfirmReject.setOnClickListener { confirmReject() }
        binding.btnResendPickupOtp.setOnClickListener { resendPickupOtp() }

        loadOrder()
    }

    override fun onDestroy() {
        super.onDestroy()
        pickupOtpResendTimer?.cancel()
    }

    private fun loadOrder() {
        lifecycleScope.launch {
            try {
                val response = api.getOrder(orderId)
                val order = response.body()?.data?.order
                if (response.isSuccessful && order != null) {
                    render(order)
                } else {
                    InAppNotifier.show(this@OrderDetailActivity, "Order not found", InAppNotifier.Type.ERROR)
                }
            } catch (e: Exception) {
                InAppNotifier.show(this@OrderDetailActivity, "Network error loading order", InAppNotifier.Type.ERROR)
            }
        }
    }

    private fun render(order: Order) {
        currentOrder = order
        binding.orderCodeText.text = order.orderCode
        binding.statusText.text = order.status.uppercase().replace("_", " ")
        binding.itemTotalText.text = "₹${"%.2f".format(order.itemTotal)}"

        val scheduledTime = ScheduledTimeFormatter.formatTime(order.scheduledFor)
        if (scheduledTime != null) {
            binding.scheduledForText.visibility = View.VISIBLE
            binding.scheduledForText.text = getString(R.string.order_scheduled_for_format, scheduledTime)
        } else {
            binding.scheduledForText.visibility = View.GONE
        }

        binding.itemsContainer.removeAllViews()
        order.items.forEach { item ->
            val row = TextView(this).apply {
                text = "${item.quantity}x ${item.name}${item.variantName?.let { " ($it)" } ?: ""}  —  ₹${"%.2f".format(item.subtotal)}"
                setTextColor(getColor(R.color.text_primary))
                setPadding(0, 6, 0, 6)
            }
            binding.itemsContainer.addView(row)
        }

        if (!order.deliveryInstructions.isNullOrBlank()) {
            binding.instructionsLabel.visibility = View.VISIBLE
            binding.instructionsText.visibility = View.VISIBLE
            binding.instructionsText.text = order.deliveryInstructions
        } else {
            binding.instructionsLabel.visibility = View.GONE
            binding.instructionsText.visibility = View.GONE
        }

        renderPickupOtp(order)
        configureActions(order.status)
    }

    /** Pickup OTP (2026-09-11, migration 83) — card only shows while
     *  orders-detail.php actually reveals a code: status ==
     *  rider_assigned and not yet verified (matches that endpoint's own
     *  reveal condition — see its kdoc). Cancels any in-flight resend
     *  cooldown on re-render (e.g. after a poll/refresh) since a fresh
     *  render means we don't know if the card is even the same order. */
    private fun renderPickupOtp(order: Order) {
        pickupOtpResendTimer?.cancel()
        val showCard = order.status == "rider_assigned" && !order.pickupOtpVerified && order.pickupOtp != null
        binding.pickupOtpCard.visibility = if (showCard) View.VISIBLE else View.GONE
        if (showCard) {
            binding.pickupOtpValueText.text = order.pickupOtp
            binding.btnResendPickupOtp.isEnabled = true
            binding.btnResendPickupOtp.text = getString(R.string.btn_resend_pickup_otp)
        }
    }

    private fun resendPickupOtp() {
        binding.btnResendPickupOtp.isEnabled = false
        lifecycleScope.launch {
            try {
                val response = api.resendPickupOtp(orderId)
                if (response.isSuccessful && response.body()?.success == true) {
                    InAppNotifier.show(this@OrderDetailActivity, getString(R.string.pickup_otp_resend_sent), InAppNotifier.Type.SUCCESS)
                    startPickupOtpResendCooldown()
                } else {
                    val parsed = parseApiError(response.errorBody())
                    val message = if (parsed.code == "resend_cooldown") {
                        getString(R.string.pickup_otp_resend_cooldown_message)
                    } else {
                        getString(R.string.pickup_otp_resend_failed)
                    }
                    InAppNotifier.show(this@OrderDetailActivity, message, InAppNotifier.Type.ERROR)
                    // Cooldown or a stale/invalid_state response both mean
                    // "don't let them hammer the button" just as much as a
                    // successful send does — restart the same 30s window
                    // either way, matching the resend's own server cooldown.
                    startPickupOtpResendCooldown()
                }
            } catch (e: Exception) {
                binding.btnResendPickupOtp.isEnabled = true
                InAppNotifier.show(this@OrderDetailActivity, "Network error", InAppNotifier.Type.ERROR)
            }
        }
    }

    private fun startPickupOtpResendCooldown() {
        pickupOtpResendTimer?.cancel()
        binding.btnResendPickupOtp.isEnabled = false
        pickupOtpResendTimer = object : CountDownTimer(pickupOtpResendCooldownMillis, 1_000L) {
            override fun onTick(millisUntilFinished: Long) {
                val secondsLeft = (millisUntilFinished / 1000L) + 1
                binding.btnResendPickupOtp.text = getString(R.string.pickup_otp_resend_countdown, secondsLeft)
            }

            override fun onFinish() {
                binding.btnResendPickupOtp.isEnabled = true
                binding.btnResendPickupOtp.text = getString(R.string.btn_resend_pickup_otp)
            }
        }.start()
    }

    private fun configureActions(status: String) {
        binding.rejectGroup.visibility = View.GONE

        when (status) {
            "pending" -> {
                binding.actionBar.visibility = View.VISIBLE
                binding.btnSecondaryAction.visibility = View.VISIBLE
                binding.btnSecondaryAction.text = getString(R.string.btn_reject)
                binding.btnSecondaryAction.setOnClickListener { binding.rejectGroup.visibility = View.VISIBLE }
                binding.btnPrimaryAction.visibility = View.VISIBLE
                binding.btnPrimaryAction.text = getString(R.string.btn_accept)
                binding.btnPrimaryAction.setOnClickListener { promptAcceptPrepTime() }
            }
            "accepted" -> {
                binding.actionBar.visibility = View.VISIBLE
                binding.btnSecondaryAction.visibility = View.GONE
                binding.btnPrimaryAction.visibility = View.VISIBLE
                binding.btnPrimaryAction.text = getString(R.string.btn_mark_preparing)
                binding.btnPrimaryAction.setOnClickListener { updateStatus("preparing") }
            }
            "preparing" -> {
                binding.actionBar.visibility = View.VISIBLE
                binding.btnSecondaryAction.visibility = View.GONE
                binding.btnPrimaryAction.visibility = View.VISIBLE
                binding.btnPrimaryAction.text = getString(R.string.btn_mark_ready)
                binding.btnPrimaryAction.setOnClickListener { updateStatus("ready") }
            }
            else -> {
                // ready / rider_assigned / picked_up / out_for_delivery / delivered / cancelled / rejected:
                // no restaurant-side action available — rider assignment is Phase 4 scope.
                binding.actionBar.visibility = View.GONE
            }
        }
    }

    private fun promptAcceptPrepTime() {
        PrepTimeDialog.show(this) { prepMinutes -> acceptOrder(prepMinutes) }
    }

    private fun acceptOrder(prepMinutes: Int) {
        setActionsEnabled(false)
        lifecycleScope.launch {
            try {
                val response = api.acceptOrder(orderId, AcceptBody(estimatedPrepMinutes = prepMinutes))
                val order = response.body()?.data?.order
                if (response.isSuccessful && order != null) {
                    render(order)
                } else {
                    InAppNotifier.show(this@OrderDetailActivity, response.body()?.error ?: "Couldn't accept order", InAppNotifier.Type.ERROR)
                }
            } catch (e: Exception) {
                InAppNotifier.show(this@OrderDetailActivity, "Network error", InAppNotifier.Type.ERROR)
            } finally {
                setActionsEnabled(true)
            }
        }
    }

    private fun confirmReject() {
        val reason = binding.inputRejectReason.text?.toString()?.trim().orEmpty()
        if (reason.isEmpty()) {
            InAppNotifier.show(this, "Enter a reason for rejecting", InAppNotifier.Type.INFO)
            return
        }
        setActionsEnabled(false)
        lifecycleScope.launch {
            try {
                val response = api.rejectOrder(orderId, RejectBody(reason))
                val order = response.body()?.data?.order
                if (response.isSuccessful && order != null) {
                    render(order)
                } else {
                    InAppNotifier.show(this@OrderDetailActivity, response.body()?.error ?: "Couldn't reject order", InAppNotifier.Type.ERROR)
                }
            } catch (e: Exception) {
                InAppNotifier.show(this@OrderDetailActivity, "Network error", InAppNotifier.Type.ERROR)
            } finally {
                setActionsEnabled(true)
            }
        }
    }

    private fun updateStatus(newStatus: String) {
        setActionsEnabled(false)
        lifecycleScope.launch {
            try {
                val response = api.updateStatus(orderId, StatusUpdateBody(newStatus))
                val order = response.body()?.data?.order
                if (response.isSuccessful && order != null) {
                    render(order)
                } else {
                    InAppNotifier.show(this@OrderDetailActivity, response.body()?.error ?: "Couldn't update status", InAppNotifier.Type.ERROR)
                }
            } catch (e: Exception) {
                InAppNotifier.show(this@OrderDetailActivity, "Network error", InAppNotifier.Type.ERROR)
            } finally {
                setActionsEnabled(true)
            }
        }
    }

    private fun setActionsEnabled(enabled: Boolean) {
        binding.btnPrimaryAction.isEnabled = enabled
        binding.btnSecondaryAction.isEnabled = enabled
        binding.btnConfirmReject.isEnabled = enabled
    }
}
