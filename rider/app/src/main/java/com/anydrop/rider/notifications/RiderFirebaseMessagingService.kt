package com.anydrop.rider.notifications

import android.util.Log
import com.anydrop.rider.data.TokenManager
import com.anydrop.rider.network.ApiClient
import com.anydrop.rider.network.FcmTokenBody
import com.google.firebase.messaging.FirebaseMessagingService
import com.google.firebase.messaging.RemoteMessage
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch

/**
 * FCM entry point for the Rider app (deep-plan §23, docs 99-105).
 *
 * Deliberately thin, same "route to an existing display helper" shape
 * as the customer/restaurant apps' equivalents. `create_notification()`
 * (`backend/lib/notifications.php`) always stamps the caller's `$type`
 * onto the FCM payload as `data.notification_type` — this class routes
 * on that field to pick which [RiderNotificationHelper] channel/style
 * to use, then forwards `data.screen` unchanged for that helper to turn
 * into a PendingIntent.
 *
 * Doc 99-101/103's original version only ever called
 * [RiderNotificationHelper.showAccountNotification], because 'account'
 * was the only `notification_type` this app's backend sent at the
 * time. That default silently applied to every push regardless of its
 * real type — including 'payout' pushes (`rider_payout.php`,
 * `orders-deliver.php`), which predate this session and were already
 * being funneled through the wrong channel/routing before doc 105's
 * new 'order'-type pushes made the same gap harder to ignore. Fixed
 * here, not by widening `showAccountNotification`, but by giving each
 * real type its own path — 'account' keeps its exact original
 * behavior unchanged.
 *
 * No polling service exists in this app for account-status changes
 * (unlike the customer app's `OrderUpdatePollingService`) — the
 * notification bell's own pull-to-refresh/badge check
 * (`rider/notifications-list.php`) is this app's only other way of
 * learning about the same event, so this push is additive to that,
 * not a replacement for it.
 */
class RiderFirebaseMessagingService : FirebaseMessagingService() {

    override fun onNewToken(token: String) {
        super.onNewToken(token)
        val tokenManager = TokenManager(applicationContext)
        if (!tokenManager.isLoggedIn()) {
            Log.d("RiderFCM", "New FCM token received but not logged in yet — skipping registration")
            return
        }
        CoroutineScope(Dispatchers.IO).launch {
            try {
                ApiClient.create(applicationContext).updateFcmToken(FcmTokenBody(token))
            } catch (e: Exception) {
                Log.w("RiderFCM", "Failed to register FCM token", e)
            }
        }
    }

    override fun onMessageReceived(message: RemoteMessage) {
        super.onMessageReceived(message)
        val data = message.data
        val title = message.notification?.title ?: data["title"] ?: "AnyDrop"
        val body = message.notification?.body ?: data["body"] ?: ""
        val screen = data["screen"]

        when (data["notification_type"]) {
            "order" -> RiderNotificationHelper.showOrderNotification(applicationContext, title, body, screen)
            "payout" -> RiderNotificationHelper.showPayoutNotification(applicationContext, title, body, screen)
            // 'account' and anything unrecognized (a future type this
            // build doesn't know about yet) keep the original fallback
            // — same behavior as before this session for every case
            // that isn't a known non-account type.
            else -> RiderNotificationHelper.showAccountNotification(applicationContext, title, body, screen)
        }
    }
}
