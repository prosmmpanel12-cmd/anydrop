package com.anydrop.rider.ui.signup

import android.Manifest
import android.annotation.SuppressLint
import android.content.Intent
import android.content.pm.PackageManager
import android.os.Bundle
import android.view.View
import android.widget.AdapterView
import android.widget.ArrayAdapter
import android.widget.Spinner
import androidx.activity.result.contract.ActivityResultContracts
import androidx.appcompat.app.AppCompatActivity
import androidx.core.content.ContextCompat
import androidx.lifecycle.lifecycleScope
import com.anydrop.rider.R
import com.anydrop.rider.data.TokenManager
import com.anydrop.rider.databinding.ActivitySignupBinding
import com.anydrop.rider.network.ApiClient
import com.anydrop.rider.network.ServiceArea
import com.anydrop.rider.network.SignupBody
import com.anydrop.rider.network.parseApiError
import com.anydrop.rider.ui.common.InAppNotifier
import com.anydrop.rider.ui.pending.ApplicationStatusActivity
import com.google.android.gms.location.LocationServices
import com.google.android.gms.location.Priority
import com.google.android.gms.tasks.CancellationTokenSource
import kotlinx.coroutines.launch

/**
 * Step 3 of rider signup (name, mobile, service area only — vehicle
 * type/number and document uploads are explicitly deferred to a
 * post-approval "Complete Profile" step, per the app-owner scope
 * decision this session). Reached only from OtpVerifyActivity with an
 * already-verified email — this screen never re-collects/re-verifies it.
 *
 * Service area is both GPS auto-detect AND a cascading
 * State -> District -> City/Village -> Area dropdown, exactly mirroring
 * rider-signup.php's own precedence: a dropdown selection always
 * overrides the GPS-resolved area, so both are sent to the backend and
 * the backend decides, rather than the app trying to pre-resolve GPS to
 * an area name itself (there is no reverse-geocode-to-service-area
 * endpoint — resolve_service_area() only runs server-side, at signup).
 */
class SignupActivity : AppCompatActivity() {

    private lateinit var binding: ActivitySignupBinding
    private val api by lazy { ApiClient.create(this) }
    private lateinit var tokenManager: TokenManager
    private lateinit var email: String

    private var pickedLat: Double? = null
    private var pickedLng: Double? = null
    private var selectedAreaId: Int? = null

    private var allAreas: List<ServiceArea> = emptyList()
    private val levelOrder = listOf("state", "district", "city_village", "area")
    private val spinners: List<Spinner> by lazy {
        listOf(binding.spinnerState, binding.spinnerDistrict, binding.spinnerCityVillage, binding.spinnerArea)
    }

    private val fusedLocationClient by lazy { LocationServices.getFusedLocationProviderClient(this) }

    private val locationPermissionLauncher =
        registerForActivityResult(ActivityResultContracts.RequestPermission()) { granted ->
            if (granted) {
                fetchCurrentLocation()
            } else {
                InAppNotifier.show(this, getString(R.string.location_permission_denied), InAppNotifier.Type.INFO)
            }
        }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivitySignupBinding.inflate(layoutInflater)
        setContentView(binding.root)

        tokenManager = TokenManager(this)
        email = intent.getStringExtra(EXTRA_EMAIL) ?: run { finish(); return }

        binding.btnBack.setOnClickListener { finishWithSlide() }
        binding.btnContinue.setOnClickListener { attemptContinue() }
        binding.rowUseLocation.setOnClickListener { onUseLocationClicked() }

