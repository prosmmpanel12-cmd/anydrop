package com.anydrop.restaurant.ui.account

import android.content.Intent
import android.location.Geocoder
import android.net.Uri
import android.os.Bundle
import android.provider.Settings
import androidx.activity.result.contract.ActivityResultContracts
import androidx.appcompat.app.AppCompatActivity
import androidx.lifecycle.lifecycleScope
import coil.load
import com.google.android.material.chip.Chip
import com.anydrop.restaurant.R
import com.anydrop.restaurant.data.TokenManager
import com.anydrop.restaurant.databinding.ActivityEditProfileBinding
import com.anydrop.restaurant.network.ApiClient
import com.anydrop.restaurant.network.ProfileUpdateBody
import com.anydrop.restaurant.network.RestaurantProfileDetail
import com.anydrop.restaurant.ui.common.CropActivity
import com.anydrop.restaurant.ui.common.InAppNotifier
import kotlinx.coroutines.launch
import okhttp3.MediaType.Companion.toMediaTypeOrNull
import okhttp3.MultipartBody
import okhttp3.RequestBody.Companion.asRequestBody
import java.io.File
import java.io.FileOutputStream
import java.util.Locale

/**
 * Account tab / Edit Profile form (docs/restorent/19 §7, §10 item 5,
 * NEXT_SESSION_PROMPT.md item 1). Backend + Kotlin networking layer were
 * built in the previous session (profile-get.php / profile-update.php /
 * logo-upload.php, RestaurantProfileDetail / ProfileUpdateBody /
 * LogoUploadResult in Models.kt) — this screen is the missing UI half.
 *
 * Launched from AccountFragment with the already-loaded profile passed
 * via [EXTRA_PROFILE_JSON] so this screen doesn't need its own
 * getProfile() round trip on open — AccountFragment already has it.
 *
 * Logo handling mirrors the Customer app's H6 address-photo flow
 * (MapPinDropActivity): picking a new logo only stages a local Uri and
 * updates the on-screen preview; the actual uploadLogo() multipart call
 * only fires from Save, and only if a new logo was actually picked —
 * so cancelling out of this screen (back button, no Save) never leaves
 * an orphaned upload half-applied to the profile. See logo-upload.php's
 * kdoc for the same reasoning on the backend side.
 *
 * Location picker (app-owner real-device feedback, 2026-08-16,
 * docs/restorent/00_Status.md item 3) follows the same staging pattern —
 * LocationPickerActivity returns a lat/lng via activity result, staged
 * locally in [pickedLat]/[pickedLng], only sent to profile-update.php
 * when Save is actually tapped.
 */
class EditProfileActivity : AppCompatActivity() {

    private lateinit var binding: ActivityEditProfileBinding
    private val api by lazy { ApiClient.create(this) }
    private lateinit var tokenManager: TokenManager

    private var pickedLogoUri: Uri? = null
    private var currentLogoUrl: String? = null
    private var saveInFlight = false

    // Staged location pick — null until the user actually picks one (either
    // via GPS or the map), same "only applied on Save" pattern as
    // pickedLogoUri above. Pre-filled from the loaded profile in populate()
    // if one was already saved, so re-saving the form without touching
    // either location row doesn't accidentally clear it.
    private var pickedLat: Double? = null
    private var pickedLng: Double? = null

    // Which row last set pickedLat/pickedLng — purely for
    // renderLocationRowState()'s label text (app-owner ask, 2026-08-17:
    // "2 bhag mein dalo"); both rows write into the exact same
    // pickedLat/pickedLng and profile-update.php has no concept of "how"
    // a location was set, so this never leaves the UI layer. Starts null
    // (unknown/not-this-session) for a location loaded from the existing
    // profile — renderLocationRowState() falls back to a source-agnostic
    // label in that case.
    private enum class LocationSource { GPS, MAP }
    private var pickedLocationSource: LocationSource? = null

    // 1 (Monday) .. 7 (Sunday), matches lib validation on profile-update.php
    // (PHP's date('N') convention, same as lib/restaurant_status.php).
    private val dayLabels = listOf(
        1 to R.string.day_short_mon, 2 to R.string.day_short_tue, 3 to R.string.day_short_wed,
        4 to R.string.day_short_thu, 5 to R.string.day_short_fri, 6 to R.string.day_short_sat,
        7 to R.string.day_short_sun
    )
    private val dayChips = mutableMapOf<Int, Chip>()

