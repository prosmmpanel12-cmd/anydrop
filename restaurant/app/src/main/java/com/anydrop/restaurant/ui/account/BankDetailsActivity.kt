package com.anydrop.restaurant.ui.account

import android.os.Bundle
import android.view.View
import androidx.appcompat.app.AppCompatActivity
import androidx.core.content.ContextCompat
import androidx.lifecycle.lifecycleScope
import com.anydrop.restaurant.R
import com.anydrop.restaurant.databinding.ActivityBankDetailsBinding
import com.anydrop.restaurant.network.ApiClient
import com.anydrop.restaurant.network.BankDetails
import com.anydrop.restaurant.network.BankDetailsSaveBody
import com.anydrop.restaurant.ui.common.InAppNotifier
import kotlinx.coroutines.launch
import java.util.Locale

/**
 * Restaurant Bank Details submission screen (PENDING.md §15, migration
 * 59, docs/63_Handover_2026-08-29_BankDetails_Built.md — this is the
 * Android piece that doc left open). Launched from AccountFragment's
 * new "Bank Details" row with no extras, same as ClosureScheduleActivity
 * — loads its own data on open via getBankDetails() rather than being
 * passed anything, since (unlike EditProfileActivity's profile) nothing
 * else in this app already has a fresh copy of this data lying around.
 *
 * Account number handling: `bank-details-get.php` only ever returns
 * `account_number_masked` (e.g. "XXXXXXXX1234") — the real number is
 * never re-echoed after the initial save, per
 * `serialize_bank_details_for_restaurant()`'s own kdoc. So
 * [inputAccountNumber] always starts blank on load; [maskedAccountLabel]
 * shows whatever's currently on file instead. Because
 * `bank-details-save.php` still requires a real `account_number` on
 * every POST (it has no "unchanged" sentinel), leaving the field blank
 * when a masked value already exists is treated here as "resubmit the
 * same masked value the server already stores is not actually
 * possible" — instead, on save, a blank account number is only allowed
 * through when nothing is on file yet (a brand-new submission); once a
 * masked value exists, the owner must re-type the full number to save
 * *anything* on this screen, same as re-entering a password to change
 * an email. This is stricter than "leave blank to keep unchanged" but
 * avoids ever sending a fake/placeholder number that would silently
 * overwrite a correct one with garbage.
 */
class BankDetailsActivity : AppCompatActivity() {

    private lateinit var binding: ActivityBankDetailsBinding
    private val api by lazy { ApiClient.create(this) }

    private var saveInFlight = false
    private var hasExistingRecord = false

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityBankDetailsBinding.inflate(layoutInflater)
        setContentView(binding.root)

        binding.btnBack.setOnClickListener { finish() }
        binding.btnSaveBankDetails.setOnClickListener { save() }

