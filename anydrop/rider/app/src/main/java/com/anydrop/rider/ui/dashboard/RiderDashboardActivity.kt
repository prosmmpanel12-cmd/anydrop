package com.anydrop.rider.ui.dashboard

import android.Manifest
import android.annotation.SuppressLint
import android.content.Intent
import android.content.pm.PackageManager
import android.os.Bundle
import android.os.Handler
import android.os.Looper
import android.view.View
import androidx.activity.result.contract.ActivityResultContracts
import androidx.appcompat.app.AppCompatActivity
import androidx.core.content.ContextCompat
import androidx.lifecycle.lifecycleScope
import com.anydrop.rider.R
import com.anydrop.rider.data.TokenManager
import com.anydrop.rider.databinding.ActivityRiderDashboardBinding
import com.anydrop.rider.network.ApiClient
import com.anydrop.rider.network.LocationBody
import com.anydrop.rider.network.OnlineStatusBody
import com.anydrop.rider.network.parseApiError
import com.anydrop.rider.ui.common.InAppNotifier
import com.anydrop.rider.ui.login.LoginActivity
import com.anydrop.rider.ui.pending.ApplicationStatusActivity
import com.google.android.gms.location.LocationServices
import com.google.android.gms.location.Priority
import com.google.android.gms.tasks.CancellationTokenSource
import kotlinx.coroutines.launch

/**
 * Landing screen for approved riders (Phase 3, doc 83). Reached only
 * via ApplicationStatusActivity's redirect — see that screen's kdoc
 * for why the branch lives there instead of at every login/signup
 * call site.
 *
 * Scope for this slice, deliberately: online/offline switch + a static
 * "no active delivery" / "₹0 earnings" placeholder. No order data flows
 * here yet — that's R3 (assignment engine), not built. See
 * docs/rider/83_Plan_Phase3... "Explicitly out of scope".
 *
 * Location: going online requires a location already on file
 * server-side (status.php returns 422 location_required otherwise).
 * Rather than surface that as a confusing error, this screen requests
 * the permission + sends one location ping proactively the moment the
 * rider flips the switch on, then retries the online call. Reuses the
 * same ACCESS_FINE_LOCATION permission SignupActivity already requests
 * (already granted for most riders by the time they reach here).
 *
 * Foreground-only polling while online (see doc 83 "explicitly out of
 * scope" re: background service) — a periodic ping via postDelayed,
 * stopped in onPause so it never runs with the screen off/backgrounded.
 * 30s interval per the doc's stated default.
 */
class RiderDashboardActivity : AppCompatActivity() {

    private lateinit var binding: ActivityRiderDashboardBinding
    private lateinit var tokenManager: TokenManager
    private val api by lazy { ApiClient.create(this) }
    private val fusedLocationClient by lazy { LocationServices.getFusedLocationProviderClient(this) }

    private val locationPermissionLauncher =
        registerForActivityResult(ActivityResultContracts.RequestPermission()) { granted ->
            if (granted) {
                sendLocationThenGoOnline()
            } else {
                binding.onlineSwitch.isChecked = false
                InAppNotifier.show(this, getString(R.string.dashboard_location_permission_denied), InAppNotifier.Type.INFO)
            }
        }

    private val locationPoller = Handler(Looper.getMainLooper())
    private val locationPollRunnable = object : Runnable {
        override fun run() {
            sendLocationPing()
            locationPoller.postDelayed(this, LOCATION_POLL_INTERVAL_MS)
        }
    }
    private var suppressSwitchListener = false

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityRiderDashboardBinding.inflate(layoutInflater)
        setContentView(binding.root)

        tokenManager = TokenManager(this)

        if (!tokenManager.isLoggedIn()) {
            goToLogin()
            return
        }

        binding.dashboardGreeting.text = getString(
            R.string.dashboard_greeting_format,
            tokenManager.getRiderName() ?: ""
        )
        renderOnlineState(tokenManager.getIsOnline())

        binding.btnLogout.setOnClickListener {
            tokenManager.clear()
            goToLogin()
        }

        binding.onlineSwitch.setOnCheckedChangeListener { _, checked ->
            if (suppressSwitchListener) return@setOnCheckedChangeListener
            if (checked) attemptGoOnline() else setOnlineStatus(false)
        }