    private var openingHour = 9
    private var openingMinute = 0
    private var closingHour = 22
    private var closingMinute = 0

    private val pickLogoLauncher =
        registerForActivityResult(ActivityResultContracts.GetContent()) { uri ->
            // Bug/feature (app-owner feedback item #2, 2026-08-17) — used to
            // stage the raw picked Uri straight as the preview; now routes
            // through CropActivity first (square-only, since the logo is
            // always shown as a small circle/square everywhere) so the
            // owner controls exactly which part of their photo becomes the
            // logo before it's ever staged/uploaded.
            if (uri != null) {
                CropActivity.start(this, uri, CropActivity.SLOT_SQUARE_ONLY, cropLogoLauncher)
            }
        }

    private val cropLogoLauncher =
        registerForActivityResult(ActivityResultContracts.StartActivityForResult()) { result ->
            if (result.resultCode == RESULT_OK) {
                val croppedUri = CropActivity.getResultUri(result.data) ?: return@registerForActivityResult
                pickedLogoUri = croppedUri
                // See populate()'s comment below — activity_edit_profile.xml's
                // app:tint on logoPreview is meant for the ic_store
                // placeholder only, and must be cleared once a real image
                // (here, the freshly-cropped local file) is showing.
                binding.logoPreview.imageTintList = null
                binding.logoPreview.load(croppedUri) {
                    placeholder(R.drawable.ic_store)
                    error(R.drawable.ic_store)
                    crossfade(true)
                    listener(onError = { _, _ ->
                        binding.logoPreview.imageTintList = androidx.core.content.ContextCompat.getColorStateList(this@EditProfileActivity, R.color.text_secondary)
                    })
                }
            }
        }

    private val pickLocationLauncher =
        registerForActivityResult(ActivityResultContracts.StartActivityForResult()) { result ->
            if (result.resultCode == RESULT_OK) {
                val data = result.data ?: return@registerForActivityResult
                val lat = data.getDoubleExtra(LocationPickerActivity.EXTRA_RESULT_LAT, Double.NaN)
                val lng = data.getDoubleExtra(LocationPickerActivity.EXTRA_RESULT_LNG, Double.NaN)
                if (!lat.isNaN() && !lng.isNaN()) {
                    pickedLat = lat
                    pickedLng = lng
                    pickedLocationSource = LocationSource.MAP
                    renderLocationRowState()
                    // Bug fix (user report, 2026-08-20): LocationPickerActivity
                    // already reverse-geocodes the dropped pin and returns it
                    // as EXTRA_RESULT_ADDRESS_LINE, but this callback was only
                    // ever reading the lat/lng extras — the "Restaurant
                    // address" field above stayed blank even after a location
                    // was clearly picked (rows below it correctly flipped to
                    // "set" state, which is what made it look like only the
                    // address half was broken). User explicitly placed this
                    // pin, so overwrite the field outright — same "map
                    // selection wins" convention the Customer app's own
                    // MapPinDropActivity caller follows with resolvedAddressLine.
                    val addressLine = data.getStringExtra(LocationPickerActivity.EXTRA_RESULT_ADDRESS_LINE)
                    if (!addressLine.isNullOrBlank()) {
                        binding.inputAddress.setText(addressLine)
                    }
                }
            }
        }

    // ---- "Use current location" (app-owner ask, 2026-08-17) — deliberately
    // plain android.location.LocationManager + Geocoder, same APIs
    // LocationPickerActivity's own GPS button already uses internally, but
    // called directly here with **no Maps SDK/GoogleMap involved at all**.
    // That's the actual point of splitting this into its own row: a
    // restaurant owner standing in their own restaurant just wants their
    // GPS fix saved, and that shouldn't require the Google Maps SDK to have
    // initialized (or a real google_maps_key to be configured) at all — see
    // CropActivity-style "no new dependency" reasoning elsewhere in this
    // session, same spirit here with an SDK this app already has but
    // doesn't need to invoke for this particular action. ----

    private val currentLocationPermissionLauncher =
        registerForActivityResult(ActivityResultContracts.RequestPermission()) { granted ->
            if (granted) fetchCurrentLocationForRow()
            else InAppNotifier.show(this, getString(R.string.location_permission_denied), InAppNotifier.Type.INFO)
        }

