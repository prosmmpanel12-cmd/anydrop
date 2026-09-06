package com.anydrop.food.util

import com.google.android.gms.maps.model.LatLng
import kotlin.math.atan2
import kotlin.math.cos
import kotlin.math.sin
import kotlin.math.sqrt

/**
 * Plan doc 91 (Progress-Trim Route Line + Deviation-Based Recalc) —
 * shared geometry helpers for OrderStatusActivity's two pieces:
 *   - Piece A (progress trim): [nearestPointOnPath] finds where the
 *     rider currently sits relative to the drawn polyline so the
 *     already-travelled portion can be dropped from what's rendered.
 *   - Piece B (deviation recalc): the same result's `distanceMeters`
 *     is what OrderStatusActivity compares against
 *     `deviationThresholdM` to decide whether the rider has drifted
 *     off the drawn route.
 *
 * Hand-rolled rather than pulling in `play-services-maps-utils`
 * (`PolyUtil` has this built in) for the same reason PolylineDecoder
 * already gives for not adding that dependency — this is a well-known,
 * short, exactly-specified bit of geometry, not worth a new library
 * for one function.
 *
 * All math here is planar-equirectangular (see [projectFlat]) rather
 * than true great-circle — over the few-hundred-metre segment lengths
 * a single Directions-API polyline leg covers in a city delivery, the
 * error versus a full spherical calculation is negligible (a handful
 * of centimetres at most), and it keeps nearest-point-on-segment a
 * simple 2D projection instead of spherical trig. Same "good enough at
 * delivery-app scale" reasoning [PolylineDecoder]/`animateRiderMarker`
 * already lean on for their own simplifications.
 */
object RouteGeometry {

    private const val EARTH_RADIUS_M = 6_371_000.0

    /** Result of projecting a point onto a polyline. */
    data class NearestPointResult(
        /** Index into the ORIGINAL point list of the segment this
         * projection falls on — the segment runs from
         * `points[segmentIndex]` to `points[segmentIndex + 1]`. */
        val segmentIndex: Int,
        /** The projected point itself, on the segment (not necessarily
         * one of the original vertices — usually falls between two). */
        val point: LatLng,
        /** Straight-line distance in metres from the query point to
         * [point] — this is the number both trimming's "how far off
         * am I" gate and Piece B's deviation threshold check use. */
        val distanceMeters: Double
    )

    /** Haversine great-circle distance in metres — used for the final
     * reported distance (accurate regardless of how the nearest point
     * itself was found), even though the nearest-point search below
     * uses the flat approximation for the segment-projection math. */
    fun haversineMeters(a: LatLng, b: LatLng): Double {
        val lat1 = Math.toRadians(a.latitude)
        val lat2 = Math.toRadians(b.latitude)
        val dLat = Math.toRadians(b.latitude - a.latitude)
        val dLng = Math.toRadians(b.longitude - a.longitude)
        val h = sin(dLat / 2) * sin(dLat / 2) +
            cos(lat1) * cos(lat2) * sin(dLng / 2) * sin(dLng / 2)
        return 2 * EARTH_RADIUS_M * atan2(sqrt(h), sqrt(1 - h))
    }

    /**
     * Finds the closest point on the polyline [points] to [target],
     * scanning every segment (linear scan — Directions API overview
     * polylines here are at most a few dozen points per leg, so no
     * spatial index is worth the complexity, matching this plan's own
     * "polylines here are short" note).
     *
     * Returns null for a degenerate input (fewer than 2 points).
     */
    fun nearestPointOnPath(points: List<LatLng>, target: LatLng): NearestPointResult? {
        if (points.size < 2) return null

        // Flatten to a local tangent-plane approximation centred near
        // the target, cheap and accurate enough at these distances
        // (see class kdoc) — metres-per-degree varies with latitude
        // for longitude, so scale lng by cos(lat).
        val latToM = EARTH_RADIUS_M * Math.PI / 180.0
        val lngToM = latToM * cos(Math.toRadians(target.latitude))

        fun flatten(p: LatLng): DoubleArray =
            doubleArrayOf(p.longitude * lngToM, p.latitude * latToM)

        val targetFlat = flatten(target)

        var bestSegment = 0
        var bestT = 0.0
        var bestDistSq = Double.MAX_VALUE
        var bestFlat = flatten(points[0])

        for (i in 0 until points.size - 1) {
            val a = flatten(points[i])
            val b = flatten(points[i + 1])
            val abx = b[0] - a[0]
            val aby = b[1] - a[1]
            val lenSq = abx * abx + aby * aby

            val t = if (lenSq > 0.0) {
                (((targetFlat[0] - a[0]) * abx) + ((targetFlat[1] - a[1]) * aby)) / lenSq
            } else {
                0.0
            }.coerceIn(0.0, 1.0)

            val projX = a[0] + abx * t
            val projY = a[1] + aby * t
            val dx = targetFlat[0] - projX
            val dy = targetFlat[1] - projY
            val distSq = dx * dx + dy * dy

            if (distSq < bestDistSq) {
                bestDistSq = distSq
                bestSegment = i
                bestT = t
                bestFlat = doubleArrayOf(projX, projY)
            }
        }

        val projectedLatLng = LatLng(bestFlat[1] / latToM, bestFlat[0] / lngToM)
        return NearestPointResult(
            segmentIndex = bestSegment,
            point = projectedLatLng,
            distanceMeters = haversineMeters(target, projectedLatLng)
        )
    }

    /**
     * Piece A — returns the "remaining route" sub-path starting at
     * [nearest]'s projected position: the projected point itself,
     * followed by every original vertex strictly after its segment.
     * This is what OrderStatusActivity redraws the polyline with each
     * trim tick, so the already-travelled portion visually disappears.
     */
    fun trimToNearest(points: List<LatLng>, nearest: NearestPointResult): List<LatLng> {
        if (points.size < 2) return points
        val remainder = points.subList(nearest.segmentIndex + 1, points.size)
        return listOf(nearest.point) + remainder
    }
}
