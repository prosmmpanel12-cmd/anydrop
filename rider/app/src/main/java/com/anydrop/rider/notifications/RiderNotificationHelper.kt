package com.anydrop.rider.notifications

import android.Manifest
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.os.Build
import androidx.core.app.NotificationCompat
import androidx.core.app.NotificationManagerCompat
import androidx.core.content.ContextCompat
import com.anydrop.rider.R
import com.anydrop.rider.ui.dashboard.RiderDashboardActivity
import com.anydrop.rider.ui.documents.SubmitDocumentsActivity
import com.anydrop.rider.ui.earnings.EarningsActivity
import com.anydrop.rider.ui.pending.ApplicationStatusActivity

/**
 * System (status-bar) notification helper for this app's pushes.
 *
 * Originally account-status only (approved/reactivated/rejected/
 * suspended/documents-verified/documents-rejected — see
 * `backend/admin/riders.php`'s four `create_notification('rider', ...,
 * 'account', ...)` call sites) — see doc 99/100's kdoc for that
 * original, single-channel design and why it was deliberately scoped
 * to just 'account' at the time ("'account' is the only
 * notification_type this app's backend currently sends").
 *
 * That's no longer true: `backend/lib/dispatch.php` ('order' —
 * new/expired/cancelled delivery offers), `backend/admin/orders.php`
 * ('order' — admin force-cancel), `backend/lib/support.php` ('order' —
 * delivery issue reported), and `backend/lib/rider_payout.php`/
 * `orders-deliver.php` ('payout' — earning posted, payout approved/
 * completed/rejected) all already call `create_notification('rider',
 * ...)` with real content today (deep-plan §23's Order and Finance
 * categories) — this session (doc 105) is the first to route them to
 * their own channels instead of every rider push silently rendering
 * through [showAccountNotification] regardless of its actual type,
 * which was a real, pre-existing bug this session's backend additions
 * would otherwise have made worse, not introduced fresh.
 *
 * Three channels now, same "don't let a rider mute delivery offers
 * while muting account alerts" reasoning the customer app's channel
 * split already follows — and payout/earnings pushes get their own
 * channel rather than folding into 'order', since a rider may
 * reasonably want delivery-offer alerts at high volume/urgency but
 * payout alerts at a calmer cadence.
 */
object RiderNotificationHelper {

    const val CHANNEL_ACCOUNT = "anydrop_rider_account"
    const val CHANNEL_ORDER = "anydrop_rider_order_updates"
    const val CHANNEL_PAYOUT = "anydrop_rider_payout_updates"
    private const val NOTIF_ID_ACCOUNT = 6001
    private const val NOTIF_ID_ORDER = 6002
    private const val NOTIF_ID_PAYOUT = 6003

