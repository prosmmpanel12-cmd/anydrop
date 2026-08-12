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

    fun clear() {
        prefs.edit().clear().apply()
    }

    companion object {
        private const val KEY_TOKEN = "token"
        private const val KEY_CUSTOMER_ID = "customer_id"
        private const val KEY_EMAIL = "email"
    }
}
