package com.anydrop.rider.ui.statement

import android.os.Bundle
import android.view.View
import androidx.appcompat.app.AppCompatActivity
import androidx.core.content.ContextCompat
import androidx.lifecycle.lifecycleScope
import androidx.recyclerview.widget.LinearLayoutManager
import com.anydrop.rider.R
import com.anydrop.rider.databinding.ActivityStatementBinding
import com.anydrop.rider.network.ApiClient
import com.anydrop.rider.ui.common.InAppNotifier
import com.google.android.material.datepicker.MaterialDatePicker
import kotlinx.coroutines.launch
import java.text.SimpleDateFormat
import java.util.Calendar
import java.util.Date
import java.util.Locale
import java.util.TimeZone

/**
 * Rider Statement screen (Deep Plan Phase 3,
 * docs/00_Deep_Plan_Statement_CODLimit_QRPay_CashFlow_2026-09-09.md).
 * Launched from EarningsFragment's "View Statement" button with no
 * extras — always opens on today. Backend: backend/api/v1/rider/statement.php
 * (built in Phase 1).
 *
 * Date picker uses the same UTC-anchored MaterialDatePicker pattern the
 * Restaurant App's StatementActivity (Phase 2) already established —
 * kept consistent across both apps even though they're separate Gradle
 * modules with no shared code.
 */
class StatementActivity : AppCompatActivity() {

    private lateinit var binding: ActivityStatementBinding
    private val api by lazy { ApiClient.create(this) }
    private lateinit var ordersAdapter: StatementOrderAdapter
    private lateinit var depositsAdapter: DepositHistoryAdapter

    private val wireDateFormat = SimpleDateFormat("yyyy-MM-dd", Locale.US).apply {
        timeZone = TimeZone.getTimeZone("UTC")
    }
    private val displayDateFormat = SimpleDateFormat("d MMM yyyy", Locale.getDefault())

    private var selectedDate: String = SimpleDateFormat("yyyy-MM-dd", Locale.US).format(Date())

    // Deep Plan Phase 6 — which tab is active. Deposits list is loaded
    // lazily (only once the tab is first opened, see loadDepositHistory)
    // and cached for the rest of this screen's lifetime — no pull-to-
    // refresh re-triggers it a second time unless the user is actually
    // on that tab, same "small list, fetch once" reasoning as the rest
    // of this screen.
    private var showingDeposits = false
    private var depositsLoadedOnce = false

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityStatementBinding.inflate(layoutInflater)
        setContentView(binding.root)

        binding.btnBack.setOnClickListener { finish() }

        ordersAdapter = StatementOrderAdapter()
        depositsAdapter = DepositHistoryAdapter()
        binding.contentList.layoutManager = LinearLayoutManager(this)
        binding.contentList.adapter = ordersAdapter

        binding.swipeRefresh.setOnRefreshListener {
            if (showingDeposits) loadDepositHistory() else loadStatement()
        }
        binding.btnPickDate.setOnClickListener { showDatePicker() }

        binding.tabOrders.setOnClickListener { selectTab(deposits = false) }
        binding.tabDeposits.setOnClickListener { selectTab(deposits = true) }

        updateDateButtonLabel()
        loadStatement()
    }

    private fun selectTab(deposits: Boolean) {
        if (showingDeposits == deposits) return
        showingDeposits = deposits

        val activeTint = ContextCompat.getColorStateList(this, R.color.surface_alt)
        val inactiveTint = ContextCompat.getColorStateList(this, R.color.background)
        binding.tabOrders.backgroundTintList = if (deposits) inactiveTint else activeTint
        binding.tabOrders.setTextColor(ContextCompat.getColor(this, if (deposits) R.color.text_secondary else R.color.text_primary))
        binding.tabDeposits.backgroundTintList = if (deposits) activeTint else inactiveTint
        binding.tabDeposits.setTextColor(ContextCompat.getColor(this, if (deposits) R.color.text_primary else R.color.text_secondary))

        binding.summaryCard.visibility = if (deposits) View.GONE else View.VISIBLE
        binding.btnPickDate.visibility = if (deposits) View.GONE else View.VISIBLE
        binding.emptyState.text = getString(if (deposits) R.string.deposit_history_empty else R.string.statement_empty)

        if (deposits) {
            binding.contentList.adapter = depositsAdapter
            if (depositsLoadedOnce) {
                binding.emptyState.visibility = if (depositsAdapter.itemCount == 0) View.VISIBLE else View.GONE
            } else {
                loadDepositHistory()
            }
        } else {
            binding.contentList.adapter = ordersAdapter
            binding.emptyState.visibility = if (ordersAdapter.itemCount == 0) View.VISIBLE else View.GONE
        }
    }

    private fun loadDepositHistory() {
        if (isFinishing) return
        binding.swipeRefresh.isRefreshing = true
        lifecycleScope.launch {
            try {
                val response = api.getRiderDepositHistory()
                val result = response.body()?.data

                if (response.isSuccessful && result != null) {
                    depositsLoadedOnce = true
                    depositsAdapter.submit(result.deposits)
                    if (showingDeposits) {
                        binding.emptyState.visibility = if (result.deposits.isEmpty()) View.VISIBLE else View.GONE
                    }
                } else {
                    InAppNotifier.show(this@StatementActivity, getString(R.string.deposit_history_load_error), InAppNotifier.Type.ERROR)
                }
            } catch (e: Exception) {
                InAppNotifier.show(this@StatementActivity, getString(R.string.deposit_history_load_error), InAppNotifier.Type.ERROR)
            } finally {
                binding.swipeRefresh.isRefreshing = false
            }
        }
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
                val response = api.getRiderStatement(date = selectedDate)
                val result = response.body()?.data

                if (response.isSuccessful && result != null) {
                    binding.statOrdersDelivered.text = result.summary.ordersDelivered.toString()
                    binding.statEarnings.text = "\u20b9${"%.0f".format(result.summary.totalEarnings)}"
                    binding.statCodCollected.text = "\u20b9${"%.0f".format(result.summary.codCollected)}"
                    binding.statCashHeldNow.text = "\u20b9${"%.0f".format(result.summary.codCashHeldNow)}"

                    ordersAdapter.submit(result.orders)
                    if (!showingDeposits) {
                        binding.emptyState.visibility = if (result.orders.isEmpty()) View.VISIBLE else View.GONE
                    }
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
