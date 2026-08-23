package com.anydrop.food.ui.checkout

import android.content.Intent
import android.graphics.Bitmap
import android.graphics.Color
import android.os.Bundle
import android.view.View
import androidx.appcompat.app.AppCompatActivity
import androidx.lifecycle.lifecycleScope
import com.anydrop.food.databinding.ActivityUpiPaymentBinding
import com.anydrop.food.network.ApiClient
import com.anydrop.food.network.SubmitUtrBody
import com.anydrop.food.network.UpiPaymentInitResult
import com.anydrop.food.ui.common.InAppNotifier
import com.anydrop.food.ui.orderstatus.OrderStatusActivity
import com.google.zxing.BarcodeFormat
import com.google.zxing.qrcode.QRCodeWriter
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

/**
 * Native UPI Payment screen (docs/23_Native_UPI_Payment_Gateway_
 * Architecture_2026-08-23.md §2/§3/§4). Reached from CheckoutActivity
 * right after an order is created with payment_method="upi" — the
 * order already exists (payment_status="pending") by the time this
 * screen opens; this screen's only job is to get it paid and confirmed.
 *
 * QR RENDERING — no server-generated QR image exists (see
 * backend/lib/payment/UpipeProvider.php's doc-comment for why). This
 * screen renders the actual scannable QR itself from the `upiLink`
 * string using ZXing, fully offline.
 *
 * SPOOF-SAFETY NOTE for anyone touching this file: this screen must
 * NEVER treat anything client-side (a deep-link return callback, a
 * "payment done" button, a locally-computed timer) as proof of
 * payment. The only source of truth is what
 * GET .../payment/upi/status returns — see startPolling() below,
 * which is the only place `success` can be reached from.
 */
class UpiPaymentActivity : AppCompatActivity() {

    companion object {
        const val EXTRA_ORDER_ID = "extra_order_id"
        private const val TICK_MS = 1000L
    }

    private lateinit var binding: ActivityUpiPaymentBinding
    private val api by lazy { ApiClient.create(this) }
    private var orderId: Int = 0
    private var pollIntervalSec: Int = 10
    private var expiresInSec: Int = 0
    private var utrWindowSec: Int = 300
    private var polling = true
    private var pollJob: Job? = null
    private var tickJob: Job? = null

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityUpiPaymentBinding.inflate(layoutInflater)
        setContentView(binding.root)

        orderId = intent.getIntExtra(EXTRA_ORDER_ID, 0)
        if (orderId == 0) {
            finish()
            return
        }

        binding.btnCancelPayment.setOnClickListener { confirmSwitchToCod() }
        binding.btnSubmitUtr.setOnClickListener { submitUtr() }

