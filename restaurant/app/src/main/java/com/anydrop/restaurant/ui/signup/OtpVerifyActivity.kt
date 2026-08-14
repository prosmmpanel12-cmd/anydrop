package com.anydrop.restaurant.ui.signup

import android.content.Intent
import android.os.Bundle
import android.os.CountDownTimer
import android.text.Editable
import android.text.TextWatcher
import android.view.KeyEvent
import android.view.View
import android.view.animation.AnimationUtils
import android.widget.EditText
import androidx.appcompat.app.AppCompatActivity
import androidx.lifecycle.lifecycleScope
import com.anydrop.restaurant.R
import com.anydrop.restaurant.databinding.ActivityOtpVerifyBinding
import com.anydrop.restaurant.network.ApiClient
import com.anydrop.restaurant.network.RequestOtpBody
import com.anydrop.restaurant.network.SignupBody
import com.anydrop.restaurant.network.VerifyOtpBody
import com.anydrop.restaurant.ui.common.InAppNotifier
import kotlinx.coroutines.launch

/**
 * Step 2 of Restaurant Partner Signup. Six auto-advancing digit boxes
 * (the common Zomato/Swiggy OTP pattern) instead of one 6-char field.
 * On successful verify, immediately calls /auth/restaurant-signup.php
 * with the SignupDraft carried over from SignupActivity — the account
 * (status='pending') is only created here, after email ownership is
 * actually proven.
 */
class OtpVerifyActivity : AppCompatActivity() {

    private lateinit var binding: ActivityOtpVerifyBinding
    private val api by lazy { ApiClient.create(this) }
    private lateinit var draft: SignupDraft
    private var resendTimer: CountDownTimer? = null

    private val digitBoxes by lazy {
        listOf(
            binding.otpDigit1, binding.otpDigit2, binding.otpDigit3,
            binding.otpDigit4, binding.otpDigit5, binding.otpDigit6
        )
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityOtpVerifyBinding.inflate(layoutInflater)
        setContentView(binding.root)

        @Suppress("DEPRECATION")
        draft = intent.getParcelableExtra(EXTRA_DRAFT) ?: run { finish(); return }
        val debugOtp = intent.getStringExtra(EXTRA_DEBUG_OTP)

        binding.otpSubtitle.text = getString(R.string.otp_subtitle_format, draft.email)
        binding.btnBack.setOnClickListener { finishWithSlide() }
        binding.btnVerify.setOnClickListener { attemptVerify() }
        binding.txtResend.setOnClickListener { requestOtp(isResend = true) }

        setupAutoAdvance()
        playEntranceAnimation()
        startResendCountdown()

        // Dev/staging convenience only — mirrors debug_otp_enabled gating already
        // used by customer-request-otp.php, never populated against production.
        if (!debugOtp.isNullOrEmpty()) {
            InAppNotifier.show(this, "Debug OTP: $debugOtp", InAppNotifier.Type.INFO)
        }
    }

    override fun onBackPressed() {
        super.onBackPressed()
        finishWithSlide()
    }

    override fun onDestroy() {
        super.onDestroy()
        resendTimer?.cancel()
    }

    private fun finishWithSlide() {
        finish()
        overridePendingTransition(R.anim.slide_in_left, R.anim.slide_out_right)
    }

    private fun playEntranceAnimation() {
        val views = listOf(binding.otpTitle, binding.otpSubtitle, binding.otpBoxRow, binding.btnVerify)
        views.forEachIndexed { index, view ->
            val anim = AnimationUtils.loadAnimation(this, R.anim.form_field_in).apply {
                startOffset = index * 70L
            }
            view.startAnimation(anim)
        }
    }

    /** Standard OTP-box behaviour: typing a digit jumps focus to the next box,
     * backspace on an empty box jumps back — same interaction every OTP screen
     * in Zomato/Swiggy-style apps uses. */
    private fun setupAutoAdvance() {
        digitBoxes.forEachIndexed { index, box ->
            box.addTextChangedListener(object : TextWatcher {
                override fun beforeTextChanged(s: CharSequence?, start: Int, count: Int, after: Int) {}
                override fun onTextChanged(s: CharSequence?, start: Int, before: Int, count: Int) {}
                override fun afterTextChanged(s: Editable?) {
                    if (!s.isNullOrEmpty() && index < digitBoxes.lastIndex) {
                        digitBoxes[index + 1].requestFocus()
                    }
                }
            })
            box.setOnKeyListener { _, keyCode, event ->
                if (keyCode == KeyEvent.KEYCODE_DEL && event.action == KeyEvent.ACTION_DOWN &&
                    box.text.isNullOrEmpty() && index > 0
                ) {
                    digitBoxes[index - 1].requestFocus()
                    digitBoxes[index - 1].text?.clear()
                    true
                } else {
                    false
                }
            }
        }
        digitBoxes.first().requestFocus()
    }

