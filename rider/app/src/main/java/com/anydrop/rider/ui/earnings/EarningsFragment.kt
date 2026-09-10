package com.anydrop.rider.ui.earnings

import android.content.res.ColorStateList
import android.os.Bundle
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import androidx.fragment.app.Fragment
import androidx.lifecycle.lifecycleScope
import com.anydrop.rider.R
import com.anydrop.rider.databinding.FragmentEarningsBinding
import com.anydrop.rider.network.ApiClient
import com.anydrop.rider.network.EarningsSummaryResult
import kotlinx.coroutines.launch

/**
 * Bottom-nav shell (v24 Part 2) — Earnings tab. Ported from
 * EarningsActivity (see that class's own kdoc for the full feature
 * history: deep-plan §17-21, doc 90/95). Logic unchanged; only the
 * Activity scaffolding changed — no more btnBack (a tab has no back
 * target), `binding` is the nullable Fragment pair, `this` (Activity)
 * → `requireContext()` where a Context was needed. Still reachable
 * indirectly via HomeFragment's TODAY earnings card
 * (RiderMainActivity.goToEarningsTab() switches to this tab instead of
 * starting a new Activity — see that method's kdoc).
 */
class EarningsFragment : Fragment() {

    companion object {
        private const val COD_WARNING_RATIO = 0.8
    }

    private var _binding: FragmentEarningsBinding? = null
    private val binding get() = _binding!!

    private val api by lazy { ApiClient.create(requireContext()) }
    private val adapter = EarningsLedgerAdapter()

    // Deep Plan Phase 5 — last-known cod_cash_held, kept only so
    // btnPayCodAmount's click handler has a starting value to hand
    // PayCodDepositActivity; that screen re-fetches/re-validates the
    // real number server-side before doing anything with it (see its
    // own kdoc), so a value that's gone stale between refreshes here
    // is harmless.
    private var latestCodCashHeld: Double = 0.0

    override fun onCreateView(
        inflater: LayoutInflater,
        container: ViewGroup?,
        savedInstanceState: Bundle?
    ): View {
        _binding = FragmentEarningsBinding.inflate(inflater, container, false)
        return binding.root
    }

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        super.onViewCreated(view, savedInstanceState)

        binding.btnRequestPayout.setOnClickListener {
            startActivity(android.content.Intent(requireContext(), RequestPayoutActivity::class.java))
        }

        // Statement (Deep Plan Phase 3) — same one-line launch pattern
        // as btnRequestPayout above.
        binding.btnViewStatement.setOnClickListener {
            startActivity(android.content.Intent(requireContext(), com.anydrop.rider.ui.statement.StatementActivity::class.java))
        }

        // Deep Plan Phase 5 — "Pay COD Amount". codCashHeld is passed
        // as a display-only starting value for PayCodDepositActivity's
        // own amount field; that screen re-validates against the
        // server's own current cod_cash_held before ever calling
        // initiateCodDeposit() (see its own kdoc) rather than trusting
        // this snapshot, same reasoning RequestPayoutActivity's kdoc
        // gives for re-fetching balance instead of trusting a passed
        // value.
        binding.btnPayCodAmount.setOnClickListener {
            val intent = android.content.Intent(requireContext(), PayCodDepositActivity::class.java)
            intent.putExtra(PayCodDepositActivity.EXTRA_COD_CASH_HELD, latestCodCashHeld)
            startActivity(intent)
        }

        binding.earningsLedgerList.layoutManager =
            androidx.recyclerview.widget.LinearLayoutManager(requireContext())
        binding.earningsLedgerList.adapter = adapter

        binding.earningsSwipeRefresh.setOnRefreshListener { loadEarnings() }

        loadEarnings()
    }

    // Same "came back from a screen that might have changed something,
    // re-load" pattern the Activity version used in its own onResume —
    // a payout request debits earnings_balance immediately, so this
    // screen's balance figure would otherwise show a stale number until
    // the next manual pull-to-refresh or tab re-select.
    override fun onResume() {
        super.onResume()
        loadEarnings()
    }

    override fun onDestroyView() {
        super.onDestroyView()
        _binding = null
    }

    private fun loadEarnings() {
        lifecycleScope.launch {
            try {
                val response = api.getEarningsSummary()
                if (response.isSuccessful && response.body()?.success == true) {
                    val result = response.body()?.data
                    val b = _binding
                    if (result != null && b != null) {
                        b.earningsTodayValue.text =
                            getString(R.string.dashboard_earnings_amount_format, result.todayTotal)
                        b.earningsBalanceValue.text =
                            getString(R.string.dashboard_earnings_amount_format, result.balance)

                        if (result.sharePercent > 0) {
                            b.earningsShareNote.text =
                                getString(R.string.earnings_share_note_format, result.sharePercent)
                            b.earningsShareNote.visibility = View.VISIBLE
                        } else {
                            b.earningsShareNote.visibility = View.GONE
                        }

                        adapter.submit(result.recent)
                        b.earningsEmptyState.visibility =
                            if (result.recent.isEmpty()) View.VISIBLE else View.GONE
                        b.earningsLedgerList.visibility =
                            if (result.recent.isEmpty()) View.GONE else View.VISIBLE

                        renderCodCard(result)
                    }
                } else {
                    android.widget.Toast.makeText(
                        requireContext(), R.string.earnings_load_error, android.widget.Toast.LENGTH_SHORT
                    ).show()
                }
            } catch (e: Exception) {
                context?.let {
                    android.widget.Toast.makeText(it, R.string.earnings_load_error, android.widget.Toast.LENGTH_SHORT).show()
                }
            } finally {
                _binding?.earningsSwipeRefresh?.isRefreshing = false
            }
        }
    }

    private fun renderCodCard(result: EarningsSummaryResult) {
        val b = _binding ?: return
        val held = result.codCashHeld
        latestCodCashHeld = held
        val limit = if (result.codSettlementLimit > 0) result.codSettlementLimit else 1.0
        val ratio = (held / limit).coerceIn(0.0, 1.0)

        b.codCashHeldValue.text =
            getString(R.string.dashboard_earnings_amount_format, held)
        b.codCashHeldBar.progress = (ratio * 100).toInt()
        b.btnPayCodAmount.visibility = if (held > 0) View.VISIBLE else View.GONE

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

        val ctx = requireContext()
        b.codCashHeldPill.text = pillText
        b.codCashHeldPill.backgroundTintList = ColorStateList.valueOf(ctx.getColor(pillBg))
        b.codCashHeldPill.setTextColor(ctx.getColor(pillFg))
        b.codCashHeldBar.progressTintList = ColorStateList.valueOf(ctx.getColor(barTint))
        b.codCashHeldLimitNote.text = note
    }

    private data class Quintuple<A, B, C, D, E>(
        val first: A, val second: B, val third: C, val fourth: D, val fifth: E
    )
}
