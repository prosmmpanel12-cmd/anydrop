package com.anydrop.food.ui.login

import android.content.Intent
import android.os.Bundle
import android.text.SpannableString
import android.text.Spanned
import android.text.method.LinkMovementMethod
import android.text.style.ClickableSpan
import android.view.View
import androidx.appcompat.app.AppCompatActivity
import androidx.lifecycle.lifecycleScope
import com.anydrop.food.data.AppConfigCache
import com.anydrop.food.data.TokenManager
import com.anydrop.food.databinding.ActivityLoginBinding
import com.anydrop.food.network.ApiClient
import com.anydrop.food.network.RequestOtpBody
import com.anydrop.food.network.VerifyOtpBody
import com.anydrop.food.ui.common.BannerCarousel
import com.anydrop.food.ui.common.InAppNotifier
import com.anydrop.food.ui.home.HomeActivity
import com.anydrop.food.ui.webview.WebViewActivity
import kotlinx.coroutines.launch

class LoginActivity : AppCompatActivity() {

    companion object {
        // Doc 26 — HomeActivity's suspension observer passes this when it
        // force-navigates here after a mid-session `account_suspended`
        // 403; it's whatever the backend's `data.reason` held, or null.
        const val EXTRA_SUSPENSION_REASON = "extra_suspension_reason"
    }

    private lateinit var binding: ActivityLoginBinding
    private lateinit var tokenManager: TokenManager
    private val api by lazy { ApiClient.create(this) }
    private var currentEmail: String = ""

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityLoginBinding.inflate(layoutInflater)
        setContentView(binding.root)
        tokenManager = TokenManager(this)

        binding.btnSendOtp.setOnClickListener { onSendOtp() }
        binding.btnVerifyOtp.setOnClickListener { onVerifyOtp() }
        binding.btnResendOtp.setOnClickListener { onSendOtp(isResend = true) }
        binding.btnGoogle.setOnClickListener {
            // POST /auth/customer/google is not implemented on the backend yet
            // (see docs/Status.md — "Pending"). Email OTP is the only working
            // login path for now.
            InAppNotifier.show(this, getString(com.anydrop.food.R.string.google_coming_soon), InAppNotifier.Type.INFO)
        }

        loadBanner()
        setupLegalLinks()

