package com.anydrop.restaurant.ui.ordertrack

import android.animation.ValueAnimator
import android.os.Bundle
import android.view.View
import androidx.appcompat.app.AppCompatActivity
import androidx.lifecycle.lifecycleScope
import com.anydrop.restaurant.databinding.ActivityRiderTrackBinding
import com.anydrop.restaurant.network.ApiClient
import com.anydrop.restaurant.network.RestaurantTrackResult
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import com.google.android.gms.maps.CameraUpdateFactory
import com.google.android.gms.maps.GoogleMap
import com.google.android.gms.maps.OnMapReadyCallback
import com.google.android.gms.maps.model.BitmapDescriptorFactory
import com.google.android.gms.maps.model.LatLng
import com.google.android.gms.maps.model.LatLngBounds
import com.google.android.gms.maps.model.Marker
import com.google.android.gms.maps.model.MarkerOptions

/**
 * Restaurant order tracking (plan doc 127 §1, 2026-09-11) — "restorent
 * apne parcel ko track kar shkta hai track order". Polls the new
 * restaurant/orders-track.php every 5s while this screen is open, same
 * cadence as the Customer app's OrderStatusActivity, and draws
 * restaurant/delivery/rider markers with the rider animated (lerped, not
 * jumped) between polls the same way.
 *
 * Deliberately **marker-only** — no route.php-equivalent polyline, no
 * progress-trim, no deviation-triggered recalc. Doc 127 §1's own open
 * question recommends shipping this way first ("a restaurant owner
 * checking 'where's my order' probably just wants confirmation the rider
 * is moving in the right direction, not turn-by-turn precision") and
 * only adding a route line as a fast-follow if the app owner asks for
 * it — so this class is intentionally a much smaller subset of
 * OrderStatusActivity's map logic, not a straight port of the whole
 * thing.
 */
class RiderTrackActivity : AppCompatActivity(), OnMapReadyCallback {

    companion object {
        const val EXTRA_ORDER_ID = "extra_order_id"
        private const val POLL_INTERVAL_MS = 5000L
    }

    private lateinit var binding: ActivityRiderTrackBinding
    private val api by lazy { ApiClient.create(this) }
    private var orderId: Int = 0
    private var polling = true

    private var googleMap: GoogleMap? = null
    private var mapReady = false
    private var restaurantMarker: Marker? = null
    private var deliveryMarker: Marker? = null
    private var riderMarker: Marker? = null
    private var riderMarkerAnimator: ValueAnimator? = null
    private var restaurantLatLng: LatLng? = null
    private var deliveryLatLng: LatLng? = null
    private var boundsEverFit = false

    // So onMapReady() (which can fire after a poll has already landed)
    // can draw the current state immediately instead of waiting up to
    // POLL_INTERVAL_MS — same reasoning as the Customer app's lastTrack.
    private var lastTrack: RestaurantTrackResult? = null

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityRiderTrackBinding.inflate(layoutInflater)
        setContentView(binding.root)

        orderId = intent.getIntExtra(EXTRA_ORDER_ID, 0)
        if (orderId == 0) {
            finish()
            return
        }

        binding.btnBack.setOnClickListener { finish() }

        binding.riderTrackMapView.onCreate(savedInstanceState)
        binding.riderTrackMapView.getMapAsync(this)

