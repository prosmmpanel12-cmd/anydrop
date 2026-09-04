package com.anydrop.food.notifications

import android.app.Service
import android.content.Context
import android.content.Intent
import android.os.IBinder
import androidx.core.content.ContextCompat
import com.anydrop.food.network.ApiClient
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.cancel
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

/**
 * The "outside the app" counterpart to the notification bell, for order
 * status updates specifically — "Order accepted", "Preparing your order",
 * "Order ready", etc. (2026-08-21 app-owner request). Mirrors the
 * restaurant app's `OrderPollingService` in mechanism (foreground service,
 * 15s poll, `startForeground()` for process survival — see that class's
 * kdoc for the full reliability caveat re: OEM battery management / no
 * real FCM push backing this), but deliberately scoped differently:
 *
 * The restaurant app polls constantly for as long as the operator is
 * logged in — that's a business tool that always needs to know about new
 * orders. A customer does NOT want a persistent "checking for updates"
 * notification sitting in their status bar all the time just for the rare
 * order — so this service only runs while at least one order is active
 * (non-terminal), and stops itself the moment none are.
 *
 * Started from:
 *  - `CheckoutActivity` right after an order is placed
 *  - `OrderStatusActivity.onCreate()` (covers reopening the app into an
 *    already-active order — e.g. app was killed and relaunched)
 * Both calls are idempotent/additive (see [start]).
 */
class OrderUpdatePollingService : Service() {

    private var job: Job? = null
    private val scope = CoroutineScope(Dispatchers.IO + Job())
    private lateinit var prefs: android.content.SharedPreferences

    override fun onCreate() {
        super.onCreate()
        NotificationHelper.ensureChannels(applicationContext)
        prefs = getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)
    }

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        val newOrderId = intent?.getIntExtra(EXTRA_ORDER_ID, 0)?.takeIf { it != 0 }
        if (newOrderId != null) {
            val active = activeOrderIds().toMutableSet()
            active.add(newOrderId)
            saveActiveOrderIds(active)
        }

        startForeground(NOTIF_ID_MONITORING, buildMonitoringNotification())

        // onStartCommand can fire again while already polling (e.g. a
        // second order placed while the first is still active) — the new
        // id is already folded into the persisted set above, so the loop
        // just needs to keep running, not restart.
        if (job?.isActive == true) return START_STICKY

        job = scope.launch {
            while (true) {
                pollActiveOrders()
                pollBellNotifications()
                if (activeOrderIds().isEmpty()) {
                    stopSelf()
                    return@launch
                }
                delay(POLL_INTERVAL_MS)
            }
        }

        return START_STICKY
    }

    /** Drops any order that's reached a terminal status from the active
     * set — this is what eventually makes the service stop itself once
     * nothing's left to watch. */
    private suspend fun pollActiveOrders() {
        val active = activeOrderIds()
        if (active.isEmpty()) return
        val api = ApiClient.create(applicationContext)
        val stillActive = active.toMutableSet()
        for (orderId in active) {
            try {
                val status = api.trackOrder(orderId).body()?.data?.status
                if (status != null && status in TERMINAL_STATUSES) {
                    stillActive.remove(orderId)
                }
            } catch (e: Exception) {
                // Transient — leave it in the active set, next cycle retries.
            }
        }
        saveActiveOrderIds(stillActive)
    }

    /** Same known-ids-diff approach as the restaurant app's
     * `OrderPollingService.pollBellNotifications()` — anything new in the
     * bell since last cycle gets a system notification. No extra filtering
     * by order id needed: while this service is running, the only
     * `create_notification()` call sites that fire for a customer are the
     * order-status ones (accept/reject/preparing/ready) — promo broadcast
     * (Type 2) isn't built yet, see notifications.php's kdoc. */
    private suspend fun pollBellNotifications() {
        try {
            val api = ApiClient.create(applicationContext)
            val result = api.getNotifications(page = 1, perPage = 20, unreadOnly = "1").body()?.data ?: return
            val items = result.items
            val currentIds = items.map { it.id }.toSet()
            val knownIds = prefs.getStringSet(KEY_KNOWN_NOTIFICATION_IDS, null)?.mapNotNull { it.toIntOrNull() }?.toSet()

            if (knownIds != null) {
                items.filter { it.id !in knownIds }
                    .forEach { NotificationHelper.showOrderUpdateNotification(applicationContext, it) }
            }
            // else: first poll since this service (re)started — establish
            // baseline without alerting, same reasoning as the restaurant
            // app's equivalent.

            prefs.edit().putStringSet(KEY_KNOWN_NOTIFICATION_IDS, currentIds.map { it.toString() }.toSet()).apply()
        } catch (e: Exception) {
            // Transient — next poll cycle retries.
        }
    }

    private fun activeOrderIds(): Set<Int> =
        prefs.getStringSet(KEY_ACTIVE_ORDER_IDS, null)?.mapNotNull { it.toIntOrNull() }?.toSet() ?: emptySet()

    private fun saveActiveOrderIds(ids: Set<Int>) {
        prefs.edit().putStringSet(KEY_ACTIVE_ORDER_IDS, ids.map { it.toString() }.toSet()).apply()
    }

    private fun buildMonitoringNotification() =
        androidx.core.app.NotificationCompat.Builder(this, NotificationHelper.CHANNEL_ORDER_UPDATES)
            .setSmallIcon(com.anydrop.food.R.drawable.ic_notification)
            .setContentTitle("Tracking your order")
            .setContentText("You'll be notified as your order status changes")
            .setOngoing(true)
            .setPriority(androidx.core.app.NotificationCompat.PRIORITY_LOW)
            .build()

    override fun onBind(intent: Intent?): IBinder? = null

    override fun onDestroy() {
        super.onDestroy()
        scope.cancel()
    }

    companion object {
        const val EXTRA_ORDER_ID = "extra_order_id"
        private const val POLL_INTERVAL_MS = 15000L
        private const val NOTIF_ID_MONITORING = 4001
        private const val PREFS_NAME = "anydrop_customer_order_polling"
        private const val KEY_ACTIVE_ORDER_IDS = "active_order_ids"
        private const val KEY_KNOWN_NOTIFICATION_IDS = "known_bell_notification_ids"
        private val TERMINAL_STATUSES = setOf("delivered", "cancelled", "rejected", "refunded", "failed", "expired")

        /** Adds [orderId] to the watch set and (re)starts the service —
         * safe to call whether or not it's already running, and whether
         * or not other orders are already active. */
        fun start(context: Context, orderId: Int) {
            val intent = Intent(context, OrderUpdatePollingService::class.java)
                .putExtra(EXTRA_ORDER_ID, orderId)
            ContextCompat.startForegroundService(context, intent)
        }
    }
}