    private fun useCurrentLocation() {
        val fineGranted = androidx.core.content.ContextCompat.checkSelfPermission(
            this, android.Manifest.permission.ACCESS_FINE_LOCATION
        ) == android.content.pm.PackageManager.PERMISSION_GRANTED
        if (fineGranted) {
            fetchCurrentLocationForRow()
        } else {
            currentLocationPermissionLauncher.launch(android.Manifest.permission.ACCESS_FINE_LOCATION)
        }
    }

    private fun fetchCurrentLocationForRow() {
        val locationManager = getSystemService(LOCATION_SERVICE) as android.location.LocationManager
        val hasGps = locationManager.isProviderEnabled(android.location.LocationManager.GPS_PROVIDER)
        val hasNetwork = locationManager.isProviderEnabled(android.location.LocationManager.NETWORK_PROVIDER)
        if (!hasGps && !hasNetwork) {
            // App-owner ask (HANDOVER_TAGS_FEATURE.md item 1): don't just
            // toast-and-stop — take the user straight to the device's
            // Location Settings screen so they can turn it on immediately,
            // instead of having to back out and hunt for it themselves.
            // fetchCurrentLocationForRow() only ever runs off an explicit
            // tap on rowUseCurrentLocation (via useCurrentLocation()), so
            // unlike LocationPickerActivity's copy of this same check
            // (which also fires silently on screen-open) there's no
            // "don't interrupt an auto-triggered flow" case to guard here.
            InAppNotifier.show(this, getString(R.string.location_gps_off), InAppNotifier.Type.INFO)
            startActivity(Intent(Settings.ACTION_LOCATION_SOURCE_SETTINGS))
            return
        }
        InAppNotifier.show(this, getString(R.string.location_fetching), InAppNotifier.Type.INFO)
        val provider = if (hasGps) android.location.LocationManager.GPS_PROVIDER else android.location.LocationManager.NETWORK_PROVIDER
        try {
            val lastKnown = locationManager.getLastKnownLocation(provider)
            if (lastKnown != null) {
                onCurrentLocationResolved(lastKnown)
            } else {
                locationManager.requestSingleUpdate(
                    provider,
                    { location -> onCurrentLocationResolved(location) },
                    android.os.Looper.getMainLooper()
                )
            }
        } catch (e: SecurityException) {
            // Permission race (revoked between the check and this call) —
            // non-fatal, same handling as LocationPickerActivity's own copy
            // of this same try/catch.
        }
    }

    private fun onCurrentLocationResolved(location: android.location.Location) {
        pickedLat = location.latitude
        pickedLng = location.longitude
        pickedLocationSource = LocationSource.GPS
        renderLocationRowState()
        InAppNotifier.show(this, getString(R.string.row_current_location_set), InAppNotifier.Type.SUCCESS)
        reverseGeocodeAndFillAddress(location.latitude, location.longitude)
    }

    /** Same gap as pickLocationLauncher above (user report, 2026-08-20) —
     * this GPS path only ever set pickedLat/pickedLng, never touched the
     * "Restaurant address" field at all (LocationPickerActivity's map flow
     * at least *had* a resolved address to forward, this one didn't even
     * geocode). Runs the same on-device Geocoder call LocationPickerActivity
     * uses; on any failure (no geocoder backend, network hiccup) just leaves
     * the field as-is for the owner to fill in manually, same fallback
     * reasoning as elsewhere in this app. */
    private fun reverseGeocodeAndFillAddress(lat: Double, lng: Double) {
        lifecycleScope.launch {
            val addressLine = try {
                val geocoder = Geocoder(this@EditProfileActivity, Locale.getDefault())
                @Suppress("DEPRECATION")
                val results = geocoder.getFromLocation(lat, lng, 1)
                results?.firstOrNull()?.getAddressLine(0)
            } catch (e: Exception) {
                null
            }
            if (!addressLine.isNullOrBlank()) {
                binding.inputAddress.setText(addressLine)
            }
        }
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityEditProfileBinding.inflate(layoutInflater)
        setContentView(binding.root)

        tokenManager = TokenManager(this)
        buildDayChips()

        val profile = readProfileExtra()
        if (profile != null) {
            populate(profile)
        }

        binding.btnBack.setOnClickListener { finish() }
        binding.logoPickerRow.setOnClickListener { pickLogoLauncher.launch("image/*") }
        binding.rowOpeningTime.setOnClickListener { showTimePicker(isOpening = true) }
        binding.rowClosingTime.setOnClickListener { showTimePicker(isOpening = false) }
        binding.rowSetLocation.setOnClickListener { openLocationPicker() }
        binding.rowUseCurrentLocation.setOnClickListener { useCurrentLocation() }
        binding.btnSaveProfile.setOnClickListener { save() }
    }

