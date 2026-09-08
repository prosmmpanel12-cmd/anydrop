package com.anydrop.rider.ui.main

import android.Manifest
import android.content.Intent
import android.content.pm.PackageManager
import android.os.Build
import android.os.Bundle
import android.view.View
import androidx.activity.result.contract.ActivityResultContracts
import androidx.appcompat.app.AppCompatActivity
import androidx.core.content.ContextCompat
import androidx.fragment.app.Fragment
import androidx.lifecycle.lifecycleScope
import com.anydrop.rider.R
import com.anydrop.rider.data.TokenManager
import com.anydrop.rider.databinding.ActivityRiderMainBinding
import com.anydrop.rider.network.ApiClient
import com.anydrop.rider.notifications.NotificationsFragment
import com.anydrop.rider.ui.account.AccountFragment
import com.anydrop.rider.ui.documents.SubmitDocumentsActivity
import com.anydrop.rider.ui.earnings.EarningsFragment
import com.anydrop.rider.ui.home.HomeFragment
import com.anydrop.rider.ui.pending.ApplicationStatusActivity
import kotlinx.coroutines.launch

/**
 * Bottom-nav shell (v24 Part 2 — "alag alag screens jaise restaurant
 * app", owner request 06 Sep 2026). Replaces RiderDashboardActivity as
 * the approved-rider landing screen — reached exactly the same way
 * RiderDashboardActivity used to be (only via
 * ApplicationStatusActivity's redirect; never launched directly by
 * Splash/Login/Signup themselves). Four always-visible tabs, same
 * "top bar + FragmentContainerView + BottomNavigationView" shape as the
 * restaurant app's own MainActivity:
 *
 * - **Home** — HomeFragment, a straight port of RiderDashboardActivity's
 *   body (online toggle, offer/current-delivery card, pickup/deliver
 *   actions). See that class's own kdoc for what changed in the port.
 * - **Earnings** — EarningsFragment, ported from the standalone
 *   EarningsActivity (which stays around as a class but is no longer
 *   reachable from anywhere in the app — see EarningsFragment's kdoc;
 *   left in place rather than deleted since RequestPayoutActivity and
 *   the manifest entry still reference nothing that would break by its
 *   presence, and removing dead code is out of scope for this pass).
 * - **Alerts** — NotificationsFragment, ported from
 *   NotificationListActivity (same "left in place, no longer reachable"
 *   note as EarningsActivity above).
 * - **Account** — AccountFragment, a new screen (this app had no
 *   Account/Profile hub before this session).
 *
 * Detail screens stay separate Activities reached by navigation from
 * within a tab's Fragment, same split the restaurant app itself uses:
 * RiderOrderDetailActivity (from Home), RequestPayoutActivity (from
 * Earnings), SubmitDocumentsActivity (from Account, or the header
 * alert below).
 *
 * Shared top bar: rider's name + the documents-rejected alert +
 * notification-bell-with-badge, all ported unchanged from
 * RiderDashboardActivity's old header row. These three elements became
 * shell-level (not per-tab) for the same reason the restaurant app's
 * OPEN/CLOSED pill lives in its MainActivity rather than inside
 * OrdersFragment: they need to stay visible and correct regardless of
 * which tab is currently showing, and MainActivity is the one place
 * shared across all four tabs. (The restaurant app's pill is genuinely
 * cross-tab-relevant in a way an on/off switch bound to one specific
 * screen's own state isn't — this app's online/offline switch itself
 * stays inside HomeFragment, not promoted here, since toggling it is a
 * Home-specific action a rider takes while looking at their delivery
 * queue, not a global status bar.)
 *
 * Deep-linking (notifications, tapping the bell) lands on this
 * Activity's Home tab by default, or the Earnings tab for an
 * "earnings"-scoped notification — see goToHomeTab()/goToEarningsTab(),
 * called from NotificationsFragment.onNotificationClick() and
 * HomeFragment's earnings-card click respectively, instead of the old
 * "start a new Activity" navigation those call sites used to do.
 */
class RiderMainActivity : AppCompatActivity() {

    private lateinit var binding: ActivityRiderMainBinding
    private lateinit var tokenManager: TokenManager
    private val api by lazy { ApiClient.create(this) }

    // POST_NOTIFICATIONS runtime permission (API 33+) — same "ask once
    // right when the landing screen is first reached, either way nothing
    // else here depends on the result" pattern
    // RiderDashboardActivity.notificationPermissionLauncher used, ported
    // as-is since the request timing (this Activity's onCreate) is
    // unchanged.
    private val notificationPermissionLauncher =
        registerForActivityResult(ActivityResultContracts.RequestPermission()) { /* no-op either way */ }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityRiderMainBinding.inflate(layoutInflater)
        setContentView(binding.root)

        tokenManager = TokenManager(this)
        if (!tokenManager.isLoggedIn()) {
            goToLogin()
            return
        }

