package com.anydrop.rider.ui.pending

import android.content.Intent
import android.content.res.ColorStateList
import android.os.Bundle
import android.view.View
import androidx.appcompat.app.AppCompatActivity
import com.anydrop.rider.R
import com.anydrop.rider.data.TokenManager
import com.anydrop.rider.databinding.ActivityApplicationStatusBinding
import com.anydrop.rider.ui.common.InAppNotifier
import com.anydrop.rider.ui.login.LoginActivity

/**
 * Whole-app landing screen right now — Phase 2's real rider dashboard
 * doesn't exist yet, so every authenticated rider (regardless of
 * status) ends up here. Reads status straight from TokenManager rather
 * than a live "who am I" call, since none exists yet (flagged in the
 * handover as a good next-session addition — closest live equivalent
 * today is re-running rider-verify-otp.php's login path, which needs a
 * fresh OTP, not just a token check).
 *
 * "Refresh Status" therefore logs the rider out and sends them back
 * through email-OTP login — verifying again *is* the refresh, since
 * rider-verify-otp.php's login branch always returns the rider's
 * current status. A dedicated rider-me.php would make this instant
 * instead of requiring a fresh OTP; that's next-session backend work,
 * not something this screen can fix on its own.
 */
class ApplicationStatusActivity : AppCompatActivity() {

    private lateinit var binding: ActivityApplicationStatusBinding
    private lateinit var tokenManager: TokenManager

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityApplicationStatusBinding.inflate(layoutInflater)
        setContentView(binding.root)

        tokenManager = TokenManager(this)

        if (!tokenManager.isLoggedIn()) {
            goToLogin()
            return
        }

        renderStatus(tokenManager.getStatus())

        binding.btnRefreshStatus.setOnClickListener { onRefreshClicked() }
        binding.btnLogout.setOnClickListener { onLogoutClicked() }
    }

    private fun renderStatus(status: String?) {
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

        val reason = tokenManager.getRejectionReason()
        if ((status == "rejected" || status == "suspended") && !reason.isNullOrBlank()) {
            binding.statusReason.text = getString(R.string.status_reason_format, reason)
            binding.statusReason.visibility = View.VISIBLE
        } else {
            binding.statusReason.visibility = View.GONE
        }
    }

    private fun onRefreshClicked() {
        InAppNotifier.show(this, getString(R.string.status_refresh_hint), InAppNotifier.Type.INFO)
        tokenManager.clear()
        goToLogin()
    }

    private fun onLogoutClicked() {
        tokenManager.clear()
        goToLogin()
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
