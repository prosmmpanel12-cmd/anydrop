package com.anydrop.food.ui.login

import android.content.Intent
import android.os.Bundle
import androidx.appcompat.app.AppCompatActivity
import androidx.lifecycle.lifecycleScope
import com.anydrop.food.data.TokenManager
import com.anydrop.food.databinding.ActivityCompleteProfileBinding
import com.anydrop.food.network.ApiClient
import com.anydrop.food.network.ApiErrorParser
import com.anydrop.food.network.CompleteProfileBody
import com.anydrop.food.ui.common.InAppNotifier
import com.anydrop.food.ui.home.HomeActivity
import kotlinx.coroutines.launch

/**
 * One-time "tell us your name + number" step. LoginActivity.onVerifyOtp()
 * routes here instead of straight to HomeActivity when the just-logged-in
 * customer's name or mobile comes back null from verify-otp (i.e. a
 * brand-new email-OTP signup — see complete-profile.php's kdoc). A
 * returning customer who already completed this on a previous login skips
 * straight to Home, since verify-otp will return both fields already set.
 */
class CompleteProfileActivity : AppCompatActivity() {

    private lateinit var binding: ActivityCompleteProfileBinding
    private lateinit var tokenManager: TokenManager
    private val api by lazy { ApiClient.create(this) }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityCompleteProfileBinding.inflate(layoutInflater)
        setContentView(binding.root)
        tokenManager = TokenManager(this)

        binding.btnSaveProfile.setOnClickListener { onSave() }
    }

    private fun onSave() {
        val name = binding.nameInput.text?.toString()?.trim().orEmpty()
        val mobile = binding.mobileInput.text?.toString()?.trim().orEmpty()

        var hasError = false
        if (name.isEmpty()) {
            binding.nameLayout.error = "Enter your name"
            hasError = true
        } else {
            binding.nameLayout.error = null
        }
        if (mobile.length != 10 || !mobile.all { it.isDigit() }) {
            binding.mobileLayout.error = "Enter a valid 10-digit number"
            hasError = true
        } else {
            binding.mobileLayout.error = null
        }
        if (hasError) return

        setLoading(true)
        lifecycleScope.launch {
            try {
                val response = api.completeProfile(CompleteProfileBody(name, mobile))
                setLoading(false)
                val body = response.body()
                if (response.isSuccessful && body?.success == true) {
                    tokenManager.setProfileComplete(name, mobile)
                    startActivity(Intent(this@CompleteProfileActivity, HomeActivity::class.java))
                    finish()
                } else {
                    val err = ApiErrorParser.parse(response)
                    val message = when (err.code) {
                        "mobile_already_in_use" -> "This mobile number is already linked to another account"
                        "validation_error" -> "Please check your name and mobile number"
                        else -> err.code ?: "Couldn't save your details"
                    }
                    InAppNotifier.show(this@CompleteProfileActivity, message, InAppNotifier.Type.ERROR)
                }
            } catch (e: Exception) {
                setLoading(false)
                InAppNotifier.show(
                    this@CompleteProfileActivity,
                    "Couldn't reach the server. Is the backend running?",
                    InAppNotifier.Type.ERROR
                )
            }
        }
    }

    private fun setLoading(loading: Boolean) {
        binding.completeProfileProgress.visibility = if (loading) android.view.View.VISIBLE else android.view.View.GONE
        binding.btnSaveProfile.isEnabled = !loading
    }
}
