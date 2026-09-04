package com.anydrop.food.ui.profile

import android.os.Bundle
import androidx.appcompat.app.AppCompatActivity
import androidx.lifecycle.lifecycleScope
import androidx.recyclerview.widget.LinearLayoutManager
import com.anydrop.food.R
import com.anydrop.food.databinding.ActivitySimpleListBinding
import com.anydrop.food.network.ApiClient
import com.anydrop.food.ui.common.InAppNotifier
import kotlinx.coroutines.launch

class FaqsActivity : AppCompatActivity() {

    private lateinit var binding: ActivitySimpleListBinding
    private val api by lazy { ApiClient.create(this) }
    private lateinit var adapter: FaqAdapter

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivitySimpleListBinding.inflate(layoutInflater)
        setContentView(binding.root)

        binding.screenTitle.text = getString(R.string.faqs_title)
        binding.btnBack.setOnClickListener { finish() }
        binding.btnAction.visibility = android.view.View.GONE
        binding.emptyStateText.text = getString(R.string.empty_faqs)

        adapter = FaqAdapter()
        binding.contentList.layoutManager = LinearLayoutManager(this)
        binding.contentList.adapter = adapter

        binding.swipeRefresh.setOnRefreshListener { loadFaqs() }
        loadFaqs()
    }

    private fun loadFaqs() {
        binding.swipeRefresh.isRefreshing = true
        lifecycleScope.launch {
            try {
                val faqs = api.getFaqs().body()?.data?.faqs ?: emptyList()
                adapter.submit(faqs)
                binding.emptyState.visibility = if (faqs.isEmpty()) android.view.View.VISIBLE else android.view.View.GONE
                binding.contentList.visibility = if (faqs.isEmpty()) android.view.View.GONE else android.view.View.VISIBLE
            } catch (e: Exception) {
                InAppNotifier.show(this@FaqsActivity, "Couldn't load FAQs", InAppNotifier.Type.ERROR)
            } finally {
                binding.swipeRefresh.isRefreshing = false
            }
        }
    }
}
