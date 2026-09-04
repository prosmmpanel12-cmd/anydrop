package com.anydrop.restaurant.service

import android.util.Log
import com.anydrop.restaurant.data.TokenManager
import com.anydrop.restaurant.network.ApiClient
import com.anydrop.restaurant.network.FcmTokenBody
import com.anydrop.restaurant.network.NotificationItem
import com.google.firebase.messaging.FirebaseMessagingService
import com.google.firebase.messaging.RemoteMessage
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch

/**
 * FCM entry point for the Restaurant app (this session — see
 * backend/lib/fcm.php's kdoc for the full push-delivery design).
 *
 * DELIBERATELY THIN: this class does not build its own "new order"
 * notification UI from scratch. [OrderNotificationHelper] already has
 * a fully-designed, real-device-tested ringing/full-screen-intent/
 * dismiss-action system for exactly that (see that file's own long
 * kdoc — built and iterated well before FCM existed, purely from the
 * polling service). Duplicating that here would mean two different
 * "new order" notification experiences that could drift apart. Instead:
 *
 * - A push carrying `notification_type=order` and an `order_id` fetches
 *   that one order via the existing `getOrder()` endpoint and calls
 *   [OrderNotificationHelper.showNewOrderAlert] — the *exact* same loud
 *   ringing experience `OrderPollingService` already produces, just
 *   triggered by a push instead of (or alongside) the next poll tick.
 * - Every other push type builds a [NotificationItem] from the FCM
 *   payload directly (no extra fetch needed — title/body/id are already
 *   in the payload) and calls [OrderNotificationHelper.showBellNotification],
 *   the same "quiet, tap-opens-the-bell-list" path a poll-discovered bell
 *   row already gets — unless `data.link` is present (an admin
 *   broadcast's `link_url`), in which case that same call's `linkUrl`
 *   param routes the tap to an external browser instead. See that
 *   function's kdoc for the external-browser-vs-WebView decision.
 *
 * `OrderPollingService`'s own polling loop is left running as-is this
 * session — FCM is additive here, not a replacement. Removing polling
 * entirely once push delivery is confirmed reliable on real devices is
 * a reasonable future cleanup, but doing that in the same pass as
 * standing up FCM for the first time (with zero device verification
 * possible in this container) would risk losing the one delivery path
 * that's actually been proven to work.
 */
class RestaurantFirebaseMessagingService : FirebaseMessagingService() {

    /** Fired whenever FCM (re)issues a token — first app install, app
     * data cleared, or a periodic silent rotation. Registers it with the
     * backend so create_notification() has something to send to.
     * Silently no-ops if the restaurant isn't logged in yet (a token
     * minted before login has nothing to attach to) — the login screen's
     * own post-login flow is responsible for re-sending the
     * then-current token once an auth token exists (see
     * TokenManager/login flow — not wired in this pass, flagged in the
     * handover doc's "still open" list since it's a login-flow change,
     * not a messaging-service one). */
    override fun onNewToken(token: String) {
        super.onNewToken(token)
        val tokenManager = TokenManager(applicationContext)
        if (tokenManager.getToken().isNullOrEmpty()) {
            Log.d("RestaurantFCM", "New FCM token received but not logged in yet — skipping registration")
            return
        }
        CoroutineScope(Dispatchers.IO).launch {
            try {
                ApiClient.create(applicationContext).updateFcmToken(FcmTokenBody(token))
            } catch (e: Exception) {
                Log.w("RestaurantFCM", "Failed to register FCM token", e)
            }
        }
    }

    override fun onMessageReceived(message: RemoteMessage) {
        super.onMessageReceived(message)
        val data = message.data
        val title = message.notification?.title ?: data["title"] ?: "Anydrop Restaurant"
        val body = message.notification?.body ?: data["body"] ?: ""
        val notificationType = data["notification_type"] ?: "system"
        val linkUrl = data["link"]

        if (notificationType == "order" && data["order_id"] != null) {
            val orderId = data["order_id"]?.toIntOrNull()
            if (orderId != null) {
                CoroutineScope(Dispatchers.IO).launch {
                    try {
                        val response = ApiClient.create(applicationContext).getOrder(orderId)
                        val order = response.body()?.data?.order
                        if (order != null) {
                            OrderNotificationHelper.showNewOrderAlert(applicationContext, listOf(order))
                        }
                    } catch (e: Exception) {
                        Log.w("RestaurantFCM", "Failed to fetch order $orderId for push alert", e)
                    }
                }
            }
            return
        }

        // Every other type: build a NotificationItem straight from the
        // payload — id defaults to a timestamp-derived value when the
        // push doesn't carry one (e.g. an admin broadcast with no
        // single underlying bell row id yet), just enough to keep
        // showBellNotification's per-item notification id stable for
        // that one call. linkUrl is null for every existing
        // (pre-broadcast) call site, which keeps that path's
        // open-bell-list-on-tap behavior unchanged.
        val itemId = data["notification_id"]?.toIntOrNull() ?: (System.currentTimeMillis() % Int.MAX_VALUE).toInt()
        OrderNotificationHelper.showBellNotification(
            applicationContext,
            NotificationItem(
                id = itemId,
                title = title,
                body = body,
                type = notificationType,
                isRead = false,
                data = null,
                createdAt = ""
            ),
            linkUrl
        )
    }
}
