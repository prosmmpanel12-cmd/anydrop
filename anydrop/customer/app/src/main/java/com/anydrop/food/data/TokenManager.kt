package com.anydrop.food.data

import android.content.Context

/** Thin wrapper around SharedPreferences for the customer auth token + basic profile. */
class TokenManager(context: Context) {

    private val prefs = context.getSharedPreferences("anydrop_customer_prefs", Context.MODE_PRIVATE)

    fun saveSession(token: String, customerId: Int, email: String?) {
        prefs.edit()
            .putString(KEY_TOKEN, token)
            .putInt(KEY_CUSTOMER_ID, customerId)
            .putString(KEY_EMAIL, email)
            .apply()
    }

    fun getToken(): String? = prefs.getString(KEY_TOKEN, null)

    fun getEmail(): String? = prefs.getString(KEY_EMAIL, null)

    fun isLoggedIn(): Boolean = !getToken().isNullOrEmpty()

    // Set once CompleteProfileActivity finishes successfully, so a
    // returning customer's next launch doesn't re-prompt them (see
    // LoginActivity.onVerifyOtp's null-check, which is the primary
    // signal — this local flag is just a fast-path cache of it).
    fun setProfileComplete(name: String, mobile: String) {
        prefs.edit()
            .putString(KEY_NAME, name)
            .putString(KEY_MOBILE, mobile)
            .apply()
    }

    fun getName(): String? = prefs.getString(KEY_NAME, null)

    fun getMobile(): String? = prefs.getString(KEY_MOBILE, null)

    fun clear() {
        prefs.edit().clear().apply()
    }

    companion object {
        private const val KEY_TOKEN = "token"
        private const val KEY_CUSTOMER_ID = "customer_id"
        private const val KEY_EMAIL = "email"
        private const val KEY_NAME = "name"
        private const val KEY_MOBILE = "mobile"
    }
}
