package com.anydrop.restaurant.ui.login

import android.content.Intent
import android.os.Bundle
import android.view.View
import android.view.animation.AnimationUtils
import androidx.appcompat.app.AppCompatActivity
import androidx.lifecycle.lifecycleScope
import com.anydrop.restaurant.R
import com.anydrop.restaurant.data.TokenManager
import com.anydrop.restaurant.databinding.ActivityLoginBinding
import com.anydrop.restaurant.network.ApiClient
import com.anydrop.restaurant.network.LoginBody
import com.anydrop.restaurant.ui.common.InAppNotifier
import com.anydrop.restaurant.ui.main.MainActivity
import com.anydrop.restaurant.ui.signup.SignupActivity
import kotlinx.coroutines.launch

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
            goToDashboard()
            return
        }

        playEntranceAnimation()

        binding.btnLogin.setOnClickListener { attemptLogin() }
        binding.btnGoSignup.setOnClickListener {
            startActivity(Intent(this, SignupActivity::class.java))
            overridePendingTransition(R.anim.slide_in_right, R.anim.slide_out_left)
        }
    }

    /** Same cascading fade-up used across the signup flow (res/anim/form_field_in.xml)
     * — each field/row is offset a little later than the one above it so the screen
     * doesn't just pop in all at once, matching the Zomato/Swiggy-style onboarding feel. */
    private fun playEntranceAnimation() {
        val views = listOf(
            binding.loginTitle, binding.loginSubtitle, binding.inputEmail,
            binding.inputPassword, binding.btnLogin, binding.loginSignupRow
        )
        views.forEachIndexed { index, view ->
            val anim = AnimationUtils.loadAnimation(this, R.anim.form_field_in).apply {
                startOffset = index * 60L
            }
            view.startAnimation(anim)
        }
    }

    private fun attemptLogin() {
        val email = binding.inputEmail.text?.toString()?.trim().orEmpty()
        val password = binding.inputPassword.text?.toString().orEmpty()

        if (email.isEmpty() || password.isEmpty()) {
            InAppNotifier.show(this, "Enter email and password", InAppNotifier.Type.INFO)
            return
        }

        setLoading(true)
        lifecycleScope.launch {
            try {
                val response = api.login(LoginBody(email, password))
                val result = response.body()?.data
                if (response.isSuccessful && result?.token != null && result.restaurant != null) {
                    tokenManager.saveSession(result.token, result.restaurant.id, result.restaurant.name)
                    goToDashboard()
                } else {
                    val error = response.body()?.error ?: "login_failed"
                    InAppNotifier.show(this@LoginActivity, friendlyError(error), InAppNotifier.Type.ERROR)
                    setLoading(false)
                }
            } catch (e: Exception) {
                InAppNotifier.show(this@LoginActivity, "Network error — check your connection", InAppNotifier.Type.ERROR)
                setLoading(false)
            }
        }
    }

    private fun friendlyError(error: String): String = when (error) {
        "invalid_credentials" -> "Incorrect email or password"
        "account_suspended" -> "This account is suspended — contact support"
        "pending_approval" -> "Your account is pending admin approval"
        else -> "Couldn't log in — please try again"
    }

    private fun setLoading(loading: Boolean) {
        binding.loginProgress.visibility = if (loading) View.VISIBLE else View.GONE
        binding.btnLogin.isEnabled = !loading
    }

    private fun goToDashboard() {
        startActivity(Intent(this, MainActivity::class.java))
        finish()
    }
}
