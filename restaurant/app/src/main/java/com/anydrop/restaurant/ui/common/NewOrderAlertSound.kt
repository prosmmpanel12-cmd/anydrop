package com.anydrop.restaurant.ui.common

import android.content.Context
import android.media.AudioAttributes
import android.media.MediaPlayer
import android.media.RingtoneManager
import android.os.Build
import android.os.Handler
import android.os.Looper
import android.os.VibrationEffect
import android.os.Vibrator
import android.os.VibratorManager

/**
 * Order Management small addition — "🟢 Loud sound on new order" from
 * docs/18_Restaurant_App_Full_Scope_And_Rating_System.md: "dashboard
 * currently polls silently; needs a distinct notification sound (not the
 * default Toast/system tone) fired when loadOrders()'s poll finds a new
 * pending order."
 *
 * `OrdersFragment` polls every 10s (`POLL_INTERVAL_MS`) while the app is in
 * the foreground — this plays a deliberately more attention-grabbing alert
 * than a quiet system notification blip a busy kitchen could easily miss.
 * Deliberately uses the device's default **alarm** sound (not notification)
 * — alarm tones are conventionally louder/longer and play on the alarm
 * audio stream, which (unlike the notification stream) users don't
 * routinely leave silenced. This is the closest available approximation to
 * a real "urgent new order" tone without bundling a new audio asset — this
 * sandbox has no network access to source one, and no Android SDK to
 * confirm a bundled file plays correctly either (see every prior session's
 * standing build-verification caveat in docs/restorent/00_Status.md).
 * Swap [RingtoneManager.TYPE_ALARM] for a bundled `res/raw` file later if a
 * real branded asset shows up and gets confirmed on a device.
 *
 * Object (not a class) — there's only ever one "currently ringing new-order
 * alert" for the whole app at a time, same shape as this app's other
 * process-wide singletons (`TokenManager`, `ApiClient`).
 */
object NewOrderAlertSound {

    // Long enough to actually get noticed over kitchen noise, short enough
    // that a missed/ignored alert doesn't ring forever — the next poll
    // cycle (10s) will re-evaluate whether new orders are still unseen.
    private const val AUTO_STOP_MS = 8000L
    private val VIBRATION_PATTERN = longArrayOf(0, 400, 200, 400, 200, 400)

    private var mediaPlayer: MediaPlayer? = null
    private var vibrator: Vibrator? = null
    private val stopHandler = Handler(Looper.getMainLooper())
    private var stopRunnable: Runnable? = null

    /** Starts the alert, replacing whatever was already ringing (a fresh
     * batch of new orders arriving mid-alert restarts the clock rather than
     * layering a second sound on top). Every failure path is non-fatal —
     * a missed alert sound must never crash order polling. */
    fun play(context: Context) {
        stop()

        try {
            val alarmUri = RingtoneManager.getActualDefaultRingtoneUri(context, RingtoneManager.TYPE_ALARM)
                ?: RingtoneManager.getDefaultUri(RingtoneManager.TYPE_NOTIFICATION)
            mediaPlayer = MediaPlayer().apply {
                setAudioAttributes(
                    AudioAttributes.Builder()
                        .setUsage(AudioAttributes.USAGE_ALARM)
                        .setContentType(AudioAttributes.CONTENT_TYPE_SONIFICATION)
                        .build()
                )
                setDataSource(context.applicationContext, alarmUri)
                isLooping = true
                prepare()
                start()
            }
        } catch (e: Exception) {
            // No alarm tone resolvable on this device/ROM — vibration below
            // still fires, so the alert isn't entirely silent.
        }

        try {
            val vib = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
                val manager = context.getSystemService(Context.VIBRATOR_MANAGER_SERVICE) as VibratorManager
                manager.defaultVibrator
            } else {
                @Suppress("DEPRECATION")
                context.getSystemService(Context.VIBRATOR_SERVICE) as Vibrator
            }
            vibrator = vib
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
                vib.vibrate(VibrationEffect.createWaveform(VIBRATION_PATTERN, 0))
            } else {
                @Suppress("DEPRECATION")
                vib.vibrate(VIBRATION_PATTERN, 0)
            }
        } catch (e: Exception) {
            // Non-fatal — sound alone (if it started above) still alerts.
        }

        val runnable = Runnable { stop() }
        stopRunnable = runnable
        stopHandler.postDelayed(runnable, AUTO_STOP_MS)
    }

    /** Stops sound + vibration immediately — called on auto-timeout, when
     * the fragment backgrounds/is destroyed, and as soon as the staff
     * actually acts on a new order (accept/reject), since the alert has
     * done its job at that point. Safe to call even if nothing is playing. */
    fun stop() {
        stopRunnable?.let { stopHandler.removeCallbacks(it) }
        stopRunnable = null

        mediaPlayer?.let {
            try {
                if (it.isPlaying) it.stop()
            } catch (e: Exception) {
                // Already stopped/released — fine.
            }
            it.release()
        }
        mediaPlayer = null

        vibrator?.cancel()
        vibrator = null
    }
}
