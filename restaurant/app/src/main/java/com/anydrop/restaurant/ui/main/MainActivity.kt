package com.anydrop.restaurant.ui.main

import android.Manifest
import android.app.AlertDialog
import android.content.Intent
import android.content.pm.PackageManager
import android.os.Build
import android.os.Bundle
import androidx.activity.result.contract.ActivityResultContracts
import androidx.appcompat.app.AppCompatActivity
import androidx.core.content.ContextCompat
import androidx.fragment.app.Fragment
import androidx.lifecycle.lifecycleScope
import com.anydrop.restaurant.R
import com.anydrop.restaurant.data.TokenManager
import com.anydrop.restaurant.databinding.ActivityMainBinding
import com.anydrop.restaurant.network.ApiClient
import com.anydrop.restaurant.network.OperationalStatusUpdateBody
import com.anydrop.restaurant.service.OrderPollingService
import com.anydrop.restaurant.ui.account.AccountFragment
import com.anydrop.restaurant.ui.common.InAppNotifier
import com.anydrop.restaurant.ui.insights.InsightsFragment
import com.anydrop.restaurant.ui.login.LoginActivity
import com.anydrop.restaurant.ui.menu.MenuFragment
import com.anydrop.restaurant.ui.orders.OrdersFragment
import kotlinx.coroutines.launch

/**
 * UI plan §3/§10 item 2 — the bottom-nav shell everything else in the
 * plan hangs off of. Four always-visible tabs after login: Orders / Menu
 * / Insights / Account.
 *
 * Replaces the old DashboardActivity (now OrdersFragment, tab 1) and
 * MenuManagementActivity (now MenuFragment, tab 2) as the post-login
 * entry point — see SplashActivity/LoginActivity, which now route here.
 *
 * Deliberately simple for this pass: switching tabs uses a plain
 * fragment replace(), so each tab reloads its data on every switch
 * rather than retaining state in the background. That's an acceptable
 * cost for a 4-tab merchant app and keeps this step small; revisit with
 * add()/hide()/show() + per-tab ViewModels later if switching feels
 * slow once Insights/Account have real data to fetch too.
 *
 * Orders tab redesign (§10 item 3): the OPEN/CLOSED pill (§3: "stays
 * pinned in a top bar above the bottom nav on every tab") now lives
 * here, since MainActivity is the one place shared across all four
 * tabs. It replaces the small SwitchMaterial that used to live inside
 * OrdersFragment's own top section. OrdersFragment no longer owns any
 * operational-status UI or logic.
 */
class MainActivity : AppCompatActivity() {

    private lateinit var binding: ActivityMainBinding
    private lateinit var tokenManager: TokenManager
    private val api by lazy { ApiClient.create(this) }

    // Tracks the pill's confirmed (server-acknowledged) state so a
    // failed toggle call can be reverted without a second network
    // round trip, same revert-on-failure pattern OrdersFragment used
    // to use for its switch.
    private var isOpen = true
    private var togglingInFlight = false

    // "Sound doesn't work once the app is closed" fix — POST_NOTIFICATIONS
    // is a runtime permission on Android 13+ (API 33); without it,
    // OrderPollingService's alerts never show at all, silently, no error
    // anywhere. Requested once right after login/launch, alongside
    // starting the service itself.
    private val notificationPermissionLauncher = registerForActivityResult(
        ActivityResultContracts.RequestPermission()
    ) { /* Either way, OrderPollingService itself still runs — it just won't
          be able to show the alert notification if this was denied. Not
          re-prompting on denial; Account tab is the natural place to
          explain/re-request later if that becomes worth building. */ }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityMainBinding.inflate(layoutInflater)
        setContentView(binding.root)

        tokenManager = TokenManager(this)
        if (!tokenManager.isLoggedIn()) {
            goToLogin()
            return
        }

        startOrderPollingService()

        binding.restaurantNameText.text = tokenManager.getRestaurantName().orEmpty()

        binding.bottomNav.setOnItemSelectedListener { item ->
            val fragment = when (item.itemId) {
                R.id.nav_orders -> OrdersFragment()
                R.id.nav_menu -> MenuFragment()
                R.id.nav_insights -> InsightsFragment()
                R.id.nav_account -> AccountFragment()
                else -> return@setOnItemSelectedListener false
            }
            showFragment(fragment)
            true
        }
        // Logout lives inside AccountFragment itself (it clears the token
        // and starts LoginActivity directly) rather than being passed in
        // as a constructor callback here — fragment constructor args don't
        // survive system-initiated recreation, so this keeps that path safe.

