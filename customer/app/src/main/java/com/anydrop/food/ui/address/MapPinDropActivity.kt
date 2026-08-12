package com.anydrop.food.ui.address

import android.Manifest
import android.content.pm.PackageManager
import android.location.Geocoder
import android.location.Location
import android.location.LocationManager
import android.net.Uri
import android.os.Bundle
import android.os.Looper
import android.view.View
import androidx.activity.result.contract.ActivityResultContracts
import androidx.appcompat.app.AppCompatActivity
import androidx.core.content.ContextCompat
import androidx.lifecycle.lifecycleScope
import com.anydrop.food.R
import com.anydrop.food.databinding.ActivityMapPinDropBinding
import com.anydrop.food.network.AddAddressBody
import com.anydrop.food.network.ApiClient
import com.anydrop.food.ui.common.InAppNotifier
import com.google.android.gms.maps.CameraUpdateFactory
import com.google.android.gms.maps.GoogleMap
import com.google.android.gms.maps.OnMapReadyCallback
import com.google.android.gms.maps.model.CameraPosition
import com.google.android.gms.maps.model.LatLng
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import okhttp3.MediaType.Companion.toMediaTypeOrNull
import okhttp3.MultipartBody
import okhttp3.RequestBody.Companion.asRequestBody
import java.io.File
import java.io.FileOutputStream
import java.util.Locale

/**
 * H6 part 2 — Zomato-style full-screen map pin-drop (screenshot 13). Opened
 * from LocationPickerActivity's "Add Address" (replacing the old
 * direct-to-AddressEditorBottomSheet flow for the map-first path — the
 * editor sheet itself is untouched and still used by AddressBook/edit).
 *
 * Migrated from osmdroid to Google Maps SDK (2026-08-12) — see
 * docs/12_Handover_H6_Map_PinDrop_Photo.md, "Google Maps SDK migration
 * plan," for the full reasoning. Requires a real
 * com.google.android.geo.API_KEY in AndroidManifest.xml (currently a
 * placeholder in strings.xml's google_maps_key — Maps SDK init will not
 * render tiles until that's a real Android-restricted key) and Google
 * Cloud billing set up before this screen will actually work end to end.
 * Code compiles and is structurally complete either way; the map area will
 * just show blank/grey until a real key exists.
 *
 * Flow: map opens centered on device's current fix if available (else a
 * default city center); pin stays fixed at screen-center while the map
 * pans underneath it; every camera-idle reverse-geocodes the new center via
 * Android's on-device Geocoder (no backend call — see kdoc on
 * reverseGeocode() for why this hasn't moved to the backend-proxied
 * Geocoding API yet); user fills the address-details/receiver form already
 * built into the layout; optional photo upload goes through
 * address-photo.php *before* the address save, since addAddress needs the
 * resulting photo_url string, not the file itself.
 *
 * No search bar (removed 2026-08-12, see the doc section above) — this
 * screen only supports current-location and manual pin-drop.
 */
class MapPinDropActivity : AppCompatActivity(), OnMapReadyCallback {

    private lateinit var binding: ActivityMapPinDropBinding
    private val api by lazy { ApiClient.create(this) }
    private var googleMap: GoogleMap? = null

    // Osian, Jodhpur — same fallback center used elsewhere in this project's
    // planning for this region; only used when no GPS fix is available at
    // all, so the map isn't left blank/undefined.
    private val defaultCenter = LatLng(26.7213, 72.9166)
    private val defaultZoom = 15f

    private var resolvedLat: Double = defaultCenter.latitude
    private var resolvedLng: Double = defaultCenter.longitude
    private var resolvedAddressLine: String? = null

    private var selectedPhotoUri: Uri? = null
    private var uploadedPhotoUrl: String? = null

    // Debounces reverse-geocode calls to one-per-settle rather than firing
    // continuously during a drag — GoogleMap.OnCameraMoveListener fires on
    // every frame of a pan, and Geocoder.getFromLocation is a blocking call
    // we don't want hammering on every frame.
    private var geocodeDebounceJob: Job? = null

