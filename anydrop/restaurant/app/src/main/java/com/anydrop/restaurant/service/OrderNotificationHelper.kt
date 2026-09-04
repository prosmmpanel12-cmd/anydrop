package com.anydrop.restaurant.service

import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.media.AudioAttributes
import android.media.MediaPlayer
import android.media.RingtoneManager
import android.os.Build
import android.os.VibrationEffect
import android.os.Vibrator
import android.os.VibratorManager
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
    private const val CHANNEL_ID_GENERAL = "general_notifications_v1"

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

        // Quiet, normal-priority channel for the notification-bell items
        // (order accepted/rejected/ready, promo, system, security) — the
        // "outside the app" counterpart to the in-app bell list. Deliberately
        // NOT the loud alarm channel above: a fresh *pending* order already
        // gets the full ringing treatment via showNewOrderAlert; this
        // channel is for everything else the bell shows, at normal
        // heads-up/shade behavior with the device's default notification
        // sound, same as most apps' regular notifications.
        val generalChannel = NotificationChannel(
            CHANNEL_ID_GENERAL,
            "Order & account updates",
            NotificationManager.IMPORTANCE_DEFAULT
        ).apply {
            description = "Order status changes, promos, and account notifications"
            enableVibration(true)
            val soundUri = RingtoneManager.getDefaultUri(RingtoneManager.TYPE_NOTIFICATION)
            setSound(
                soundUri,
                AudioAttributes.Builder()
                    .setUsage(AudioAttributes.USAGE_NOTIFICATION_EVENT)
                    .setContentType(AudioAttributes.CONTENT_TYPE_SONIFICATION)
                    .build()
            )
        }
        manager.createNotificationChannel(generalChannel)
    }

    /** Posts a normal (non-looping, non-alarm) system notification for one
     * notification-bell item — the "outside the app" counterpart to a row
     * appearing in [com.anydrop.restaurant.ui.notifications.NotificationListActivity].
     * Called from `OrderPollingService`'s poll loop for any bell item not
     * already seen. Skipped for items that look like a brand-new pending
     * order (title starting "New order") since [showNewOrderAlert] already
     * covers that case loudly elsewhere in the same poll cycle — posting
     * both would just double up on the same event.
     *
     * Tapping it opens [NotificationListActivity] directly (not a specific
     * order screen) — the bell list itself handles the deep-link once
     * opened, same as tapping a row there does, so there's no need to
     * duplicate that per-type routing logic here.
     *
     * Exception: [linkUrl], when present, overrides that and opens the
     * link in an external browser instead — this is how an admin
     * broadcast's `link_url` (rides in the FCM `data` payload as
     * `data.link`, see `backend/admin/broadcast.php`'s kdoc) does
     * something on tap, closing the gap flagged in doc 66's "still open"
     * list. External browser was chosen over an in-app WebView since this
     * app has no generic "open arbitrary URL" screen and standing one up
     * just for this is more than the feature needs. Only `http`/`https`
     * is honored — a malformed/unexpected scheme (`intent://`,
     * `market://`, `file://`) falls back to the bell-list intent above. */
    fun showBellNotification(context: Context, item: com.anydrop.restaurant.network.NotificationItem, linkUrl: String? = null) {
        val validatedLink = linkUrl?.trim()?.takeIf {
            it.isNotEmpty() && (it.startsWith("http://") || it.startsWith("https://"))
        }
        val contentIntent = if (validatedLink != null) {
            Intent(Intent.ACTION_VIEW, android.net.Uri.parse(validatedLink))
                .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
        } else {
            Intent(
                context,
                com.anydrop.restaurant.ui.notifications.NotificationListActivity::class.java
            ).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TOP)
        }

        val pendingIntent = PendingIntent.getActivity(
            context,
            BELL_NOTIFICATION_ID_BASE + item.id,
            contentIntent,
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
        )

        val notification = NotificationCompat.Builder(context, CHANNEL_ID_GENERAL)
            .setSmallIcon(R.drawable.ic_notification)
            .setContentTitle(item.title)
            .setContentText(item.body ?: "")
            .setStyle(NotificationCompat.BigTextStyle().bigText(item.body ?: ""))
            .setAutoCancel(true)
            .setContentIntent(pendingIntent)
            .setPriority(NotificationCompat.PRIORITY_DEFAULT)
            .build()

        val manager = context.getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager
        // Distinct id per item (offset well clear of the fixed ids below)
        // so a burst of several updates stacks as separate notifications
        // instead of overwriting one another.
        manager.notify(BELL_NOTIFICATION_ID_BASE + item.id, notification)
    }

    /** The "new order" alert. Three things fire together:
     *
     * 1. A normal heads-up notification (visible in the shade, tappable
     *    any time) with a "Dismiss" action so the ringing loop below can
     *    be silenced without opening the app at all.
     * 2. [startRingingLoop] — a **looping** alarm sound + continuous
     *    vibration that starts the instant this fires and keeps going
     *    until [stopRingingLoop] is called, independent of whether any
     *    activity is on screen. This is the actual "rings until you go in
     *    and do something" behavior the app owner asked for. It does NOT
     *    depend on the full-screen intent below — real-device testing
     *    (2026-08-18) confirmed Android only auto-launches a full-screen
     *    intent when the device is locked/screen-off; with the phone
     *    unlocked and another app in the foreground (the common case —
     *    staff carrying the phone around a kitchen) the OS just shows the
     *    heads-up notification and nothing else fires, so relying on the
     *    activity alone left orders silent after one beep in that case.
     * 3. A full-screen intent to [NewOrderAlarmActivity] — still sent, so
     *    the incoming-call-style screen *does* pop up automatically when
     *    the device happens to be locked, same as before. It no longer
     *    starts its own separate sound/vibration; it just shows the order
     *    and its buttons stop the same central loop from (2).
     *
     * Tapping the plain notification opens the order directly if there's
     * exactly one, or the Orders tab (via MainActivity) if more than one —
     * either path stops the ringing loop (see `OrderDetailActivity`/
     * `MainActivity`'s `onResume`). */
    fun showNewOrderAlert(context: Context, newOrders: List<Order>) {
        if (newOrders.isEmpty()) return
        val appContext = context.applicationContext

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

        val dismissPendingIntent = PendingIntent.getBroadcast(
            context,
            2,
            Intent(context, DismissOrderAlertReceiver::class.java),
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
            .addAction(R.drawable.ic_notification, "Dismiss", dismissPendingIntent)
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

        startRingingLoop(appContext)
    }

    // --- Central looping ringtone + vibration -------------------------
    // Lives here (not in NewOrderAlarmActivity) specifically so it starts
    // the moment a new-order notification is posted, regardless of
    // whether the OS actually shows the full-screen activity — see the
    // kdoc on showNewOrderAlert for why that distinction matters. Backed
    // by application context, so it keeps running independent of any one
    // Activity's lifecycle; OrderPollingService (which posts the
    // notification) is itself a long-lived foreground service, so the
    // process this lives in is the same one already being kept alive.
    private var ringtoneMediaPlayer: MediaPlayer? = null
    private var ringtoneVibrator: Vibrator? = null

    private fun startRingingLoop(appContext: Context) {
        if (ringtoneMediaPlayer != null) return // already ringing for an earlier undismissed order

        ringtoneMediaPlayer = MediaPlayer().apply {
            setAudioAttributes(
                AudioAttributes.Builder()
                    .setUsage(AudioAttributes.USAGE_ALARM)
                    .setContentType(AudioAttributes.CONTENT_TYPE_SONIFICATION)
                    .build()
            )
            isLooping = true
            try {
                // Bundled tone (res/raw/alarm_tone.wav) rather than the
                // device's system default ringtone/alarm URI — real-device
                // testing (2026-08-18) found the system-URI approach came
                // out vibration-only on at least one phone: if the OEM has
                // no ringtone assigned for TYPE_ALARM,
                // getActualDefaultRingtoneUri can return a URI that fails
                // silently in setDataSource/prepare (swallowed by the
                // catch below), leaving only the vibration pattern
                // running. A packaged raw resource always resolves.
                val afd = appContext.resources.openRawResourceFd(com.anydrop.restaurant.R.raw.alarm_tone)
                setDataSource(afd.fileDescriptor, afd.startOffset, afd.length)
                afd.close()
                prepare()
                start()
            } catch (e: Exception) {
                // Shouldn't happen with a bundled resource, but a failure
                // here still shouldn't crash anything — vibration below
                // still fires either way.
            }
        }

        val pattern = longArrayOf(0, 500, 300, 500, 300)
        val vibrator = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
            (appContext.getSystemService(Context.VIBRATOR_MANAGER_SERVICE) as VibratorManager).defaultVibrator
        } else {
            @Suppress("DEPRECATION")
            appContext.getSystemService(Context.VIBRATOR_SERVICE) as Vibrator
        }
        ringtoneVibrator = vibrator
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            vibrator.vibrate(VibrationEffect.createWaveform(pattern, 0))
        } else {
            @Suppress("DEPRECATION")
            vibrator.vibrate(pattern, 0)
        }
    }

    /** Stops the looping sound/vibration from [startRingingLoop] and
     * cancels the still-visible new-order notification. Safe to call any
     * time, including when nothing is ringing. Called from:
     * `OrderDetailActivity`/`MainActivity`'s `onResume` (opening any order
     * counts as "went in and did something"), `NewOrderAlarmActivity`'s
     * buttons, and [DismissOrderAlertReceiver]. */
    fun stopRingingLoop(context: Context) {
        ringtoneMediaPlayer?.apply {
            try {
                if (isPlaying) stop()
            } catch (e: IllegalStateException) {
                // Already stopped/released — nothing to do.
            }
            release()
        }
        ringtoneMediaPlayer = null
        ringtoneVibrator?.cancel()
        ringtoneVibrator = null

        val manager = context.getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager
        manager.cancel(NEW_ORDER_NOTIFICATION_ID)
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

    /** Base id for [showBellNotification] — actual id is this + the bell
     * item's own id, keeping every bell notification's id well clear of
     * the two fixed ids above and distinct from one another. */
    private const val BELL_NOTIFICATION_ID_BASE = 10000

    /** Whether this app is currently allowed to launch [NewOrderAlarmActivity]
     * as a full-screen intent. On Android 14+ (API 34) this is `false` by
     * default for a freshly-installed app even though `USE_FULL_SCREEN_INTENT`
     * is declared in the manifest — the OS still requires the user to flip
     * a dedicated toggle in Settings before it'll actually launch the
     * activity. Below API 34 the manifest permission alone is enough, so
     * this always returns true there. When this is false, `notify()` still
     * posts the normal heads-up notification (what the app owner reported
     * seeing) — it just silently skips opening the ringing screen, with no
     * error anywhere, which is why this needs an explicit check + prompt
     * rather than assuming the manifest permission was sufficient. */
    fun hasFullScreenIntentPermission(context: Context): Boolean {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.UPSIDE_DOWN_CAKE) return true
        val manager = context.getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager
        return manager.canUseFullScreenIntent()
    }

    /** Opens the exact Settings screen where the user grants the toggle
     * checked by [hasFullScreenIntentPermission] — there's no runtime
     * permission dialog for this one, only this settings page. */
    fun openFullScreenIntentSettings(context: Context) {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.UPSIDE_DOWN_CAKE) return
        val intent = Intent(android.provider.Settings.ACTION_MANAGE_APP_USE_FULL_SCREEN_INTENT).apply {
            data = android.net.Uri.parse("package:${context.packageName}")
            addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
        }
        context.startActivity(intent)
    }
}

/** Backs the "Dismiss" action button on the new-order notification —
 * exists as its own tiny receiver (rather than reusing an Activity) so the
 * ringing loop can be silenced straight from the notification shade,
 * including from the lock screen, without opening the app at all. */
class DismissOrderAlertReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent) {
        OrderNotificationHelper.stopRingingLoop(context.applicationContext)
    }
}
