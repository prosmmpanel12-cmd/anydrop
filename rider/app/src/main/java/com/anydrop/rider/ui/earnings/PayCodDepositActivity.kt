package com.anydrop.rider.ui.earnings

import android.content.Intent
import android.graphics.Bitmap
import android.graphics.Color
import android.os.Bundle
import android.view.View
import androidx.appcompat.app.AlertDialog
import androidx.appcompat.app.AppCompatActivity
import androidx.lifecycle.lifecycleScope
import com.anydrop.rider.R
import com.anydrop.rider.databinding.ActivityPayCodDepositBinding
import com.anydrop.rider.network.ApiClient
import com.anydrop.rider.network.ApiErrorParser
import com.anydrop.rider.network.DepositInitBody
import com.anydrop.rider.network.DepositInitResult
import com.anydrop.rider.network.DepositSubmitUtrBody
import com.anydrop.rider.ui.common.InAppNotifier
import com.google.zxing.BarcodeFormat
import com.google.zxing.qrcode.QRCodeWriter
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

/**
 * Deep Plan Phase 5 (docs/00_Deep_Plan_Statement_CODLimit_QRPay_
 * CashFlow_2026-09-09.md §5) — "Pay COD Amount". Reached from
 * EarningsFragment's new btnPayCodAmount, only shown once
 * cod_cash_held > 0.
 *
 * Two states on one screen (amountEntrySection → qrSection, toggled by
 * visibility — see activity_pay_cod_deposit.xml's own header comment
 * for why this isn't two Activities). This class is otherwise modeled
 * directly on the customer app's own UpiPaymentActivity.kt (QR
 * rendering via ZXing, poll-driven status, UTR fallback) — same
 * backend (PaymentService/UpipeProvider), same
 * poll-never-trust-client-side spoof-safety rule (see that class's own
 * kdoc, which applies here verbatim): this screen must NEVER treat
 * anything client-side as proof of a completed deposit — only
 * getCodDepositStatus()'s response can move this screen to `success`.
 *
 * WHAT'S DIFFERENT FROM THE CUSTOMER FLOW, deliberately:
 *  - Amount entry — the customer flow's amount is fixed by an order
 *    total; a COD deposit is partial-amount by app-owner decision
 *    (2026-09-09), so this screen asks for an amount BEFORE calling
 *    initiate, capped client-side at [codCashHeld] (server re-validates
 *    this independently in cod-deposit-initiate.php — this cap is UX
 *    only, not the real guard).
 *  - No "switch to COD" equivalent — that button exists on the
 *    customer flow because an order still needs SOME payment method;
 *    a rider's deposit has no such fallback to switch to. Cancelling
 *    here just abandons the in-flight payment_transactions row, which
 *    expires on its own server-side (same expiry mechanism the
 *    customer flow already relies on) — nothing needs to be actively
 *    undone.
 *  - No order/OrderStatusActivity hand-off on success — there's no
 *    order behind this transaction. Success just re-notifies
 *    EarningsFragment (via a plain finish(), which triggers that
 *    fragment's existing onResume() → loadEarnings() refresh — no new
 *    plumbing needed for that part).
 */
class PayCodDepositActivity : AppCompatActivity() {

    companion object {
        const val EXTRA_COD_CASH_HELD = "extra_cod_cash_held"
        private const val TICK_MS = 1000L
    }

    private lateinit var binding: ActivityPayCodDepositBinding
    private val api by lazy { ApiClient.create(this) }

    private var codCashHeld: Double = 0.0
    private var txnId: Int = 0
    private var pollIntervalSec: Int = 10
    private var expiresInSec: Int = 0
    private var polling = true
    private var pollJob: Job? = null
    private var tickJob: Job? = null

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityPayCodDepositBinding.inflate(layoutInflater)
        setContentView(binding.root)

        codCashHeld = intent.getDoubleExtra(EXTRA_COD_CASH_HELD, 0.0)

        binding.btnBack.setOnClickListener { onBackConfirmIfNeeded() }
        binding.codHeldValueText.text = "\u20b9${"%.2f".format(codCashHeld)}"
        binding.depositAmountInput.setText(if (codCashHeld > 0) "%.2f".format(codCashHeld) else "")

        binding.btnDepositFullAmount.setOnClickListener {
            binding.depositAmountInput.setText(if (codCashHeld > 0) "%.2f".format(codCashHeld) else "")
        }

