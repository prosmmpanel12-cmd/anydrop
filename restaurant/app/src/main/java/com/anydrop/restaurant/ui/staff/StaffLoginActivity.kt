package com.anydrop.restaurant.ui.staff

import android.content.Intent
import android.os.Bundle
import android.view.View
import androidx.appcompat.app.AppCompatActivity
import androidx.lifecycle.lifecycleScope
import com.anydrop.restaurant.data.TokenManager
import com.anydrop.restaurant.databinding.ActivityStaffLoginBinding
import com.anydrop.restaurant.network.ApiClient
import com.anydrop.restaurant.network.FcmTokenBody
import com.anydrop.restaurant.network.StaffLoginBody
import com.anydrop.restaurant.ui.common.InAppNotifier
import com.anydrop.restaurant.ui.main.MainActivity
import kotlinx.coroutines.launch

/**
 * Migration 63 (Restaurant Staff/RBAC, PENDING.md item 3) — sibling of
 * LoginActivity for a named staff account (manager/kitchen/cashier)
 * rather than the restaurant owner. See
 * backend/api/v1/auth/restaurant-staff-login.php's own kdoc for why
 * this hits a separate endpoint, and TokenManager.saveSession()'s own
 * kdoc for how the resulting session is distinguished from an owner's.
 */
class StaffLoginActivity : AppCompatActivity() {

    private lateinit var binding: ActivityStaffLoginBinding
    private val api by lazy { ApiClient.create(this) }
    private lateinit var tokenManager: TokenManager

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityStaffLoginBinding.inflate(layoutInflater)
        setContentView(binding.root)

        tokenManager = TokenManager(this)
        if (tokenManager.isLoggedIn()) {
            goToDashboard()
            return
        }

        binding.btnStaffLoginSubmit.setOnClickListener { attemptStaffLogin() }
        binding.btnBackToOwnerLogin.setOnClickListener { finish() }
    }

    private fun attemptStaffLogin() {
        val username = binding.inputUsername.text?.toString()?.trim().orEmpty()
        val password = binding.inputStaffPassword.text?.toString().orEmpty()

        if (username.isEmpty() || password.isEmpty()) {
            InAppNotifier.show(this, "Enter username and password", InAppNotifier.Type.INFO)
            return
        }

        setLoading(true)
        lifecycleScope.launch {
            try {
                val response = api.staffLogin(StaffLoginBody(username, password))
                val result = response.body()?.data
                if (response.isSuccessful && result?.token != null && result.restaurant != null && result.staff != null) {
                    tokenManager.saveSession(
                        token = result.token,
                        restaurantId = result.restaurant.id,
                        name = result.restaurant.name,
                        role = result.staff.role,
                        staffName = result.staff.name
                    )
                    registerFcmTokenAfterLogin()
                    goToDashboard()
                } else {
                    val error = response.body()?.error ?: "login_failed"
                    InAppNotifier.show(this@StaffLoginActivity, friendlyError(error), InAppNotifier.Type.ERROR)
                    setLoading(false)
                }
            } catch (e: Exception) {
                InAppNotifier.show(this@StaffLoginActivity, "Network error — check your connection", InAppNotifier.Type.ERROR)
                setLoading(false)
            }
        }
    }

    private fun friendlyError(error: String): String = when (error) {
        "invalid_credentials" -> "Incorrect username or password"
        "staff_disabled" -> "This staff account has been disabled — contact your restaurant owner"
        "account_suspended" -> "This restaurant's account is suspended — contact support"
        "pending_approval" -> "This restaurant is pending admin approval"
        else -> "Couldn't log in — please try again"
    }

    private fun setLoading(loading: Boolean) {
        binding.staffLoginProgress.visibility = if (loading) View.VISIBLE else View.GONE
        binding.btnStaffLoginSubmit.isEnabled = !loading
    }

    private fun goToDashboard() {
        startActivity(Intent(this, MainActivity::class.java))
        finish()
    }

    /** Same best-effort FCM registration LoginActivity's own copy of
     * this method documents — kept duplicated rather than shared since
     * the two activities don't otherwise share a base class, and this
     * is a handful of lines. */
    private fun registerFcmTokenAfterLogin() {
        com.google.firebase.messaging.FirebaseMessaging.getInstance().token
            .addOnSuccessListener { token ->
                lifecycleScope.launch {
                    try {
                        api.updateFcmToken(FcmTokenBody(token))
                    } catch (e: Exception) {
                        // Non-fatal — see LoginActivity's own copy of this method.
                    }
                }
            }
    }
}