        requestNotificationPermissionIfNeeded()

        binding.mainGreetingText.text = getString(
            R.string.dashboard_greeting_format,
            tokenManager.getRiderName() ?: ""
        )

        binding.btnDocumentsAlert.setOnClickListener {
            startActivity(Intent(this, SubmitDocumentsActivity::class.java))
        }
        renderDocumentsEntryPoint()

        binding.btnNotifications.setOnClickListener {
            binding.bottomNav.selectedItemId = R.id.nav_notifications
        }

        binding.bottomNav.setOnItemSelectedListener { item ->
            val fragment = when (item.itemId) {
                R.id.nav_home -> HomeFragment()
                R.id.nav_earnings -> EarningsFragment()
                R.id.nav_notifications -> NotificationsFragment()
                R.id.nav_account -> AccountFragment()
                else -> return@setOnItemSelectedListener false
            }
            showFragment(fragment)
            true
        }
        // Logout lives inside AccountFragment itself (clears the token
        // and starts LoginActivity directly) rather than being passed in
        // as a constructor callback here — same reasoning the restaurant
        // app's own MainActivity kdoc gives: fragment constructor args
        // don't survive system-initiated recreation.

        if (savedInstanceState == null) {
            binding.bottomNav.selectedItemId = R.id.nav_home
        }

        updateNotificationBadge()
    }

    override fun onResume() {
        super.onResume()
        renderDocumentsEntryPoint()
        updateNotificationBadge()
    }

    /** Switches to the Home tab — used by deep-link routing (see class
     *  kdoc) instead of starting a new HomeFragment/RiderDashboardActivity
     *  instance, since Home already exists as a tab in this shell. */
    fun goToHomeTab() {
        binding.bottomNav.selectedItemId = R.id.nav_home
    }

    /** Switches to the Earnings tab — used by HomeFragment's TODAY
     *  earnings card tap and by "earnings"-scoped notification routing
     *  (see class kdoc), instead of starting a standalone
     *  EarningsActivity the way both call sites used to. */
    fun goToEarningsTab() {
        binding.bottomNav.selectedItemId = R.id.nav_earnings
    }

    /** Called by HomeFragment.refreshFromServer() when /rider/me reports
     *  a non-approved status (e.g. suspended since login) — same
     *  redirect ApplicationStatusActivity/TokenManager already handle
     *  for every other non-approved case. */
    fun goToStatusScreen() {
        val intent = Intent(this, ApplicationStatusActivity::class.java).apply {
            flags = Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TASK
        }
        startActivity(intent)
        finish()
    }

    /** Called by HomeFragment on an account_suspended API error — same
     *  "clear + redirect" shape the old RiderDashboardActivity used. */
    fun goToLogin() {
        val intent = Intent(this, com.anydrop.rider.ui.login.LoginActivity::class.java).apply {
            flags = Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TASK
        }
        startActivity(intent)
        finish()
    }

    /** Ported unchanged from RiderDashboardActivity.renderDocumentsEntryPoint()
     *  — only shown when this approved rider's documents were rejected
     *  and need re-submission. Called from onResume (covers coming back
     *  from SubmitDocumentsActivity) and from HomeFragment/AccountFragment
     *  indirectly via TokenManager's cached value whenever /rider/me or
     *  documents-get.php refreshes it. */
    fun renderDocumentsEntryPoint() {
        binding.btnDocumentsAlert.visibility =
            if (tokenManager.getDocumentsStatus() == "rejected") View.VISIBLE else View.GONE
    }

    /** Ported unchanged from RiderDashboardActivity.updateNotificationBadge()
     *  — cheap unread-count refresh for the top-bar bell badge. */
    private fun updateNotificationBadge() {
        lifecycleScope.launch {
            try {
                val response = api.getNotifications(page = 1, perPage = 1, unreadOnly = "1")
                val result = if (response.isSuccessful) response.body()?.data else null
                val count = result?.unreadCount ?: 0
                if (count > 0) {
                    binding.notificationBadge.text = if (count > 99) "99+" else count.toString()
                    binding.notificationBadge.visibility = View.VISIBLE
                } else {
                    binding.notificationBadge.visibility = View.GONE
                }
            } catch (e: Exception) {
                // Silent — next onCreate/onResume retries.
            }
        }
    }

    private fun requestNotificationPermissionIfNeeded() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU &&
            ContextCompat.checkSelfPermission(this, Manifest.permission.POST_NOTIFICATIONS) != PackageManager.PERMISSION_GRANTED
        ) {
            notificationPermissionLauncher.launch(Manifest.permission.POST_NOTIFICATIONS)
        }
    }

    private fun showFragment(fragment: Fragment) {
        supportFragmentManager.beginTransaction()
            .replace(R.id.navHostContainer, fragment)
            .commit()
    }
}
