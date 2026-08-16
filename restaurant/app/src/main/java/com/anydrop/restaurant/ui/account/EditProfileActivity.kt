package com.anydrop.restaurant.ui.account

import android.content.Intent
import android.net.Uri
import android.os.Bundle
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

    // Staged location pick — null until the user actually opens the map
    // and confirms, same "only applied on Save" pattern as pickedLogoUri
    // above. Pre-filled from the loaded profile in populate() if one was
    // already saved, so re-saving the form without touching the location
    // row doesn't accidentally clear it.
    private var pickedLat: Double? = null
    private var pickedLng: Double? = null

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
            if (uri != null) {
                pickedLogoUri = uri
                // See populate()'s comment below — activity_edit_profile.xml's
                // app:tint on logoPreview is meant for the ic_store
                // placeholder only, and must be cleared once a real image
                // (here, the freshly-picked local Uri) is showing.
                binding.logoPreview.imageTintList = null
                binding.logoPreview.load(uri) {
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
                    renderLocationRowState()
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
        binding.locationRowText.text = if (pickedLat != null && pickedLng != null) {
            getString(R.string.row_location_set)
        } else {
            getString(R.string.row_set_location)
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
                    logoUrl = logoUrlToSave
                )

                val response = api.updateProfile(body)
                val updated = response.body()?.data?.restaurant
                if (response.isSuccessful && updated != null) {
                    tokenManager.updateRestaurantName(updated.name)
                    InAppNotifier.show(this@EditProfileActivity, getString(R.string.profile_saved), InAppNotifier.Type.SUCCESS)
                    setResult(RESULT_OK)
                    finish()
                } else {
                    InAppNotifier.show(this@EditProfileActivity, getString(R.string.profile_save_failed), InAppNotifier.Type.ERROR)
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
