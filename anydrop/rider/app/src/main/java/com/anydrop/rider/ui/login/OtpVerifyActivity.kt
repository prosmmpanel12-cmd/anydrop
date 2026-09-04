package com.anydrop.rider.ui.login

import android.content.Intent
import android.os.Bundle
import android.os.CountDownTimer
import android.text.Editable
import android.text.TextWatcher
import android.view.KeyEvent
import android.view.View
import android.view.animation.AnimationUtils
import androidx.appcompat.app.AppCompatActivity
import androidx.lifecycle.lifecycleScope
import com.anydrop.rider.R
import com.anydrop.rider.data.TokenManager
import com.anydrop.rider.databinding.ActivityOtpVerifyBinding
import com.anydrop.rider.network.ApiClient
import com.anydrop.rider.network.RequestOtpBody
import com.anydrop.rider.network.VerifyOtpBody
import com.anydrop.rider.network.parseApiError
import com.anydrop.rider.ui.common.InAppNotifier
import com.anydrop.rider.ui.pending.ApplicationStatusActivity
import com.anydrop.rider.ui.signup.SignupActivity
import kotlinx.coroutines.launch

/**
 * Step 2 of rider auth (six auto-advancing digit boxes, same interaction
 * as the Restaurant app's OtpVerifyActivity, restyled dark/green).
 *
 * Unlike the Restaurant app, this screen does NOT collect a form first —
 * rider-verify-otp.php does double duty as both login (existing rider)
 * and step 2 of signup (new email), so a single verify call tells us
 * which branch we're on:
 *   - `account_exists=true`  -> the verify call already returned
 *     token/rider/status. This IS login — save the session and go
 *     straight to ApplicationStatusActivity, no further network call.
 *   - `account_exists=false` -> hand the verified email to SignupActivity.
 *     rider-signup.php re-checks for a fresh is_used=1 OTP row for that
 *     same email, so the signup form must be completed soon after this,
 *     not much later.
 *
 * A third, non-obvious branch: an existing rider whose account is
 * `rejected`/`suspended` gets `account_suspended` back as an *error* (no
 * token issued at all — see rider-verify-otp.php). There is nothing to
 * save a session with in that case, so we just surface the reason and
 * leave the rider on this screen.
 */
class OtpVerifyActivity : AppCompatActivity() {

    private lateinit var binding: ActivityOtpVerifyBinding
    private val api by lazy { ApiClient.create(this) }
    private lateinit var tokenManager: TokenManager
    private lateinit var email: String
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

        tokenManager = TokenManager(this)

        email = intent.getStringExtra(EXTRA_EMAIL) ?: run { finish(); return }
        val debugOtp = intent.getStringExtra(EXTRA_DEBUG_OTP)

        binding.otpSubtitle.text = getString(R.string.otp_subtitle_format, email)
        binding.btnBack.setOnClickListener { finishWithSlide() }
        binding.btnVerify.setOnClickListener { attemptVerify() }
        binding.txtResend.setOnClickListener { requestOtp(isResend = true) }

        setupAutoAdvance()
        playEntranceAnimation()
        startResendCountdown()

        // Dev/staging convenience only — mirrors debug_otp_enabled gating
        // already used by customer-request-otp.php, never populated
        // against production.
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

    /** Standard OTP-box behaviour: typing a digit jumps focus to the next
     * box, backspace on an empty box jumps back. */
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
                val response = api.verifyOtp(VerifyOtpBody(email, otp))
                val result = response.body()?.data

                if (response.isSuccessful && response.body()?.success == true && result != null) {
                    if (result.accountExists) {
                        val rider = result.rider
                        val token = result.token
                        val status = result.status
                        if (rider != null && token != null && status != null) {
                            tokenManager.saveSession(token, rider.id, rider.name, status, rider.rejectionReason)
                            goToStatus()
                        } else {
                            // Shouldn't happen per the endpoint's own contract, but
                            // don't crash on a malformed response.
                            InAppNotifier.show(this@OtpVerifyActivity, "Something went wrong — please try again", InAppNotifier.Type.ERROR)
                            setLoading(false)
                        }
                    } else {
                        goToSignup()
                    }
                    return@launch
                }

                // Non-2xx: the real error code only lives in errorBody() —
                // see ErrorParsing.kt's kdoc.
                val parsed = parseApiError(response.errorBody())
                if (parsed.code == "account_suspended") {
                    handleAccountBlocked(parsed.status, parsed.reason)
                } else {
                    shakeBoxes()
                    clearBoxes()
                    InAppNotifier.show(this@OtpVerifyActivity, friendlyOtpError(parsed.code), InAppNotifier.Type.ERROR)
                }
                setLoading(false)
            } catch (e: Exception) {
                InAppNotifier.show(this@OtpVerifyActivity, getString(R.string.error_network), InAppNotifier.Type.ERROR)
                setLoading(false)
            }
        }
    }

    /** rider-verify-otp.php issues no token at all for a rejected/suspended
     * rider — there's no session to save and nowhere authenticated to send
     * them, so this screen is where the news has to land. */
    private fun handleAccountBlocked(status: String?, reason: String?) {
        val title = when (status) {
            "suspended" -> getString(R.string.status_suspended_title)
            else -> getString(R.string.status_rejected_title)
        }
        val message = if (!reason.isNullOrBlank()) "$title — $reason" else title
        clearBoxes()
        InAppNotifier.show(this, message, InAppNotifier.Type.ERROR)
    }

    private fun requestOtp(isResend: Boolean) {
        lifecycleScope.launch {
            try {
                val response = api.requestOtp(RequestOtpBody(email))
                if (response.isSuccessful && response.body()?.success == true) {
                    if (isResend) {
                        InAppNotifier.show(this@OtpVerifyActivity, getString(R.string.otp_resent), InAppNotifier.Type.SUCCESS)
                        clearBoxes()
                        startResendCountdown()
                    }
                } else if (isResend) {
                    val parsed = parseApiError(response.errorBody())
                    InAppNotifier.show(this@OtpVerifyActivity, friendlyOtpError(parsed.code), InAppNotifier.Type.ERROR)
                }
            } catch (e: Exception) {
                if (isResend) {
                    InAppNotifier.show(this@OtpVerifyActivity, getString(R.string.error_network), InAppNotifier.Type.ERROR)
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

    private fun friendlyOtpError(error: String?): String = when (error) {
        "invalid_otp" -> getString(R.string.error_otp_invalid)
        "otp_expired" -> getString(R.string.error_otp_expired)
        "otp_not_found" -> getString(R.string.error_otp_expired)
        "otp_max_attempts_exceeded" -> "Too many attempts — request a new code"
        "otp_request_cooldown" -> "Please wait a moment before requesting another code"
        else -> "Something went wrong — please try again"
    }

    private fun goToSignup() {
        setLoading(false)
        val intent = Intent(this, SignupActivity::class.java).apply {
            putExtra(SignupActivity.EXTRA_EMAIL, email)
        }
        startActivity(intent)
        overridePendingTransition(R.anim.slide_in_right, R.anim.slide_out_left)
        finish()
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
        binding.otpProgress.visibility = if (loading) View.VISIBLE else View.GONE
        binding.btnVerify.isEnabled = !loading
        digitBoxes.forEach { it.isEnabled = !loading }
    }

    companion object {
        const val EXTRA_EMAIL = "extra_email"
        const val EXTRA_DEBUG_OTP = "extra_debug_otp"
    }
}
