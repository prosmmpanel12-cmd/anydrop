package com.anydrop.food.util

import kotlin.math.atan2
import kotlin.math.cos
import kotlin.math.sin
import kotlin.math.sqrt

/**
 * H6 — Location Picker screen needs a "3.6 km" / "0 m" distance line on
 * each saved address card (screenshot 12), measured from the device's
 * current GPS fix. No backend endpoint returns this (addresses/list.php
 * has no distance concept), so it's computed on-device from each
 * Address's own lat/lng — same values already used for the delivery-radius
 * filtering restaurants/list.php does server-side (see part 9).
 */
object DistanceUtil {

    private const val EARTH_RADIUS_KM = 6371.0

    /** Great-circle distance in kilometers between two lat/lng points. */
    fun km(lat1: Double, lng1: Double, lat2: Double, lng2: Double): Double {
        val dLat = Math.toRadians(lat2 - lat1)
        val dLng = Math.toRadians(lng2 - lng1)
        val a = sin(dLat / 2) * sin(dLat / 2) +
            cos(Math.toRadians(lat1)) * cos(Math.toRadians(lat2)) *
            sin(dLng / 2) * sin(dLng / 2)
        val c = 2 * atan2(sqrt(a), sqrt(1 - a))
        return EARTH_RADIUS_KM * c
    }

    /** Formats like the screenshot: sub-1km as meters ("450 m", "0 m"),
     * otherwise one decimal of km ("3.6 km"). */
    fun formatDistance(km: Double): String {
        return if (km < 1.0) {
            "${(km * 1000).toInt()} m"
        } else {
            "%.1f km".format(km)
        }
    }
}