    private fun buildDayChips() {
        dayLabels.forEach { (dayNumber, labelRes) ->
            val chip = Chip(this).apply {
                text = getString(labelRes)
                isCheckable = true
                textSize = 12f
            }
            dayChips[dayNumber] = chip
            binding.workingDaysChipGroup.addView(chip)
        }
    }

    private fun populate(profile: RestaurantProfileDetail) {
        binding.inputName.setText(profile.name)
        binding.inputAddress.setText(profile.address.orEmpty())
        binding.inputCuisineTags.setText(profile.cuisineTags.orEmpty())
        // recall.md Phase B item 13 / migration 36 — show the owner's
        // current value, blank if it's 0/never set, so an empty field
        // doesn't silently look like a real "₹0 minimum" to the owner.
        binding.inputMinOrderAmount.setText(
            profile.minOrderAmount?.takeIf { it > 0.0 }?.let { formatAmountForInput(it) }.orEmpty()
        )
        binding.inputDescription.setText(profile.description.orEmpty())

        currentLogoUrl = profile.logoUrl
        if (!profile.logoUrl.isNullOrBlank()) {
            // activity_edit_profile.xml sets app:tint on logoPreview so the
            // ic_store *placeholder* renders muted-grey. That tint applies
            // to whatever drawable is currently on the ImageView though,
            // not just the placeholder — so a real logo bitmap loaded on
            // top was getting tinted grey too, making it look like the
            // photo wasn't showing at all. Clear on success, restore on
            // error (falls back to the ic_store placeholder look).
            binding.logoPreview.load(ApiClient.baseUrlForStaticFiles(this) + profile.logoUrl) {
                placeholder(R.drawable.ic_store)
                error(R.drawable.ic_store)
                crossfade(true)
                listener(
                    onSuccess = { _, _ -> binding.logoPreview.imageTintList = null },
                    onError = { _, _ ->
                        binding.logoPreview.imageTintList = androidx.core.content.ContextCompat.getColorStateList(this@EditProfileActivity, R.color.text_secondary)
                    }
                )
            }
        }

        parseTime(profile.openingTime, isOpening = true)
        parseTime(profile.closingTime, isOpening = false)
        renderTimeText()

        pickedLat = profile.latitude
        pickedLng = profile.longitude
        renderLocationRowState()

        val selectedDays = (profile.workingDays ?: "1,2,3,4,5,6,7")
            .split(",")
            .mapNotNull { it.trim().toIntOrNull() }
            .toSet()
        dayChips.forEach { (dayNumber, chip) -> chip.isChecked = dayNumber in selectedDays }
    }

    /** "HH:MM:SS" / "HH:MM" -> hour/minute, falls back to a sane default
     * (9am/10pm) if the profile hasn't set hours yet (new restaurants). */
    private fun parseTime(value: String?, isOpening: Boolean) {
        val parts = value?.split(":")
        val hour = parts?.getOrNull(0)?.toIntOrNull()
        val minute = parts?.getOrNull(1)?.toIntOrNull()
        if (hour != null && minute != null) {
            if (isOpening) { openingHour = hour; openingMinute = minute }
            else { closingHour = hour; closingMinute = minute }
        }
    }

    private fun showTimePicker(isOpening: Boolean) {
        val currentHour = if (isOpening) openingHour else closingHour
        val currentMinute = if (isOpening) openingMinute else closingMinute
        android.app.TimePickerDialog(
            this,
            { _, hour, minute ->
                if (isOpening) { openingHour = hour; openingMinute = minute }
                else { closingHour = hour; closingMinute = minute }
                renderTimeText()
            },
            currentHour, currentMinute, false
        ).show()
    }

    private fun renderTimeText() {
        binding.openingTimeText.text = formatDisplayTime(openingHour, openingMinute)
        binding.closingTimeText.text = formatDisplayTime(closingHour, closingMinute)
    }

