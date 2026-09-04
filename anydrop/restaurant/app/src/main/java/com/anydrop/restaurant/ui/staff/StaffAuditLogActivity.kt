package com.anydrop.restaurant.ui.staff

import android.os.Bundle
import android.view.View
import androidx.appcompat.app.AppCompatActivity
import androidx.lifecycle.lifecycleScope
import androidx.recyclerview.widget.LinearLayoutManager
import com.anydrop.restaurant.R
import com.anydrop.restaurant.data.TokenManager
import com.anydrop.restaurant.databinding.ActivityNotificationListBinding
import com.anydrop.restaurant.network.ApiClient
import com.anydrop.restaurant.ui.common.InAppNotifier
import kotlinx.coroutines.launch

/**
 * Owner-only Staff Audit Trail screen (migration 64, PENDING.md §7's
 * last remaining checkbox — the "doc's own still-open" item from doc
 * 71/72). Reuses activity_notification_list.xml as its shell, same
 * pattern as StaffManagementActivity/ClosureScheduleActivity —
 * screenTitle fixed, but btnAction stays hidden (same as
 * ReviewListActivity's own pattern for a screen with no "add" action)
 * since this is a read-only history, not something an owner mutates
 * from here.
 *
 * Launch is guarded by tokenManager.canManageStaff(), same
 * client-side-convenience check StaffManagementActivity itself uses —
 * the backend's require_restaurant_permission('manage_staff') 403 is
 * the actual enforcement regardless.
 */
class StaffAuditLogActivity : AppCompatActivity() {

    private lateinit var binding: ActivityNotificationListBinding
    private val api by lazy { ApiClient.create(this) }
    private lateinit var tokenManager: TokenManager
    private lateinit var adapter: StaffAuditLogAdapter

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityNotificationListBinding.inflate(layoutInflater)
        setContentView(binding.root)

        tokenManager = TokenManager(this)
        if (!tokenManager.canManageStaff()) {
            // Same "shouldn't realistically be reachable, but don't
            // show a screen a non-owner can't actually load anyway"
            // guard as StaffManagementActivity.
            finish()
            return
        }

        binding.screenTitle.text = getString(R.string.staff_audit_log_title)
        binding.btnBack.setOnClickListener { finish() }
        binding.btnAction.visibility = View.GONE

        adapter = StaffAuditLogAdapter()
        binding.contentList.layoutManager = LinearLayoutManager(this)
        binding.contentList.adapter = adapter

        binding.emptyStateText.text = getString(R.string.empty_staff_audit_log)
        binding.swipeRefresh.setOnRefreshListener { loadAuditLog() }

        loadAuditLog()
    }

    private fun loadAuditLog() {
        binding.swipeRefresh.isRefreshing = true
        lifecycleScope.launch {
            try {
                val response = api.listStaffAuditLog()
                val entries = response.body()?.data?.entries.orEmpty()
                if (response.isSuccessful) {
                    adapter.submit(entries)
                    binding.emptyState.visibility = if (entries.isEmpty()) View.VISIBLE else View.GONE
                    binding.contentList.visibility = if (entries.isEmpty()) View.GONE else View.VISIBLE
                } else {
                    InAppNotifier.show(this@StaffAuditLogActivity, getString(R.string.staff_audit_log_load_failed), InAppNotifier.Type.ERROR)
                }
            } catch (e: Exception) {
                InAppNotifier.show(this@StaffAuditLogActivity, getString(R.string.staff_audit_log_load_failed), InAppNotifier.Type.ERROR)
            } finally {
                binding.swipeRefresh.isRefreshing = false
            }
        }
    }
}
