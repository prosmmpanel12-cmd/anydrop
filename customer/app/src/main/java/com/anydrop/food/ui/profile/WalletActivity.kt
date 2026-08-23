package com.anydrop.food.ui.profile

import android.os.Bundle
import android.view.View
import androidx.appcompat.app.AppCompatActivity
import androidx.lifecycle.lifecycleScope
import androidx.recyclerview.widget.LinearLayoutManager
import com.anydrop.food.R
import com.anydrop.food.databinding.ActivityWalletBinding
import com.anydrop.food.network.ApiClient
import com.anydrop.food.ui.common.InAppNotifier
import kotlinx.coroutines.launch

/**
 * Profile → Wallet (item 26 §D.15). Read-only — balance + transaction
 * history via GET /customer/wallet, no write actions (no
 * customer-initiated top-up in v1, matches wallet.php's own kdoc).
 *
 * No pagination — the backend endpoint itself caps at 50 most-recent
 * rows and doesn't accept a page param yet (see wallet.php's kdoc:
 * "add ?page= pagination later if a customer's history ever
 * realistically exceeds that"), so this screen has nothing further to
 * request even with infinite scroll wired up. Revisit if/when the
 * backend adds pagination.
 */
class WalletActivity : AppCompatActivity() {

    private lateinit var binding: ActivityWalletBinding
    private val api by lazy { ApiClient.create(this) }
    private lateinit var adapter: WalletTransactionAdapter

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityWalletBinding.inflate(layoutInflater)
        setContentView(binding.root)

        binding.btnBack.setOnClickListener { finish() }

        adapter = WalletTransactionAdapter()
        binding.contentList.layoutManager = LinearLayoutManager(this)
        binding.contentList.adapter = adapter

        binding.swipeRefresh.setOnRefreshListener { loadWallet() }

        loadWallet()
    }

    private fun loadWallet() {
        binding.swipeRefresh.isRefreshing = true
        lifecycleScope.launch {
            try {
                val result = api.getWallet().body()?.data
                val balance = result?.balance ?: 0.0
                val transactions = result?.transactions ?: emptyList()

                binding.walletBalanceText.text = "₹${"%.2f".format(balance)}"
                adapter.submit(transactions)
                binding.emptyState.visibility = if (transactions.isEmpty()) View.VISIBLE else View.GONE
                binding.contentList.visibility = if (transactions.isEmpty()) View.GONE else View.VISIBLE
            } catch (e: Exception) {
                InAppNotifier.show(this@WalletActivity, getString(R.string.wallet_load_failed), InAppNotifier.Type.ERROR)
            } finally {
                binding.swipeRefresh.isRefreshing = false
            }
        }
    }
}
