package com.anydrop.food.util

import com.google.android.gms.maps.model.LatLng

/**
 * Phase 3 R5 follow-up (deep-plan §14-15) — decodes the
 * `overview_polyline.points` string Google's Directions API returns
 * (route.php passes it straight through as-is) into a list of LatLng
 * points OrderStatusActivity can draw as a Polyline.
 *
 * Hand-rolled rather than adding the `play-services-maps-utils`
 * dependency (which has `PolyUtil.decode()` built in) — this project
 * already pulls in `play-services-maps` alone for the pin-drop screen
 * (see MapPinDropActivity's kdoc) and a whole extra library for one
 * ~20-line, decades-old, well-specified algorithm isn't worth the
 * added dependency surface. This is Google's own published decoding
 * algorithm (the same one used across essentially every Directions API
 * client), not a novel implementation.
 */
object PolylineDecoder {

    fun decode(encoded: String): List<LatLng> {
        val points = mutableListOf<LatLng>()
        var index = 0
        val len = encoded.length
        var lat = 0
        var lng = 0

        while (index < len) {
            var shift = 0
            var result = 0
            var b: Int
            do {
                b = encoded[index++].code - 63
                result = result or ((b and 0x1f) shl shift)
                shift += 5
            } while (b >= 0x20)
            val dLat = if (result and 1 != 0) (result shr 1).inv() else (result shr 1)
            lat += dLat

            shift = 0
            result = 0
            do {
                b = encoded[index++].code - 63
                result = result or ((b and 0x1f) shl shift)
                shift += 5
            } while (b >= 0x20)
            val dLng = if (result and 1 != 0) (result shr 1).inv() else (result shr 1)
            lng += dLng

            points.add(LatLng(lat / 1e5, lng / 1e5))
        }
        return points
    }
}
