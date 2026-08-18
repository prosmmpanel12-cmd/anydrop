package com.anydrop.restaurant.ui.orders

import android.content.Context
import android.content.Intent
import android.media.AudioAttributes
import android.media.MediaPlayer
import android.media.RingtoneManager
import android.os.Build
import android.os.Bundle
import android.os.VibrationEffect
import android.os.Vibrator
import android.os.VibratorManager
import android.view.WindowManager
import androidx.appcompat.app.AppCompatActivity
import com.anydrop.restaurant.databinding.ActivityNewOrderAlarmBinding
import com.anydrop.restaurant.ui.orderdetail.OrderDetailActivity

/**
 * The "ring until you go in and do something" screen requested for new
 * orders (app-owner feedback, 2026-08-18) — same idea as an incoming-call
 * screen: it shows over the lock screen if needed, keeps a looping alarm
 * sound + continuous vibration going, and only stops when the owner taps
 * "View order" or "Dismiss". A single notification sound (what
 * OrderNotificationHelper's channel plays on its own) is too easy to miss
 * in a busy kitchen; this is the louder, harder-to-ignore version for the
 * same event, launched via that notification's setFullScreenIntent.
 *
 * Deliberately gives two ways to stop the ringing rather than forcing
 * "View order" only — a locked-down screen with no dismiss button risks an
 * ANR-style stuck state if e.g. OrderDetailActivity fails to load, and the
 * back button is disabled below so it can't be swiped away silently
 * without either choice being made.
 */
class NewOrderAlarmActivity : AppCompatActivity() {

    companion object {
        const val EXTRA_ORDER_ID = "extra_order_id"
        const val EXTRA_ORDER_CODE = "extra_order_code"
        const val EXTRA_ORDER_TOTAL_TEXT = "extra_order_total_text"
    }

    private lateinit var binding: ActivityNewOrderAlarmBinding
    private var mediaPlayer: MediaPlayer? = null
    private var vibrator: Vibrator? = null

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)

        // Show over the lock screen and wake the device, same flags an
        // incoming-call UI uses — needed for the screen to actually
        // appear if the phone was locked/asleep when the order came in.
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O_MR1) {
            setShowWhenLocked(true)
            setTurnScreenOn(true)
        } else {
            @Suppress("DEPRECATION")
            window.addFlags(
                WindowManager.LayoutParams.FLAG_SHOW_WHEN_LOCKED or
                    WindowManager.LayoutParams.FLAG_TURN_SCREEN_ON or
                    WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON
            )
        }
        window.addFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON)

        binding = ActivityNewOrderAlarmBinding.inflate(layoutInflater)
        setContentView(binding.root)

        val orderId = intent.getIntExtra(EXTRA_ORDER_ID, 0)
        val orderCode = intent.getStringExtra(EXTRA_ORDER_CODE).orEmpty()
        val totalText = intent.getStringExtra(EXTRA_ORDER_TOTAL_TEXT).orEmpty()
        binding.detailText.text = if (orderCode.isNotEmpty()) {
            "Order $orderCode — $totalText"
        } else {
            "Tap to view your new orders"
        }

        binding.btnViewOrder.setOnClickListener {
            stopRinging()
            if (orderId != 0) {
                startActivity(
                    Intent(this, OrderDetailActivity::class.java)
                        .putExtra(OrderDetailActivity.EXTRA_ORDER_ID, orderId)
                        .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TOP)
                )
            }
            finish()
        }
        binding.btnDismiss.setOnClickListener {
            stopRinging()
            finish()
        }

        startRinging()
    }

    /** Back press alone must not silently dismiss the ringing without
     * registering as a choice — routes it through the same dismiss path
     * as the button so the sound/vibration are always explicitly stopped. */
    override fun onBackPressed() {
        stopRinging()
        finish()
    }

    private fun startRinging() {
        val soundUri = RingtoneManager.getActualDefaultRingtoneUri(this, RingtoneManager.TYPE_ALARM)
            ?: RingtoneManager.getDefaultUri(RingtoneManager.TYPE_NOTIFICATION)
        mediaPlayer = MediaPlayer().apply {
            setAudioAttributes(
                AudioAttributes.Builder()
                    .setUsage(AudioAttributes.USAGE_ALARM)
                    .setContentType(AudioAttributes.CONTENT_TYPE_SONIFICATION)
                    .build()
            )
            isLooping = true
            try {
                setDataSource(this@NewOrderAlarmActivity, soundUri)
                prepare()
                start()
            } catch (e: Exception) {
                // A missing/unreadable ringtone shouldn't crash the alarm
                // screen — vibration below still fires either way.
            }
        }

        val pattern = longArrayOf(0, 500, 300, 500, 300)
        vibrator = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
            (getSystemService(Context.VIBRATOR_MANAGER_SERVICE) as VibratorManager).defaultVibrator
        } else {
            @Suppress("DEPRECATION")
            getSystemService(Context.VIBRATOR_SERVICE) as Vibrator
        }
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            // repeat index 0 → loops the whole pattern continuously until
            // vibrator.cancel() is called in stopRinging().
            vibrator?.vibrate(VibrationEffect.createWaveform(pattern, 0))
        } else {
            @Suppress("DEPRECATION")
            vibrator?.vibrate(pattern, 0)
        }
    }

    private fun stopRinging() {
        mediaPlayer?.apply {
            try {
                if (isPlaying) stop()
            } catch (e: IllegalStateException) {
                // Already stopped/released — nothing to do.
            }
            release()
        }
        mediaPlayer = null
        vibrator?.cancel()
        vibrator = null
    }

    override fun onDestroy() {
        stopRinging()
        super.onDestroy()
    }
}
