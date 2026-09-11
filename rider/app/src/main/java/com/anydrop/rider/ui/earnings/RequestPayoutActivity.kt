package com.anydrop.rider.ui.earnings

import android.os.Bundle
import android.os.CountDownTimer
import android.view.View
import androidx.appcompat.app.AppCompatActivity
import androidx.lifecycle.lifecycleScope
import androidx.recyclerview.widget.LinearLayoutManager
import com.anydrop.rider.R
import com.anydrop.rider.databinding.ActivityRequestPayoutBinding
import com.anydrop.rider.databinding.DialogBankDetailsOtpBinding
import com.anydrop.rider.network.ApiClient
import com.anydrop.rider.network.ApiErrorParser
import com.anydrop.rider.network.RequestRiderPayoutBody
import com.anydrop.rider.network.SaveRiderBankDetailsBody
import com.anydrop.rider.ui.common.InAppNotifier
import kotlinx.coroutines.launch

/**
 * Deep-plan §21, migration 74 — Rider Self-Service Payout Requests.
 * Modeled directly on the customer app's WithdrawActivity (same form:
 * one screen for both payout methods, bank/UPI fields toggled by
 * visibility, read-only history list below), same division of
 * responsibility, adapted to this app's ApiService/models and
 * InAppNotifier instead of the customer app's own copies.
 *
 * Reached only via EarningsActivity's "Request Payout" button, same
 * relationship the customer app's WalletActivity → WithdrawActivity
 * button already has.
 *
 * The balance shown here is a snapshot re-fetched via
 * getEarningsSummary() on load — this screen does NOT trust a value
 * passed in from EarningsActivity, since request_rider_payout()'s
 * server-side row-locked balance check (see backend/lib/rider_payout.php)
 * is the real guard; a stale client-side number here can only under- or
 * over-estimate what the rider CAN submit, never let them bypass the
 * server check. Same reasoning WithdrawActivity's own kdoc gives for the
 * customer side, just re-fetched here instead of passed in since this
 * screen has no natural "previous screen already had it" shortcut the
 * way WalletActivity → WithdrawActivity does.
 */
class RequestPayoutActivity : AppCompatActivity() {

    private lateinit var binding: ActivityRequestPayoutBinding
    private val api by lazy { ApiClient.create(this) }
    private lateinit var historyAdapter: RiderPayoutAdapter

    private var selectedMethod: String = "bank"

    // App-owner ask, 2026-09-09: bank/UPI save is OTP-confirmed. This
    // timer only gates the dialog's own "Resend" link (30s, same window
    // the customer app's login-OTP resend uses this same session) — the
    // real cooldown enforcement is server-side (otp_request_cooldown_seconds
    // in payout-bank-details-request-otp.php); this is UX only.
    private var bankOtpResendTimer: CountDownTimer? = null
    private val bankOtpResendCooldownMillis = 30_000L

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityRequestPayoutBinding.inflate(layoutInflater)
        setContentView(binding.root)

        binding.btnBack.setOnClickListener { finish() }

        historyAdapter = RiderPayoutAdapter()
        binding.historyList.layoutManager = LinearLayoutManager(this)
        binding.historyList.adapter = historyAdapter

        binding.methodToggleGroup.check(binding.btnMethodBank.id)
        binding.methodToggleGroup.addOnButtonCheckedListener { _, checkedId, isChecked ->
            if (!isChecked) return@addOnButtonCheckedListener
            selectedMethod = if (checkedId == binding.btnMethodUpi.id) "upi" else "bank"
            updateMethodFieldsVisibility()
        }
        updateMethodFieldsVisibility()

        binding.btnSubmitPayout.setOnClickListener { onSubmit() }
        binding.btnSaveBankDetails.setOnClickListener { onSaveBankDetails() }

