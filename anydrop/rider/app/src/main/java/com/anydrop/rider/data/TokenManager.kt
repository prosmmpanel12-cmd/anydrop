package com.anydrop.rider.data

import android.content.Context

/** Thin wrapper around SharedPreferences for the rider auth token + basic profile.
 * Mirrors the Restaurant/Customer apps' TokenManager pattern. */
class TokenManager(context: Context) {

    private val prefs = context.getSharedPreferences("anydrop_rider_prefs", Context.MODE_PRIVATE)

    fun saveSession(token: String, riderId: Int, name: String?, status: String, rejectionReason: String? = null) {
        prefs.edit()
            .putString(KEY_TOKEN, token)
            .putInt(KEY_RIDER_ID, riderId)
            .putString(KEY_NAME, name)
            .putString(KEY_STATUS, status)
            .putString(KEY_REJECTION_REASON, rejectionReason)
            .apply()
    }

    fun getToken(): String? = prefs.getString(KEY_TOKEN, null)

    fun getRiderId(): Int = prefs.getInt(KEY_RIDER_ID, -1)

    fun getRiderName(): String? = prefs.getString(KEY_NAME, null)

    /** "pending" | "approved" | "rejected" | "suspended" — updated every
     * time ApplicationStatusActivity re-checks (see that screen's kdoc). */
    fun getStatus(): String? = prefs.getString(KEY_STATUS, null)

    fun getRejectionReason(): String? = prefs.getString(KEY_REJECTION_REASON, null)

    /** Updates status + rejection_reason in-place (called by ApplicationStatusActivity
     *  after a successful /rider/me refresh — no full re-login needed). */
    fun updateStatus(status: String, rejectionReason: String? = null) {
        prefs.edit()
            .putString(KEY_STATUS, status)
            .putString(KEY_REJECTION_REASON, rejectionReason)
            .apply()
    }

    /** Phase 3 (doc 83) — cached purely so RiderDashboardActivity has an
     *  immediate switch state to render before /rider/me's response comes
     *  back, not treated as a source of truth (the switch re-syncs from
     *  the network response the same way status/rejectionReason do). */
    fun getIsOnline(): Boolean = prefs.getBoolean(KEY_IS_ONLINE, false)

    fun setIsOnline(online: Boolean) {
        prefs.edit().putBoolean(KEY_IS_ONLINE, online).apply()
    }

    fun isLoggedIn(): Boolean = !getToken().isNullOrEmpty()

    fun clear() {
        prefs.edit().clear().apply()
    }

    companion object {
        private const val KEY_TOKEN = "token"
        private const val KEY_RIDER_ID = "rider_id"
        private const val KEY_NAME = "name"
        private const val KEY_STATUS = "status"
        private const val KEY_REJECTION_REASON = "rejection_reason"
        private const val KEY_IS_ONLINE = "is_online"
    }
}