        setupCascade()
        loadServiceAreas()
    }

    override fun onBackPressed() {
        super.onBackPressed()
        finishWithSlide()
    }

    private fun finishWithSlide() {
        finish()
        overridePendingTransition(R.anim.slide_in_left, R.anim.slide_out_right)
    }

    // ---- Service area dropdown (flat list, grouped client-side —
    // same approach admin/areas.php uses) ----

    private fun loadServiceAreas() {
        lifecycleScope.launch {
            try {
                val response = api.getServiceAreas()
                if (response.isSuccessful && response.body()?.success == true) {
                    allAreas = response.body()?.data?.areas ?: emptyList()
                    populateSpinner(0, null)
                }
                // Silently ignore failure — the dropdown just stays empty,
                // GPS auto-detect alone is still a valid way to sign up.
            } catch (e: Exception) {
                // Same — no dropdown options is not fatal to this screen.
            }
        }
    }

    private fun setupCascade() {
        spinners.forEachIndexed { levelIndex, spinner ->
            spinner.onItemSelectedListener = object : AdapterView.OnItemSelectedListener {
                override fun onItemSelected(parent: AdapterView<*>?, view: View?, position: Int, id: Long) {
                    @Suppress("UNCHECKED_CAST")
                    val items = spinner.tag as? List<ServiceArea> ?: emptyList()
                    for (i in levelIndex + 1 until spinners.size) {
                        spinners[i].visibility = View.GONE
                        spinners[i].adapter = null
                    }
                    if (position == 0) {
                        // "Select..." placeholder — nothing chosen at this level,
                        // so the shallower ancestor (if any) is the effective choice.
                        return
                    }
                    val chosen = items[position - 1]
                    selectedAreaId = chosen.id
                    if (levelIndex < spinners.size - 1) {
                        populateSpinner(levelIndex + 1, chosen.id)
                    }
                }

                override fun onNothingSelected(parent: AdapterView<*>?) {}
            }
        }
    }

    private fun populateSpinner(levelIndex: Int, parentId: Int?) {
        val level = levelOrder[levelIndex]
        val items = allAreas.filter { it.level == level && it.parentId == parentId }.sortedBy { it.name }
        val spinner = spinners[levelIndex]
        val labels = listOf(placeholderFor(level)) + items.map { it.name }
        spinner.adapter = ArrayAdapter(this, android.R.layout.simple_spinner_dropdown_item, labels)
        spinner.tag = items
        spinner.visibility = if (items.isEmpty()) View.GONE else View.VISIBLE
    }

    private fun placeholderFor(level: String): String = when (level) {
        "state" -> "Select state"
        "district" -> "Select district"
        "city_village" -> "Select city/village"
        else -> "Select area"
    }

    // ---- GPS auto-detect ----

    private fun onUseLocationClicked() {
        val granted = ContextCompat.checkSelfPermission(this, Manifest.permission.ACCESS_FINE_LOCATION) ==
            PackageManager.PERMISSION_GRANTED
        if (granted) {
            fetchCurrentLocation()
        } else {
            locationPermissionLauncher.launch(Manifest.permission.ACCESS_FINE_LOCATION)
        }
    }

    @SuppressLint("MissingPermission")
    private fun fetchCurrentLocation() {
        binding.locationStatusText.text = getString(R.string.detecting_location)
        binding.locationProgress.visibility = View.VISIBLE
        val cancellationSource = CancellationTokenSource()
        fusedLocationClient.getCurrentLocation(Priority.PRIORITY_BALANCED_POWER_ACCURACY, cancellationSource.token)
            .addOnSuccessListener { location ->
                binding.locationProgress.visibility = View.GONE
                if (location != null) {
                    pickedLat = location.latitude
                    pickedLng = location.longitude
                    binding.locationStatusText.text = getString(R.string.location_captured)
                } else {
                    binding.locationStatusText.text = getString(R.string.btn_use_current_location)
                    InAppNotifier.show(this, getString(R.string.location_unavailable), InAppNotifier.Type.INFO)
                }
            }
            .addOnFailureListener {
                binding.locationProgress.visibility = View.GONE
                binding.locationStatusText.text = getString(R.string.btn_use_current_location)
                InAppNotifier.show(this, getString(R.string.location_unavailable), InAppNotifier.Type.ERROR)
            }
    }

    // ---- Submit ----

    private fun attemptContinue() {
        val name = binding.inputName.text?.toString()?.trim().orEmpty()
        val mobile = binding.inputMobile.text?.toString()?.trim().orEmpty()

        if (name.isEmpty() || mobile.isEmpty()) {
            InAppNotifier.show(this, getString(R.string.error_fill_all_fields), InAppNotifier.Type.INFO)
            return
        }
        if (name.length < 2) {
            InAppNotifier.show(this, getString(R.string.error_fill_all_fields), InAppNotifier.Type.INFO)
            return
        }
        if (!mobile.matches(Regex("^[0-9]{10}$"))) {
            InAppNotifier.show(this, getString(R.string.error_invalid_mobile), InAppNotifier.Type.ERROR)
            return
        }

        setLoading(true)
        lifecycleScope.launch {
            try {
                val response = api.signup(
                    SignupBody(
                        name = name,
                        email = email,
                        mobile = mobile,
                        serviceAreaId = selectedAreaId,
                        latitude = pickedLat,
                        longitude = pickedLng
                    )
                )
                val result = response.body()?.data
                if (response.isSuccessful && response.body()?.success == true && result != null) {
                    val token = result.token
                    if (token != null) {
                        tokenManager.saveSession(token, result.rider.id, result.rider.name, result.status, result.rider.rejectionReason)
                    }
                    // Only claim "couldn't detect" when the rider actually gave
                    // us GPS coords (pickedLat != null) — if they skipped
                    // location entirely, area_resolved=false too but that's
                    // just "not provided", not "not covered".
                    if (pickedLat != null) {
                        val notice = if (result.areaResolved && result.area != null) {
                            getString(R.string.area_detected_format, result.area.name)
                        } else {
                            getString(R.string.area_not_detected)
                        }
                        InAppNotifier.show(this@SignupActivity, notice, InAppNotifier.Type.INFO)
                    }
                    goToStatus()
                } else {
                    val parsed = parseApiError(response.errorBody())
                    InAppNotifier.show(this@SignupActivity, friendlySignupError(parsed.code), InAppNotifier.Type.ERROR)
                    setLoading(false)
                }
            } catch (e: Exception) {
                InAppNotifier.show(this@SignupActivity, getString(R.string.error_network), InAppNotifier.Type.ERROR)
                setLoading(false)
            }
        }
    }

    private fun friendlySignupError(error: String?): String = when (error) {
        "email_already_registered" -> getString(R.string.error_email_registered)
        "email_not_verified" -> "Verification expired — please verify your email again"
        "validation_error" -> "Please check the details you entered"
        else -> "Couldn't submit your application — please try again"
    }

    private fun goToStatus() {
        setLoading(false)
        val intent = Intent(this, ApplicationStatusActivity::class.java).apply {
            flags = Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TASK
        }
        startActivity(intent)
        finish()
    }

    private fun setLoading(loading: Boolean) {
        binding.signupProgress.visibility = if (loading) View.VISIBLE else View.GONE
        binding.btnContinue.isEnabled = !loading
    }

    companion object {
        const val EXTRA_EMAIL = "extra_email"
    }
}
