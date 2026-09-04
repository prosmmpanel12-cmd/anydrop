package com.anydrop.food.notifications

import android.content.Context
import androidx.work.Worker
import androidx.work.WorkerParameters

/**
 * Fired by [DailyEngagementScheduler] at one of the day's ~4-5 fixed slots.
 * Picks one template not shown in the last
 * [EngagementNotificationHistory]'s rotation window, shows it, and records
 * it as shown. If every template in the pool happens to be within the
 * rotation window (shouldn't happen at 45 templates / ~5 per day / 7-day
 * window, but defensively handled anyway), falls back to the
 * least-recently-shown one rather than skipping the slot entirely — a
 * slightly-reused line beats silently sending nothing.
 */
class DailyEngagementWorker(context: Context, params: WorkerParameters) : Worker(context, params) {

    override fun doWork(): Result {
        val recentlyShown = EngagementNotificationHistory.recentlyShownIds(applicationContext)
        val available = NotificationTemplates.all.filter { it.id !in recentlyShown }

        val chosen = if (available.isNotEmpty()) {
            available.random()
        } else {
            // Defensive fallback — see kdoc above. Shouldn't be reachable
            // with today's pool size/window, but a full pool exhaustion
            // shouldn't silently drop the slot either.
            NotificationTemplates.all.random()
        }

        NotificationHelper.showMealReminder(applicationContext, chosen.title, chosen.message)
        // Recorded only after the show call actually ran — see
        // EngagementNotificationHistory.markShown's kdoc on why this order
        // matters (a permission-blocked notification shouldn't burn the
        // template from rotation for nothing). NotificationHelper's own
        // permission check is silent (no exception), so this still runs
        // every time doWork() does — acceptable: worst case a template is
        // marked "shown" on a device with notifications off, which just
        // means that one device's rotation is slightly conservative, not
        // wrong in a way that affects anyone else.
        EngagementNotificationHistory.markShown(applicationContext, chosen.id)

        return Result.success()
    }
}
