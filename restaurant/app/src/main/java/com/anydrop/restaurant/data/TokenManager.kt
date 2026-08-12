package com.anydrop.restaurant.data

import android.content.Context

/** Thin wrapper around SharedPreferences for the restaurant auth token + basic profile. */
class TokenManager(context: Context) {

    private val prefs = context.getSharedPreferences("anydrop_restaurant_prefs", Context.MODE_PRIVATE)

    fun saveSession(token: String, restaurantId: Int, name: String?) {
        prefs.edit()
            .putString(KEY_TOKEN, token)
            .putInt(KEY_RESTAURANT_ID, restaurantId)
            .putString(KEY_NAME, name)
            .apply()
    }

    fun getToken(): String? = prefs.getString(KEY_TOKEN, null)

    fun getRestaurantName(): String? = prefs.getString(KEY_NAME, null)

    fun isLoggedIn(): Boolean = !getToken().isNullOrEmpty()

    fun clear() {
        prefs.edit().clear().apply()
    }

    companion object {
        private const val KEY_TOKEN = "token"
        private const val KEY_RESTAURANT_ID = "restaurant_id"
        private const val KEY_NAME = "name"
    }
}