        startPayment()
    }

    override fun onDestroy() {
        super.onDestroy()
        polling = false
        pollJob?.cancel()
        tickJob?.cancel()
    }

    private fun startPayment() {
        binding.qrLoadingSpinner.visibility = View.VISIBLE
        lifecycleScope.launch {
            try {
                val response = api.createUpiPayment(orderId)
                val result = response.body()?.data
                if (response.isSuccessful && result != null) {
                    if (result.alreadyPaid) {
                        goToOrderStatus()
                        return@launch
                    }
                    if (result.method == "unavailable") {
                        InAppNotifier.show(this@UpiPaymentActivity, result.message ?: getString(com.anydrop.food.R.string.upi_unavailable), InAppNotifier.Type.ERROR)
                        finish()
                        return@launch
                    }
                    bindPayload(result)
                    startPolling()
                    startCountdown()
                } else {
                    InAppNotifier.show(this@UpiPaymentActivity, getString(com.anydrop.food.R.string.upi_qr_generation_failed), InAppNotifier.Type.ERROR)
                    finish()
                }
            } catch (e: Exception) {
                InAppNotifier.show(this@UpiPaymentActivity, getString(com.anydrop.food.R.string.upi_qr_generation_failed), InAppNotifier.Type.ERROR)
                finish()
            }
        }
    }

    private fun bindPayload(result: UpiPaymentInitResult) {
        binding.testModeBanner.visibility = if (result.isTestMode) View.VISIBLE else View.GONE
        binding.amountText.text = "₹${"%.2f".format(result.amount ?: 0.0)}"
        binding.instructionsText.text = result.instructions.mapIndexed { i, line -> "${i + 1}. $line" }.joinToString("\n")
        pollIntervalSec = result.pollIntervalSec.takeIf { it > 0 } ?: 10
        expiresInSec = result.expiresInSec
        utrWindowSec = result.utrWindowSec

        val upiLink = result.upiLink
        if (!upiLink.isNullOrBlank()) {
            renderQr(upiLink)
        }
        binding.qrLoadingSpinner.visibility = View.GONE
    }

    /** Real, offline QR encoding — see class kdoc for why this replaced a server-side image. */
    private fun renderQr(content: String) {
        try {
            val writer = QRCodeWriter()
            val size = 600
            val bitMatrix = writer.encode(content, BarcodeFormat.QR_CODE, size, size)
            val bitmap = Bitmap.createBitmap(size, size, Bitmap.Config.RGB_565)
            for (x in 0 until size) {
                for (y in 0 until size) {
                    bitmap.setPixel(x, y, if (bitMatrix[x, y]) Color.BLACK else Color.WHITE)
                }
            }
            binding.qrImage.setImageBitmap(bitmap)
        } catch (e: Exception) {
            InAppNotifier.show(this, getString(com.anydrop.food.R.string.upi_qr_generation_failed), InAppNotifier.Type.ERROR)
        }
    }

    private fun startCountdown() {
        tickJob?.cancel()
        tickJob = lifecycleScope.launch {
            while (polling && expiresInSec > 0) {
                val mins = expiresInSec / 60
                val secs = expiresInSec % 60
                binding.expiryText.text = getString(com.anydrop.food.R.string.upi_expiry_format, "%d:%02d".format(mins, secs))
                delay(TICK_MS)
                expiresInSec -= 1
            }
        }
    }

    /**
     * Polls GET .../payment/upi/status every `pollIntervalSec`
     * (10s, per doc 23 §4 — server-controlled via the create response,
     * never a value this screen decides on its own). This is the ONLY
     * function in this file allowed to react to a "success" status.
     */
    private fun startPolling() {
        pollJob?.cancel()
        pollJob = lifecycleScope.launch {
            while (polling) {
                delay(pollIntervalSec * 1000L)
                if (!polling) break
                try {
                    val response = api.getUpiPaymentStatus(orderId)
                    val result = response.body()?.data ?: continue
                    when (result.status) {
                        "success" -> {
                            polling = false
                            binding.statusText.text = getString(com.anydrop.food.R.string.upi_status_success)
                            binding.pollingSpinner.visibility = View.GONE
                            delay(600) // let the confirmation text register before navigating
                            goToOrderStatus()
                        }
                        "failed" -> {
                            polling = false
                            binding.pollingSpinner.visibility = View.GONE
                            binding.statusText.text = if (!result.rejectReason.isNullOrBlank()) {
                                getString(com.anydrop.food.R.string.upi_status_failed_with_reason, result.rejectReason)
                            } else {
                                getString(com.anydrop.food.R.string.upi_status_failed)
                            }
                        }
                        "expired" -> {
                            polling = false
                            binding.pollingSpinner.visibility = View.GONE
                            binding.statusText.text = getString(com.anydrop.food.R.string.upi_status_expired)
                        }
                        "utr_pending_window" -> {
                            binding.statusText.text = getString(
                                com.anydrop.food.R.string.upi_status_utr_pending_window,
                                result.utrAllowedInSec ?: 0
                            )
                        }
                        "utr_available" -> {
                            binding.statusText.text = getString(com.anydrop.food.R.string.upi_status_utr_available)
                            binding.utrSection.visibility = View.VISIBLE
                        }
                        "utr_submitted" -> {
                            binding.statusText.text = getString(com.anydrop.food.R.string.upi_status_utr_submitted)
                            binding.utrSection.visibility = View.GONE
                        }
                        else -> {
                            binding.statusText.text = getString(com.anydrop.food.R.string.upi_status_checking)
                        }
                    }
                } catch (e: Exception) {
                    // Transient network hiccup — keep polling, don't
                    // treat a failed poll as a failed payment.
                }
            }
        }
    }

    private fun submitUtr() {
        val utr = binding.utrInput.text?.toString()?.trim().orEmpty()
        if (!utr.matches(Regex("^\\d{12}$"))) {
            InAppNotifier.show(this, getString(com.anydrop.food.R.string.upi_utr_invalid), InAppNotifier.Type.ERROR)
            return
        }
        binding.btnSubmitUtr.isEnabled = false
        lifecycleScope.launch {
            try {
                val response = api.submitUpiUtr(orderId, SubmitUtrBody(utr))
                val result = response.body()?.data
                if (response.isSuccessful && result != null) {
                    when (result.status) {
                        "utr_submitted" -> {
                            binding.utrSection.visibility = View.GONE
                            binding.statusText.text = getString(com.anydrop.food.R.string.upi_status_utr_submitted)
                        }
                        "too_many_attempts" -> InAppNotifier.show(this@UpiPaymentActivity, getString(com.anydrop.food.R.string.upi_status_too_many_attempts), InAppNotifier.Type.ERROR)
                        "utr_already_used" -> InAppNotifier.show(this@UpiPaymentActivity, getString(com.anydrop.food.R.string.upi_utr_invalid), InAppNotifier.Type.ERROR)
                        else -> InAppNotifier.show(this@UpiPaymentActivity, getString(com.anydrop.food.R.string.upi_utr_invalid), InAppNotifier.Type.ERROR)
                    }
                } else {
                    InAppNotifier.show(this@UpiPaymentActivity, getString(com.anydrop.food.R.string.upi_utr_invalid), InAppNotifier.Type.ERROR)
                }
            } catch (e: Exception) {
                InAppNotifier.show(this@UpiPaymentActivity, getString(com.anydrop.food.R.string.upi_utr_invalid), InAppNotifier.Type.ERROR)
            } finally {
                binding.btnSubmitUtr.isEnabled = true
            }
        }
    }

    /**
     * Real switch-to-COD flow (backend/api/v1/orders/payment-switch-cod.php).
     * Confirms first (this can't be undone from this screen), then lets
     * the server make the actual call — the same area-payment-restriction
     * + COD-eligibility rules a fresh COD checkout would hit apply here
     * too, so this can still come back rejected (e.g. this area doesn't
     * allow COD, or this customer is under the new-customer COD block).
     */
    private fun confirmSwitchToCod() {
        androidx.appcompat.app.AlertDialog.Builder(this)
            .setTitle(com.anydrop.food.R.string.upi_switch_to_cod_confirm_title)
            .setMessage(com.anydrop.food.R.string.upi_switch_to_cod_confirm_message)
            .setPositiveButton(com.anydrop.food.R.string.upi_switch_to_cod_confirm_yes) { _, _ -> switchToCod() }
            .setNegativeButton(com.anydrop.food.R.string.upi_switch_to_cod_confirm_no, null)
            .show()
    }

    private fun switchToCod() {
        binding.btnCancelPayment.isEnabled = false
        lifecycleScope.launch {
            try {
                val response = api.switchOrderToCod(orderId)
                if (response.isSuccessful) {
                    polling = false
                    pollJob?.cancel()
                    tickJob?.cancel()
                    InAppNotifier.show(this@UpiPaymentActivity, getString(com.anydrop.food.R.string.upi_switch_to_cod_success), InAppNotifier.Type.SUCCESS)
                    goToOrderStatus()
                    return@launch
                }
                val errInfo = com.anydrop.food.network.ApiErrorParser.parse(response)
                when (errInfo.code) {
                    "cod_not_eligible", "payment_method_not_allowed" -> {
                        val reason = (errInfo.data["reason"] as? String).orEmpty()
                        InAppNotifier.show(
                            this@UpiPaymentActivity,
                            getString(com.anydrop.food.R.string.upi_switch_to_cod_not_eligible, reason),
                            InAppNotifier.Type.ERROR
                        )
                    }
                    "order_already_paid" -> {
                        // Payment landed between the button tap and this
                        // call resolving — just show the confirmed order,
                        // don't tell the customer their switch "failed".
                        goToOrderStatus()
                        return@launch
                    }
                    else -> InAppNotifier.show(this@UpiPaymentActivity, getString(com.anydrop.food.R.string.upi_switch_to_cod_failed), InAppNotifier.Type.ERROR)
                }
            } catch (e: Exception) {
                InAppNotifier.show(this@UpiPaymentActivity, getString(com.anydrop.food.R.string.upi_switch_to_cod_failed), InAppNotifier.Type.ERROR)
            } finally {
                binding.btnCancelPayment.isEnabled = true
            }
        }
    }

    /**
     * Reached only after a real "success" from the status poll (or a
     * successful switch-to-COD) — see class kdoc's spoof-safety note.
     * This is deliberately where OrderUpdatePollingService gets started
     * for a UPI order now (moved here from CheckoutActivity 2026-08-23,
     * app owner report) — before this point the order isn't confirmed
     * yet, so there's nothing legitimate to "track" and no notification
     * should be firing.
     */
    private fun goToOrderStatus() {
        com.anydrop.food.notifications.OrderUpdatePollingService.start(this, orderId)
        val intent = Intent(this, OrderStatusActivity::class.java)
        intent.putExtra(OrderStatusActivity.EXTRA_ORDER_ID, orderId)
        startActivity(intent)
        finish()
    }
}
