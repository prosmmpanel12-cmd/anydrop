package com.anydrop.rider.ui.login

import android.content.Intent
import android.os.Bundle
import android.util.Patterns
import android.view.View
import android.view.animation.AnimationUtils
import androidx.appcompat.app.AppCompatActivity
import androidx.lifecycle.lifecycleScope
import com.anydrop.rider.R
import com.anydrop.rider.data.TokenManager
import com.anydrop.rider.databinding.ActivityLoginBinding
import com.anydrop.rider.network.ApiClient
import com.anydrop.rider.network.RequestOtpBody
import com.anydrop.rider.network.parseApiError
import com.anydrop.rider.ui.common.InAppNotifier
import com.anydrop.rider.ui.pending.ApplicationStatusActivity
import kotlinx.coroutines.launch

/**
 * Entry point for rider auth. Riders are email-OTP-only (passwordless,
 * same as customers) — see rider-verify-otp.php's own kdoc, which does
 * double duty as both login (existing rider) and step 2 of signup (new
 * email). This screen only collects the email and requests an OTP;
 * OtpVerifyActivity decides whether to route to the signup form or
 * straight into an authenticated session based on `account_exists`.
 *
 * "Sign up" link reuses the same OTP flow — new email → OtpVerifyActivity
 * gets account_exists=false → routes to SignupActivity automatically.
 * We prefill the email field (if already typed) so the rider doesn't
 * have to retype it.
 */
class LoginActivity : AppCompatActivity() {

    private lateinit var binding: ActivityLoginBinding
    private val api by lazy { ApiClient.create(this) }
    private lateinit var tokenManager: TokenManager

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityLoginBinding.inflate(layoutInflater)
        setContentView(binding.root)

        tokenManager = TokenManager(this)
        if (tokenManager.isLoggedIn()) {
            goToStatus()
            return
        }

        playEntranceAnimation()

        binding.btnSendOtp.setOnClickListener { attemptSendOtp() }

        // "Sign up" routes through the same OTP flow — OtpVerifyActivity will
        // detect account_exists=false and forward to SignupActivity with the
        // verified email. We prefill whatever the rider already typed so they
        // don't have to enter it again.
        binding.btnGoSignup.setOnClickListener {
            val typedEmail = binding.inputEmail.text?.toString()?.trim()?.lowercase().orEmpty()
            if (typedEmail.isNotEmpty() && Patterns.EMAIL_ADDRESS.matcher(typedEmail).matches()) {
                // Email already filled and valid — send OTP straight away
                attemptSendOtp()
            } else {
                // Email empty or invalid — scroll focus to the field with a hint
                binding.inputEmail.requestFocus()
                InAppNotifier.show(
                    this,
                    getString(R.string.signup_enter_email_hint),
                    InAppNotifier.Type.INFO
                )
            }
        }
    }

    private fun playEntranceAnimation() {
        val views = listOf(
            binding.loginTitle, binding.loginSubtitle, binding.inputEmail,
            binding.btnSendOtp, binding.loginSignupRow
        )
        views.forEachIndexed { index, view ->
            val anim = AnimationUtils.loadAnimation(this, R.anim.form_field_in).apply {
                startOffset = index * 60L
            }
            view.startAnimation(anim)
        }
    }

    private fun attemptSendOtp() {
        val email = binding.inputEmail.text?.toString()?.trim()?.lowercase().orEmpty()

        if (email.isEmpty()) {
            InAppNotifier.show(this, getString(R.string.error_fill_all_fields), InAppNotifier.Type.INFO)
            return
        }
        if (!Patterns.EMAIL_ADDRESS.matcher(email).matches()) {
            InAppNotifier.show(this, getString(R.string.error_invalid_email), InAppNotifier.Type.ERROR)
            return
        }

        setLoading(true)
        lifecycleScope.launch {
            try {
                val response = api.requestOtp(RequestOtpBody(email))
                if (response.isSuccessful && response.body()?.success == true) {
                    val debugOtp = response.body()?.data?.debugOtp
                    goToOtpVerify(email, debugOtp)
                } else {
                    // Non-2xx: the real error code only lives in errorBody() —
                    // see network/ErrorParsing.kt's kdoc.
                    val parsed = parseApiError(response.errorBody())
                    InAppNotifier.show(this@LoginActivity, friendlyError(parsed.code), InAppNotifier.Type.ERROR)
                    setLoading(false)
                }
            } catch (e: Exception) {
                InAppNotifier.show(this@LoginActivity, getString(R.string.error_network), InAppNotifier.Type.ERROR)
                setLoading(false)
            }
        }
    }

    private fun friendlyError(error: String?): String = when (error) {
        "otp_request_cooldown" -> "Please wait a moment before requesting another code"
        "validation_error" -> "Please check the email you entered"
        else -> "Couldn't send OTP — please try again"
    }

    private fun goToOtpVerify(email: String, debugOtp: String?) {
        setLoading(false)
        val intent = Intent(this, OtpVerifyActivity::class.java).apply {
            putExtra(OtpVerifyActivity.EXTRA_EMAIL, email)
            putExtra(OtpVerifyActivity.EXTRA_DEBUG_OTP, debugOtp)
        }
        startActivity(intent)
        overridePendingTransition(R.anim.slide_in_right, R.anim.slide_out_left)
    }

    private fun goToStatus() {
        startActivity(Intent(this, ApplicationStatusActivity::class.java))
        finish()
    }

    private fun setLoading(loading: Boolean) {
        binding.loginProgress.visibility = if (loading) View.VISIBLE else View.GONE
        binding.btnSendOtp.isEnabled = !loading
    }
}
