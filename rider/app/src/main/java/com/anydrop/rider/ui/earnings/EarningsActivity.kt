package com.anydrop.rider.ui.earnings

import android.content.res.ColorStateList
import android.os.Bundle
import android.view.View
import androidx.appcompat.app.AppCompatActivity
import androidx.lifecycle.lifecycleScope
import com.anydrop.rider.R
import com.anydrop.rider.databinding.ActivityEarningsBinding
import com.anydrop.rider.network.ApiClient
import com.anydrop.rider.network.EarningsSummaryResult
import kotlinx.coroutines.launch

/**
 * Rider-facing earnings screen (deep-plan §19-20, migration 73).
 * Doc 90 built the backend (`earnings-summary.php`) and the Android
 * model (`EarningsSummaryResult`/`EarningsLedgerEntry`) but explicitly
 * flagged that nothing rendered the `recent` ledger array beyond the
 * dashboard's single today-total figure — this activity is that
 * flagged "natural next slice" (doc 90, "Still open" §2).
 *
 * Reached only by tapping the dashboard's TODAY earnings card
 * (RiderDashboardActivity wires the click) — never launched from
 * anywhere else, same "one entry point" shape ApplicationStatusActivity
 * already uses for its own screen.
 *
 * Scope, deliberately: read-only view of what `earnings-summary.php`
 * already returns (today total, running balance, share percent, last
 * 20 ledger rows). No pagination beyond those 20 rows — the endpoint
 * itself doesn't support it yet (see its own kdoc); a "load more" flow
 * is a later slice if the 20-row window turns out to be too short in
 * practice. No payout-request action here either — doc 90 explicitly
 * called that flow ("rider requests a payout") a separate unbuilt
 * piece (deep-plan §21), not part of this read-only ledger view.
 *
 * UPDATE (this session, deep-plan §21 built): the payout-request flow
 * called out above as separate/unbuilt now exists — see
 * RequestPayoutActivity, reached via the "Request Payout" button added
 * below the share-percent note. This activity itself stays read-only;
 * the button is a pure navigation launch, no request logic lives here.
 */
class EarningsActivity : AppCompatActivity() {

    companion object {
        // Deep-plan §17-18 — matches dispatch.php's own ">= limit"
        // block exactly at 100%; 80% is a client-only "heads up" zone,
        // not a server-enforced threshold, so it's fine to tune here
        // without a backend change.
        private const val COD_WARNING_RATIO = 0.8
    }

    private lateinit var binding: ActivityEarningsBinding
    private val api by lazy { ApiClient.create(this) }
    private val adapter = EarningsLedgerAdapter()

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityEarningsBinding.inflate(layoutInflater)
        setContentView(binding.root)

        binding.btnBack.setOnClickListener { finish() }
        binding.btnRequestPayout.setOnClickListener {
            startActivity(android.content.Intent(this, RequestPayoutActivity::class.java))
        }

        binding.earningsLedgerList.layoutManager =
            androidx.recyclerview.widget.LinearLayoutManager(this)
        binding.earningsLedgerList.adapter = adapter

        binding.earningsSwipeRefresh.setOnRefreshListener { loadEarnings() }

