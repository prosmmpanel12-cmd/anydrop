package com.anydrop.rider.ui.pending

import android.content.Intent
import android.content.res.ColorStateList
import android.os.Bundle
import android.view.View
import androidx.appcompat.app.AppCompatActivity
import androidx.lifecycle.lifecycleScope
import com.anydrop.rider.R
import com.anydrop.rider.data.TokenManager
import com.anydrop.rider.databinding.ActivityApplicationStatusBinding
import com.anydrop.rider.network.ApiClient
import com.anydrop.rider.network.parseApiError
import com.anydrop.rider.ui.common.InAppNotifier
import com.anydrop.rider.ui.dashboard.RiderDashboardActivity
import com.anydrop.rider.ui.login.LoginActivity
import kotlinx.coroutines.launch

/**
 * Landing screen for pending/rejected/suspended riders. Every entry
 * point in the app (Splash, post-signup, post-login) still routes here
 * first — rather than teach all four of those call sites the
 * approved-vs-not branch, this screen is the single choke point:
 * `status == "approved"` redirects straight to RiderDashboardActivity
 * (Phase 3, doc 83) before any UI here is even shown. Non-approved
 * statuses render normally below, unchanged from before Phase 3.
 *
 * "Refresh Status" calls GET /api/v1/rider/me instead of forcing a
 * full logout + OTP re-login. The endpoint re-checks the riders row
 * live (require_auth already does a status re-check on every call) and
 * returns current status + rejection_reason. On success, TokenManager
 * is updated in-place and the UI re-renders — no session disruption.
 * If the refresh reveals the rider just got approved, redirect to the
 * dashboard the same way onCreate does.
 *
 * Fallback: if /rider/me returns 403 account_suspended the rider IS
 * suspended (a new suspension since they logged in) — clear session and
 * send to login. Any other network error shows a toast and leaves the
 * rider where they are (don't disrupt the session for a transient fail).
 */
class ApplicationStatusActivity : AppCompatActivity() {

    private lateinit var binding: ActivityApplicationStatusBinding
    private lateinit var tokenManager: TokenManager
    private val api by lazy { ApiClient.create(this) }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityApplicationStatusBinding.inflate(layoutInflater)
        setContentView(binding.root)

        tokenManager = TokenManager(this)

        if (!tokenManager.isLoggedIn()) {
            goToLogin()
            return
        }

        if (tokenManager.getStatus() == "approved") {
            goToDashboard()
            return
        }

        renderStatus(tokenManager.getStatus(), tokenManager.getRejectionReason())

        binding.btnRefreshStatus.setOnClickListener { onRefreshClicked() }
        binding.btnLogout.setOnClickListener { onLogoutClicked() }
    }

    private fun renderStatus(status: String?, rejectionReason: String?) {
        val (pillBg, pillFg, title, body, icon) = when (status) {
            "approved" -> StatusPresentation(
                R.color.status_approved_bg, R.color.status_approved_fg,
                getString(R.string.status_approved_title), getString(R.string.status_approved_body),
                R.drawable.ic_check_circle
            )
            "rejected" -> StatusPresentation(
                R.color.status_rejected_bg, R.color.status_rejected_fg,
                getString(R.string.status_rejected_title), getString(R.string.status_rejected_body),
                R.drawable.ic_error
            )
            "suspended" -> StatusPresentation(
                R.color.status_suspended_bg, R.color.status_suspended_fg,
                getString(R.string.status_suspended_title), getString(R.string.status_suspended_body),
                R.drawable.ic_warning
            )
            else -> StatusPresentation(
                R.color.status_pending_bg, R.color.status_pending_fg,
                getString(R.string.status_pending_title), getString(R.string.status_pending_body),
                R.drawable.ic_info
            )
        }

        binding.statusPill.text = (status ?: "pending").uppercase()
        binding.statusPill.setTextColor(getColor(pillFg))
        binding.statusPill.backgroundTintList = ColorStateList.valueOf(getColor(pillBg))
        binding.statusIcon.setImageResource(icon)
        binding.statusIcon.setColorFilter(getColor(pillFg))
        binding.statusTitle.text = title
        binding.statusBody.text = body

        if ((status == "rejected" || status == "suspended") && !rejectionReason.isNullOrBlank()) {
            binding.statusReason.text = getString(R.string.status_reason_format, rejectionReason)
            binding.statusReason.visibility = View.VISIBLE
        } else {
            binding.statusReason.visibility = View.GONE
        }
    }

    private fun onRefreshClicked() {
        setRefreshLoading(true)
        lifecycleScope.launch {
            try {
                val response = api.getMe()
                if (response.isSuccessful && response.body()?.success == true) {
                    val result = response.body()?.data ?: run {
                        setRefreshLoading(false)
                        InAppNotifier.show(this@ApplicationStatusActivity, getString(R.string.error_network), InAppNotifier.Type.ERROR)
                        return@launch
                    }
                    // Update cached status + reason in TokenManager so the
                    // next cold start also gets the fresh values.
                    tokenManager.updateStatus(result.status, result.rider.rejectionReason)
                    tokenManager.setIsOnline(result.rider.isOnline)
                    setRefreshLoading(false)
                    if (result.status == "approved") {
                        InAppNotifier.show(this@ApplicationStatusActivity, getString(R.string.status_refreshed), InAppNotifier.Type.SUCCESS)
                        goToDashboard()
                        return@launch
                    }
                    renderStatus(result.status, result.rider.rejectionReason)
                    InAppNotifier.show(this@ApplicationStatusActivity, getString(R.string.status_refreshed), InAppNotifier.Type.SUCCESS)
                } else {
                    val parsed = parseApiError(response.errorBody())
                    setRefreshLoading(false)
                    if (parsed.code == "account_suspended") {
                        // Account was suspended since last login — end session
                        tokenManager.clear()
                        goToLogin()
                    } else {
                        InAppNotifier.show(this@ApplicationStatusActivity, getString(R.string.error_network), InAppNotifier.Type.ERROR)
                    }
                }
            } catch (e: Exception) {
                setRefreshLoading(false)
                InAppNotifier.show(this@ApplicationStatusActivity, getString(R.string.error_network), InAppNotifier.Type.ERROR)
            }
        }
    }

    private fun onLogoutClicked() {
        tokenManager.clear()
        goToLogin()
    }

    private fun setRefreshLoading(loading: Boolean) {
        binding.btnRefreshStatus.isEnabled = !loading
        // Reuse the existing progress bar if the layout has one, otherwise
        // just disable the button — the layout already has statusProgress.
        val progressId = binding.root.resources.getIdentifier("statusProgress", "id", packageName)
        if (progressId != 0) {
            binding.root.findViewById<View>(progressId)?.visibility =
                if (loading) View.VISIBLE else View.GONE
        }
    }

    private fun goToDashboard() {
        val intent = Intent(this, RiderDashboardActivity::class.java).apply {
            flags = Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TASK
        }
        startActivity(intent)
        finish()
    }

    private fun goToLogin() {
        val intent = Intent(this, LoginActivity::class.java).apply {
            flags = Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TASK
        }
        startActivity(intent)
        finish()
    }

    private data class StatusPresentation(
        val pillBg: Int,
        val pillFg: Int,
        val title: String,
        val body: String,
        val icon: Int
    )
}