        loadBalance()
        loadSavedBankDetails()
        loadHistory()
    }

    override fun onDestroy() {
        super.onDestroy()
        bankOtpResendTimer?.cancel()
    }

    private fun updateMethodFieldsVisibility() {
        val isBank = selectedMethod == "bank"
        binding.bankFieldsGroup.visibility = if (isBank) View.VISIBLE else View.GONE
        binding.upiIdLayout.visibility = if (isBank) View.GONE else View.VISIBLE
    }

    private fun loadBalance() {
        lifecycleScope.launch {
            try {
                val balance = api.getEarningsSummary().body()?.data?.balance ?: 0.0
                binding.availableBalanceText.text =
                    getString(R.string.payout_available_balance_format, "%.2f".format(balance))
            } catch (e: Exception) {
                // Non-fatal — this is a display-only convenience number,
                // see the class kdoc above for why the server check is
                // what actually matters.
            }
        }
    }

    private fun loadSavedBankDetails() {
        lifecycleScope.launch {
            try {
                val details = api.getRiderBankDetails().body()?.data?.bankDetails ?: return@launch
                binding.holderNameInput.setText(details.accountHolderName)
                if (!details.upiId.isNullOrBlank()) {
                    binding.methodToggleGroup.check(binding.btnMethodUpi.id)
                    binding.upiIdInput.setText(details.upiId)
                } else {
                    binding.methodToggleGroup.check(binding.btnMethodBank.id)
                    binding.bankNameInput.setText(details.bankName)
                    binding.ifscInput.setText(details.ifscCode)
                    // account_number comes back masked (e.g. "XXXXXX1234")
                    // — deliberately NOT pre-filled into accountNumberInput,
                    // same reasoning the customer app's WithdrawActivity
                    // never echoes a full previously-saved account number
                    // back into an editable field. The rider re-enters it
                    // if they want to reuse the same account, or edits any
                    // other field to save new details.
                }
            } catch (e: Exception) {
                // Non-fatal — no saved details is a completely normal
                // first-time state, not an error worth surfacing.
            }
        }
    }

    private fun loadHistory() {
        lifecycleScope.launch {
            try {
                val requests = api.getRiderPayoutHistory().body()?.data?.requests ?: emptyList()
                historyAdapter.submit(requests)
                binding.historyList.visibility = if (requests.isEmpty()) View.GONE else View.VISIBLE
                binding.historyEmptyText.visibility = if (requests.isEmpty()) View.VISIBLE else View.GONE
            } catch (e: Exception) {
                InAppNotifier.show(this@RequestPayoutActivity, getString(R.string.payout_load_failed), InAppNotifier.Type.ERROR)
            }
        }
    }

    /** Holds the payout-method-specific fields read off the form, once
     *  they've passed [validateBankFields]. Shared by [onSubmit] and
     *  [onSaveBankDetails] so the two entry points (submit a payout /
     *  save bank details on their own) never validate differently. */
    private data class BankFields(
        val holderName: String,
        val bankName: String?,
        val accountNumber: String?,
        val ifscCode: String?,
        val upiId: String?
    )

    /** Validates the holder-name + method-specific fields (amount is
     *  NOT included — only [onSubmit] needs an amount, [onSaveBankDetails]
     *  doesn't). Sets/clears each TextInputLayout's inline error as a
     *  side effect, same convention the original onSubmit used. Returns
     *  null if any field is invalid. */
    private fun validateBankFields(): BankFields? {
        val holderName = binding.holderNameInput.text?.toString()?.trim().orEmpty()
        var hasError = false

        if (holderName.isEmpty()) {
            binding.holderNameLayout.error = getString(R.string.payout_error_holder_name)
            hasError = true
        } else {
            binding.holderNameLayout.error = null
        }

        var bankName: String? = null
        var accountNumber: String? = null
        var ifscCode: String? = null
        var upiId: String? = null

        if (selectedMethod == "bank") {
            bankName = binding.bankNameInput.text?.toString()?.trim().orEmpty()
            accountNumber = binding.accountNumberInput.text?.toString()?.trim().orEmpty()
            ifscCode = binding.ifscInput.text?.toString()?.trim().orEmpty()

            if (bankName.isEmpty()) {
                binding.bankNameLayout.error = getString(R.string.payout_error_bank_name)
                hasError = true
            } else {
                binding.bankNameLayout.error = null
            }
            if (accountNumber.isEmpty() || !accountNumber.all { it.isDigit() } || accountNumber.length !in 9..18) {
                binding.accountNumberLayout.error = getString(R.string.payout_error_account_number)
                hasError = true
            } else {
                binding.accountNumberLayout.error = null
            }
            if (!ifscCode.matches(Regex("^[A-Za-z]{4}0[A-Za-z0-9]{6}$"))) {
                binding.ifscLayout.error = getString(R.string.payout_error_ifsc)
                hasError = true
            } else {
                binding.ifscLayout.error = null
                ifscCode = ifscCode.uppercase()
            }
        } else {
            upiId = binding.upiIdInput.text?.toString()?.trim().orEmpty()
            if (!upiId.matches(Regex("^[\\w.\\-]{2,256}@[\\w]{2,64}$"))) {
                binding.upiIdLayout.error = getString(R.string.payout_error_upi_id)
                hasError = true
            } else {
                binding.upiIdLayout.error = null
            }
        }

        if (hasError) return null
        return BankFields(holderName, bankName, accountNumber, ifscCode, upiId)
    }

    private fun onSubmit() {
        val amountText = binding.amountInput.text?.toString()?.trim().orEmpty()
        val amount = amountText.toDoubleOrNull()

        if (amount == null || amount <= 0) {
            binding.amountLayout.error = getString(R.string.payout_error_invalid_amount)
        } else {
            binding.amountLayout.error = null
        }

        val fields = validateBankFields()
        if (amount == null || amount <= 0 || fields == null) return

        setLoading(true)
        lifecycleScope.launch {
            try {
                val response = api.requestRiderPayout(
                    RequestRiderPayoutBody(
                        amount = amount,
                        payoutMethod = selectedMethod,
                        accountHolderName = fields.holderName,
                        bankName = fields.bankName,
                        accountNumber = fields.accountNumber,
                        ifscCode = fields.ifscCode,
                        upiId = fields.upiId
                    )
                )
                setLoading(false)
                val body = response.body()
                if (response.isSuccessful && body?.success == true) {
                    InAppNotifier.show(this@RequestPayoutActivity, getString(R.string.payout_success_message), InAppNotifier.Type.SUCCESS)
                    binding.amountInput.text?.clear()
                    loadBalance()
                    loadHistory()
                } else {
                    val err = ApiErrorParser.parse(response)
                    val message = when (err.code) {
                        "insufficient_balance" -> getString(R.string.payout_insufficient_balance)
                        "below_minimum_amount" -> {
                            val min = (err.data["minimum"] as? Number)?.toDouble() ?: 0.0
                            getString(R.string.payout_below_minimum_format, "%.2f".format(min))
                        }
                        "validation_error" -> getString(R.string.payout_failed_generic)
                        else -> err.code ?: getString(R.string.payout_failed_generic)
                    }
                    InAppNotifier.show(this@RequestPayoutActivity, message, InAppNotifier.Type.ERROR)
                }
            } catch (e: Exception) {
                setLoading(false)
                InAppNotifier.show(
                    this@RequestPayoutActivity,
                    getString(R.string.payout_failed_generic),
                    InAppNotifier.Type.ERROR
                )
            }
        }
    }

    private fun setLoading(loading: Boolean) {
        binding.payoutSubmitProgress.visibility = if (loading) View.VISIBLE else View.GONE
        binding.btnSubmitPayout.isEnabled = !loading
    }

    // ---- Save Bank Details (OTP-confirmed) — app-owner ask, 2026-09-09.
    // Flow: validate form → request OTP → show dialog → confirm calls
    // saveRiderBankDetails() with the entered otp. Two-step because the
    // OTP-request endpoint has no body of its own (see
    // payout-bank-details-request-otp.php's kdoc) — validating the form
    // first avoids sending an email the rider can't actually use yet. ----

    private fun onSaveBankDetails() {
        val fields = validateBankFields() ?: return
        setBankDetailsSaveLoading(true)
        lifecycleScope.launch {
            try {
                val response = api.requestPayoutBankDetailsOtp()
                setBankDetailsSaveLoading(false)
                val body = response.body()
                if (response.isSuccessful && body?.success == true) {
                    showBankOtpDialog(fields)
                } else {
                    val err = ApiErrorParser.parse(response)
                    val message = if (err.code == "otp_request_cooldown") {
                        getString(R.string.bank_otp_cooldown_message)
                    } else {
                        getString(R.string.bank_otp_send_failed)
                    }
                    InAppNotifier.show(this@RequestPayoutActivity, message, InAppNotifier.Type.ERROR)
                }
            } catch (e: Exception) {
                setBankDetailsSaveLoading(false)
                InAppNotifier.show(this@RequestPayoutActivity, getString(R.string.bank_otp_send_failed), InAppNotifier.Type.ERROR)
            }
        }
    }

    /** Custom-button dialog pattern (2026-09-11 restyle — matches
     *  AccountFragment.kt's dialog_logout_confirm.xml wiring): the old
     *  AlertDialog.Builder + setPositiveButton/setTitle calls are gone
     *  now that the title lives inside dialog_bank_details_otp.xml
     *  itself and Confirm/Cancel are real MaterialButtons in the layout.
     *  btnBankOtpConfirm's click listener does its own isEnabled
     *  toggling on invalid OTP (no default dismiss-on-click behavior to
     *  fight, unlike the old AlertDialog positive button). Resend
     *  re-fires requestPayoutBankDetailsOtp() and restarts the 30s
     *  cooldown on its own button, independent of the confirm button's
     *  enabled state. */
    private fun showBankOtpDialog(fields: BankFields) {
        val dialogBinding = DialogBankDetailsOtpBinding.inflate(layoutInflater)
        dialogBinding.bankOtpResend.text = getString(R.string.btn_resend_bank_otp)

        val dialog = com.google.android.material.dialog.MaterialAlertDialogBuilder(this)
            .setView(dialogBinding.root)
            .setCancelable(true)
            .create()

        dialog.setOnDismissListener { bankOtpResendTimer?.cancel() }

        dialogBinding.btnBankOtpCancel.setOnClickListener { dialog.dismiss() }

        val confirmButton = dialogBinding.btnBankOtpConfirm
        confirmButton.setOnClickListener {
                val otp = dialogBinding.inputBankOtp.text?.toString()?.trim().orEmpty()
                if (otp.isEmpty()) {
                    dialogBinding.bankOtpError.text = getString(R.string.error_bank_otp_empty)
                    dialogBinding.bankOtpError.visibility = View.VISIBLE
                    return@setOnClickListener
                }

                confirmButton.isEnabled = false
                dialogBinding.bankOtpError.visibility = View.GONE

                lifecycleScope.launch {
                    try {
                        val response = api.saveRiderBankDetails(
                            SaveRiderBankDetailsBody(
                                payoutMethod = selectedMethod,
                                accountHolderName = fields.holderName,
                                bankName = fields.bankName,
                                accountNumber = fields.accountNumber,
                                ifscCode = fields.ifscCode,
                                upiId = fields.upiId,
                                otp = otp
                            )
                        )
                        val body = response.body()
                        if (response.isSuccessful && body?.success == true) {
                            dialog.dismiss()
                            InAppNotifier.show(this@RequestPayoutActivity, getString(R.string.payout_bank_details_saved), InAppNotifier.Type.SUCCESS)
                            loadSavedBankDetails()
                        } else {
                            val err = ApiErrorParser.parse(response)
                            when (err.code) {
                                "invalid_otp" -> {
                                    confirmButton.isEnabled = true
                                    val attemptsRemaining = (err.data["attempts_remaining"] as? Number)?.toInt()
                                    val msg = if (attemptsRemaining != null) {
                                        getString(R.string.error_bank_otp_invalid_format, attemptsRemaining)
                                    } else {
                                        getString(R.string.error_bank_otp_invalid)
                                    }
                                    dialogBinding.bankOtpError.text = msg
                                    dialogBinding.bankOtpError.visibility = View.VISIBLE
                                }
                                "otp_expired", "otp_not_found", "otp_max_attempts_exceeded" -> {
                                    dialog.dismiss()
                                    InAppNotifier.show(this@RequestPayoutActivity, getString(R.string.payout_bank_details_save_failed), InAppNotifier.Type.ERROR)
                                }
                                else -> {
                                    dialog.dismiss()
                                    InAppNotifier.show(this@RequestPayoutActivity, getString(R.string.payout_bank_details_save_failed), InAppNotifier.Type.ERROR)
                                }
                            }
                        }
                    } catch (e: Exception) {
                        dialog.dismiss()
                        InAppNotifier.show(this@RequestPayoutActivity, getString(R.string.payout_bank_details_save_failed), InAppNotifier.Type.ERROR)
                    }
                }
        }

        dialogBinding.bankOtpResend.setOnClickListener {
            dialogBinding.bankOtpResend.isEnabled = false
            lifecycleScope.launch {
                try {
                    val response = api.requestPayoutBankDetailsOtp()
                    val body = response.body()
                    if (response.isSuccessful && body?.success == true) {
                        InAppNotifier.show(this@RequestPayoutActivity, getString(R.string.bank_otp_sent), InAppNotifier.Type.SUCCESS)
                        startBankOtpResendCooldown(dialogBinding)
                    } else {
                        dialogBinding.bankOtpResend.isEnabled = true
                        val err = ApiErrorParser.parse(response)
                        val message = if (err.code == "otp_request_cooldown") {
                            getString(R.string.bank_otp_cooldown_message)
                        } else {
                            getString(R.string.bank_otp_send_failed)
                        }
                        InAppNotifier.show(this@RequestPayoutActivity, message, InAppNotifier.Type.ERROR)
                    }
                } catch (e: Exception) {
                    dialogBinding.bankOtpResend.isEnabled = true
                    InAppNotifier.show(this@RequestPayoutActivity, getString(R.string.bank_otp_send_failed), InAppNotifier.Type.ERROR)
                }
            }
        }

        dialog.show()
        startBankOtpResendCooldown(dialogBinding)
    }

    private fun startBankOtpResendCooldown(dialogBinding: DialogBankDetailsOtpBinding) {
        bankOtpResendTimer?.cancel()
        dialogBinding.bankOtpResend.isEnabled = false
        bankOtpResendTimer = object : CountDownTimer(bankOtpResendCooldownMillis, 1_000L) {
            override fun onTick(millisUntilFinished: Long) {
                val secondsLeft = (millisUntilFinished / 1000L) + 1
                dialogBinding.bankOtpResend.text = getString(R.string.bank_otp_resend_countdown, secondsLeft)
            }

            override fun onFinish() {
                dialogBinding.bankOtpResend.isEnabled = true
                dialogBinding.bankOtpResend.text = getString(R.string.btn_resend_bank_otp)
            }
        }.start()
    }

    private fun setBankDetailsSaveLoading(loading: Boolean) {
        binding.bankDetailsSaveProgress.visibility = if (loading) View.VISIBLE else View.GONE
        binding.btnSaveBankDetails.isEnabled = !loading
    }
}
