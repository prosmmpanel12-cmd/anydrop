package com.anydrop.restaurant.ui.account

import android.Manifest
import android.content.Intent
import android.content.pm.PackageManager
import android.location.Geocoder
import android.location.Location
import android.location.LocationManager
import android.os.Bundle
import android.os.Looper
import androidx.activity.result.contract.ActivityResultContracts
import androidx.appcompat.app.AppCompatActivity
import androidx.core.content.ContextCompat
import androidx.lifecycle.lifecycleScope
import com.anydrop.restaurant.R
import com.anydrop.restaurant.databinding.ActivityLocationPickerBinding
import com.anydrop.restaurant.ui.common.InAppNotifier
import com.google.android.gms.maps.CameraUpdateFactory
import com.google.android.gms.maps.GoogleMap
import com.google.android.gms.maps.OnMapReadyCallback
import com.google.android.gms.maps.model.CameraPosition
import com.google.android.gms.maps.model.LatLng
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import java.util.Locale

/**
 * Location Picker for the restaurant's own profile (app-owner real-device
 * feedback, 2026-08-16, docs/restorent/00_Status.md item 3). Launched from
 * EditProfileActivity's "Set restaurant location on map" row, returns the
 * picked coordinate + reverse-geocoded address line via activity result —
 * EditProfileActivity is responsible for actually saving it (as part of
 * the normal profile-update.php Save flow, same "stage locally, only
 * upload/save on Save" pattern the logo picker already uses), not this
 * screen.
 *
 * Deliberately a trimmed copy of the Customer app's H6
 * MapPinDropActivity.kt/activity_map_pin_drop.xml (same fixed-center-pin,
 * pan-the-map-underneath, reverse-geocode-on-camera-idle pattern) rather
 * than a from-scratch build, per the app owner's explicit ask to reuse
 * that flow. What's cut relative to the Customer app's version, and why:
 * - No photo picker — a restaurant only has one address to place a pin
 *   for, not a per-delivery door photo.
 * - No receiver-name/phone form fields — those are delivery-specific,
 *   meaningless for a restaurant's own location.
 * - No saved-addresses list / LocationPickerActivity.kt (Customer app's
 *   version, name collision is coincidental) — a restaurant has exactly
 *   one location, not a address book to choose between.
 * - No "X km away from current location" distance-warning banner — no
 *   DistanceUtil equivalent exists in this module yet and it's a "nice to
 *   have" for this use case, not core to the ask; can be added later if
 *   wanted.
 * Same Google Maps SDK requirement/caveat as the Customer app's version:
 * requires a real com.google.android.geo.API_KEY (currently a placeholder
 * in strings.xml's google_maps_key) or the map area renders blank/grey.
 */
class LocationPickerActivity : AppCompatActivity(), OnMapReadyCallback {

    private lateinit var binding: ActivityLocationPickerBinding
    private var googleMap: GoogleMap? = null

    // Osian, Jodhpur — same fallback center the Customer app's
    // MapPinDropActivity uses, only reached if no GPS fix and no existing
    // profile location are available at all.
    private val defaultCenter = LatLng(26.7213, 72.9166)
    private val defaultZoom = 15f

    private var resolvedLat: Double = defaultCenter.latitude
    private var resolvedLng: Double = defaultCenter.longitude
    private var resolvedAddressLine: String? = null

    // Debounces reverse-geocode calls to one-per-settle, same reasoning as
    // MapPinDropActivity's geocodeDebounceJob.
    private var geocodeDebounceJob: Job? = null

    private val locationPermissionLauncher =
        registerForActivityResult(ActivityResultContracts.RequestPermission()) { granted ->
            if (granted) fetchCurrentLocation(recenter = true)
            else InAppNotifier.show(this, "Location permission denied", InAppNotifier.Type.INFO)
        }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityLocationPickerBinding.inflate(layoutInflater)
        setContentView(binding.root)

        // If the profile already has a saved location, open centered there
        // instead of the default fallback/device GPS — editing an existing
        // pin should start from where it already is, not jump the user
        // somewhere else first.
        val existingLat = intent.getDoubleExtra(EXTRA_EXISTING_LAT, Double.NaN)
        val existingLng = intent.getDoubleExtra(EXTRA_EXISTING_LNG, Double.NaN)
        if (!existingLat.isNaN() && !existingLng.isNaN()) {
            resolvedLat = existingLat
            resolvedLng = existingLng
        }

        binding.mapView.onCreate(savedInstanceState)
        binding.mapView.getMapAsync(this)

        binding.btnBack.setOnClickListener { finish() }
        binding.btnUseCurrentLocation.setOnClickListener { requestCurrentLocation() }
        binding.btnConfirmLocation.setOnClickListener { confirmLocation() }