        binding.openClosedPill.setOnClickListener { onPillTapped() }

        if (savedInstanceState == null) {
            binding.bottomNav.selectedItemId = R.id.nav_orders
        }

        loadOperationalStatus()
    }

    override fun onResume() {
        super.onResume()
        // Covers e.g. coming back from the (not-yet-built) profile screen —
        // cheap enough to just re-fetch rather than pass a result back.
        loadOperationalStatus()
    }

    private fun startOrderPollingService() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU &&
            ContextCompat.checkSelfPermission(this, Manifest.permission.POST_NOTIFICATIONS) != PackageManager.PERMISSION_GRANTED
        ) {
            notificationPermissionLauncher.launch(Manifest.permission.POST_NOTIFICATIONS)
        }
        // Started regardless of whether the permission prompt above was
        // granted — the polling loop itself (and the persistent
        // low-priority "monitoring" notification, which pre-dates the
        // POST_NOTIFICATIONS requirement on some OS versions/OEM skins)
        // doesn't depend on it; only the new-order alert notification does.
        OrderPollingService.start(this)
    }

    private fun showFragment(fragment: Fragment) {
        supportFragmentManager.beginTransaction()
            .replace(R.id.navHostContainer, fragment)
            .commit()
    }

    private fun loadOperationalStatus() {
        lifecycleScope.launch {
            try {
                val summary = api.getDashboard().body()?.data
                if (summary != null) {
                    isOpen = summary.operationalStatus == "open"
                    renderPill()
                }
            } catch (e: Exception) {
                // Non-critical — leave the pill showing its last known state.
            }
        }
    }

    private fun onPillTapped() {
        if (togglingInFlight) return
        if (isOpen) {
            // Tap-to-confirm before closing (§4: "tap to confirm before
            // closing"), same AlertDialog.Builder pattern MenuFragment
            // uses for its own confirmation dialogs.
            AlertDialog.Builder(this)
                .setTitle(R.string.dialog_close_restaurant_title)
                .setMessage(R.string.dialog_close_restaurant_message)
                .setPositiveButton(R.string.btn_confirm_close) { _, _ -> setOperationalStatus(false) }
                .setNegativeButton(R.string.btn_cancel, null)
                .show()
        } else {
            // Re-opening needs no confirmation — only pausing does.
            setOperationalStatus(true)
        }
    }

    private fun setOperationalStatus(turningOn: Boolean) {
        togglingInFlight = true
        val newStatus = if (turningOn) "open" else "busy"
        lifecycleScope.launch {
            try {
                val response = api.updateOperationalStatus(OperationalStatusUpdateBody(newStatus))
                if (response.isSuccessful && response.body()?.data != null) {
                    isOpen = turningOn
                } else {
                    InAppNotifier.show(this@MainActivity, getString(R.string.status_update_failed), InAppNotifier.Type.ERROR)
                }
            } catch (e: Exception) {
                InAppNotifier.show(this@MainActivity, getString(R.string.status_update_failed), InAppNotifier.Type.ERROR)
            } finally {
                togglingInFlight = false
                renderPill()
            }
        }
    }

    private fun renderPill() {
        if (isOpen) {
            binding.openClosedPill.background = ContextCompat.getDrawable(this, R.drawable.bg_pill_open)
            binding.openClosedDot.background = ContextCompat.getDrawable(this, R.drawable.bg_dot_green)
            binding.openClosedText.text = getString(R.string.restaurant_open_label)
            binding.openClosedText.setTextColor(ContextCompat.getColor(this, R.color.veg_green))
        } else {
            binding.openClosedPill.background = ContextCompat.getDrawable(this, R.drawable.bg_pill_closed)
            binding.openClosedDot.background = ContextCompat.getDrawable(this, R.drawable.bg_dot_red)
            binding.openClosedText.text = getString(R.string.restaurant_closed_label)
            binding.openClosedText.setTextColor(ContextCompat.getColor(this, R.color.nonveg_red))
        }
    }

    private fun goToLogin() {
        startActivity(
            Intent(this, LoginActivity::class.java)
                .addFlags(Intent.FLAG_ACTIVITY_CLEAR_TOP or Intent.FLAG_ACTIVITY_NEW_TASK)
        )
        finish()
    }
}