        // Doc 26 — shown once, right after arriving here. Falls back to a
        // generic message since `suspension_reason` is a nullable column.
        if (intent.hasExtra(EXTRA_SUSPENSION_REASON)) {
            val reason = intent.getStringExtra(EXTRA_SUSPENSION_REASON)
            InAppNotifier.show(
                this,
                "Your account was suspended" + (reason?.let { ": $it" } ?: ". Contact support for details."),
                InAppNotifier.Type.ERROR
            )
        }
    }

    /**
     * Rotates through up to 8 bundled banner images (login_banner_1 ..
     * login_banner_8 in res/drawable) every ~2.8s with a crossfade + slow
     * zoom. Falls back to the single backend bannerImageUrl (cached from
     * splash) if no bundled images are found yet.
     */
    private fun loadBanner() {
        BannerCarousel.start(
            context = this,
            scope = lifecycleScope,
            viewA = binding.loginBannerImageA,
            viewB = binding.loginBannerImageB,
            fallbackUrl = AppConfigCache.splashConfig?.bannerImageUrl,
        )
    }

    private fun setupLegalLinks() {
        val config = AppConfigCache.splashConfig
        val terms = config?.termsUrl
        val privacy = config?.privacyUrl
        val content = config?.contentPolicyUrl

        val fullText = "By continuing, you agree to our Terms of Service, Privacy Policy and Content Policy"
        val spannable = SpannableString(fullText)

        fun linkify(label: String, url: String?) {
            if (url.isNullOrBlank()) return
            val start = fullText.indexOf(label)
            if (start == -1) return
            spannable.setSpan(object : ClickableSpan() {
                override fun onClick(widget: View) {
                    openLegalPage(url, label)
                }
            }, start, start + label.length, Spanned.SPAN_EXCLUSIVE_EXCLUSIVE)
        }

        linkify("Terms of Service", terms)
        linkify("Privacy Policy", privacy)
        linkify("Content Policy", content)

        binding.legalLinksText.text = spannable
        binding.legalLinksText.movementMethod = LinkMovementMethod.getInstance()
    }

    private fun openLegalPage(url: String, title: String) {
        startActivity(
            Intent(this, WebViewActivity::class.java)
                .putExtra(WebViewActivity.EXTRA_URL, url)
                .putExtra(WebViewActivity.EXTRA_TITLE, title)
        )
    }

    private fun onSendOtp(isResend: Boolean = false) {
        val email = binding.emailInput.text?.toString()?.trim().orEmpty()
        if (!isResend) {
            if (email.isEmpty() || !android.util.Patterns.EMAIL_ADDRESS.matcher(email).matches()) {
                binding.emailLayout.error = "Enter a valid email"
                return
            }
            binding.emailLayout.error = null
            currentEmail = email
        }

        setLoading(true)
        lifecycleScope.launch {
            try {
                val response = api.requestOtp(RequestOtpBody(currentEmail))
                setLoading(false)
                if (response.isSuccessful && response.body()?.success == true) {
                    binding.emailStepGroup.visibility = android.view.View.GONE
                    binding.otpStepGroup.visibility = android.view.View.VISIBLE
                    binding.otpSubtitle.text = getString(com.anydrop.food.R.string.otp_subtitle, currentEmail)
                    InAppNotifier.show(this@LoginActivity, "OTP sent to $currentEmail", InAppNotifier.Type.SUCCESS)
                } else {
                    // Same root-cause fix as CheckoutActivity's placeOrder() —
                    // response.body() is null on this non-2xx branch; the real
                    // error code is only in errorBody(). See ApiErrorParser's kdoc.
                    val err = com.anydrop.food.network.ApiErrorParser.parse(response).code ?: "Could not send OTP"
                    InAppNotifier.show(this@LoginActivity, err, InAppNotifier.Type.ERROR)
                }
            } catch (e: Exception) {
                setLoading(false)
                InAppNotifier.show(
                    this@LoginActivity,
                    "Couldn't reach the server. Is the backend running?",
                    InAppNotifier.Type.ERROR
                )
            }
        }
    }

    private fun onVerifyOtp() {
        val otp = binding.otpInput.text?.toString()?.trim().orEmpty()
        if (otp.length < 4) {
            binding.otpLayout.error = "Enter the code"
            return
        }
        binding.otpLayout.error = null

        setLoading(true)
        lifecycleScope.launch {
            try {
                val response = api.verifyOtp(VerifyOtpBody(currentEmail, otp))
                setLoading(false)
                val body = response.body()
                if (response.isSuccessful && body?.success == true && body.data?.token != null) {
                    tokenManager.saveSession(
                        token = body.data.token,
                        customerId = body.data.customer?.id ?: 0,
                        email = currentEmail
                    )
                    InAppNotifier.show(this@LoginActivity, "Welcome to Anydrop!", InAppNotifier.Type.SUCCESS)
                    startActivity(Intent(this@LoginActivity, HomeActivity::class.java))
                    finish()
                } else {
                    val err = body?.error ?: "Invalid or expired code"
                    InAppNotifier.show(this@LoginActivity, err, InAppNotifier.Type.ERROR)
                }
            } catch (e: Exception) {
                setLoading(false)
                InAppNotifier.show(
                    this@LoginActivity,
                    "Couldn't reach the server. Is the backend running?",
                    InAppNotifier.Type.ERROR
                )
            }
        }
    }

    private fun setLoading(loading: Boolean) {
        binding.loginProgress.visibility = if (loading) android.view.View.VISIBLE else android.view.View.GONE
        binding.btnSendOtp.isEnabled = !loading
        binding.btnVerifyOtp.isEnabled = !loading
    }
}
