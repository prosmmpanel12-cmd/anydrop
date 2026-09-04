package com.anydrop.food.data

import android.content.Context

/**
 * Global "Veg Mode" toggle, Zomato/Swiggy style — a single switch, ON by
 * default, that filters the restaurant list (and menu screens) down to veg
 * items only. Persisted across app restarts via SharedPreferences.
 */
object VegModeManager {

    private const val PREFS = "anydrop_prefs"
    private const val KEY_VEG_ONLY = "veg_only_mode"

    private var cached: Boolean? = null

    fun isVegOnly(context: Context): Boolean {
        cached?.let { return it }
        val prefs = context.applicationContext.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
        // Default ON — matches the "default veg" requirement.
        val value = prefs.getBoolean(KEY_VEG_ONLY, true)
        cached = value
        return value
    }

    fun setVegOnly(context: Context, vegOnly: Boolean) {
        cached = vegOnly
        context.applicationContext
            .getSharedPreferences(PREFS, Context.MODE_PRIVATE)
            .edit()
            .putBoolean(KEY_VEG_ONLY, vegOnly)
            .apply()
    }
}
