package com.anydrop.food.notifications

import android.content.Context
import androidx.work.ExistingWorkPolicy
import androidx.work.OneTimeWorkRequestBuilder
import androidx.work.WorkManager
import com.anydrop.food.data.CartManager
import java.util.concurrent.TimeUnit

/**
 * Phase J cart-abandonment. Pair of events per the roadmap: "cart
 * non-empty + app backgrounded" → one-shot 15-minute delayed local
 * notification, self-cancels on order-placed or cart-cleared.
 *
 * [AnydropApplication] calls [onAppBackgrounded]/[cancel] from
 * `ProcessLifecycleOwner`'s onStop/onStart. [CartManager.removeCart] (order
 * placed) and any other cart-clearing path don't call [cancel] directly —
 * see [CartAbandonmentWorker]'s kdoc for why a live re-check at fire time
 * covers that more simply than threading a cancel() call through every
 * call site that empties a cart.
 */
object CartAbandonmentScheduler {

    private const val WORK_NAME = "anydrop_cart_abandonment"
    private const val DELAY_MINUTES = 15L

    fun onAppBackgrounded(context: Context) {
        if (!CartManager.hasAnyItems()) return

        val request = OneTimeWorkRequestBuilder<CartAbandonmentWorker>()
            .setInitialDelay(DELAY_MINUTES, TimeUnit.MINUTES)
            .build()

        // REPLACE, not KEEP — a fresh backgrounding restarts the 15-minute
        // window rather than letting an older, possibly-almost-fired timer
        // from an earlier background/foreground cycle win. Matches "15
        // minutes after the cart was last left unattended," not "15
        // minutes after the first time it was ever left unattended today."
        WorkManager.getInstance(context).enqueueUniqueWork(
            WORK_NAME,
            ExistingWorkPolicy.REPLACE,
            request
        )
    }

    fun cancel(context: Context) {
        WorkManager.getInstance(context).cancelUniqueWork(WORK_NAME)
    }
}
