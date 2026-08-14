package com.anydrop.food.notifications

import android.content.Context
import androidx.work.Worker
import androidx.work.WorkerParameters
import com.anydrop.food.data.CartManager

/**
 * Fires 15 minutes after [CartAbandonmentScheduler.onAppBackgrounded]
 * scheduled it. Re-checks [CartManager.hasAnyItems] live rather than
 * trusting the snapshot from scheduling time — covers "order placed or
 * cart cleared while the app was still backgrounded and never reopened"
 * (a background cart-sync completing, for instance) without needing every
 * cart-clearing call site (checkout success, "clear cart," restaurant
 * switch) to remember to also call [CartAbandonmentScheduler.cancel]. The
 * far more common case — app reopened, cart looked at or cleared there —
 * is already covered by [com.anydrop.food.AnydropApplication]'s onStart
 * calling [CartAbandonmentScheduler.cancel] before this delay even elapses.
 */
class CartAbandonmentWorker(context: Context, params: WorkerParameters) : Worker(context, params) {

    override fun doWork(): Result {
        if (!CartManager.hasAnyItems()) {
            return Result.success()
        }

        val cart = CartManager.getCarts().firstOrNull() ?: return Result.success()
        val itemWord = if (cart.totalItemCount() == 1) "item" else "items"

        NotificationHelper.showCartAbandonmentReminder(
            applicationContext,
            title = "Your cart is waiting \uD83D\uDED2",
            message = "You've got ${cart.totalItemCount()} $itemWord from ${cart.restaurantName} sitting in your cart. Complete your order before it slips away."
        )
        return Result.success()
    }
}
