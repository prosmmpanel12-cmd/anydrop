package com.anydrop.restaurant.data

import android.content.Context

/** Thin wrapper around SharedPreferences for the restaurant auth token + basic profile. */
class TokenManager(context: Context) {

    private val prefs = context.getSharedPreferences("anydrop_restaurant_prefs", Context.MODE_PRIVATE)

    /**
     * Migration 63 (Restaurant Staff/RBAC, PENDING.md item 3) — `role`
     * is `"owner"` for the restaurant's own login (default, unchanged
     * from before this parameter existed — every pre-existing call
     * site keeps compiling with no change), or one of
     * `"manager"`/`"kitchen"`/`"cashier"` for a staff login.
     * `staffName` is null for an owner session — kept separate from
     * the restaurant's own `name` (KEY_NAME) since a staff member's own
     * display name and the restaurant's name are two different things
     * a UI might want to show at once (e.g. "Logged in as Priya —
     * Spice Villa").
     */
    fun saveSession(
        token: String,
        restaurantId: Int,
        name: String?,
        role: String = "owner",
        staffName: String? = null
    ) {
        prefs.edit()
            .putString(KEY_TOKEN, token)
            .putInt(KEY_RESTAURANT_ID, restaurantId)
            .putString(KEY_NAME, name)
            .putString(KEY_ROLE, role)
            .putString(KEY_STAFF_NAME, staffName)
            .apply()
    }

    fun getToken(): String? = prefs.getString(KEY_TOKEN, null)

    fun getRestaurantName(): String? = prefs.getString(KEY_NAME, null)

    /** `"owner"`/`"manager"`/`"kitchen"`/`"cashier"` — defaults to
     * `"owner"` for any session saved before this field existed, same
     * as `saveSession()`'s own default, so an already-logged-in owner
     * from before this update isn't forced to re-login. */
    fun getRole(): String = prefs.getString(KEY_ROLE, "owner") ?: "owner"

    fun isOwner(): Boolean = getRole() == "owner"

    /** Convenience for gating UI entry points client-side (e.g. hiding
     * the Staff Management screen from non-owners) — the backend's own
     * `require_restaurant_permission()` is still the actual enforcement;
     * this only avoids showing a control that would just 403 anyway. */
    fun canManageStaff(): Boolean = isOwner()

    fun getStaffName(): String? = prefs.getString(KEY_STAFF_NAME, null)

    /** Called after a successful Edit Profile save so the top bar
     * (activity_main.xml's restaurantNameText) reflects a renamed
     * restaurant immediately, without waiting for the next full login. */
    fun updateRestaurantName(name: String) {
        prefs.edit().putString(KEY_NAME, name).apply()
    }

    fun isLoggedIn(): Boolean = !getToken().isNullOrEmpty()

    fun clear() {
        prefs.edit().clear().apply()
    }

    companion object {
        private const val KEY_TOKEN = "token"
        private const val KEY_RESTAURANT_ID = "restaurant_id"
        private const val KEY_NAME = "name"
        private const val KEY_ROLE = "role"
        private const val KEY_STAFF_NAME = "staff_name"
    }
}