    fun ensureChannels(context: Context) {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) return
        val manager = context.getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager
        manager.createNotificationChannel(
            NotificationChannel(
                CHANNEL_ACCOUNT,
                "Account updates",
                NotificationManager.IMPORTANCE_HIGH
            ).apply { description = "Approval, rejection, suspension, and document review updates" }
        )
        manager.createNotificationChannel(
            NotificationChannel(
                CHANNEL_ORDER,
                "Delivery updates",
                NotificationManager.IMPORTANCE_HIGH
            ).apply { description = "New delivery offers, offer expiry, and order cancellations" }
        )
        manager.createNotificationChannel(
            NotificationChannel(
                CHANNEL_PAYOUT,
                "Earnings & payouts",
                NotificationManager.IMPORTANCE_DEFAULT
            ).apply { description = "Earnings posted and payout status updates" }
        )
    }

    private fun hasPermission(context: Context): Boolean {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.TIRAMISU) return true
        return ContextCompat.checkSelfPermission(
            context, Manifest.permission.POST_NOTIFICATIONS
        ) == PackageManager.PERMISSION_GRANTED
    }

    /** `screen` values this app's backend sends across every
     * `create_notification('rider', ...)` call site today:
     * `dashboard`/`submit_documents` (account, doc 99/100),
     * `order_offer`/`order_status` (order, doc 105), `earnings`
     * (payout, already sent by `rider_payout.php`/`orders-deliver.php`
     * since before this session but never actually routed correctly —
     * see this object's header). `order_offer`/`order_status` both
     * land on [RiderDashboardActivity] rather than a dedicated order-
     * detail screen, since deep-plan §9's Rider Order Detail screen
     * isn't built yet (Android side) — the dashboard is this app's own
     * "what's happening with my current delivery" landing screen in
     * the meantime, same honest-fallback spirit as this function's
     * original account-only design. Anything else (unmapped/malformed)
     * still falls back to [ApplicationStatusActivity], preserving the
     * original account-flow behavior exactly. */
    private fun contentIntentFor(context: Context, screen: String?): PendingIntent {
        val targetClass = when (screen) {
            "dashboard" -> RiderDashboardActivity::class.java
            "submit_documents" -> SubmitDocumentsActivity::class.java
            "order_offer", "order_status" -> RiderDashboardActivity::class.java
            "earnings" -> EarningsActivity::class.java
            else -> ApplicationStatusActivity::class.java
        }
        val intent = Intent(context, targetClass).apply {
            flags = Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TASK
        }
        val flags = PendingIntent.FLAG_UPDATE_CURRENT or
            (if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M) PendingIntent.FLAG_IMMUTABLE else 0)
        return PendingIntent.getActivity(context, 0, intent, flags)
    }

    /** Posts the status-bar counterpart to an account-status push.
     * Text-only (BigTextStyle) — none of the four call sites this
     * routes send an image, unlike the customer app's offer pushes. */
    fun showAccountNotification(context: Context, title: String, body: String, screen: String?) {
        show(context, CHANNEL_ACCOUNT, NOTIF_ID_ACCOUNT, title, body, screen)
    }

    /** Posts the status-bar counterpart to an order/delivery-offer push
     * (deep-plan §23 Assignment + Order categories) — new offer, offer
     * expired, order cancelled by admin, delivery issue reported. */
    fun showOrderNotification(context: Context, title: String, body: String, screen: String?) {
        show(context, CHANNEL_ORDER, NOTIF_ID_ORDER, title, body, screen)
    }

    /** Posts the status-bar counterpart to a payout/earnings push
     * (deep-plan §23 Finance category) — earning posted, payout
     * approved/completed/rejected. */
    fun showPayoutNotification(context: Context, title: String, body: String, screen: String?) {
        show(context, CHANNEL_PAYOUT, NOTIF_ID_PAYOUT, title, body, screen)
    }

    private fun show(
        context: Context,
        channel: String,
        notifId: Int,
        title: String,
        body: String,
        screen: String?
    ) {
        if (!hasPermission(context)) return
        ensureChannels(context)

        // Priority mirrors each channel's own IMPORTANCE (doc 106 fix) —
        // CHANNEL_PAYOUT is IMPORTANCE_DEFAULT precisely because payout
        // updates warrant a calmer cadence than delivery offers (see
        // this object's header); hardcoding PRIORITY_HIGH here for every
        // channel silently undid that distinction on pre-O devices and
        // on O+ devices via NotificationCompat's own priority fallback.
        val priority = if (channel == CHANNEL_PAYOUT) {
            NotificationCompat.PRIORITY_DEFAULT
        } else {
            NotificationCompat.PRIORITY_HIGH
        }

        val builder = NotificationCompat.Builder(context, channel)
            .setSmallIcon(R.drawable.ic_notification)
            .setContentTitle(title)
            .setContentText(body)
            .setStyle(NotificationCompat.BigTextStyle().bigText(body))
            .setAutoCancel(true)
            .setContentIntent(contentIntentFor(context, screen))
            .setPriority(priority)

        NotificationManagerCompat.from(context).notify(notifId, builder.build())
    }
}