        startPolling()
    }

    override fun onResume() {
        super.onResume()
        binding.riderTrackMapView.onResume()
    }

    override fun onPause() {
        super.onPause()
        binding.riderTrackMapView.onPause()
    }

    override fun onStart() {
        super.onStart()
        binding.riderTrackMapView.onStart()
    }

    override fun onStop() {
        super.onStop()
        binding.riderTrackMapView.onStop()
    }

    override fun onLowMemory() {
        super.onLowMemory()
        binding.riderTrackMapView.onLowMemory()
    }

    override fun onSaveInstanceState(outState: Bundle) {
        super.onSaveInstanceState(outState)
        binding.riderTrackMapView.onSaveInstanceState(outState)
    }

    override fun onDestroy() {
        super.onDestroy()
        polling = false
        riderMarkerAnimator?.cancel()
        binding.riderTrackMapView.onDestroy()
    }

    override fun onMapReady(map: GoogleMap) {
        googleMap = map
        mapReady = true
        map.uiSettings.isZoomControlsEnabled = false
        map.uiSettings.isMyLocationButtonEnabled = false
        lastTrack?.let { updateMap(it) }
    }

    private fun startPolling() {
        lifecycleScope.launch {
            while (polling) {
                try {
                    val track = api.trackOrder(orderId).body()?.data
                    if (track != null) {
                        lastTrack = track
                        updateMap(track)
                        // A restaurant has no further use for this screen
                        // once the order's off their plate entirely —
                        // stop polling rather than hammering the endpoint
                        // for an order that's no longer trackable.
                        if (track.status !in setOf("rider_assigned", "picked_up", "out_for_delivery")) {
                            polling = false
                        }
                    }
                } catch (e: Exception) {
                    // Silent — same next-cycle-retries convention as the
                    // Customer app's own poll loop.
                }
                if (!polling) break
                delay(POLL_INTERVAL_MS)
            }
        }
    }

    /** Adds the static restaurant/delivery markers the first time
     * coordinates for them show up, and moves the rider marker (animated,
     * never jumped) to its latest position — no route/deviation logic,
     * see class kdoc. Shows [ActivityRiderTrackBinding.waitingText] until
     * a rider position actually arrives (the order is in a trackable
     * status but a rider hasn't reported a GPS fix yet, or hasn't been
     * assigned this exact second). */
    private fun updateMap(track: RestaurantTrackResult) {
        val map = googleMap
        if (!mapReady || map == null) return // onMapReady's own lastTrack replay will catch up once it fires

        if (restaurantMarker == null && track.restaurant?.lat != null && track.restaurant.lng != null) {
            val pos = LatLng(track.restaurant.lat, track.restaurant.lng)
            restaurantLatLng = pos
            restaurantMarker = map.addMarker(
                MarkerOptions().position(pos).title("Restaurant")
                    .icon(BitmapDescriptorFactory.defaultMarker(BitmapDescriptorFactory.HUE_ORANGE))
            )
        }
        if (deliveryMarker == null && track.delivery?.lat != null && track.delivery.lng != null) {
            val pos = LatLng(track.delivery.lat, track.delivery.lng)
            deliveryLatLng = pos
            deliveryMarker = map.addMarker(
                MarkerOptions().position(pos).title("Delivery address")
                    .icon(BitmapDescriptorFactory.defaultMarker(BitmapDescriptorFactory.HUE_AZURE))
            )
        }

        val riderLat = track.rider?.lat
        val riderLng = track.rider?.lng
        if (riderLat == null || riderLng == null) {
            binding.waitingText.visibility = View.VISIBLE
            return
        }
        binding.waitingText.visibility = View.GONE

        val newPos = LatLng(riderLat, riderLng)
        val existing = riderMarker
        if (existing == null) {
            riderMarker = map.addMarker(
                MarkerOptions().position(newPos).title(track.rider.name ?: "Rider")
                    .icon(BitmapDescriptorFactory.defaultMarker(BitmapDescriptorFactory.HUE_GREEN))
            )
        } else {
            animateRiderMarker(existing, existing.position, newPos)
        }

        if (!boundsEverFit) {
            boundsEverFit = true
            refitCameraBounds(newPos)
        }
    }

    /** Same linear-lerp marker tween as the Customer app's
     * OrderStatusActivity.animateRiderMarker() — accurate enough over the
     * short hops one 5s poll interval covers at delivery speeds. */
    private fun animateRiderMarker(marker: Marker, from: LatLng, to: LatLng) {
        riderMarkerAnimator?.cancel()
        riderMarkerAnimator = ValueAnimator.ofFloat(0f, 1f).apply {
            duration = POLL_INTERVAL_MS
            addUpdateListener { anim ->
                val t = anim.animatedValue as Float
                val lat = from.latitude + (to.latitude - from.latitude) * t
                val lng = from.longitude + (to.longitude - from.longitude) * t
                marker.position = LatLng(lat, lng)
            }
            start()
        }
    }

    /** Fits the camera to whichever of restaurant/delivery/rider markers
     * currently exist — only on the map's first appearance, same
     * "don't re-fit every 5s poll, it'd fight the marker animation and
     * feel jumpy" reasoning as OrderStatusActivity.refitCameraBounds(),
     * just without that class's separate slower recalc loop to re-trigger
     * it later (no route line here to recalc alongside). */
    private fun refitCameraBounds(riderPos: LatLng) {
        val map = googleMap ?: return
        val points = listOfNotNull(restaurantLatLng, deliveryLatLng, riderPos)
        if (points.size == 1) {
            map.moveCamera(CameraUpdateFactory.newLatLngZoom(points[0], 15f))
            return
        }
        val boundsBuilder = LatLngBounds.Builder()
        points.forEach { boundsBuilder.include(it) }
        try {
            map.moveCamera(CameraUpdateFactory.newLatLngBounds(boundsBuilder.build(), 80))
        } catch (e: Exception) {
            // newLatLngBounds can throw if the map hasn't laid out yet —
            // harmless to skip this one refit attempt, same tolerance
            // OrderStatusActivity.refitCameraBounds() has for the same
            // exception.
        }
    }
}
