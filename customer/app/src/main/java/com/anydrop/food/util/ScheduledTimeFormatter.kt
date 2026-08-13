package com.anydrop.food.util

import java.text.SimpleDateFormat
import java.util.Locale

/**
 * I4 — every `scheduled_for` value on the wire is a MySQL-style
 * "yyyy-MM-dd HH:mm:ss" string (see Order.scheduledFor, CartManager,
 * ScheduleTimeSlotBottomSheet). Displaying it is always the same two
 * steps: parse that storage format, then render as a friendly "8:30 PM"
 * clock time. This used to be copy-pasted in RestaurantDetailActivity's
 * renderEtaRowText() and CheckoutActivity's renderDeliveryTimeRow() —
 * factored out here per both docs' standing note, triggered by
 * OrderStatusActivity needing the same thing as a third copy.
 */
object ScheduledTimeFormatter {

    private const val STORAGE_FORMAT = "yyyy-MM-dd HH:mm:ss"
    private const val DISPLAY_FORMAT = "h:mm a"

    /** Parses a "yyyy-MM-dd HH:mm:ss" value into a "h:mm a" clock time
     * (e.g. "8:30 PM"). Returns null for a null/blank input (a normal
     * "Deliver Now" order) or if parsing fails for any reason — callers
     * should fall back to their own "now"/unscheduled copy in that case. */
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