        loadEarnings()
    }

    // Same "came back from a screen that might have changed something,
    // re-load" pattern the customer app's WalletActivity.onResume()
    // already uses for its own Withdraw button — a payout request debits
    // earnings_balance immediately, so this screen's balance figure would
    // otherwise show a stale number until the next manual pull-to-refresh.
    override fun onResume() {
        super.onResume()
        loadEarnings()
    }

    private fun loadEarnings() {
        lifecycleScope.launch {
            try {
                val response = api.getEarningsSummary()
                if (response.isSuccessful && response.body()?.success == true) {
                    val result = response.body()?.data
                    if (result != null) {
                        binding.earningsTodayValue.text =
                            getString(R.string.dashboard_earnings_amount_format, result.todayTotal)
                        binding.earningsBalanceValue.text =
                            getString(R.string.dashboard_earnings_amount_format, result.balance)

                        // share_percent only shown when it's a normal positive
                        // rate — a 0 or missing value (e.g. a mid-rollout
                        // server without the setting configured yet) would
                        // read as a confusing "You earn 0% of each delivery
                        // fee" line, so it's hidden rather than shown wrong.
                        if (result.sharePercent > 0) {
                            binding.earningsShareNote.text =
                                getString(R.string.earnings_share_note_format, result.sharePercent)
                            binding.earningsShareNote.visibility = View.VISIBLE
                        } else {
                            binding.earningsShareNote.visibility = View.GONE
                        }

                        adapter.submit(result.recent)
                        binding.earningsEmptyState.visibility =
                            if (result.recent.isEmpty()) View.VISIBLE else View.GONE
                        binding.earningsLedgerList.visibility =
                            if (result.recent.isEmpty()) View.GONE else View.VISIBLE

                        renderCodCard(result)
                    }
                } else {
                    // Leave whatever was last rendered (or the initial
                    // placeholder on first load) — same "don't disrupt the
                    // screen for a transient failure" stance
                    // RiderDashboardActivity.refreshEarnings() already
                    // takes, plus a toast here since this screen's whole
                    // purpose is showing this data, unlike the dashboard
                    // card where it's a secondary element.
                    android.widget.Toast.makeText(
                        this@EarningsActivity, R.string.earnings_load_error, android.widget.Toast.LENGTH_SHORT
                    ).show()
                }
            } catch (e: Exception) {
                android.widget.Toast.makeText(
                    this@EarningsActivity, R.string.earnings_load_error, android.widget.Toast.LENGTH_SHORT
                ).show()
            } finally {
                binding.earningsSwipeRefresh.isRefreshing = false
            }
        }
    }

    /** Deep-plan §17-18 — the "cash to settle" card, kept as its own
     * render step (not inlined into loadEarnings()'s success branch)
     * since it has its own three-state color logic, distinct from the
     * plain-text today/balance figures above it. `limit` falls back to
     * a sane positive default (matches [EarningsSummaryResult]'s own
     * constructor default) so a divide-by-zero can't happen even if a
     * server response somehow sent 0 — a misconfigured limit should
     * read as "held vs 0" (100%+, blocked-looking), not crash the
     * screen.
     */
    private fun renderCodCard(result: EarningsSummaryResult) {
        val held = result.codCashHeld
        val limit = if (result.codSettlementLimit > 0) result.codSettlementLimit else 1.0
        val ratio = (held / limit).coerceIn(0.0, 1.0)

        binding.codCashHeldValue.text =
            getString(R.string.dashboard_earnings_amount_format, held)
        binding.codCashHeldBar.progress = (ratio * 100).toInt()

        val limitLabel = if (result.codSettlementLimit == result.codSettlementLimit.toLong().toDouble())
            result.codSettlementLimit.toLong().toString()
        else
            result.codSettlementLimit.toString()

        val (pillText, pillBg, pillFg, barTint, note) = when {
            held >= result.codSettlementLimit -> Quintuple(
                getString(R.string.cod_cash_held_pill_blocked),
                R.color.status_rejected_bg, R.color.status_rejected_fg, R.color.error_fg,
                getString(R.string.cod_cash_held_limit_note_blocked_format, limitLabel)
            )
            ratio >= COD_WARNING_RATIO -> Quintuple(
                getString(R.string.cod_cash_held_pill_warning),
                R.color.status_pending_bg, R.color.status_pending_fg, R.color.warning_fg,
                getString(R.string.cod_cash_held_limit_note_format, limitLabel)
            )
            else -> Quintuple(
                getString(R.string.cod_cash_held_pill_ok),
                R.color.status_approved_bg, R.color.status_approved_fg, R.color.status_approved_fg,
                getString(R.string.cod_cash_held_limit_note_format, limitLabel)
            )
        }

        binding.codCashHeldPill.text = pillText
        binding.codCashHeldPill.backgroundTintList = ColorStateList.valueOf(getColor(pillBg))
        binding.codCashHeldPill.setTextColor(getColor(pillFg))
        binding.codCashHeldBar.progressTintList = ColorStateList.valueOf(getColor(barTint))
        binding.codCashHeldLimitNote.text = note
    }

    // Small local stand-in for a 5-tuple — not worth pulling in a
    // library data type for one private helper's return value.
    private data class Quintuple<A, B, C, D, E>(
        val first: A, val second: B, val third: C, val fourth: D, val fifth: E
    )
}
