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
                    // response.body() is always null on a non-2xx HTTP
                    // response (401/403/...) — restaurant-staff-login.php's
                    // actual error code (invalid_credentials,
                    // staff_disabled, account_suspended,
                    // pending_approval) only ever lives in errorBody().
                    // Reading response.body()?.error here always
                    // returned null, so every one of those distinct
                    // failures was silently showing the exact same
                    // generic "Couldn't log in" message — indistinguishable
                    // from a wrong password, a disabled account, or a
                    // suspended restaurant. See ErrorParsing.kt's kdoc.
                    val error = com.anydrop.restaurant.network.parseApiError(response.errorBody()).code ?: "login_failed"
                    InAppNotifier.show(this@StaffLoginActivity, friendlyError(error), InAppNotifier.Type.ERROR)
                    setLoading(false)
                }
            } catch (e: java.io.IOException) {
                // Genuine transport-level failure — no internet, DNS
                // failure, timeout, connection refused, TLS handshake
                // failure. This is the only case that should actually
                // say "check your connection".
                InAppNotifier.show(this@StaffLoginActivity, "Network error — check your connection", InAppNotifier.Type.ERROR)
                setLoading(false)
            } catch (e: Exception) {
                // Anything else (most commonly Gson's JsonSyntaxException)
                // means the server WAS reached and DID respond, but with
                // something the app couldn't parse as the expected JSON —
                // e.g. a PHP notice/warning printed before the JSON body,
                // a raw HTML error page from the web server, or a WAF/
                // hosting-panel interstitial page. That used to get
                // lumped into the same "check your connection" message,
                // which sent people looking at their WiFi for a bug that
                // was actually on the server. Logged so `adb logcat` (or
                // KS Web's PHP error log for the matching request) shows
                // the real cause.
                android.util.Log.e("StaffLogin", "Unexpected response parsing staff login", e)
                InAppNotifier.show(
                    this@StaffLoginActivity,
                    "Server sent an unexpected response — check KS Web's PHP error log, or try again in a moment",
                    InAppNotifier.Type.ERROR
                )
                setLoading(false)
            }
        }
    }

    private fun friendlyError(error: String): String = when (error) {
        "invalid_credentials" -> "Incorrect username or password"
        "staff_disabled" -> "This staff account has been disabled — contact your restaurant owner"
        "account_suspended" -> "This restaurant's account is suspended — contact support"
        "pending_approval" -> "This restaurant is pending admin approval"
        "validation_error" -> "Enter both username and password"
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
