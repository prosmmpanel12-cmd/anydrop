package com.anydrop.food.notifications

import android.Manifest
import android.app.Activity
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.graphics.Bitmap
import android.os.Build
import androidx.core.app.ActivityCompat
import androidx.core.app.NotificationCompat
import androidx.core.app.NotificationManagerCompat
import androidx.core.content.ContextCompat
import com.anydrop.food.R
import com.anydrop.food.ui.home.HomeActivity
import java.net.URL

/**
 * System (status-bar) notification helper — separate from InAppNotifier,
 * which only shows in-app banners. Used for:
 *  - Offer / discount push-style alerts (with or without an image —
 *    BigPictureStyle when an image URL is supplied, plain text otherwise)
 *  - The daily "your meal is waiting for you" reminder (see MealReminderWorker)
 */
object NotificationHelper {

    const val CHANNEL_OFFERS = "anydrop_offers"
    const val CHANNEL_REMINDERS = "anydrop_reminders"
    // Phase J — separate channel from CHANNEL_REMINDERS (meal
    // reminders/engagement) since a cart-abandonment nudge is a different
    // kind of alert (action-needed on something already started, not a
    // generic "come order something") — letting the user mute one without
    // muting the other.
    const val CHANNEL_CART_ABANDONMENT = "anydrop_cart_abandonment"
    // 2026-08-21 — order status updates (accepted/preparing/ready/etc.),
    // the "outside the app" counterpart to the notification bell. Separate
    // channel from offers/reminders/cart so a user can't accidentally mute
    // "is my food ready" alerts while muting promotional ones.
    const val CHANNEL_ORDER_UPDATES = "anydrop_order_updates"
    private const val NOTIF_ID_OFFER = 1001
    const val NOTIF_ID_REMINDER = 2001
    const val NOTIF_ID_CART_ABANDONMENT = 3001
    /** Base id for [showOrderUpdateNotification] — actual id is this + the
     * bell item's own id, same reasoning as the restaurant app's
     * equivalent: keeps every order-update notification's id distinct so a
     * burst of several stacks instead of overwriting one another, and well
     * clear of the other fixed ids in this file. */
    private const val NOTIF_ID_ORDER_UPDATE_BASE = 4100

    private const val REQUEST_CODE_NOTIF_PERMISSION = 5001

    fun ensureChannels(context: Context) {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) return
        val manager = context.getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager

        manager.createNotificationChannel(
            NotificationChannel(
                CHANNEL_OFFERS,
                "Offers & discounts",
                NotificationManager.IMPORTANCE_DEFAULT
            ).apply { description = "Deals, discounts and promo alerts from Anydrop" }
        )

        manager.createNotificationChannel(
            NotificationChannel(
                CHANNEL_REMINDERS,
                "Meal reminders",
                NotificationManager.IMPORTANCE_DEFAULT
            ).apply { description = "Daily reminders when it's time to order" }
        )

        manager.createNotificationChannel(
            NotificationChannel(
                CHANNEL_CART_ABANDONMENT,
                "Cart reminders",
                NotificationManager.IMPORTANCE_DEFAULT
            ).apply { description = "Nudges when you've left items in your cart" }
        )

