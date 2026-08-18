package com.anydrop.restaurant.service

import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.media.AudioAttributes
import android.media.RingtoneManager
import android.os.Build
import androidx.core.app.NotificationCompat
import com.anydrop.restaurant.R
import com.anydrop.restaurant.network.Order
import com.anydrop.restaurant.ui.main.MainActivity
import com.anydrop.restaurant.ui.orderdetail.OrderDetailActivity
import com.anydrop.restaurant.ui.orders.NewOrderAlarmActivity

/**
 * Real fix for "sound sometimes plays, sometimes doesn't, and nothing at
 * all once the app is closed" — the previous approach (`NewOrderAlertSound`,
 * a raw `MediaPlayer` played from `OrdersFragment`'s poll loop) had two
 * fundamental problems no amount of tweaking could fix:
 *
 * 1. It only ran while `OrdersFragment`'s view existed — closing the app
 *    (or even just navigating to a different tab, on some lifecycle
 *    timings) stopped the polling coroutine entirely, so there was
 *    nothing left to ever detect a new order and fire the alert.
 * 2. A raw `MediaPlayer` set to `USAGE_ALARM` only plays through the
 *    device's alarm volume stream — inconsistent vibration and "no sound
 *    at all" is consistent with that stream being low/muted on a phone
 *    that's never used an alarm, independent of anything in the code.
 *
 * The real, standard Android mechanism for "alert the user reliably, even
 * when the app isn't open" is a **notification posted through a
 * NotificationChannel with sound + vibration configured on the channel
 * itself**. From Android 8 (Oreo) onward, a channel's sound/vibration
 * settings are what actually get honored — the moment
 * `NotificationCompat.Builder` fires through `notify()`, the OS plays
 * whatever the channel says, using the *notification* stream (governed by
 * the phone's regular ringer/notification volume — the one people
 * actually keep audible — not the alarm stream). This is paired with
 * `OrderPollingService` (a foreground service) so the polling loop itself
 * survives the app being closed, not just the sound mechanism.
 *
 * Channel settings are immutable after first creation on a given device —
 * changing `IMPORTANCE_HIGH`/sound/vibration here after users already
 * have the app installed requires deleting and recreating the channel
 * under a new ID, which is why `CHANNEL_ID` has a version suffix.
 */
object OrderNotificationHelper {

    private const val CHANNEL_ID_NEW_ORDER = "new_order_alerts_v1"
    private const val CHANNEL_ID_MONITORING = "order_monitoring_v1"

    private val VIBRATION_PATTERN = longArrayOf(0, 400, 200, 400, 200, 400)

    /** Safe to call repeatedly — `createNotificationChannel` is a no-op if
     * the channel already exists with the same ID. Called once from
     * `OrderPollingService.onCreate()`, before anything tries to post to
     * either channel. */
    fun ensureChannels(context: Context) {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) return
        val manager = context.getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager

        val alertChannel = NotificationChannel(
            CHANNEL_ID_NEW_ORDER,
            "New order alerts",
            NotificationManager.IMPORTANCE_HIGH
        ).apply {
            description = "Sound + vibration alert when a new order comes in"
            enableVibration(true)
            vibrationPattern = VIBRATION_PATTERN
            val soundUri = RingtoneManager.getDefaultUri(RingtoneManager.TYPE_NOTIFICATION)
            setSound(
                soundUri,
                AudioAttributes.Builder()
                    .setUsage(AudioAttributes.USAGE_NOTIFICATION_EVENT)
                    .setContentType(AudioAttributes.CONTENT_TYPE_SONIFICATION)
                    .build()
            )
            setBypassDnd(false)
            lockscreenVisibility = android.app.Notification.VISIBILITY_PUBLIC
        }
        manager.createNotificationChannel(alertChannel)

