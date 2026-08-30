package com.anydrop.food.ui.profile

import android.os.Bundle
import android.view.View
import androidx.appcompat.app.AppCompatActivity
import androidx.lifecycle.lifecycleScope
import androidx.recyclerview.widget.LinearLayoutManager
import com.anydrop.food.R
import com.anydrop.food.databinding.ActivityWithdrawBinding
import com.anydrop.food.network.ApiClient
import com.anydrop.food.network.ApiErrorParser
import com.anydrop.food.network.RequestWithdrawalBody
import com.anydrop.food.ui.common.InAppNotifier
import kotlinx.coroutines.launch

/**
 * Wallet → Withdraw (PENDING.md §37, migration 65). Reachable from
 * WalletActivity's "Withdraw" button. One form for both payout
 * methods (bank fields / UPI field toggle visibility based on the
 * MaterialButtonToggleGroup selection) plus a read-only history list
 * below, same "form above, history below" shape CompleteProfileActivity
 * and WalletActivity individually use, combined here since this screen
 * needs both.
 *
 * Bank details, if the customer has any saved from a prior withdrawal,
 * are pre-filled via GET wallet-bank-details-get.php on load — saving
 * again here always calls wallet-bank-details-save.php first (create-
 * or-replace, same as the restaurant equivalent), then submits the
 * withdrawal with those exact values, so what's saved and what's
 * requested never drift apart within a single submit.
 *
 * The wallet balance shown here is a snapshot the previous screen
 * (WalletActivity) already had — this screen does NOT re-fetch it
 * itself, since request_wallet_withdrawal()'s server-side debit_wallet()
 * is the real balance guard (see that function's own kdoc); a stale
 * client-side number here can only under- or over-estimate what the
 * user CAN submit, never let them bypass the server check.
 */
class WithdrawActivity : AppCompatActivity() {

    private lateinit var binding: ActivityWithdrawBinding
    private val api by lazy { ApiClient.create(this) }
    private lateinit var historyAdapter: WalletWithdrawalAdapter

    private var selectedMethod: String = "bank"

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityWithdrawBinding.inflate(layoutInflater)
        setContentView(binding.root)

        binding.btnBack.setOnClickListener { finish() }

        historyAdapter = WalletWithdrawalAdapter()
        binding.historyList.layoutManager = LinearLayoutManager(this)
        binding.historyList.adapter = historyAdapter

        binding.methodToggleGroup.check(binding.btnMethodBank.id)
        binding.methodToggleGroup.addOnButtonCheckedListener { _, checkedId, isChecked ->
            if (!isChecked) return@addOnButtonCheckedListener
            selectedMethod = if (checkedId == binding.btnMethodUpi.id) "upi" else "bank"
            updateMethodFieldsVisibility()
        }
        updateMethodFieldsVisibility()

        binding.btnSubmitWithdrawal.setOnClickListener { onSubmit() }

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
                val balance = api.getWallet().body()?.data?.balance ?: 0.0
                binding.availableBalanceText.text =
                    getString(R.string.withdraw_available_balance_format, "%.2f".format(balance))
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
                val details = api.getWalletBankDetails().body()?.data?.bankDetails ?: return@launch
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
                    // same reasoning restaurant_bank.php's masking exists
                    // for: this screen never echoes a full previously-saved
                    // account number back into an editable field. The
                    // customer re-enters it if they want to reuse the same
                    // account, or edits any other field to save new details.
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
                val withdrawals = api.getWalletWithdrawalHistory().body()?.data?.withdrawals ?: emptyList()
                historyAdapter.submit(withdrawals)
                binding.historyList.visibility = if (withdrawals.isEmpty()) View.GONE else View.VISIBLE
                binding.historyEmptyText.visibility = if (withdrawals.isEmpty()) View.VISIBLE else View.GONE
            } catch (e: Exception) {
                InAppNotifier.show(this@WithdrawActivity, getString(R.string.withdraw_load_failed), InAppNotifier.Type.ERROR)
            }
        }
    }

    private fun onSubmit() {
        val amountText = binding.amountInput.text?.toString()?.trim().orEmpty()
        val amount = amountText.toDoubleOrNull()
        val holderName = binding.holderNameInput.text?.toString()?.trim().orEmpty()

        var hasError = false

        if (amount == null || amount <= 0) {
            binding.amountLayout.error = "Enter a valid amount"
            hasError = true
        } else {
            binding.amountLayout.error = null
        }

        if (holderName.isEmpty()) {
            binding.holderNameLayout.error = "Enter the account holder name"
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
                binding.bankNameLayout.error = "Enter the bank name"
                hasError = true
            } else {
                binding.bankNameLayout.error = null
            }
            if (accountNumber.isEmpty() || !accountNumber.all { it.isDigit() } || accountNumber.length !in 9..18) {
                binding.accountNumberLayout.error = "Enter a valid account number"
                hasError = true
            } else {
                binding.accountNumberLayout.error = null
            }
            if (!ifscCode.matches(Regex("^[A-Za-z]{4}0[A-Za-z0-9]{6}$"))) {
                binding.ifscLayout.error = "Enter a valid IFSC code"
                hasError = true
            } else {
                binding.ifscLayout.error = null
                ifscCode = ifscCode.uppercase()
            }
        } else {
            upiId = binding.upiIdInput.text?.toString()?.trim().orEmpty()
            if (!upiId.matches(Regex("^[\\w.\\-]{2,256}@[\\w]{2,64}$"))) {
                binding.upiIdLayout.error = "Enter a valid UPI ID"
                hasError = true
            } else {
                binding.upiIdLayout.error = null
            }
        }

        if (hasError) return

        setLoading(true)
        lifecycleScope.launch {
            try {
                val response = api.requestWalletWithdrawal(
                    RequestWithdrawalBody(
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
                    InAppNotifier.show(this@WithdrawActivity, getString(R.string.withdraw_success_message), InAppNotifier.Type.SUCCESS)
                    binding.amountInput.text?.clear()
                    loadBalance()
                    loadHistory()
                } else {
                    val err = ApiErrorParser.parse(response)
                    val message = when (err.code) {
                        "insufficient_balance" -> getString(R.string.withdraw_insufficient_balance)
                        "below_minimum_amount" -> {
                            val min = (err.data["minimum"] as? Number)?.toDouble() ?: 0.0
                            getString(R.string.withdraw_below_minimum_format, "%.2f".format(min))
                        }
                        "validation_error" -> getString(R.string.withdraw_failed_generic)
                        else -> err.code ?: getString(R.string.withdraw_failed_generic)
                    }
                    InAppNotifier.show(this@WithdrawActivity, message, InAppNotifier.Type.ERROR)
                }
            } catch (e: Exception) {
                setLoading(false)
                InAppNotifier.show(
                    this@WithdrawActivity,
                    "Couldn't reach the server. Is the backend running?",
                    InAppNotifier.Type.ERROR
                )
            }
        }
    }

    private fun setLoading(loading: Boolean) {
        binding.withdrawSubmitProgress.visibility = if (loading) View.VISIBLE else View.GONE
        binding.btnSubmitWithdrawal.isEnabled = !loading
    }
}