    private val locationPermissionLauncher =
        registerForActivityResult(ActivityResultContracts.RequestPermission()) { granted ->
            if (granted) fetchCurrentLocation(recenter = true)
            else InAppNotifier.show(this, "Location permission denied", InAppNotifier.Type.INFO)
        }

    private val pickPhotoLauncher =
        registerForActivityResult(ActivityResultContracts.GetContent()) { uri ->
            if (uri != null) onPhotoPicked(uri)
        }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityMapPinDropBinding.inflate(layoutInflater)
        setContentView(binding.root)

        // Google Maps' MapView needs its own lifecycle forwarded from the
        // Activity's — onCreate/onStart/onResume/onPause/onStop/onDestroy/
        // onLowMemory/onSaveInstanceState all have to be called through, or
        // the map leaks/misbehaves. onCreate here, the rest below.
        binding.mapView.onCreate(savedInstanceState)
        binding.mapView.getMapAsync(this)

        binding.btnBack.setOnClickListener { finish() }
        binding.btnUseCurrentLocationMap.setOnClickListener { requestCurrentLocation() }
        binding.rowAddPhoto.setOnClickListener { pickPhotoLauncher.launch("image/*") }
        binding.btnSaveAddressMap.setOnClickListener { saveAddress() }

        requestCurrentLocation()
    }

    // ---- Map setup ----

    /** Fired once by the Maps SDK when the underlying GoogleMap object is
     * ready to use — camera/listener setup has to happen here, not in
     * onCreate, since googleMap doesn't exist yet at that point. */
    override fun onMapReady(map: GoogleMap) {
        googleMap = map
        map.uiSettings.isZoomControlsEnabled = false
        map.uiSettings.isMyLocationButtonEnabled = false // custom pill button handles this instead
        map.moveCamera(CameraUpdateFactory.newCameraPosition(
            CameraPosition.fromLatLngZoom(defaultCenter, defaultZoom)
        ))

        // Fixed-center-pin pattern (Zomato/Swiggy style): no GoogleMap
        // Marker that moves with a tap — instead centerPin is a plain
        // ImageView fixed in the FrameLayout's center via
        // layout_gravity="center", and the map pans underneath it. The
        // "selected" coordinate is always just the camera's target
        // (map center), read on every idle.
        map.setOnCameraMoveListener { onMapCenterChanged() }
        map.setOnCameraIdleListener { onMapCenterChanged() }

        // Camera may already have been positioned by fetchCurrentLocation()
        // completing before onMapReady fires (async race) — read it once
        // here too so resolvedLat/Lng and the address line aren't left at
        // defaultCenter if that happened.
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
     * mid-drag. */
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

    /** Uses Android's on-device Geocoder for now, same approach
     * LocationPickerActivity already uses for its current-location row.
     *
     * NOT YET migrated to the backend-proxied Google Geocoding API
     * described in the migration plan doc — that requires a new backend
     * endpoint that doesn't exist yet (step 6 of the migration steps in
     * docs/12_Handover_H6_Map_PinDrop_Photo.md). The on-device Geocoder
     * happens to also be backed by Google on most Android devices, so this
     * still "uses Google" in a loose sense, but it is NOT the
     * server-key-protected path the security section of that doc
     * describes, and results/coverage may differ from what the Geocoding
     * API proper would return. Treat this as an interim step — swap this
     * method's body for a call to the new backend endpoint once it exists,
     * rather than treating on-device Geocoder as the final state. */
    private fun reverseGeocode(lat: Double, lng: Double) {
        binding.textResolvedAddress.text = getString(R.string.map_pin_drop_resolving_address)
        lifecycleScope.launch {
            var addressLine: String? = null
            try {
                val geocoder = Geocoder(this@MapPinDropActivity, Locale.getDefault())
                @Suppress("DEPRECATION")
                val results = geocoder.getFromLocation(lat, lng, 1)
                addressLine = results?.firstOrNull()?.getAddressLine(0)
            } catch (e: Exception) {
                // Network hiccup or no geocoder backend on this device —
                // fall through to the raw-coordinate fallback below rather
                // than leaving "Locating…" stuck on screen.
            }

            resolvedAddressLine = addressLine
            binding.textResolvedAddress.text = addressLine
                ?: getString(R.string.map_pin_drop_geocode_failed)

            updateDistanceWarning(lat, lng)
        }
    }

    /** Shows "X away from your current location" when the pinned point is
     * far from wherever the device actually is right now — same distance
     * math as the saved-addresses list (DistanceUtil), reusing the fix
     * captured by fetchCurrentLocation() rather than re-resolving GPS. */
    private fun updateDistanceWarning(lat: Double, lng: Double) {
        val curLat = deviceLat
        val curLng = deviceLng
        if (curLat == null || curLng == null) {
            binding.textDistanceWarning.visibility = View.GONE
            return
        }
        val km = com.anydrop.food.util.DistanceUtil.km(curLat, curLng, lat, lng)
        if (km < 1.0) {
            binding.textDistanceWarning.visibility = View.GONE
            return
        }
        binding.textDistanceWarning.text = getString(
            R.string.map_pin_drop_distance_warning,
            com.anydrop.food.util.DistanceUtil.formatDistance(km)
        )
        binding.textDistanceWarning.visibility = View.VISIBLE
    }

    // ---- Current location ----

    private var deviceLat: Double? = null
    private var deviceLng: Double? = null

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
            // GPS off — leave the map on its default/last center rather
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
            // call) — non-fatal, same as LocationPickerActivity's handling.
        }
    }

    private fun onDeviceLocationResolved(location: Location, recenter: Boolean) {
        deviceLat = location.latitude
        deviceLng = location.longitude
        if (recenter) {
            val target = LatLng(location.latitude, location.longitude)
            // googleMap may still be null here if this GPS fix resolves
            // before onMapReady has fired (the two are async and race each
            // other) — onMapReady's own onMapCenterChanged() call at the
            // end of its setup covers that case by reading whatever camera
            // position ends up set, so it's safe to just no-op here rather
            // than queue this update.
            googleMap?.moveCamera(CameraUpdateFactory.newLatLngZoom(target, defaultZoom))
            onMapCenterChanged()
        } else {
            updateDistanceWarning(resolvedLat, resolvedLng)
        }
    }

    // ---- Photo picker ----

    private fun onPhotoPicked(uri: Uri) {
        selectedPhotoUri = uri
        uploadedPhotoUrl = null // stale, re-upload will happen at save time
        binding.imgAddressPhotoThumb.setImageURI(uri)
        binding.textAddPhotoLabel.text = getString(R.string.map_pin_drop_photo_added)
    }

    /** Copies the picked content:// Uri into a temp file and uploads it —
     * MultipartBody.Part needs a File-backed RequestBody, and content Uris
     * from GetContent() aren't guaranteed to expose a real filesystem path
     * (photo picker/gallery providers commonly don't), so a local copy via
     * ContentResolver.openInputStream is the safe general approach. */
    private suspend fun uploadPhotoIfNeeded(): String? {
        val uri = selectedPhotoUri ?: return null
        uploadedPhotoUrl?.let { return it }

        val mimeType = contentResolver.getType(uri) ?: "image/jpeg"
        val ext = when (mimeType) {
            "image/png" -> "png"
            "image/webp" -> "webp"
            else -> "jpg"
        }
        val tempFile = File(cacheDir, "address_photo_upload.$ext")
        contentResolver.openInputStream(uri)?.use { input ->
            FileOutputStream(tempFile).use { output -> input.copyTo(output) }
        } ?: return null

        val requestBody = tempFile.asRequestBody(mimeType.toMediaTypeOrNull())
        val part = MultipartBody.Part.createFormData("photo", tempFile.name, requestBody)

        val response = api.uploadAddressPhoto(part)
        tempFile.delete()

        return if (response.isSuccessful && response.body()?.success == true) {
            val relativePath = response.body()?.data?.photoUrl
            relativePath?.let { absoluteUrl(it) }
        } else {
            null
        }
    }

    /** address-photo.php returns a path relative to the backend root (e.g.
     * "uploads/address_photos/addr_1_....jpg"), not a full URL — matches
     * the endpoint's kdoc. ApiClient's BASE_URL is ".../anydrop/api/v1/", so
     * the static file actually lives one level up, at ".../anydrop/" plus
     * the relative path. Swap "api/v1/" out rather than hardcoding the
     * anydrop/ root again here, so this keeps working if BASE_URL's host or
     * scheme ever changes (only place this logic needs to live). */
    private fun absoluteUrl(relativePath: String): String {
        val base = ApiClient.baseUrlForStaticFiles(this)
        return base + relativePath
    }

    // ---- Save ----

    private fun saveAddress() {
        val addressDetails = binding.inputAddressDetails.text?.toString()?.trim().orEmpty()
        val receiverName = binding.inputReceiverNameMap.text?.toString()?.trim().orEmpty()
        val receiverPhone = binding.inputReceiverPhoneMap.text?.toString()?.trim().orEmpty()

        if (addressDetails.isEmpty()) {
            InAppNotifier.show(this, getString(R.string.error_area_required), InAppNotifier.Type.INFO)
            return
        }
        if (receiverName.isEmpty()) {
            InAppNotifier.show(this, getString(R.string.error_receiver_name_required), InAppNotifier.Type.INFO)
            return
        }
        if (receiverPhone.length < 10) {
            InAppNotifier.show(this, getString(R.string.error_receiver_phone_required), InAppNotifier.Type.INFO)
            return
        }

        binding.btnSaveAddressMap.isEnabled = false
        lifecycleScope.launch {
            // Photo upload (if any) happens first and best-effort — a
            // failed upload doesn't block the address save, matching
            // map_pin_drop_photo_upload_failed's wording ("address will be
            // saved without it").
            var photoUrl: String? = null
            if (selectedPhotoUri != null) {
                binding.textAddPhotoLabel.text = getString(R.string.map_pin_drop_uploading_photo)
                try {
                    photoUrl = uploadPhotoIfNeeded()
                    if (photoUrl == null) {
                        InAppNotifier.show(
                            this@MapPinDropActivity,
                            getString(R.string.map_pin_drop_photo_upload_failed),
                            InAppNotifier.Type.INFO
                        )
                    }
                } catch (e: Exception) {
                    InAppNotifier.show(
                        this@MapPinDropActivity,
                        getString(R.string.map_pin_drop_photo_upload_failed),
                        InAppNotifier.Type.INFO
                    )
                }
            }

            val body = AddAddressBody(
                fullAddress = resolvedAddressLine ?: addressDetails,
                houseFlatNo = addressDetails,
                receiverName = receiverName,
                receiverPhone = receiverPhone,
                latitude = resolvedLat,
                longitude = resolvedLng,
                isDefault = true,
                photoUrl = photoUrl
            )

            try {
                val response = api.addAddress(body)
                if (response.isSuccessful && response.body()?.success == true) {
                    InAppNotifier.show(this@MapPinDropActivity, "Address saved", InAppNotifier.Type.SUCCESS)
                    setResult(RESULT_OK)
                    finish()
                } else {
                    binding.btnSaveAddressMap.isEnabled = true
                    InAppNotifier.show(this@MapPinDropActivity, "Couldn't save address", InAppNotifier.Type.ERROR)
                }
            } catch (e: Exception) {
                binding.btnSaveAddressMap.isEnabled = true
                InAppNotifier.show(this@MapPinDropActivity, "Network error while saving address", InAppNotifier.Type.ERROR)
            }
        }
    }
}
