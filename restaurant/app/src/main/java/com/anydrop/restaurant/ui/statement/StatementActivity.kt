package com.anydrop.restaurant.ui.statement

import android.os.Bundle
import android.view.View
import androidx.appcompat.app.AppCompatActivity
import androidx.lifecycle.lifecycleScope
import androidx.recyclerview.widget.LinearLayoutManager
import com.anydrop.restaurant.R
import com.anydrop.restaurant.databinding.ActivityStatementBinding
import com.anydrop.restaurant.network.ApiClient
import com.anydrop.restaurant.ui.common.InAppNotifier
import com.google.android.material.datepicker.MaterialDatePicker
import kotlinx.coroutines.launch
import java.text.SimpleDateFormat
import java.util.Calendar
import java.util.Date
import java.util.Locale
import java.util.TimeZone

/**
 * Restaurant Statement screen (Deep Plan Phase 2,
 * docs/00_Deep_Plan_Statement_CODLimit_QRPay_CashFlow_2026-09-09.md).
 * Launched from AccountFragment's "Statement" row with no extras —
 * always opens on today, same as InsightsFragment defaulting to
 * "week" on open. Backend: backend/api/v1/restaurant/statement.php.
 *
 * Date picker uses the same UTC-anchored MaterialDatePicker +
 * wireDateFormat pattern InsightsFragment's custom-range export
 * already uses, for the same reason: MaterialDatePicker returns UTC
 * millis regardless of device timezone, so formatting in the device's
 * local zone can roll the picked date back/forward a day near
 * midnight.
 */
class StatementActivity : AppCompatActivity() {

    private lateinit var binding: ActivityStatementBinding
    private val api by lazy { ApiClient.create(this) }
    private lateinit var adapter: StatementOrderAdapter

    private val wireDateFormat = SimpleDateFormat("yyyy-MM-dd", Locale.US).apply {
        timeZone = TimeZone.getTimeZone("UTC")
    }
    private val displayDateFormat = SimpleDateFormat("d MMM yyyy", Locale.getDefault())

    // Defaults to today in the DEVICE's local date (the field the user
    // actually reads on screen), separate from wireDateFormat's UTC
    // formatting used only for the picker round-trip.
    private var selectedDate: String = SimpleDateFormat("yyyy-MM-dd", Locale.US).format(Date())

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityStatementBinding.inflate(layoutInflater)
        setContentView(binding.root)

        binding.btnBack.setOnClickListener { finish() }

        adapter = StatementOrderAdapter()
        binding.contentList.layoutManager = LinearLayoutManager(this)
        binding.contentList.adapter = adapter

        binding.swipeRefresh.setOnRefreshListener { loadStatement() }
        binding.btnPickDate.setOnClickListener { showDatePicker() }

        updateDateButtonLabel()
        loadStatement()
    }

    private fun showDatePicker() {
        val cal = Calendar.getInstance(TimeZone.getTimeZone("UTC"))
        try {
            cal.time = wireDateFormat.parse(selectedDate)!!
        } catch (e: Exception) { /* keep current cal (today) on parse failure */ }

        val picker = MaterialDatePicker.Builder.datePicker()
            .setTitleText(getString(R.string.statement_title))
            .setSelection(cal.timeInMillis)
            .build()
        picker.addOnPositiveButtonClickListener { millis ->
            val pickedCal = Calendar.getInstance(TimeZone.getTimeZone("UTC"))
            pickedCal.timeInMillis = millis
            selectedDate = wireDateFormat.format(pickedCal.time)
            updateDateButtonLabel()
            loadStatement()
        }
        picker.show(supportFragmentManager, "statement_date_picker")
    }

    private fun updateDateButtonLabel() {
        // Always show the actual date (not just "Today") so it's
        // unambiguous which day is on screen after the very first load.
        binding.btnPickDate.text = try {
            displayDateFormat.format(wireDateFormat.parse(selectedDate)!!)
        } catch (e: Exception) {
            selectedDate
        }
    }

    private fun loadStatement() {
        if (isFinishing) return
        lifecycleScope.launch {
            try {
                val response = api.getStatement(date = selectedDate)
                val result = response.body()?.data

                if (response.isSuccessful && result != null) {
                    binding.statTotalAmount.text = "₹${"%.0f".format(result.summary.totalAmount)}"
                    binding.statOrderCount.text = "${result.summary.totalOrders} orders on this day"
                    binding.statSettledAmount.text = "₹${"%.0f".format(result.summary.settledAmount)}"
                    binding.statPendingAmount.text = "₹${"%.0f".format(result.summary.pendingAmount)}"
                    // App-owner ask, 2026-09-11 — how much commission was
                    // taken today, and what the restaurant nets after it.
                    binding.statCommissionAmount.text = "₹${"%.0f".format(result.summary.totalCommission)}"
                    binding.statNetPayableAmount.text = "₹${"%.0f".format(result.summary.totalNetPayable)}"

                    adapter.submit(result.orders)
                    binding.emptyState.visibility = if (result.orders.isEmpty()) View.VISIBLE else View.GONE
                } else {
                    InAppNotifier.show(this@StatementActivity, getString(R.string.statement_load_error), InAppNotifier.Type.ERROR)
                }
            } catch (e: Exception) {
                InAppNotifier.show(this@StatementActivity, getString(R.string.statement_load_error), InAppNotifier.Type.ERROR)
            } finally {
                binding.swipeRefresh.isRefreshing = false
            }
        }
    }
}