        loadBankDetails()
    }

    private fun loadBankDetails() {
        lifecycleScope.launch {
            try {
                val response = api.getBankDetails()
                val details = response.body()?.data?.bankDetails
                if (response.isSuccessful) {
                    hasExistingRecord = details != null
                    if (details != null) {
                        populate(details)
                    } else {
                        binding.bankStatusCard.visibility = View.GONE
                        binding.bankEmptyStateText.visibility = View.VISIBLE
                        binding.maskedAccountLabel.visibility = View.GONE
                    }
                } else {
                    InAppNotifier.show(this@BankDetailsActivity, getString(R.string.bank_details_load_failed), InAppNotifier.Type.ERROR)
                }
            } catch (e: Exception) {
                InAppNotifier.show(this@BankDetailsActivity, getString(R.string.bank_details_load_failed), InAppNotifier.Type.ERROR)
            }
        }
    }

    private fun populate(details: BankDetails) {
        binding.bankEmptyStateText.visibility = View.GONE

        binding.inputAccountHolderName.setText(details.accountHolderName)
        binding.inputBankName.setText(details.bankName)
        binding.inputIfscCode.setText(details.ifscCode)
        binding.inputUpiId.setText(details.upiId.orEmpty())
        // Account number intentionally left blank — see class kdoc.
        binding.inputAccountNumber.setText("")

        binding.maskedAccountLabel.visibility = View.VISIBLE
        binding.maskedAccountLabel.text = getString(
            R.string.bank_account_number_masked_label
        ) + ": " + details.accountNumberMasked

        renderStatusBadge(details)
    }

    private fun renderStatusBadge(details: BankDetails) {
        binding.bankStatusCard.visibility = View.VISIBLE

        val (label, colorRes) = when (details.verificationStatus) {
            "verified" -> R.string.bank_status_verified to R.color.success_fg
            "rejected" -> R.string.bank_status_rejected to R.color.error_fg
            else -> R.string.bank_status_pending to R.color.status_pending_fg
        }
        binding.bankStatusText.text = getString(label)
        binding.bankStatusText.setTextColor(ContextCompat.getColor(this, colorRes))

        val remarks = details.adminRemarks?.trim().orEmpty()
        if (remarks.isNotEmpty()) {
            binding.bankAdminRemarksText.visibility = View.VISIBLE
            binding.bankAdminRemarksText.text = remarks
        } else {
            binding.bankAdminRemarksText.visibility = View.GONE
        }
    }

    /** Client-side mirror of `validate_bank_fields()`'s regexes
     * (backend/lib/restaurant_bank.php) — checked here too so a typo
     * shows inline instantly instead of round-tripping to the server
     * first, same reasoning EditProfileActivity's GST/FSSAI checks use. */
    private fun save() {
        if (saveInFlight) return

        val accountHolderName = binding.inputAccountHolderName.text?.toString()?.trim().orEmpty()
        if (accountHolderName.isEmpty() || accountHolderName.length > 100) {
            binding.inputAccountHolderName.error = getString(R.string.error_fill_all_fields)
            return
        }

        val bankName = binding.inputBankName.text?.toString()?.trim().orEmpty()
        if (bankName.isEmpty() || bankName.length > 100) {
            binding.inputBankName.error = getString(R.string.error_fill_all_fields)
            return
        }

        // Blank is invalid whether or not a record already exists — see
        // class kdoc for why an existing masked value can't just be
        // resubmitted as-is; the owner must re-type the full number.
        val accountNumber = binding.inputAccountNumber.text?.toString()?.trim().orEmpty()
        if (!accountNumber.matches(Regex("^[0-9]{9,18}$"))) {
            binding.inputAccountNumber.error = getString(R.string.error_invalid_account_number)
            return
        }

        val ifscCode = binding.inputIfscCode.text?.toString()?.trim().orEmpty().uppercase(Locale.US)
        if (!ifscCode.matches(Regex("^[A-Z]{4}0[A-Z0-9]{6}$"))) {
            binding.inputIfscCode.error = getString(R.string.error_invalid_ifsc_code)
            return
        }

        val upiId = binding.inputUpiId.text?.toString()?.trim().orEmpty()
        if (upiId.isNotEmpty() && !upiId.matches(Regex("^[a-zA-Z0-9.\\-_]{2,}@[a-zA-Z][a-zA-Z0-9]{1,}$"))) {
            binding.inputUpiId.error = getString(R.string.error_invalid_upi_id)
            return
        }

        saveInFlight = true
        binding.btnSaveBankDetails.isEnabled = false

        lifecycleScope.launch {
            try {
                val body = BankDetailsSaveBody(
                    accountHolderName = accountHolderName,
                    bankName = bankName,
                    accountNumber = accountNumber,
                    ifscCode = ifscCode,
                    upiId = upiId.ifEmpty { null }
                )
                val response = api.saveBankDetails(body)
                val saved = response.body()?.data?.bankDetails
                if (response.isSuccessful && saved != null) {
                    hasExistingRecord = true
                    populate(saved)
                    InAppNotifier.show(this@BankDetailsActivity, getString(R.string.bank_details_saved), InAppNotifier.Type.SUCCESS)
                } else {
                    InAppNotifier.show(this@BankDetailsActivity, getString(R.string.bank_details_save_failed), InAppNotifier.Type.ERROR)
                }
            } catch (e: Exception) {
                InAppNotifier.show(this@BankDetailsActivity, getString(R.string.bank_details_save_failed), InAppNotifier.Type.ERROR)
            } finally {
                saveInFlight = false
                binding.btnSaveBankDetails.isEnabled = true
            }
        }
    }
}