        // Separate low-importance, silent channel for the persistent
        // "watching for orders" foreground-service notification — this one
        // must NOT make noise every time it updates.
        val monitoringChannel = NotificationChannel(
            CHANNEL_ID_MONITORING,
            "Order monitoring status",
            NotificationManager.IMPORTANCE_LOW
        ).apply {
            description = "Persistent icon shown while watching for new orders in the background"
            setSound(null, null)
            enableVibration(false)
        }
        manager.createNotificationChannel(monitoringChannel)
    }

    /** The "new order" alert. Two things fire together:
     *
     * 1. A normal heads-up notification (sound/vibration from the channel,
     *    [VIBRATION_PATTERN] belt-and-braces on the notification itself) —
     *    same as before, visible in the shade, tappable any time.
     * 2. A **full-screen intent** to [NewOrderAlarmActivity] — the
     *    incoming-call-style screen that rings + vibrates *continuously*
     *    until the owner explicitly views or dismisses the order, per
     *    app-owner feedback that a single notification sound is too easy
     *    to miss in a busy kitchen. The OS shows this screen directly
     *    (even over the lock screen) when the device is locked/idle; when
     *    the phone is actively in use it's shown as a heads-up
     *    notification instead and only opens the ringing screen if tapped
     *    — that's standard Android full-screen-intent behavior, same as
     *    incoming calls.
     *
     * Tapping the plain notification (not the full-screen ringing screen)
     * still opens the order directly if there's exactly one, or the
     * Orders tab (via MainActivity) if there's more than one. */
    fun showNewOrderAlert(context: Context, newOrders: List<Order>) {
        if (newOrders.isEmpty()) return

        val contentIntent = if (newOrders.size == 1) {
            Intent(context, OrderDetailActivity::class.java)
                .putExtra(OrderDetailActivity.EXTRA_ORDER_ID, newOrders.first().id)
        } else {
            Intent(context, MainActivity::class.java)
        }.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TOP)

        val pendingIntent = PendingIntent.getActivity(
            context,
            0,
            contentIntent,
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
        )

        val title = if (newOrders.size == 1) "New order received" else "${newOrders.size} new orders received"
        val text = if (newOrders.size == 1) {
            "Order ${newOrders.first().orderCode} — ₹${"%.0f".format(newOrders.first().grandTotal)}"
        } else {
            "Tap to view them in the Orders tab"
        }

        val alarmScreenIntent = Intent(context, NewOrderAlarmActivity::class.java).apply {
            if (newOrders.size == 1) {
                putExtra(NewOrderAlarmActivity.EXTRA_ORDER_ID, newOrders.first().id)
                putExtra(NewOrderAlarmActivity.EXTRA_ORDER_CODE, newOrders.first().orderCode)
                putExtra(
                    NewOrderAlarmActivity.EXTRA_ORDER_TOTAL_TEXT,
                    "₹${"%.0f".format(newOrders.first().grandTotal)}"
                )
            }
            addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TOP)
        }
        val fullScreenPendingIntent = PendingIntent.getActivity(
            context,
            1,
            alarmScreenIntent,
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
        )

        val notification = NotificationCompat.Builder(context, CHANNEL_ID_NEW_ORDER)
            .setSmallIcon(R.drawable.ic_notification)
            .setContentTitle(title)
            .setContentText(text)
            .setPriority(NotificationCompat.PRIORITY_HIGH)
            .setCategory(NotificationCompat.CATEGORY_ALARM)
            .setAutoCancel(true)
            .setContentIntent(pendingIntent)
            .setFullScreenIntent(fullScreenPendingIntent, true)
            // Belt-and-braces alongside the channel's own vibration
            // pattern — some OEM skins only honor a pattern set directly
            // on the notification itself pre-Android-13 despite channel
            // settings technically taking precedence from Oreo onward.
            .setVibrate(VIBRATION_PATTERN)
            .build()

        val manager = context.getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager
        // A stable-ish id so a rapid burst of new orders updates one
        // notification rather than stacking unboundedly, while still
        // being distinct from the foreground service's own notification id.
        manager.notify(NEW_ORDER_NOTIFICATION_ID, notification)
    }

    /** The low-priority "Anydrop Restaurant is watching for new orders"
     * notification `OrderPollingService` must show to legally run in the
     * foreground (`startForeground()` requires one). */
    fun buildMonitoringNotification(context: Context): android.app.Notification {
        val openAppIntent = PendingIntent.getActivity(
            context,
            0,
            Intent(context, MainActivity::class.java).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TOP),
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
        )
        return NotificationCompat.Builder(context, CHANNEL_ID_MONITORING)
            .setSmallIcon(R.drawable.ic_notification)
            .setContentTitle("Anydrop Restaurant")
            .setContentText("Watching for new orders")
            .setPriority(NotificationCompat.PRIORITY_LOW)
            .setOngoing(true)
            .setContentIntent(openAppIntent)
            .build()
    }

    const val MONITORING_NOTIFICATION_ID = 1001
    private const val NEW_ORDER_NOTIFICATION_ID = 1002
}