        refreshFromServer()
    }

    override fun onResume() {
        super.onResume()
        if (tokenManager.getIsOnline()) {
            locationPoller.post(locationPollRunnable)
        }
    }

    override fun onPause() {
        super.onPause()
        locationPoller.removeCallbacks(locationPollRunnable)
    }

    /** Bootstraps from /rider/me so the switch reflects the server's actual
     *  state (not just what was cached at last login/refresh), and catches
     *  the case where status changed since login (e.g. suspended). */
    private fun refreshFromServer() {
        lifecycleScope.launch {
            try {
                val response = api.getMe()
                if (response.isSuccessful && response.body()?.success == true) {
                    val result = response.body()?.data ?: return@launch
                    if (result.status != "approved") {
                        // No longer approved (e.g. suspended since login) —
                        // TokenManager + ApplicationStatusActivity handle
                        // every non-approved case, including logout on
                        // suspension; just hand off.
                        tokenManager.updateStatus(result.status, result.rider.rejectionReason)
                        goToStatusScreen()
                        return@launch
                    }
                    tokenManager.setIsOnline(result.rider.isOnline)
                    renderOnlineState(result.rider.isOnline)
                    if (result.rider.isOnline) {
                        locationPoller.removeCallbacks(locationPollRunnable)
                        locationPoller.post(locationPollRunnable)
                    }
                } else {
                    val parsed = parseApiError(response.errorBody())
                    if (parsed.code == "account_suspended") {
                        tokenManager.clear()
                        goToLogin()
                    }
                    // Any other error: leave the cached/rendered state as-is,
                    // don't disrupt the screen for a transient failure.
                }
            } catch (e: Exception) {
                // Transient network failure — cached state already rendered.
            }
        }
    }

    private fun attemptGoOnline() {
        val granted = ContextCompat.checkSelfPermission(this, Manifest.permission.ACCESS_FINE_LOCATION) ==
            PackageManager.PERMISSION_GRANTED
        if (granted) {
            sendLocationThenGoOnline()
        } else {
            locationPermissionLauncher.launch(Manifest.permission.ACCESS_FINE_LOCATION)
        }
    }

    @SuppressLint("MissingPermission")
    private fun sendLocationThenGoOnline() {
        setSwitchLoading(true)
        val cancellationSource = CancellationTokenSource()
        fusedLocationClient.getCurrentLocation(Priority.PRIORITY_BALANCED_POWER_ACCURACY, cancellationSource.token)
            .addOnSuccessListener { location ->
                if (location == null) {
                    setSwitchLoading(false)
                    setSwitchChecked(false)
                    InAppNotifier.show(this, getString(R.string.dashboard_location_required), InAppNotifier.Type.INFO)
                    return@addOnSuccessListener
                }
                lifecycleScope.launch {
                    try {
                        api.updateLocation(LocationBody(location.latitude, location.longitude))
                    } catch (e: Exception) {
                        // Best-effort — status.php will reject with location_required
                        // below if this genuinely didn't land, and we surface that.
                    }
                    setOnlineStatus(true)
                }
            }
            .addOnFailureListener {
                setSwitchLoading(false)
                setSwitchChecked(false)
                InAppNotifier.show(this, getString(R.string.dashboard_location_required), InAppNotifier.Type.INFO)
            }
    }

    private fun sendLocationPing() {
        if (ContextCompat.checkSelfPermission(this, Manifest.permission.ACCESS_FINE_LOCATION) !=
            PackageManager.PERMISSION_GRANTED
        ) {
            return
        }
        sendLocationPingInternal()
    }

    @SuppressLint("MissingPermission")
    private fun sendLocationPingInternal() {
        val cancellationSource = CancellationTokenSource()
        fusedLocationClient.getCurrentLocation(Priority.PRIORITY_BALANCED_POWER_ACCURACY, cancellationSource.token)
            .addOnSuccessListener { location ->
                if (location != null) {
                    lifecycleScope.launch {
                        try {
                            api.updateLocation(LocationBody(location.latitude, location.longitude))
                        } catch (e: Exception) {
                            // Silent — next poll tries again.
                        }
                    }
                }
            }
    }

    private fun setOnlineStatus(online: Boolean) {
        setSwitchLoading(true)
        lifecycleScope.launch {
            try {
                val response = api.setOnlineStatus(OnlineStatusBody(online))
                setSwitchLoading(false)
                if (response.isSuccessful && response.body()?.success == true) {
                    val isOnline = response.body()?.data?.isOnline ?: online
                    tokenManager.setIsOnline(isOnline)
                    renderOnlineState(isOnline)
                    setSwitchChecked(isOnline)
                    InAppNotifier.show(
                        this@RiderDashboardActivity,
                        getString(if (isOnline) R.string.dashboard_went_online else R.string.dashboard_went_offline),
                        InAppNotifier.Type.SUCCESS
                    )
                    if (isOnline) {
                        locationPoller.removeCallbacks(locationPollRunnable)
                        locationPoller.post(locationPollRunnable)
                    } else {
                        locationPoller.removeCallbacks(locationPollRunnable)
                    }
                } else {
                    val parsed = parseApiError(response.errorBody())
                    setSwitchChecked(false)
                    if (parsed.code == "location_required") {
                        InAppNotifier.show(this@RiderDashboardActivity, getString(R.string.dashboard_location_required), InAppNotifier.Type.INFO)
                    } else if (parsed.code == "account_suspended") {
                        tokenManager.clear()
                        goToLogin()
                    } else {
                        InAppNotifier.show(this@RiderDashboardActivity, getString(R.string.error_network), InAppNotifier.Type.ERROR)
                    }
                }
            } catch (e: Exception) {
                setSwitchLoading(false)
                setSwitchChecked(false)
                InAppNotifier.show(this@RiderDashboardActivity, getString(R.string.error_network), InAppNotifier.Type.ERROR)
            }
        }
    }

    /** Updates the switch without re-triggering setOnCheckedChangeListener
     *  (avoids a network-call loop when we set it programmatically). */
    private fun setSwitchChecked(checked: Boolean) {
        suppressSwitchListener = true
        binding.onlineSwitch.isChecked = checked
        suppressSwitchListener = false
    }

    private fun renderOnlineState(online: Boolean) {
        setSwitchChecked(online)
        if (online) {
            binding.onlineStatusTitle.text = getString(R.string.dashboard_online_title)
            binding.onlineStatusSubtitle.text = getString(R.string.dashboard_online_subtitle)
        } else {
            binding.onlineStatusTitle.text = getString(R.string.dashboard_offline_title)
            binding.onlineStatusSubtitle.text = getString(R.string.dashboard_offline_subtitle)
        }
    }

    private fun setSwitchLoading(loading: Boolean) {
        binding.onlineSwitch.isEnabled = !loading
        binding.onlineSwitchProgress.visibility = if (loading) View.VISIBLE else View.GONE
    }

    private fun goToStatusScreen() {
        val intent = Intent(this, ApplicationStatusActivity::class.java).apply {
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

    companion object {
        private const val LOCATION_POLL_INTERVAL_MS = 30_000L
    }
}
