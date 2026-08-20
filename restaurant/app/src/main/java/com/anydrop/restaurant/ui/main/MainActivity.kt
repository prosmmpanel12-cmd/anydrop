package com.anydrop.restaurant.ui.main

import android.Manifest
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
import com.anydrop.restaurant.service.OrderNotificationHelper
import com.anydrop.restaurant.service.OrderPollingService
import com.google.android.material.dialog.MaterialAlertDialogBuilder
import com.anydrop.restaurant.ui.account.AccountFragment
import com.anydrop.restaurant.ui.common.InAppNotifier
import com.anydrop.restaurant.ui.insights.InsightsFragment
import com.anydrop.restaurant.ui.login.LoginActivity
import com.anydrop.restaurant.ui.menu.MenuFragment
import com.anydrop.restaurant.ui.notifications.NotificationListActivity
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

    // Toggle-standardization pass: statusSwitch.isChecked is set both
    // by us (renderPill, and reverting the switch back to "on" if the
    // close-confirmation dialog is cancelled) and indirectly by the
    // user tapping the switch. Without this flag, our own programmatic
    // sets would re-fire setOnCheckedChangeListener and loop / re-show
    // the confirmation dialog.
    private var suppressToggleListener = false

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

        binding.statusSwitch.setOnCheckedChangeListener { _, isChecked ->
            if (suppressToggleListener) return@setOnCheckedChangeListener
            onStatusSwitchToggled(isChecked)
        }

        // Notification bell (Type 1, docs/Status.md 2026-08-20) — mirrors
        // the Customer App's HomeActivity wiring of the same feature.
        binding.btnNotifications.setOnClickListener {
            startActivity(Intent(this, NotificationListActivity::class.java))
        }

        if (savedInstanceState == null) {
            binding.bottomNav.selectedItemId = R.id.nav_orders
        }

        loadOperationalStatus()
        updateNotificationBadge()
        promptFullScreenIntentIfNeeded()
    }

    override fun onResume() {
        super.onResume()
        // Covers e.g. coming back from the (not-yet-built) profile screen —
        // cheap enough to just re-fetch rather than pass a result back.
        loadOperationalStatus()
        updateNotificationBadge()
        // Multiple-new-orders alerts route here (Orders tab) rather than a
        // specific OrderDetailActivity — opening this screen counts as
        // "went in and did something" too, so stop the ringing loop the
        // same way OrderDetailActivity does.
        OrderNotificationHelper.stopRingingLoop(applicationContext)
    }

    /** Cheap unread-count refresh for the top-bar bell badge — no push
     * channel yet, so this is pull-only, same cadence/reasoning as
     * loadOperationalStatus() above: called from both onCreate and
     * onResume since unread state lives server-side. unread_only=1 +
     * per_page=1 keeps the call cheap: only unread_count from the
     * response envelope is used, not the single item it returns. Mirrors
     * the Customer App's HomeActivity.updateNotificationBadge(). */
    private fun updateNotificationBadge() {
        lifecycleScope.launch {
            try {
                val result = api.getNotifications(page = 1, perPage = 1, unreadOnly = "1").body()?.data
                val count = result?.unreadCount ?: 0
                if (count > 0) {
                    binding.notificationBadge.text = if (count > 99) "99+" else count.toString()
                    if (binding.notificationBadge.visibility != android.view.View.VISIBLE) {
                        binding.notificationBadge.visibility = android.view.View.VISIBLE
                        binding.notificationBadge.scaleX = 0f
                        binding.notificationBadge.scaleY = 0f
                        binding.notificationBadge.animate().scaleX(1f).scaleY(1f).setDuration(200).start()
                    }
                } else {
                    binding.notificationBadge.visibility = android.view.View.GONE
                }
            } catch (e: Exception) {
                // Silent — same "don't interrupt Home load over a badge
                // count" reasoning as loadOperationalStatus() above. Next
                // resume retries.
            }
        }
    }

    /** "Ringing screen never opens, only the plain notification shows" fix
     * (app-owner report, 2026-08-18) — on Android 14+ the OS silently
     * withholds full-screen-intent launches until the user flips a
     * dedicated toggle in Settings, regardless of the manifest permission.
     * There's no runtime permission dialog for this one, so the only way
     * to get the user there is an explanatory prompt + deep link, shown
     * once per app launch (like the POST_NOTIFICATIONS prompt above,
     * doesn't nag again this session if dismissed — Account tab is the
     * natural place to re-offer this later if needed). No-op below
     * Android 14, where the manifest permission alone is sufficient. */
    private fun promptFullScreenIntentIfNeeded() {
        if (OrderNotificationHelper.hasFullScreenIntentPermission(this)) return
        MaterialAlertDialogBuilder(this)
            .setTitle("Turn on full-screen order alerts")
            .setMessage(
                "So a new order rings and vibrates until you open it — even " +
                    "when the screen is locked — Android needs one more " +
                    "permission turned on for Anydrop Restaurant.\n\n" +
                    "Tap Open settings, then turn on \"Allow full screen " +
                    "notifications\" (or similar wording) for this app."
            )
            .setPositiveButton("Open settings") { _, _ ->
                OrderNotificationHelper.openFullScreenIntentSettings(this)
            }
            .setNegativeButton("Not now", null)
            .setCancelable(true)
            .show()
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

    private fun onStatusSwitchToggled(turningOn: Boolean) {
        if (togglingInFlight) return
        if (!turningOn) {
            if (!isOpen) return // already closed, shouldn't get here, guard anyway
            // Tap-to-confirm before closing (§4: "tap to confirm before
            // closing"), same AlertDialog.Builder pattern MenuFragment
            // uses for its own confirmation dialogs. The switch already
            // shows "off" at this point (that's how we got this
            // callback) — revert it back to "on" on cancel/dismiss, since
            // nothing was actually confirmed yet.
            MaterialAlertDialogBuilder(this)
                .setTitle(R.string.dialog_close_restaurant_title)
                .setMessage(R.string.dialog_close_restaurant_message)
                .setPositiveButton(R.string.btn_confirm_close) { _, _ -> setOperationalStatus(false) }
                .setNegativeButton(R.string.btn_cancel) { _, _ -> revertSwitchSelection() }
                .setOnCancelListener { revertSwitchSelection() }
                .show()
        } else {
            // Re-opening needs no confirmation — only pausing does.
            if (!isOpen) setOperationalStatus(true)
        }
    }

    private fun revertSwitchSelection() {
        suppressToggleListener = true
        binding.statusSwitch.isChecked = isOpen
        suppressToggleListener = false
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
        suppressToggleListener = true
        binding.statusSwitch.isChecked = isOpen
        suppressToggleListener = false
        binding.statusLabelText.text = getString(if (isOpen) R.string.restaurant_open_label else R.string.restaurant_closed_label)
        binding.statusLabelText.setTextColor(
            ContextCompat.getColor(this, if (isOpen) R.color.veg_green else R.color.nonveg_red)
        )
    }

    private fun goToLogin() {
        startActivity(
            Intent(this, LoginActivity::class.java)
                .addFlags(Intent.FLAG_ACTIVITY_CLEAR_TOP or Intent.FLAG_ACTIVITY_NEW_TASK)
        )
        finish()
    }
}