    private fun enteredOtp(): String = digitBoxes.joinToString("") { it.text?.toString().orEmpty() }

    private fun shakeBoxes() {
        val shake = AnimationUtils.loadAnimation(this, R.anim.shake_error)
        binding.otpBoxRow.startAnimation(shake)
    }

    private fun clearBoxes() {
        digitBoxes.forEach { it.text?.clear() }
        digitBoxes.first().requestFocus()
    }

    private fun attemptVerify() {
        val otp = enteredOtp()
        if (otp.length < 6) {
            shakeBoxes()
            InAppNotifier.show(this, getString(R.string.error_otp_incomplete), InAppNotifier.Type.INFO)
            return
        }

        setLoading(true)
        lifecycleScope.launch {
            try {
                val verifyResponse = api.verifySignupOtp(VerifyOtpBody(draft.email, otp))
                if (!verifyResponse.isSuccessful || verifyResponse.body()?.success != true) {
                    val error = verifyResponse.body()?.error ?: "invalid_otp"
                    shakeBoxes()
                    clearBoxes()
                    InAppNotifier.show(this@OtpVerifyActivity, friendlyOtpError(error), InAppNotifier.Type.ERROR)
                    setLoading(false)
                    return@launch
                }

                val signupResponse = api.signup(
                    SignupBody(
                        name = draft.name,
                        ownerName = draft.ownerName,
                        ownerMobile = draft.ownerMobile,
                        ownerEmail = draft.email,
                        password = draft.password,
                        address = draft.address
                    )
                )
                if (signupResponse.isSuccessful && signupResponse.body()?.success == true) {
                    goToSuccess()
                } else {
                    val error = signupResponse.body()?.error ?: "signup_failed"
                    InAppNotifier.show(this@OtpVerifyActivity, friendlySignupError(error), InAppNotifier.Type.ERROR)
                    setLoading(false)
                }
            } catch (e: Exception) {
                InAppNotifier.show(this@OtpVerifyActivity, "Network error — check your connection", InAppNotifier.Type.ERROR)
                setLoading(false)
            }
        }
    }

    private fun requestOtp(isResend: Boolean) {
        lifecycleScope.launch {
            try {
                val response = api.requestSignupOtp(RequestOtpBody(draft.email))
                if (response.isSuccessful && response.body()?.success == true) {
                    if (isResend) {
                        InAppNotifier.show(this@OtpVerifyActivity, getString(R.string.otp_resent), InAppNotifier.Type.SUCCESS)
                        clearBoxes()
                        startResendCountdown()
                    }
                } else if (isResend) {
                    val error = response.body()?.error ?: "request_failed"
                    InAppNotifier.show(this@OtpVerifyActivity, friendlyOtpError(error), InAppNotifier.Type.ERROR)
                }
            } catch (e: Exception) {
                if (isResend) {
                    InAppNotifier.show(this@OtpVerifyActivity, "Network error — check your connection", InAppNotifier.Type.ERROR)
                }
            }
        }
    }

    private fun startResendCountdown() {
        resendTimer?.cancel()
        binding.txtResend.isEnabled = false
        resendTimer = object : CountDownTimer(30_000, 1_000) {
            override fun onTick(millisUntilFinished: Long) {
                binding.txtResend.text = getString(R.string.otp_resend_in_format, (millisUntilFinished / 1000).toInt())
            }

            override fun onFinish() {
                binding.txtResend.text = getString(R.string.otp_resend_now)
                binding.txtResend.isEnabled = true
            }
        }.start()
    }

    private fun friendlyOtpError(error: String): String = when (error) {
        "invalid_otp" -> getString(R.string.error_otp_invalid)
        "otp_expired" -> getString(R.string.error_otp_expired)
        "otp_not_found" -> getString(R.string.error_otp_expired)
        "otp_max_attempts_exceeded" -> "Too many attempts — request a new code"
        "otp_request_cooldown" -> "Please wait a moment before requesting another code"
        else -> "Something went wrong — please try again"
    }

    private fun friendlySignupError(error: String): String = when (error) {
        "email_already_registered" -> getString(R.string.error_email_registered)
        "email_not_verified" -> "Verification expired — please verify again"
        "validation_error" -> "Please check the details you entered"
        else -> "Couldn't submit your application — please try again"
    }

    private fun goToSuccess() {
        setLoading(false)
        startActivity(Intent(this, SignupSuccessActivity::class.java))
        overridePendingTransition(R.anim.slide_in_right, R.anim.slide_out_left)
        finish()
    }

    private fun setLoading(loading: Boolean) {
        binding.otpProgress.visibility = if (loading) View.VISIBLE else View.GONE
        binding.btnVerify.isEnabled = !loading
        digitBoxes.forEach { it.isEnabled = !loading }
    }

    companion object {
        const val EXTRA_DRAFT = "extra_signup_draft"
        const val EXTRA_DEBUG_OTP = "extra_debug_otp"
    }
}