    private fun openLocationPicker() {
        val intent = Intent(this, LocationPickerActivity::class.java)
        val lat = pickedLat
        val lng = pickedLng
        if (lat != null && lng != null) {
            intent.putExtra(LocationPickerActivity.EXTRA_EXISTING_LAT, lat)
            intent.putExtra(LocationPickerActivity.EXTRA_EXISTING_LNG, lng)
        }
        pickLocationLauncher.launch(intent)
    }

    private fun renderLocationRowState() {
        val hasLocation = pickedLat != null && pickedLng != null
        // Both rows reflect the *same* staged pickedLat/pickedLng — there's
        // only one location on a restaurant's profile, not one-per-row —
        // but each row's own label reflects whichever source set it most
        // recently, so re-opening this screen shows exactly what will be
        // saved rather than always defaulting to one row's wording.
        binding.useCurrentLocationRowText.text = when {
            hasLocation && pickedLocationSource == LocationSource.GPS -> getString(R.string.row_current_location_set)
            else -> getString(R.string.row_use_current_location)
        }
        binding.locationRowText.text = when {
            hasLocation && pickedLocationSource == LocationSource.MAP -> getString(R.string.row_map_location_set)
            hasLocation -> getString(R.string.row_location_set)
            else -> getString(R.string.row_choose_on_map)
        }
    }

    private fun formatDisplayTime(hour: Int, minute: Int): String {
        val amPm = if (hour < 12) "AM" else "PM"
        val hour12 = when {
            hour == 0 -> 12
            hour > 12 -> hour - 12
            else -> hour
        }
        return String.format(Locale.US, "%d:%02d %s", hour12, minute, amPm)
    }

    private fun save() {
        if (saveInFlight) return

        val name = binding.inputName.text?.toString()?.trim().orEmpty()
        if (name.isEmpty()) {
            binding.inputName.error = getString(R.string.error_fill_all_fields)
            return
        }

        val selectedDays = dayChips.filter { it.value.isChecked }.keys.sorted()
        if (selectedDays.isEmpty()) {
            InAppNotifier.show(this, getString(R.string.error_working_days_required), InAppNotifier.Type.ERROR)
            return
        }

        saveInFlight = true
        binding.btnSaveProfile.isEnabled = false

        lifecycleScope.launch {
            try {
                // Upload the logo first (if a new one was picked) so its
                // path can be included in the same profile-update.php
                // call as everything else — same split H6's
                // address-photo.php + addresses.php uses.
                var logoUrlToSave: String? = null
                val uri = pickedLogoUri
                if (uri != null) {
                    logoUrlToSave = uploadLogo(uri)
                    if (logoUrlToSave == null) {
                        InAppNotifier.show(this@EditProfileActivity, getString(R.string.logo_upload_failed), InAppNotifier.Type.ERROR)
                        saveInFlight = false
                        binding.btnSaveProfile.isEnabled = true
                        return@launch
                    }
                }

                val body = ProfileUpdateBody(
                    name = name,
                    address = binding.inputAddress.text?.toString()?.trim().orEmpty(),
                    latitude = pickedLat,
                    longitude = pickedLng,
                    cuisineTags = binding.inputCuisineTags.text?.toString()?.trim().orEmpty(),
                    openingTime = String.format(Locale.US, "%02d:%02d", openingHour, openingMinute),
                    closingTime = String.format(Locale.US, "%02d:%02d", closingHour, closingMinute),
                    workingDays = selectedDays.joinToString(","),
                    description = binding.inputDescription.text?.toString()?.trim().orEmpty(),
                    logoUrl = logoUrlToSave,
                    // recall.md Phase B item 13 / migration 36 — blank
                    // field means "leave unchanged" (profile-update.php
                    // treats a missing key that way); an actual number
                    // gets server-validated against the area floor.
                    minOrderAmount = binding.inputMinOrderAmount.text?.toString()?.trim()
                        ?.takeIf { it.isNotEmpty() }?.toDoubleOrNull()
                )

                val response = api.updateProfile(body)
                val updated = response.body()?.data?.restaurant
                if (response.isSuccessful && updated != null) {
                    tokenManager.updateRestaurantName(updated.name)
                    InAppNotifier.show(this@EditProfileActivity, getString(R.string.profile_saved), InAppNotifier.Type.SUCCESS)
                    setResult(RESULT_OK)
                    finish()
                } else {
                    // recall.md Phase B item 13 — surface the actual area
                    // floor when that's why the save failed, instead of
                    // the generic failure toast, so the owner knows what
                    // number to type instead of guessing.
                    val floorMessage = parseAreaFloorError(response.errorBody()?.string())
                    InAppNotifier.show(
                        this@EditProfileActivity,
                        floorMessage ?: getString(R.string.profile_save_failed),
                        InAppNotifier.Type.ERROR
                    )
                }
            } catch (e: Exception) {
                InAppNotifier.show(this@EditProfileActivity, getString(R.string.profile_save_failed), InAppNotifier.Type.ERROR)
            } finally {
                saveInFlight = false
                binding.btnSaveProfile.isEnabled = true
            }
        }
    }

