package com.anydrop.food.notifications

import android.util.Log
import com.anydrop.food.data.TokenManager
import com.anydrop.food.network.ApiClient
import com.anydrop.food.network.FcmTokenBody
import com.anydrop.food.network.NotificationItem
import com.google.firebase.messaging.FirebaseMessagingService
import com.google.firebase.messaging.RemoteMessage
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch

/**
 * FCM entry point for the Customer app (this session — see
 * backend/lib/fcm.php's kdoc for the full push-delivery design, and
 * RestaurantFirebaseMessagingService's kdoc for why this class is
 * deliberately thin rather than building its own notification UI).
 *
 * This app is in an even better position than the Restaurant app for
 * this: [NotificationHelper] already has [NotificationHelper
 * .showOfferNotification] with real BigPictureStyle image support (the
 * "with image / without image" requirement was already built here,
 * for the existing offer-broadcast path — this session's admin
 * broadcast feature reuses it rather than building a second image
 * pipeline) and [NotificationHelper.showOrderUpdateNotification]
 * already does `order_id`-based deep-linking. This class just routes
 * an incoming FCM payload to whichever of those already exists:
 *
 * - `notification_type=order` -> showOrderUpdateNotification (needs a
 *   NotificationItem's `data` map with an `order_id` Double, matching
 *   the exact shape OrderUpdatePollingService already builds from a
 *   polled bell row — see that class for why order_id is a Double
 *   there, not an Int: JSON numbers decode as Double by default in
 *   this app's Gson setup).
 * - `notification_type=promo` (including admin broadcasts, this
 *   session) -> showOfferNotification, with `image_url` from the FCM
 *   payload if present.
 * - everything else -> showOfferNotification with no image, as a safe
 *   generic fallback (system/security bell items don't have a more
 *   specific existing handler to route to).
 *
 * `data.link` (an admin broadcast's `link_url` — see
 * `backend/admin/broadcast.php`'s kdoc) is passed through to
 * `showOfferNotification`'s `linkUrl` param whenever present, so a tap
 * opens that link in an external browser instead of Home. See
 * `NotificationHelper.openLinkOrHomeIntent`'s kdoc for why external
 * browser was chosen over an in-app WebView.
 *
 * `OrderUpdatePollingService`'s own polling loop is left running as-is
 * this session, same "FCM is additive, not a replacement yet" call as
 * the Restaurant app — see that file's own kdoc for the parallel
 * reasoning.
 */
class CustomerFirebaseMessagingService : FirebaseMessagingService() {

    override fun onNewToken(token: String) {
        super.onNewToken(token)
        val tokenManager = TokenManager(applicationContext)
        if (tokenManager.getToken().isNullOrEmpty()) {
            Log.d("CustomerFCM", "New FCM token received but not logged in yet — skipping registration")
            return
        }
        CoroutineScope(Dispatchers.IO).launch {
            try {
                ApiClient.create(applicationContext).updateFcmToken(FcmTokenBody(token))
            } catch (e: Exception) {
                Log.w("CustomerFCM", "Failed to register FCM token", e)
            }
        }
    }

    override fun onMessageReceived(message: RemoteMessage) {
        super.onMessageReceived(message)
        val data = message.data
        val title = message.notification?.title ?: data["title"] ?: "Anydrop"
        val body = message.notification?.body ?: data["body"] ?: ""
        val notificationType = data["notification_type"] ?: "system"
        val imageUrl = message.notification?.imageUrl?.toString() ?: data["image_url"]
        val linkUrl = data["link"]

        if (notificationType == "order") {
            val orderId = data["order_id"]?.toDoubleOrNull()
            val itemId = data["notification_id"]?.toIntOrNull() ?: (System.currentTimeMillis() % Int.MAX_VALUE).toInt()
            NotificationHelper.showOrderUpdateNotification(
                applicationContext,
                NotificationItem(
                    id = itemId,
                    title = title,
                    body = body,
                    type = notificationType,
                    isRead = false,
                    data = if (orderId != null) mapOf("order_id" to orderId) else null,
                    createdAt = ""
                )
            )
            return
        }

        // Covers promo/admin-broadcast (with or without image) and the
        // system/security fallback — showOfferNotification already
        // handles a null imageUrl by falling back to BigTextStyle, so
        // no separate branch is needed for the no-image case. linkUrl is
        // null for every existing (pre-broadcast) call site, which keeps
        // that path's Home-tap behavior unchanged.
        NotificationHelper.showOfferNotification(applicationContext, title, body, imageUrl, linkUrl)
    }
}
