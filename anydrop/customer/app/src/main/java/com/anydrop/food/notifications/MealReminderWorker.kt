package com.anydrop.food.notifications

import android.content.Context
import androidx.work.Worker
import androidx.work.WorkerParameters

/**
 * Fired by WorkManager at each scheduled slot (lunch / dinner). Reads which
 * slot it is from input data and shows the matching copy.
 */
class MealReminderWorker(context: Context, params: WorkerParameters) : Worker(context, params) {

    override fun doWork(): Result {
        val slot = inputData.getString(KEY_SLOT) ?: SLOT_LUNCH
        val (title, message) = when (slot) {
            SLOT_DINNER -> "Dinner time! \uD83C\uDF7D\uFE0F" to "Your meal is waiting for you — order now on Anydrop."
            else -> "Feeling hungry? \uD83C\uDF5B" to "Your meal is waiting for you — check today's offers on Anydrop."
        }
        NotificationHelper.showMealReminder(applicationContext, title, message)
        return Result.success()
    }

    companion object {
        const val KEY_SLOT = "slot"
        const val SLOT_LUNCH = "lunch"
        const val SLOT_DINNER = "dinner"
    }
}
