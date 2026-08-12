package com.anydrop.food.notifications

import android.content.Context
import androidx.work.Data
import androidx.work.ExistingPeriodicWorkPolicy
import androidx.work.PeriodicWorkRequestBuilder
import androidx.work.WorkManager
import java.util.Calendar
import java.util.concurrent.TimeUnit

/**
 * Schedules two fixed-time daily local notifications ("your meal is waiting
 * for you") — one around lunch, one around dinner — using WorkManager so
 * they survive app kills/reboots without needing the app open. Safe to call
 * on every app launch: ExistingPeriodicWorkPolicy.KEEP means it won't
 * re-schedule (and reset the fire time) if already set up.
 */
object MealReminderScheduler {

    private const val WORK_LUNCH = "anydrop_meal_reminder_lunch"
    private const val WORK_DINNER = "anydrop_meal_reminder_dinner"

    fun scheduleDailyReminders(context: Context) {
        schedule(context, WORK_LUNCH, hour = 13, minute = 30, slot = MealReminderWorker.SLOT_LUNCH)
        schedule(context, WORK_DINNER, hour = 20, minute = 30, slot = MealReminderWorker.SLOT_DINNER)
    }

    fun cancelAll(context: Context) {
        WorkManager.getInstance(context).cancelUniqueWork(WORK_LUNCH)
        WorkManager.getInstance(context).cancelUniqueWork(WORK_DINNER)
    }

    private fun schedule(context: Context, workName: String, hour: Int, minute: Int, slot: String) {
        val initialDelayMs = computeInitialDelayMs(hour, minute)
        val input = Data.Builder().putString(MealReminderWorker.KEY_SLOT, slot).build()

        val request = PeriodicWorkRequestBuilder<MealReminderWorker>(24, TimeUnit.HOURS)
            .setInitialDelay(initialDelayMs, TimeUnit.MILLISECONDS)
            .setInputData(input)
            .build()

        WorkManager.getInstance(context).enqueueUniquePeriodicWork(
            workName,
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
