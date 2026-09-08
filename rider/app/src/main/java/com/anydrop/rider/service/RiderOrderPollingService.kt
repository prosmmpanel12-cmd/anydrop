package com.anydrop.rider.service

import android.app.Service
import android.content.Context
import android.content.Intent
import android.os.IBinder
import androidx.core.content.ContextCompat
import com.anydrop.rider.data.TokenManager
import com.anydrop.rider.network.ApiClient
import com.anydrop.rider.notifications.RiderNotificationHelper
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.cancel
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

/**
 * Root-cause fix for "rider app mein orders receive nahi ho rahe" (v24
 * bug report). Traced against the existing dispatch engine
 * (backend/lib/dispatch.php, rider/orders-available.php,
 * rider/orders-accept.php) end to end — that side is correct and
 * unchanged by this fix.
 *
 * The actual gap was Android-side only: RiderDashboardActivity's own
 * `dashboardPollRunnable`/`locationPollRunnable` are wired to
 * onResume()/onPause() (see that class's kdoc — "Foreground-only
 * polling while online... explicitly out of scope re: background
 * service" was a deliberate Phase 3 simplification that was never
 * revisited). The instant a rider backgrounds the app, locks the
 * screen, or switches to another app to wait — completely normal
 * behavior for a delivery rider between orders — `onPause()` cancels
 * both pollers immediately. No further calls to
 * `/rider/orders-available` happen until the rider manually reopens
 * this app, so a new offer can sit dispatched server-side (with its
 * own 40s `rider_assignment_timeout_seconds` expiry ticking down) with
 * nothing on the Android side ever checking for it. From the rider's
 * point of view this reads exactly as "order aata hi nahi" even though
 * the backend already created the offer correctly.
 *
 * This is the exact same class of bug the Restaurant app hit first
 * (see `restaurant/.../service/OrderPollingService.kt`'s own kdoc,
 * "real fix for alert should work even when the app is closed") and
 * the Customer app also already has its own equivalent
 * (`OrderUpdatePollingService`) — the Rider app was the one app in
 * this project that never got this treatment. This service is the
 * same fix, ported to the rider domain: a foreground `Service`,
 * independent of any Activity's lifecycle, that keeps polling
 * `/rider/orders-available` (new offer) and `/rider/orders-current`
 * (active-delivery status, in case it changed while backgrounded — an
 * admin cancel, for instance) as long as the rider is online, and
 * fires a real status-bar alert via [RiderNotificationHelper] the
 * moment a genuinely new offer shows up — not just an in-app card the
 * rider has to have the screen open to see.
 *
 * `startForeground()` reduces (does not guarantee — see the restaurant
 * service's own kdoc for the same OEM battery-management caveat,
 * unchanged here) the odds Android kills this process while
 * backgrounded. `RiderDashboardActivity` keeps its own foreground-only
 * pollers exactly as they were — this service is additive, not a
 * replacement, since a visible, focused dashboard should still update
 * at its faster in-app cadence; this service only needs to cover the
 * gap when nothing else is watching.
 *
 * Start/stop lifecycle (mirrors OrderPollingService.start()/stop()
 * being called from the restaurant app's MainActivity/AccountFragment):
 * - Started whenever the rider is (or goes) online —
 *   RiderDashboardActivity.setOnlineStatus() on a successful "went
 *   online" response, and refreshFromServer() on load if /rider/me
 *   already reports is_online=true (covers "app reopened while still
 *   online from a previous session").
 * - Stopped when the rider goes offline (setOnlineStatus()'s "went
 *   offline" branch) and on logout — an offline or logged-out rider
 *   has no business still polling for someone else's delivery offers.
 */
class RiderOrderPollingService : Service() {

    private var job: Job? = null
    private val scope = CoroutineScope(Dispatchers.IO + Job())
    private lateinit var prefs: android.content.SharedPreferences