        // Only auto-resolve device GPS on open if there's no existing
        // saved location to show instead — matches the "start from what's
        // already there" reasoning above.
        if (existingLat.isNaN() || existingLng.isNaN()) {
            requestCurrentLocation()
        }
    }

    // ---- Map setup ----

    override fun onMapReady(map: GoogleMap) {
        googleMap = map
        map.uiSettings.isZoomControlsEnabled = false
        map.uiSettings.isMyLocationButtonEnabled = false // custom pill button handles this instead
        map.moveCamera(CameraUpdateFactory.newCameraPosition(
            CameraPosition.fromLatLngZoom(LatLng(resolvedLat, resolvedLng), defaultZoom)
        ))

        // Fixed-center-pin pattern, same as MapPinDropActivity: no
        // GoogleMap Marker that moves with a tap — centerPin is a plain
        // ImageView fixed in the FrameLayout's center, and the map pans
        // underneath it. The "selected" coordinate is always just the
        // camera's target (map center), read on every idle.
        map.setOnCameraMoveListener { onMapCenterChanged() }
        map.setOnCameraIdleListener { onMapCenterChanged() }

        // Camera may already have been positioned by fetchCurrentLocation()
        // completing before onMapReady fires (async race) — read it once
        // here too, same as MapPinDropActivity's onMapReady.
        onMapCenterChanged()
    }

    override fun onResume() {
        super.onResume()
        binding.mapView.onResume()
    }

    override fun onPause() {
        super.onPause()
        binding.mapView.onPause()
    }

    override fun onStart() {
        super.onStart()
        binding.mapView.onStart()
    }

    override fun onStop() {
        super.onStop()
        binding.mapView.onStop()
    }

    override fun onDestroy() {
        super.onDestroy()
        binding.mapView.onDestroy()
    }

    override fun onLowMemory() {
        super.onLowMemory()
        binding.mapView.onLowMemory()
    }

    override fun onSaveInstanceState(outState: Bundle) {
        super.onSaveInstanceState(outState)
        binding.mapView.onSaveInstanceState(outState)
    }

    /** Called on every camera-move/idle tick — debounces so reverse-
     * geocoding only fires ~500ms after the map actually settles, not
     * mid-drag. Same pattern as MapPinDropActivity.onMapCenterChanged(). */
    private fun onMapCenterChanged() {
        val center = googleMap?.cameraPosition?.target ?: return
        resolvedLat = center.latitude
        resolvedLng = center.longitude

        geocodeDebounceJob?.cancel()
        geocodeDebounceJob = lifecycleScope.launch {
            delay(500)
            reverseGeocode(resolvedLat, resolvedLng)
        }
    }

    /** On-device Geocoder, same interim approach (and same "not yet moved
     * to a backend-proxied Geocoding API" caveat) as MapPinDropActivity's
     * reverseGeocode() — see that method's kdoc for the full reasoning. */
    private fun reverseGeocode(lat: Double, lng: Double) {
        binding.textResolvedAddress.text = getString(R.string.location_picker_resolving_address)
        lifecycleScope.launch {
            var addressLine: String? = null
            try {
                val geocoder = Geocoder(this@LocationPickerActivity, Locale.getDefault())
                @Suppress("DEPRECATION")
                val results = geocoder.getFromLocation(lat, lng, 1)
                addressLine = results?.firstOrNull()?.getAddressLine(0)
            } catch (e: Exception) {
                // Network hiccup or no geocoder backend on this device —
                // fall through to the failure-message fallback below.
            }

            resolvedAddressLine = addressLine
            binding.textResolvedAddress.text = addressLine
                ?: getString(R.string.location_picker_geocode_failed)
        }
    }

    // ---- Current location ----

    private fun requestCurrentLocation() {
        val fineGranted = ContextCompat.checkSelfPermission(
            this, Manifest.permission.ACCESS_FINE_LOCATION
        ) == PackageManager.PERMISSION_GRANTED
        if (fineGranted) {
            fetchCurrentLocation(recenter = true)
        } else {
            locationPermissionLauncher.launch(Manifest.permission.ACCESS_FINE_LOCATION)
        }
    }

    private fun fetchCurrentLocation(recenter: Boolean) {
        val locationManager = getSystemService(LOCATION_SERVICE) as LocationManager
        val hasGps = locationManager.isProviderEnabled(LocationManager.GPS_PROVIDER)
        val hasNetwork = locationManager.isProviderEnabled(LocationManager.NETWORK_PROVIDER)
        if (!hasGps && !hasNetwork) {
            // GPS off — leave the map on its default/existing center rather
            // than blocking; user can still drag the pin manually.
            return
        }
        val provider = if (hasGps) LocationManager.GPS_PROVIDER else LocationManager.NETWORK_PROVIDER
        try {
            val lastKnown = locationManager.getLastKnownLocation(provider)
            if (lastKnown != null) {
                onDeviceLocationResolved(lastKnown, recenter)
            } else {
                locationManager.requestSingleUpdate(
                    provider,
                    { location -> onDeviceLocationResolved(location, recenter) },
                    Looper.getMainLooper()
                )
            }
        } catch (e: SecurityException) {
            // Permission race (revoked between the check above and this
            // call) — non-fatal, same as MapPinDropActivity's handling.
        }
    }

    private fun onDeviceLocationResolved(location: Location, recenter: Boolean) {
        if (recenter) {
            val target = LatLng(location.latitude, location.longitude)
            // googleMap may still be null here if this GPS fix resolves
            // before onMapReady has fired — onMapReady's own
            // onMapCenterChanged() call at the end of its setup covers
            // that case, same as MapPinDropActivity.
            googleMap?.moveCamera(CameraUpdateFactory.newLatLngZoom(target, defaultZoom))
            onMapCenterChanged()
        }
    }

    // ---- Confirm ----

    private fun confirmLocation() {
        val result = Intent().apply {
            putExtra(EXTRA_RESULT_LAT, resolvedLat)
            putExtra(EXTRA_RESULT_LNG, resolvedLng)
            putExtra(EXTRA_RESULT_ADDRESS_LINE, resolvedAddressLine)
        }
        setResult(RESULT_OK, result)
        finish()
    }

    companion object {
        /** Optional — pass the profile's existing lat/lng (if any) so this
         * screen opens centered there instead of the device's current GPS
         * fix or the hardcoded fallback. */
        const val EXTRA_EXISTING_LAT = "extra_existing_lat"
        const val EXTRA_EXISTING_LNG = "extra_existing_lng"

        const val EXTRA_RESULT_LAT = "extra_result_lat"
        const val EXTRA_RESULT_LNG = "extra_result_lng"
        const val EXTRA_RESULT_ADDRESS_LINE = "extra_result_address_line"
    }
}
