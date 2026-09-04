package com.anydrop.restaurant.service

import android.app.Service
import android.content.Context
import android.content.Intent
import android.os.IBinder
import androidx.core.content.ContextCompat
import com.anydrop.restaurant.data.TokenManager
import com.anydrop.restaurant.network.ApiClient
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.cancel
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

/**
 * Real fix for "alert should work even when the app is closed" — see
 * `OrderNotificationHelper`'s kdoc for the full reasoning. This is the
 * other half: a foreground `Service`, independent of any Activity/Fragment
 * lifecycle, so the polling loop itself (not just the sound mechanism)
 * survives the app being backgrounded or fully swiped away.
 *
 * `startForeground()` is what buys this survival — Android is far less
 * likely to kill a process with an active foreground service + visible
 * notification than a plain background one. It is **not** an absolute
 * guarantee: aggressive OEM battery-management skins (MIUI/ColorOS/
 * FunTouch and similar on Xiaomi/Oppo/Vivo devices in particular) can and
 * do still kill foreground services unless the user manually exempts the
 * app from battery optimization / disables the OEM's own "auto-start
 * management" restriction for it — there is no way to fully guarantee
 * delivery without a real push-notification backend (FCM), which this
 * project doesn't have set up. This is the strongest reliability
 * available without adding that.
 *
 * Started from `MainActivity.onCreate()` (covers both "just logged in"
 * and "app reopened, already logged in") and stopped from the logout
 * handler in `AccountFragment` — a logged-out device has no business
 * still polling for another restaurant's orders.
 */
class OrderPollingService : Service() {

    private var job: Job? = null
    private val scope = CoroutineScope(Dispatchers.IO + Job())

    // Persisted (not just in-memory) so a service restart — Android killed
    // and respawned it, or the phone rebooted — doesn't treat every
    // already-known pending order as "new" again and fire a fresh alert
    // for orders the restaurant has already seen.
    private lateinit var prefs: android.content.SharedPreferences

    override fun onCreate() {
        super.onCreate()
        OrderNotificationHelper.ensureChannels(applicationContext)
        prefs = getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)
    }

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        startForeground(OrderNotificationHelper.MONITORING_NOTIFICATION_ID, OrderNotificationHelper.buildMonitoringNotification(applicationContext))

        // Restarting an already-running poll loop would just duplicate
        // it — onStartCommand can fire more than once (e.g. MainActivity
        // re-calling startForegroundService on every onCreate).
        if (job?.isActive == true) return START_STICKY

        job = scope.launch {
            while (true) {
                val tokenManager = TokenManager(applicationContext)
                if (!tokenManager.isLoggedIn()) {
                    stopSelf()
                    return@launch
                }
                pollOnce()
                pollBellNotifications()
                delay(POLL_INTERVAL_MS)
            }
        }

        // START_STICKY — if the OS does kill this process under memory
        // pressure, ask it to recreate the service (with a null Intent)
        // once resources free up, rather than leaving it permanently gone
        // until the app is next opened by hand.
        return START_STICKY
    }

    private suspend fun pollOnce() {
        try {
            val api = ApiClient.create(applicationContext)
            val response = api.getOrders(status = "pending")
            val orders = response.body()?.data?.data ?: return

            val currentIds = orders.map { it.id }.toSet()
            val knownIds = prefs.getStringSet(KEY_KNOWN_IDS, null)?.mapNotNull { it.toIntOrNull() }?.toSet()

            if (knownIds != null) {
                val newOrders = orders.filter { it.id !in knownIds }
                if (newOrders.isNotEmpty()) {
                    OrderNotificationHelper.showNewOrderAlert(applicationContext, newOrders)
                }
            }
            // else: first poll since this service (re)started — establish
            // the baseline without alerting, same reasoning as
            // OrdersFragment's old knownNewOrderIds null-check (app/service
            // startup always sees whatever's already pending; that isn't
            // "new").

            prefs.edit().putStringSet(KEY_KNOWN_IDS, currentIds.map { it.toString() }.toSet()).apply()
        } catch (e: Exception) {
            // Transient network error — next poll cycle tries again. Not
            // worth surfacing anywhere; this runs silently in the
            // background by design.
        }
    }

    /** The "outside the app" counterpart to the in-app notification bell —
     * see `OrderNotificationHelper.showBellNotification`'s kdoc. Runs every
     * poll cycle alongside [pollOnce], on its own known-ids baseline
     * (separate prefs key) and its own try/catch so a failure here can
     * never affect the pending-order alarm path above. Skips anything
     * whose title starts "New order" — [pollOnce] already fires the loud
     * ringing alert for a genuinely new pending order in the same cycle,
     * and if the backend also raises a bell entry for that same event,
     * posting a second quiet notification for it would just double up. */
    private suspend fun pollBellNotifications() {
        try {
            val api = ApiClient.create(applicationContext)
            val result = api.getNotifications(page = 1, perPage = 20, unreadOnly = "1").body()?.data ?: return
            val items = result.items
            val currentIds = items.map { it.id }.toSet()
            val knownIds = prefs.getStringSet(KEY_KNOWN_NOTIFICATION_IDS, null)?.mapNotNull { it.toIntOrNull() }?.toSet()

            if (knownIds != null) {
                items.filter { it.id !in knownIds && !it.title.startsWith("New order", ignoreCase = true) }
                    .forEach { OrderNotificationHelper.showBellNotification(applicationContext, it) }
            }
            // else: first poll since this service (re)started — establish
            // baseline without alerting, same reasoning as pollOnce().

            prefs.edit().putStringSet(KEY_KNOWN_NOTIFICATION_IDS, currentIds.map { it.toString() }.toSet()).apply()
        } catch (e: Exception) {
            // Transient — next poll cycle retries, same as pollOnce()'s own catch.
        }
    }

    override fun onBind(intent: Intent?): IBinder? = null

    override fun onDestroy() {
        super.onDestroy()
        scope.cancel()
    }

    companion object {
        private const val POLL_INTERVAL_MS = 15000L
        private const val PREFS_NAME = "anydrop_restaurant_order_polling"
        private const val KEY_KNOWN_IDS = "known_pending_ids"
        private const val KEY_KNOWN_NOTIFICATION_IDS = "known_bell_notification_ids"

        /** Idempotent — safe to call from every `MainActivity.onCreate()`,
         * whether or not the service is already running. */
        fun start(context: Context) {
            val intent = Intent(context, OrderPollingService::class.java)
            ContextCompat.startForegroundService(context, intent)
        }

        fun stop(context: Context) {
            context.stopService(Intent(context, OrderPollingService::class.java))
        }
    }
}