        manager.createNotificationChannel(
            NotificationChannel(
                CHANNEL_ORDER_UPDATES,
                "Order updates",
                NotificationManager.IMPORTANCE_HIGH
            ).apply { description = "Order accepted, preparing, ready, and delivery status changes" }
        )
    }

    /** Requests POST_NOTIFICATIONS at runtime on Android 13+ (no-op below that). */
    fun requestPermissionIfNeeded(activity: Activity) {
        ensureChannels(activity)
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
            val granted = ContextCompat.checkSelfPermission(
                activity, Manifest.permission.POST_NOTIFICATIONS
            ) == PackageManager.PERMISSION_GRANTED
            if (!granted) {
                ActivityCompat.requestPermissions(
                    activity,
                    arrayOf(Manifest.permission.POST_NOTIFICATIONS),
                    REQUEST_CODE_NOTIF_PERMISSION
                )
            }
        }
    }

    private fun hasPermission(context: Context): Boolean {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.TIRAMISU) return true
        return ContextCompat.checkSelfPermission(
            context, Manifest.permission.POST_NOTIFICATIONS
        ) == PackageManager.PERMISSION_GRANTED
    }

    /**
     * Whether the user can currently receive notifications at all — covers
     * both the Android 13+ runtime permission AND the user having disabled
     * notifications for the app entirely from system settings (which
     * [hasPermission]'s plain permission check doesn't catch on all OEMs).
     * Used by [com.anydrop.food.ui.common.NotificationPermissionDialog] to
     * decide whether the "want to stay updated?" prompt should even show.
     */
    fun areNotificationsEnabled(context: Context): Boolean {
        return NotificationManagerCompat.from(context).areNotificationsEnabled()
    }

    private fun openHomeIntent(context: Context): PendingIntent {
        val intent = Intent(context, HomeActivity::class.java).apply {
            flags = Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TOP
        }
        val flags = PendingIntent.FLAG_UPDATE_CURRENT or
            (if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M) PendingIntent.FLAG_IMMUTABLE else 0)
        return PendingIntent.getActivity(context, 0, intent, flags)
    }

    /**
     * Generic link-tap routing (closes the gap flagged in doc 66's "still
     * open" list). Admin broadcasts (and any future promo push) may carry
     * a `link_url` — see `backend/admin/broadcast.php`'s kdoc, which rides
     * in the FCM `data` payload as `data.link`. Decision made here:
     * **external browser via `Intent.ACTION_VIEW`**, not an in-app WebView
     * — this app has no generic "open this arbitrary URL" screen, and
     * standing one up just for this is more than the feature needs.
     *
     * Only `http`/`https` schemes are honored — a push payload is
     * server-controlled today (admin-only), but treating it as arbitrary
     * external input and restricting the scheme costs nothing and avoids
     * a malformed/malicious `link` value resolving to an unexpected
     * intent (e.g. `intent://`, `market://`, `file://`).
     *
     * Falls back to [openHomeIntent] when [linkUrl] is null, blank, or
     * fails validation — same behavior as before this existed.
     */
    private fun openLinkOrHomeIntent(context: Context, linkUrl: String?): PendingIntent {
        val validated = linkUrl?.trim()?.takeIf {
            it.isNotEmpty() && (it.startsWith("http://") || it.startsWith("https://"))
        }
        if (validated == null) return openHomeIntent(context)

        val intent = Intent(Intent.ACTION_VIEW, android.net.Uri.parse(validated)).apply {
            flags = Intent.FLAG_ACTIVITY_NEW_TASK
        }
        val flags = PendingIntent.FLAG_UPDATE_CURRENT or
            (if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M) PendingIntent.FLAG_IMMUTABLE else 0)
        return PendingIntent.getActivity(context, 0, intent, flags)
    }

    /**
     * Shows an offer/discount notification. If [imageUrl] is null or fails to
     * load, falls back to a plain text notification — this is what covers
     * the "with image, without image" requirement.
     *
     * [linkUrl] is optional (present for an admin broadcast's `link_url` —
     * see [openLinkOrHomeIntent]'s kdoc). When absent, tapping opens Home,
     * same as before this parameter existed.
     */
    fun showOfferNotification(context: Context, title: String, message: String, imageUrl: String?, linkUrl: String? = null) {
        if (!hasPermission(context)) return
        ensureChannels(context)

        val bigPicture: Bitmap? = imageUrl?.takeIf { it.isNotBlank() }?.let { safeDownloadBitmap(it) }

        val builder = NotificationCompat.Builder(context, CHANNEL_OFFERS)
            .setSmallIcon(R.drawable.ic_notification)
            .setContentTitle(title)
            .setContentText(message)
            .setAutoCancel(true)
            .setContentIntent(openLinkOrHomeIntent(context, linkUrl))
            .setPriority(NotificationCompat.PRIORITY_DEFAULT)

        if (bigPicture != null) {
            builder.setStyle(
                NotificationCompat.BigPictureStyle()
                    .bigPicture(bigPicture)
                    .setSummaryText(message)
            )
        } else {
            builder.setStyle(NotificationCompat.BigTextStyle().bigText(message))
        }

        NotificationManagerCompat.from(context).notify(NOTIF_ID_OFFER, builder.build())
    }

    /** The recurring "your meal is waiting for you" style reminder — text only. */
    fun showMealReminder(context: Context, title: String, message: String) {
        if (!hasPermission(context)) return
        ensureChannels(context)

        val builder = NotificationCompat.Builder(context, CHANNEL_REMINDERS)
            .setSmallIcon(R.drawable.ic_notification)
            .setContentTitle(title)
            .setContentText(message)
            .setStyle(NotificationCompat.BigTextStyle().bigText(message))
            .setAutoCancel(true)
            .setContentIntent(openHomeIntent(context))
            .setPriority(NotificationCompat.PRIORITY_DEFAULT)

        NotificationManagerCompat.from(context).notify(NOTIF_ID_REMINDER, builder.build())
    }

    /** Phase J cart-abandonment nudge — separate id/channel from the meal
     * reminder so the two can't overwrite each other in the status bar if
     * both happen to be pending at once. */
    fun showCartAbandonmentReminder(context: Context, title: String, message: String) {
        if (!hasPermission(context)) return
        ensureChannels(context)

        val builder = NotificationCompat.Builder(context, CHANNEL_CART_ABANDONMENT)
            .setSmallIcon(R.drawable.ic_notification)
            .setContentTitle(title)
            .setContentText(message)
            .setStyle(NotificationCompat.BigTextStyle().bigText(message))
            .setAutoCancel(true)
            .setContentIntent(openHomeIntent(context))
            .setPriority(NotificationCompat.PRIORITY_DEFAULT)

        NotificationManagerCompat.from(context).notify(NOTIF_ID_CART_ABANDONMENT, builder.build())
    }

    /** Posts a system notification for one notification-bell item — the
     * "outside the app" counterpart to a row appearing in
     * [com.anydrop.food.ui.notifications.NotificationListActivity]. Called
     * from [OrderUpdatePollingService] for any bell item not already seen
     * while an order is active. Tapping opens [OrderStatusActivity] for
     * the specific order when the payload's `order_id` is present (which
     * it is for every order-status notification the backend writes —
     * see `notifications.php`'s create_notification() call sites), falling
     * back to Home otherwise. */
    fun showOrderUpdateNotification(context: Context, item: com.anydrop.food.network.NotificationItem) {
        if (!hasPermission(context)) return
        ensureChannels(context)

        val orderId = (item.data?.get("order_id") as? Double)?.toInt()
        val contentIntent = if (orderId != null) {
            Intent(context, com.anydrop.food.ui.orderstatus.OrderStatusActivity::class.java)
                .putExtra(com.anydrop.food.ui.orderstatus.OrderStatusActivity.EXTRA_ORDER_ID, orderId)
                .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TOP)
        } else {
            Intent(context, HomeActivity::class.java)
                .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TOP)
        }
        val flags = PendingIntent.FLAG_UPDATE_CURRENT or
            (if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M) PendingIntent.FLAG_IMMUTABLE else 0)
        val pendingIntent = PendingIntent.getActivity(context, NOTIF_ID_ORDER_UPDATE_BASE + item.id, contentIntent, flags)

        val builder = NotificationCompat.Builder(context, CHANNEL_ORDER_UPDATES)
            .setSmallIcon(R.drawable.ic_notification)
            .setContentTitle(item.title)
            .setContentText(item.body ?: "")
            .setStyle(NotificationCompat.BigTextStyle().bigText(item.body ?: ""))
            .setAutoCancel(true)
            .setContentIntent(pendingIntent)
            .setPriority(NotificationCompat.PRIORITY_HIGH)

        NotificationManagerCompat.from(context).notify(NOTIF_ID_ORDER_UPDATE_BASE + item.id, builder.build())
    }

    private fun safeDownloadBitmap(urlString: String): Bitmap? {
        return try {
            val connection = URL(urlString).openConnection()
            connection.connectTimeout = 4000
            connection.readTimeout = 4000
            connection.getInputStream().use { android.graphics.BitmapFactory.decodeStream(it) }
        } catch (e: Exception) {
            null
        }
    }
}
