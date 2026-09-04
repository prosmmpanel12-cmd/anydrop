package com.anydrop.restaurant.ui.orders

import android.content.Intent
import android.os.Build
import android.os.Bundle
import android.view.WindowManager
import androidx.appcompat.app.AppCompatActivity
import com.anydrop.restaurant.databinding.ActivityNewOrderAlarmBinding
import com.anydrop.restaurant.service.OrderNotificationHelper
import com.anydrop.restaurant.ui.orderdetail.OrderDetailActivity

/**
 * The full-screen "incoming order" screen — the Android equivalent of an
 * incoming-call UI. Launched via NotificationCompat.setFullScreenIntent
 * from OrderNotificationHelper, but only automatically appears when the
 * device is locked/screen-off (standard Android full-screen-intent
 * behavior); otherwise the user has to tap the heads-up notification.
 *
 * This screen does **not** own the ringing sound/vibration itself — that
 * lives centrally in `OrderNotificationHelper.startRingingLoop`, started
 * the moment the notification is posted, so the alarm still rings even in
 * the (very common) case where this screen never gets shown at all — see
 * that class's kdoc on `showNewOrderAlert` for why. This activity's job is
 * just: show the order, and give two ways to stop the central loop.
 */
class NewOrderAlarmActivity : AppCompatActivity() {

    companion object {
        const val EXTRA_ORDER_ID = "extra_order_id"
        const val EXTRA_ORDER_CODE = "extra_order_code"
        const val EXTRA_ORDER_TOTAL_TEXT = "extra_order_total_text"
    }

    private lateinit var binding: ActivityNewOrderAlarmBinding

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)

        // Show over the lock screen and wake the device, same flags an
        // incoming-call UI uses.
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O_MR1) {
            setShowWhenLocked(true)
            setTurnScreenOn(true)
        } else {
            @Suppress("DEPRECATION")
            window.addFlags(
                WindowManager.LayoutParams.FLAG_SHOW_WHEN_LOCKED or
                    WindowManager.LayoutParams.FLAG_TURN_SCREEN_ON
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
            OrderNotificationHelper.stopRingingLoop(applicationContext)
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
            OrderNotificationHelper.stopRingingLoop(applicationContext)
            finish()
        }
    }

    /** Back press must register as an explicit choice too (same as the
     * Dismiss button) rather than silently leaving the central loop
     * ringing with no visible screen. */
    override fun onBackPressed() {
        OrderNotificationHelper.stopRingingLoop(applicationContext)
        finish()
    }
}
