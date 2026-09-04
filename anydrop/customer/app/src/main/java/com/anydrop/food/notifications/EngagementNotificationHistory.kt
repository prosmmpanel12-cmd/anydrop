package com.anydrop.food.notifications

import android.content.Context

/**
 * bugs.md #4.2 fix — tracks which [NotificationTemplates.Template] ids have
 * been shown recently so [DailyEngagementScheduler]/[DailyEngagementWorker]
 * never repeats one within [ROTATION_WINDOW_DAYS]. Deliberately a thin
 * SharedPreferences log (same pattern as ActiveAddressManager/VegModeManager
 * — no database needed for a list this small), storing (templateId,
 * shownAtEpochDay) pairs as a single delimited string so a single
 * get/put round-trips the whole history without N separate keys.
 */
object EngagementNotificationHistory {

    private const val PREFS = "anydrop_prefs"
    private const val KEY_HISTORY = "engagement_notif_history"

    /** No template repeats within this many days — with 45 templates and
     * ~4-5 shown/day, a 7-day window comfortably has room to always find
     * an unused one (45 / 5 ≈ 9 days' worth of inventory), while still
     * being short enough that "haven't seen this exact line in over a
     * week" feels fresh rather than the pool just being huge. */
    private const val ROTATION_WINDOW_DAYS = 7

    private data class Entry(val templateId: Int, val epochDay: Long)

    private fun readHistory(context: Context): List<Entry> {
        val raw = context.applicationContext
            .getSharedPreferences(PREFS, Context.MODE_PRIVATE)
            .getString(KEY_HISTORY, null) ?: return emptyList()
        return raw.split(";").mapNotNull { pair ->
            val parts = pair.split(":")
            if (parts.size != 2) return@mapNotNull null
            val id = parts[0].toIntOrNull() ?: return@mapNotNull null
            val day = parts[1].toLongOrNull() ?: return@mapNotNull null
            Entry(id, day)
        }
    }

    private fun writeHistory(context: Context, entries: List<Entry>) {
        val raw = entries.joinToString(";") { "${it.templateId}:${it.epochDay}" }
        context.applicationContext
            .getSharedPreferences(PREFS, Context.MODE_PRIVATE)
            .edit()
            .putString(KEY_HISTORY, raw)
            .apply()
    }

    private fun todayEpochDay(): Long =
        // minSdk is 24 (no desugaring configured), so java.time.LocalDate
        // (API 26+) isn't safe here — plain millis-since-epoch / a day's
        // millis gives the same "which day is this" bucketing without it.
        // Not timezone-perfect at the exact midnight boundary, but that
        // level of precision doesn't matter for a 7-day rotation window.
        System.currentTimeMillis() / (24L * 60 * 60 * 1000)

    /** Template ids shown within the last [ROTATION_WINDOW_DAYS] days —
     * these are off-limits for today's pick. Also prunes anything older
     * than the window while it's here, so the stored history doesn't grow
     * forever. */
    fun recentlyShownIds(context: Context): Set<Int> {
        val today = todayEpochDay()
        val original = readHistory(context)
        val kept = original.filter { today - it.epochDay < ROTATION_WINDOW_DAYS }
        // Prune-on-read: only re-persist if something was actually dropped,
        // avoiding a write on every single read.
        if (kept.size != original.size) {
            writeHistory(context, kept)
        }
        return kept.map { it.templateId }.toSet()
    }

    /** Records that [templateId] was just shown today — call this right
     * after actually posting the notification, not before, so a template
     * chosen-but-never-shown (e.g. notifications disabled,
     * NotificationHelper's permission check bails) doesn't get burned from
     * the rotation for nothing. */
    fun markShown(context: Context, templateId: Int) {
        val today = todayEpochDay()
        val updated = readHistory(context).filter { today - it.epochDay < ROTATION_WINDOW_DAYS } +
            Entry(templateId, today)
        writeHistory(context, updated)
    }
}
