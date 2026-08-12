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
    private const val NOTIF_ID_OFFER = 1001
    const val NOTIF_ID_REMINDER = 2001

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
     * Shows an offer/discount notification. If [imageUrl] is null or fails to
     * load, falls back to a plain text notification — this is what covers
     * the "with image, without image" requirement.
     */
    fun showOfferNotification(context: Context, title: String, message: String, imageUrl: String?) {
        if (!hasPermission(context)) return
        ensureChannels(context)

        val bigPicture: Bitmap? = imageUrl?.takeIf { it.isNotBlank() }?.let { safeDownloadBitmap(it) }

        val builder = NotificationCompat.Builder(context, CHANNEL_OFFERS)
            .setSmallIcon(R.drawable.ic_notification)
            .setContentTitle(title)
            .setContentText(message)
            .setAutoCancel(true)
            .setContentIntent(openHomeIntent(context))
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