    /** Same copy-to-cache-file-then-multipart-upload approach as
     * MapPinDropActivity.uploadPhotoIfNeeded() — content Uris from
     * GetContent() aren't guaranteed to expose a real filesystem path. */
    private suspend fun uploadLogo(uri: Uri): String? {
        val mimeType = contentResolver.getType(uri) ?: "image/jpeg"
        val ext = when (mimeType) {
            "image/png" -> "png"
            "image/webp" -> "webp"
            else -> "jpg"
        }
        val tempFile = File(cacheDir, "logo_upload.$ext")
        contentResolver.openInputStream(uri)?.use { input ->
            FileOutputStream(tempFile).use { output -> input.copyTo(output) }
        } ?: return null

        val requestBody = tempFile.asRequestBody(mimeType.toMediaTypeOrNull())
        val part = MultipartBody.Part.createFormData("logo", tempFile.name, requestBody)

        val response = api.uploadLogo(part)
        tempFile.delete()

        return if (response.isSuccessful && response.body()?.success == true) {
            response.body()?.data?.logoUrl
        } else {
            null
        }
    }

    /** recall.md Phase B item 13 — pulls `data.area_floor` out of a
     * `min_order_below_area_floor` error body, ad hoc with a fresh
     * Gson/JsonParser same as readProfileExtra() below, since
     * ApiResponse<T>'s `data` is only typed for the success shape and
     * this is the one error whose body carries information worth
     * showing the owner. Returns null for any other error (including
     * a body that fails to parse at all) so the caller falls back to
     * the generic failure message. */
    private fun parseAreaFloorError(errorBodyRaw: String?): String? {
        if (errorBodyRaw.isNullOrBlank()) return null
        return try {
            val obj = com.google.gson.JsonParser.parseString(errorBodyRaw).asJsonObject
            if (obj.get("error")?.asString != "min_order_below_area_floor") return null
            val floor = obj.getAsJsonObject("data")?.get("area_floor")?.asDouble ?: return null
            getString(R.string.min_order_below_area_floor, formatAmountForInput(floor))
        } catch (e: Exception) {
            null
        }
    }

    /** Trims a trailing ".0" for a whole-rupee amount (₹150 instead of
     * ₹150.0) but keeps real paise (₹150.5) — same idea as every other
     * currency display spot in this app, just inlined here since this
     * is the only place in this screen formatting a raw Double back
     * into editable/display text. */
    private fun formatAmountForInput(amount: Double): String {
        return if (amount == amount.toLong().toDouble()) {
            amount.toLong().toString()
        } else {
            amount.toString()
        }
    }

    /** Profile is passed in as a JSON string (EXTRA_PROFILE_JSON) rather
     * than a real Parcelable — RestaurantProfileDetail lives in the
     * network layer and isn't Parcelable, and re-fetching here would
     * just be a redundant getProfile() call when AccountFragment already
     * has the data from its own load. Deserialized with a plain Gson
     * instance, same shape the rest of the app's network layer uses. */
    private fun readProfileExtra(): RestaurantProfileDetail? {
        val json = intent.getStringExtra(EXTRA_PROFILE_JSON) ?: return null
        return try {
            com.google.gson.Gson().fromJson(json, RestaurantProfileDetail::class.java)
        } catch (e: Exception) {
            null
        }
    }

    companion object {
        const val EXTRA_PROFILE_JSON = "extra_profile_json"
    }
}