    override fun onCreate() {
        super.onCreate()
        RiderNotificationHelper.ensureChannels(applicationContext)
        prefs = getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)
    }

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        startForeground(
            RiderNotificationHelper.MONITORING_NOTIFICATION_ID,
            RiderNotificationHelper.buildMonitoringNotification(applicationContext)
        )

        // onStartCommand can fire more than once (e.g. every
        // RiderDashboardActivity.onCreate()/setOnlineStatus() success
        // while already online calling start() again) — don't stack a
        // second poll loop on top of an already-running one.
        if (job?.isActive == true) return START_STICKY

        job = scope.launch {
            while (true) {
                val tokenManager = TokenManager(applicationContext)
                if (!tokenManager.isLoggedIn() || !tokenManager.getIsOnline()) {
                    stopSelf()
                    return@launch
                }
                pollForOffer()
                delay(POLL_INTERVAL_MS)
            }
        }

        // START_STICKY — same reasoning as the restaurant app's
        // OrderPollingService: if the OS kills this process under
        // memory pressure, ask it to come back once resources free up
        // rather than staying gone until the rider next opens the app.
        return START_STICKY
    }

    /** Same two-step "current delivery wins, else check for an offer"
     * shape as RiderDashboardActivity.pollDashboardState() — an active
     * delivery already has its own dedicated screen/notifications from
     * accept/pickup/deliver, so this only needs to alert on a genuinely
     * new *offer* the rider hasn't seen yet, tracked by assignment_id
     * (not order_id — a rejected/expired offer on the same order gets a
     * new assignment_id on re-dispatch, and that IS a new offer worth
     * alerting on again). */
    private suspend fun pollForOffer() {
        try {
            val api = ApiClient.create(applicationContext)

            // An active delivery means no offer will ever be returned by
            // orders-available anyway (dispatch.php's own eligibility
            // query excludes a rider with one) — skip the extra call.
            val currentResponse = api.getCurrentOrder()
            val hasActiveOrder = currentResponse.isSuccessful && currentResponse.body()?.data?.order != null
            if (hasActiveOrder) {
                return
            }

            val offerResponse = api.getAvailableOffer()
            val offer = if (offerResponse.isSuccessful) offerResponse.body()?.data?.offer else null

            val lastAlertedId = prefs.getInt(KEY_LAST_ALERTED_ASSIGNMENT_ID, -1)
            if (offer != null && offer.assignmentId != lastAlertedId) {
                RiderNotificationHelper.showOrderNotification(
                    applicationContext,
                    "New delivery available",
                    "Order ${offer.orderCode} is ready for pickup",
                    "order_offer"
                )
                prefs.edit().putInt(KEY_LAST_ALERTED_ASSIGNMENT_ID, offer.assignmentId).apply()
            } else if (offer == null) {
                // No open offer right now — reset the baseline so the
                // *next* offer (even if it happens to reuse an id, which
                // can't actually happen with an autoincrement column, but
                // keeps this resilient regardless) always alerts.
                prefs.edit().putInt(KEY_LAST_ALERTED_ASSIGNMENT_ID, -1).apply()
            }
        } catch (e: Exception) {
            // Transient network error — next poll cycle tries again,
            // same silent-retry stance every other poller in this
            // codebase takes.
        }
    }

    override fun onBind(intent: Intent?): IBinder? = null

    override fun onDestroy() {
        super.onDestroy()
        scope.cancel()
    }

    companion object {
        private const val POLL_INTERVAL_MS = 15_000L
        private const val PREFS_NAME = "anydrop_rider_order_polling"
        private const val KEY_LAST_ALERTED_ASSIGNMENT_ID = "last_alerted_assignment_id"

        /** Idempotent — safe to call whenever the rider is known to be
         * online, whether or not the service is already running. */
        fun start(context: Context) {
            val intent = Intent(context, RiderOrderPollingService::class.java)
            ContextCompat.startForegroundService(context, intent)
        }

        fun stop(context: Context) {
            context.stopService(Intent(context, RiderOrderPollingService::class.java))
        }
    }
}
