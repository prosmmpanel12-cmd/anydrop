package com.anydrop.food.notifications

import android.content.Context
import androidx.work.ExistingPeriodicWorkPolicy
import androidx.work.PeriodicWorkRequestBuilder
import androidx.work.WorkManager
import java.util.Calendar
import java.util.concurrent.TimeUnit

/**
 * Phase J daily engagement — replaces [MealReminderScheduler]'s old fixed
 * two-slots-a-day (lunch/dinner) setup with 5 slots spread across the day,
 * each independently picking an unused [NotificationTemplates.Template] via
 * [DailyEngagementWorker] when it fires. 5 separate daily
 * `PeriodicWorkRequest`s (same one-request-per-slot shape
 * [MealReminderScheduler] already used for its 2) rather than one job that
 * tries to fire 5 times a day itself — WorkManager's periodic work has a
 * 15-minute minimum interval and isn't built for "fire N times within one
 * day, then repeat," so 5 independent daily jobs at fixed times is the
 * straightforward way to hit that shape with what WorkManager actually
 * offers.
 *
 * [MealReminderScheduler] itself is left in place but no longer called from
 * [com.anydrop.food.ui.home.HomeActivity] — this supersedes it. Not deleted
 * outright in case the lunch/dinner-only copy is wanted back for some
 * reason; dead code, harmless if never invoked again.
 */
object DailyEngagementScheduler {

    // Five slots spread through a typical waking day — spaced far enough
    // apart that consecutive notifications don't feel like spam, deliberately
    // avoiding very-early-morning/late-night hours nobody wants a push at.
    private data class Slot(val workName: String, val hour: Int, val minute: Int)

    private val SLOTS = listOf(
        Slot("anydrop_engagement_slot_1", hour = 9, minute = 30),   // mid-morning
        Slot("anydrop_engagement_slot_2", hour = 12, minute = 45),  // lunch
        Slot("anydrop_engagement_slot_3", hour = 16, minute = 0),   // afternoon snack window
        Slot("anydrop_engagement_slot_4", hour = 19, minute = 30),  // dinner
        Slot("anydrop_engagement_slot_5", hour = 21, minute = 45)   // late-evening craving
    )

    fun scheduleDailyEngagement(context: Context) {
        SLOTS.forEach { slot -> schedule(context, slot) }
    }

    fun cancelAll(context: Context) {
        val workManager = WorkManager.getInstance(context)
        SLOTS.forEach { slot -> workManager.cancelUniqueWork(slot.workName) }
    }

    private fun schedule(context: Context, slot: Slot) {
        val initialDelayMs = computeInitialDelayMs(slot.hour, slot.minute)
        val request = PeriodicWorkRequestBuilder<DailyEngagementWorker>(24, TimeUnit.HOURS)
            .setInitialDelay(initialDelayMs, TimeUnit.MILLISECONDS)
            .build()

        WorkManager.getInstance(context).enqueueUniquePeriodicWork(
            slot.workName,
            // KEEP, same reasoning as MealReminderScheduler — safe to call
            // on every app launch without resetting the fire time of an
            // already-scheduled slot.
            ExistingPeriodicWorkPolicy.KEEP,
            request
        )
    }

    private fun computeInitialDelayMs(hour: Int, minute: Int): Long {
        val now = Calendar.getInstance()
        val target = Calendar.getInstance().apply {
            set(Calendar.HOUR_OF_DAY, hour)
            set(Calendar.MINUTE, minute)
            set(Calendar.SECOND, 0)
            set(Calendar.MILLISECOND, 0)
        }
        if (target.before(now)) {
            target.add(Calendar.DAY_OF_MONTH, 1)
        }
        return target.timeInMillis - now.timeInMillis
    }
}