        binding.btnGetDepositQr.setOnClickListener { onGetDepositQr() }
        binding.btnCancelDeposit.setOnClickListener { confirmCancel() }
        binding.btnSubmitUtr.setOnClickListener { submitUtr() }
    }

    override fun onDestroy() {
        super.onDestroy()
        polling = false
        pollJob?.cancel()
        tickJob?.cancel()
    }

    /** Re-fetching a fresh cod_cash_held would be more correct than
     *  trusting the intent extra, but the server-side check in
     *  cod-deposit-initiate.php is the real guard either way (see class
     *  kdoc) — this validation only exists to give the rider an inline
     *  error before a network round trip, not to be the source of truth. */
    private fun onGetDepositQr() {
        val amountText = binding.depositAmountInput.text?.toString()?.trim().orEmpty()
        val amount = amountText.toDoubleOrNull()

        if (amount == null || amount <= 0) {
            binding.depositAmountLayout.error = getString(R.string.cod_deposit_error_amount_required)
            return
        }
        if (amount > codCashHeld + 0.01) {
            binding.depositAmountLayout.error = getString(R.string.cod_deposit_error_amount_exceeds_held)
            return
        }
        binding.depositAmountLayout.error = null

        binding.btnGetDepositQr.isEnabled = false
        binding.depositInitProgress.visibility = View.VISIBLE
        lifecycleScope.launch {
            try {
                val response = api.initiateCodDeposit(DepositInitBody(amount))
                val result = response.body()?.data
                binding.depositInitProgress.visibility = View.GONE
                if (response.isSuccessful && result != null) {
                    if (result.method == "unavailable") {
                        InAppNotifier.show(this@PayCodDepositActivity, result.message ?: getString(R.string.cod_deposit_qr_generation_failed), InAppNotifier.Type.ERROR)
                        binding.btnGetDepositQr.isEnabled = true
                        return@launch
                    }
                    txnId = result.txnId
                    bindPayload(result)
                    binding.amountEntrySection.visibility = View.GONE
                    binding.qrSection.visibility = View.VISIBLE
                    startPolling()
                    startCountdown()
                } else {
                    val err = ApiErrorParser.parse(response)
                    val message = if (err.code == "amount_exceeds_cod_held") {
                        getString(R.string.cod_deposit_error_amount_exceeds_held)
                    } else if (err.code == "nothing_to_deposit") {
                        getString(R.string.cod_deposit_nothing_to_deposit)
                    } else {
                        getString(R.string.cod_deposit_qr_generation_failed)
                    }
                    InAppNotifier.show(this@PayCodDepositActivity, message, InAppNotifier.Type.ERROR)
                    binding.btnGetDepositQr.isEnabled = true
                }
            } catch (e: Exception) {
                binding.depositInitProgress.visibility = View.GONE
                binding.btnGetDepositQr.isEnabled = true
                InAppNotifier.show(this@PayCodDepositActivity, getString(R.string.cod_deposit_qr_generation_failed), InAppNotifier.Type.ERROR)
            }
        }
    }

    private fun bindPayload(result: DepositInitResult) {
        binding.testModeBanner.visibility = if (result.isTestMode) View.VISIBLE else View.GONE
        binding.depositAmountText.text = "\u20b9${"%.2f".format(result.amount ?: 0.0)}"
        binding.instructionsText.text = result.instructions.mapIndexed { i, line -> "${i + 1}. $line" }.joinToString("\n")
        pollIntervalSec = result.pollIntervalSec.takeIf { it > 0 } ?: 10
        expiresInSec = result.expiresInSec

        val upiLink = result.upiLink
        if (!upiLink.isNullOrBlank()) {
            renderQr(upiLink)
        }
        binding.qrLoadingSpinner.visibility = View.GONE
    }

    /** Real, offline QR encoding — same reasoning as UpiPaymentActivity's
     *  own renderQr() (no server-generated QR image exists, see
     *  backend/lib/payment/UpipeProvider.php's doc-comment). */
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
            InAppNotifier.show(this, getString(R.string.cod_deposit_qr_generation_failed), InAppNotifier.Type.ERROR)
        }
    }

    private fun startCountdown() {
        tickJob?.cancel()
        tickJob = lifecycleScope.launch {
            while (polling && expiresInSec > 0) {
                val mins = expiresInSec / 60
                val secs = expiresInSec % 60
                binding.expiryText.text = getString(R.string.upi_expiry_format, "%d:%02d".format(mins, secs))
                delay(TICK_MS)
                expiresInSec -= 1
            }
        }
    }

    /**
     * Polls GET .../cod-deposit-status.php every `pollIntervalSec`. This
     * is the ONLY function in this file allowed to react to a "success"
     * status — see class kdoc's spoof-safety note.
     */
    private fun startPolling() {
        pollJob?.cancel()
        pollJob = lifecycleScope.launch {
            while (polling) {
                delay(pollIntervalSec * 1000L)
                if (!polling) break
                try {
                    val response = api.getCodDepositStatus(txnId)
                    val result = response.body()?.data ?: continue
                    when (result.status) {
                        "success" -> {
                            polling = false
                            binding.statusText.text = getString(R.string.upi_status_success)
                            binding.pollingSpinner.visibility = View.GONE
                            InAppNotifier.show(this@PayCodDepositActivity, getString(R.string.cod_deposit_success_message), InAppNotifier.Type.SUCCESS)
                            delay(600)
                            finish()
                        }
                        "failed" -> {
                            polling = false
                            binding.pollingSpinner.visibility = View.GONE
                            binding.statusText.text = if (!result.rejectReason.isNullOrBlank()) {
                                getString(R.string.upi_status_failed_with_reason, result.rejectReason)
                            } else {
                                getString(R.string.upi_status_failed)
                            }
                        }
                        "expired" -> {
                            polling = false
                            binding.pollingSpinner.visibility = View.GONE
                            binding.statusText.text = getString(R.string.upi_status_expired)
                        }
                        "utr_pending_window" -> {
                            binding.statusText.text = getString(
                                R.string.upi_status_utr_pending_window,
                                result.utrAllowedInSec ?: 0
                            )
                        }
                        "utr_available" -> {
                            binding.statusText.text = getString(R.string.upi_status_utr_available)
                            binding.utrSection.visibility = View.VISIBLE
                        }
                        "utr_submitted" -> {
                            binding.statusText.text = getString(R.string.upi_status_utr_submitted)
                            binding.utrSection.visibility = View.GONE
                        }
                        else -> {
                            binding.statusText.text = getString(R.string.upi_status_checking)
                        }
                    }
                } catch (e: Exception) {
                    // Transient network hiccup — keep polling, don't
                    // treat a failed poll as a failed deposit.
                }
            }
        }
    }

    private fun submitUtr() {
        val utr = binding.utrInput.text?.toString()?.trim().orEmpty()
        if (!utr.matches(Regex("^\\d{12}$"))) {
            InAppNotifier.show(this, getString(R.string.upi_utr_invalid), InAppNotifier.Type.ERROR)
            return
        }
        binding.btnSubmitUtr.isEnabled = false
        lifecycleScope.launch {
            try {
                val response = api.submitCodDepositUtr(txnId, DepositSubmitUtrBody(utr))
                val result = response.body()?.data
                if (response.isSuccessful && result != null) {
                    when (result.status) {
                        "utr_submitted" -> {
                            binding.utrSection.visibility = View.GONE
                            binding.statusText.text = getString(R.string.upi_status_utr_submitted)
                        }
                        "too_many_attempts" -> InAppNotifier.show(this@PayCodDepositActivity, getString(R.string.upi_status_too_many_attempts), InAppNotifier.Type.ERROR)
                        "utr_already_used" -> InAppNotifier.show(this@PayCodDepositActivity, getString(R.string.upi_utr_invalid), InAppNotifier.Type.ERROR)
                        else -> InAppNotifier.show(this@PayCodDepositActivity, getString(R.string.upi_utr_invalid), InAppNotifier.Type.ERROR)
                    }
                } else {
                    InAppNotifier.show(this@PayCodDepositActivity, getString(R.string.upi_utr_invalid), InAppNotifier.Type.ERROR)
                }
            } catch (e: Exception) {
                InAppNotifier.show(this@PayCodDepositActivity, getString(R.string.upi_utr_invalid), InAppNotifier.Type.ERROR)
            } finally {
                binding.btnSubmitUtr.isEnabled = true
            }
        }
    }

    /** No server call needed — see class kdoc on why cancelling has no
     *  "switch to COD"-style equivalent action to perform. */
    private fun confirmCancel() {
        AlertDialog.Builder(this)
            .setMessage(R.string.cod_deposit_cancel_button)
            .setPositiveButton(android.R.string.ok) { _, _ ->
                polling = false
                pollJob?.cancel()
                tickJob?.cancel()
                finish()
            }
            .setNegativeButton(android.R.string.cancel, null)
            .show()
    }

    private fun onBackConfirmIfNeeded() {
        if (binding.qrSection.visibility == View.VISIBLE) {
            confirmCancel()
        } else {
            finish()
        }
    }
}
