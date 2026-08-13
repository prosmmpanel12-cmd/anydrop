package com.anydrop.restaurant.util

import java.text.SimpleDateFormat
import java.util.Locale

/**
 * I4 follow-up (docs/16 Part A2) — `scheduled_for` arrives as a
 * MySQL-style "yyyy-MM-dd HH:mm:ss" string; both OrderAdapter's list
 * badge and OrderDetailActivity's detail line need the same "8:30 PM"
 * clock-time rendering. Mirrors the Customer App's util of the same
 * name — restaurant and customer are separate Gradle modules, so this
 * is a deliberate small duplication rather than a shared library.
 */
object ScheduledTimeFormatter {

    private const val STORAGE_FORMAT = "yyyy-MM-dd HH:mm:ss"
    private const val DISPLAY_FORMAT = "h:mm a"

    /** Parses a "yyyy-MM-dd HH:mm:ss" value into a "h:mm a" clock time
     * (e.g. "8:30 PM"). Returns null for a null/blank input (a normal
     * ASAP order) or if parsing fails for any reason. */
    fun formatTime(raw: String?): String? {
        if (raw.isNullOrBlank()) return null
        return try {
            val parsed = SimpleDateFormat(STORAGE_FORMAT, Locale.getDefault()).parse(raw)
            parsed?.let { SimpleDateFormat(DISPLAY_FORMAT, Locale.getDefault()).format(it) }
        } catch (e: Exception) {
            null
        }
    }
}
