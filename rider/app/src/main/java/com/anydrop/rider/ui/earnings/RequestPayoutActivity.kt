package com.anydrop.rider.ui.earnings

import android.os.Bundle
import android.view.View
import androidx.appcompat.app.AppCompatActivity
import androidx.lifecycle.lifecycleScope
import androidx.recyclerview.widget.LinearLayoutManager
import com.anydrop.rider.R
import com.anydrop.rider.databinding.ActivityRequestPayoutBinding
import com.anydrop.rider.network.ApiClient
import com.anydrop.rider.network.ApiErrorParser
import com.anydrop.rider.network.RequestRiderPayoutBody
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

        loadBalance()
        loadSavedBankDetails()
        loadHistory()
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

    private fun onSubmit() {
        val amountText = binding.amountInput.text?.toString()?.trim().orEmpty()
        val amount = amountText.toDoubleOrNull()
        val holderName = binding.holderNameInput.text?.toString()?.trim().orEmpty()

        var hasError = false

        if (amount == null || amount <= 0) {
            binding.amountLayout.error = getString(R.string.payout_error_invalid_amount)
            hasError = true
        } else {
            binding.amountLayout.error = null
        }

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

        if (hasError) return

        setLoading(true)
        lifecycleScope.launch {
            try {
                val response = api.requestRiderPayout(
                    RequestRiderPayoutBody(
                        amount = amount!!,
                        payoutMethod = selectedMethod,
                        accountHolderName = holderName,
                        bankName = if (selectedMethod == "bank") bankName else null,
                        accountNumber = if (selectedMethod == "bank") accountNumber else null,
                        ifscCode = if (selectedMethod == "bank") ifscCode else null,
                        upiId = if (selectedMethod == "upi") upiId else null
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
}
