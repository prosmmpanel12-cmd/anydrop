package com.anydrop.restaurant.ui.signup

import android.content.Intent
import android.os.Bundle
import android.util.Patterns
import android.view.View
import android.view.animation.AnimationUtils
import androidx.activity.result.contract.ActivityResultContracts
import androidx.appcompat.app.AppCompatActivity
import androidx.lifecycle.lifecycleScope
import com.anydrop.restaurant.R
import com.anydrop.restaurant.databinding.ActivitySignupBinding
import com.anydrop.restaurant.network.ApiClient
import com.anydrop.restaurant.network.RequestOtpBody
import com.anydrop.restaurant.ui.account.LocationPickerActivity
import com.anydrop.restaurant.ui.common.InAppNotifier
import kotlinx.coroutines.launch

/**
 * Step 1 of Restaurant Partner Signup (see docs/restorent/01_Signup_Login_Flow.md).
 * Collects the full restaurant + owner form up front, then requests an
 * email OTP and hands the whole form off to OtpVerifyActivity — the
 * `restaurants` row itself isn't created until OTP verification succeeds
 * (OtpVerifyActivity calls /auth/restaurant-signup.php), so nothing is
 * persisted from an abandoned signup attempt.
 */
class SignupActivity : AppCompatActivity() {

    private lateinit var binding: ActivitySignupBinding
    private val api by lazy { ApiClient.create(this) }

    // Service-area pin (§0, 2026-08-28) — null until/unless the owner taps
    // rowSetLocation and confirms a pin; stays null if skipped, exactly
    // like the optional address field above it.
    private var pickedLat: Double? = null
    private var pickedLng: Double? = null

    private val pickLocationLauncher =
        registerForActivityResult(ActivityResultContracts.StartActivityForResult()) { result ->
            if (result.resultCode == RESULT_OK) {
                val data = result.data ?: return@registerForActivityResult
                val lat = data.getDoubleExtra(LocationPickerActivity.EXTRA_RESULT_LAT, Double.NaN)
                val lng = data.getDoubleExtra(LocationPickerActivity.EXTRA_RESULT_LNG, Double.NaN)
                if (!lat.isNaN() && !lng.isNaN()) {
                    pickedLat = lat
                    pickedLng = lng
                    val addressLine = data.getStringExtra(LocationPickerActivity.EXTRA_RESULT_ADDRESS_LINE)
                    binding.locationRowText.text = getString(
                        R.string.row_service_area_location_set,
                        addressLine ?: getString(R.string.location_picker_geocode_failed)
                    )
                }
            }
        }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivitySignupBinding.inflate(layoutInflater)
        setContentView(binding.root)

        playEntranceAnimation()

        binding.btnBack.setOnClickListener { finishWithSlide() }
        binding.btnGoLogin.setOnClickListener { finishWithSlide() }
        binding.btnContinue.setOnClickListener { attemptContinue() }
        binding.rowSetLocation.setOnClickListener {
            pickLocationLauncher.launch(Intent(this, LocationPickerActivity::class.java))
        }
    }

    override fun onBackPressed() {
        super.onBackPressed()
        finishWithSlide()
    }

    private fun finishWithSlide() {
        finish()
        overridePendingTransition(R.anim.slide_in_left, R.anim.slide_out_right)
    }

    private fun playEntranceAnimation() {
        val views = listOf(
            binding.signupTitle, binding.signupSubtitle, binding.inputRestaurantName,
            binding.inputOwnerName, binding.inputOwnerMobile, binding.inputEmail,
            binding.inputPassword, binding.inputConfirmPassword, binding.inputAddress,
            binding.rowSetLocation, binding.btnContinue
        )
        views.forEachIndexed { index, view ->
            val anim = AnimationUtils.loadAnimation(this, R.anim.form_field_in).apply {
                startOffset = index * 45L
            }
            view.startAnimation(anim)
        }
    }

    private fun attemptContinue() {
        val restaurantName = binding.inputRestaurantName.text?.toString()?.trim().orEmpty()
        val ownerName = binding.inputOwnerName.text?.toString()?.trim().orEmpty()
        val ownerMobile = binding.inputOwnerMobile.text?.toString()?.trim().orEmpty()
        val email = binding.inputEmail.text?.toString()?.trim()?.lowercase().orEmpty()
        val password = binding.inputPassword.text?.toString().orEmpty()
        val confirmPassword = binding.inputConfirmPassword.text?.toString().orEmpty()
        val address = binding.inputAddress.text?.toString()?.trim().orEmpty()

        if (restaurantName.isEmpty() || ownerName.isEmpty() || ownerMobile.isEmpty() ||
            email.isEmpty() || password.isEmpty() || confirmPassword.isEmpty()
        ) {
            notify(R.string.error_fill_all_fields)
            return
        }
        if (!Patterns.EMAIL_ADDRESS.matcher(email).matches()) {
            notify(R.string.error_invalid_email)
            return
        }
        if (!ownerMobile.matches(Regex("^[0-9]{10}$"))) {
            notify(R.string.error_invalid_mobile)
            return
        }
        if (password.length < 6) {
            notify(R.string.error_password_short)
            return
        }
        if (password != confirmPassword) {
            notify(R.string.error_passwords_dont_match)
            return
        }

        setLoading(true)
        lifecycleScope.launch {
            try {
                val response = api.requestSignupOtp(RequestOtpBody(email))
                if (response.isSuccessful && response.body()?.success == true) {
                    val debugOtp = response.body()?.data?.debugOtp
                    goToOtpVerify(
                        SignupDraft(
                            name = restaurantName,
                            ownerName = ownerName,
                            ownerMobile = ownerMobile,
                            email = email,
                            password = password,
                            address = address.ifEmpty { null },
                            latitude = pickedLat,
                            longitude = pickedLng
                        ),
                        debugOtp
                    )
                } else {
                    val error = response.body()?.error ?: "request_failed"
                    InAppNotifier.show(this@SignupActivity, friendlyError(error), InAppNotifier.Type.ERROR)
                    setLoading(false)
                }
            } catch (e: Exception) {
                InAppNotifier.show(this@SignupActivity, "Network error — check your connection", InAppNotifier.Type.ERROR)
                setLoading(false)
            }
        }
    }

    private fun friendlyError(error: String): String = when (error) {
        "email_already_registered" -> getString(R.string.error_email_registered)
        "otp_request_cooldown" -> "Please wait a moment before requesting another code"
        "validation_error" -> "Please check the details you entered"
        else -> "Couldn't send OTP — please try again"
    }

    private fun goToOtpVerify(draft: SignupDraft, debugOtp: String?) {
        setLoading(false)
        val intent = Intent(this, OtpVerifyActivity::class.java).apply {
            putExtra(OtpVerifyActivity.EXTRA_DRAFT, draft)
            putExtra(OtpVerifyActivity.EXTRA_DEBUG_OTP, debugOtp)
        }
        startActivity(intent)
        overridePendingTransition(R.anim.slide_in_right, R.anim.slide_out_left)
    }

    private fun notify(resId: Int) {
        InAppNotifier.show(this, getString(resId), InAppNotifier.Type.INFO)
    }

    private fun setLoading(loading: Boolean) {
        binding.signupProgress.visibility = if (loading) View.VISIBLE else View.GONE
        binding.btnContinue.isEnabled = !loading
    }
}
