package com.anydrop.food.data

import android.content.Context
import com.anydrop.food.network.Address

/**
 * The "currently selected delivery address" concept that was missing
 * app-wide (see Status.md part 9's handover). This is what Home's
 * `loadRestaurants()` reads lat/lng from to drive the delivery-radius
 * filtering that `restaurants/list.php` gained in part 9 — without this,
 * that backend filter had nothing to filter against.
 *
 * Deliberately a thin SharedPreferences cache, same pattern as
 * VegModeManager — not a database, not synced with the server beyond what
 * Address already carries. The source of truth for the actual address list
 * (and which one is `is_default`) remains the backend via
 * `customer/addresses.php`; this just remembers, on-device, which one Home
 * should currently be using so every screen doesn't need to re-fetch and
 * re-pick it.
 */
object ActiveAddressManager {

    private const val PREFS = "anydrop_prefs"
    private const val KEY_ID = "active_address_id"
    private const val KEY_LABEL = "active_address_label"
    private const val KEY_SHORT_TEXT = "active_address_short_text"
    private const val KEY_LAT = "active_address_lat"
    private const val KEY_LNG = "active_address_lng"
    private const val KEY_IS_LIVE = "active_address_is_live"

    /** Sentinel id for a "use live location" selection — there's no saved
     * `Address` row behind it, so there's nothing real to store here. Kept
     * distinct from -1 (which `get()` already uses to mean "unset") so a
     * live selection round-trips through SharedPreferences unambiguously. */
    private const val LIVE_LOCATION_ID = -2

    data class ActiveAddress(
        val id: Int,
        val label: String,
        val shortText: String,
        val latitude: Double?,
        val longitude: Double?,
        /** True when this came from "Use current location" (Phase I),
         * not a saved address row. Screens that need to re-fetch a fresh
         * fix (rather than trust a possibly-stale lat/lng) can check this. */
        val isLiveLocation: Boolean = false
    )

    private var cached: ActiveAddress? = null
    private var cacheLoaded = false

    /** Null means "no address selected yet" — callers should fall back to
     * the pre-part-9 behaviour (no lat/lng sent, nothing filtered out). */
    fun get(context: Context): ActiveAddress? {
        if (cacheLoaded) return cached
        val prefs = context.applicationContext.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
        val id = prefs.getInt(KEY_ID, -1)
        cached = if (id == -1) {
            null
        } else {
            ActiveAddress(
                id = id,
                label = prefs.getString(KEY_LABEL, null) ?: "Selected address",
                shortText = prefs.getString(KEY_SHORT_TEXT, null).orEmpty(),
                latitude = if (prefs.contains(KEY_LAT)) prefs.getFloat(KEY_LAT, 0f).toDouble() else null,
                longitude = if (prefs.contains(KEY_LNG)) prefs.getFloat(KEY_LNG, 0f).toDouble() else null,
                isLiveLocation = prefs.getBoolean(KEY_IS_LIVE, false)
            )
        }
        cacheLoaded = true
        return cached
    }

    /** Called once an address is resolved (either the server's `is_default`
     * one on first load, or the user explicitly picking/saving one). */
    fun set(context: Context, address: Address) {
        val active = ActiveAddress(
            id = address.id,
            label = address.label ?: address.addressType.replaceFirstChar { it.uppercase() },
            shortText = address.fullAddress,
            latitude = address.latitude,
            longitude = address.longitude,
            isLiveLocation = false
        )
        persist(context, active)
    }

    /** Phase I — "Use current location" picker row. Sets the active
     * delivery location straight from a resolved GPS/network fix, with no
     * backing saved-address row (id = LIVE_LOCATION_ID). [addressLine] is
     * whatever the Geocoder reverse-lookup produced (or a raw lat/lng
     * string fallback) — same pattern already used for the sheet-fill flow
     * elsewhere in the picker/Checkout/AddressBook. */
    fun setLiveLocation(context: Context, latitude: Double, longitude: Double, addressLine: String?) {
        val active = ActiveAddress(
            id = LIVE_LOCATION_ID,
            label = "Current location",
            shortText = addressLine ?: "%.4f, %.4f".format(latitude, longitude),
            latitude = latitude,
            longitude = longitude,
            isLiveLocation = true
        )
        persist(context, active)
    }

    private fun persist(context: Context, active: ActiveAddress) {
        cached = active
        cacheLoaded = true
        context.applicationContext.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
            .edit()
            .putInt(KEY_ID, active.id)
            .putString(KEY_LABEL, active.label)
            .putString(KEY_SHORT_TEXT, active.shortText)
            .putBoolean(KEY_IS_LIVE, active.isLiveLocation)
            .apply {
                if (active.latitude != null) putFloat(KEY_LAT, active.latitude.toFloat()) else remove(KEY_LAT)
                if (active.longitude != null) putFloat(KEY_LNG, active.longitude.toFloat()) else remove(KEY_LNG)
            }
            .apply()
    }

    fun clear(context: Context) {
        cached = null
        cacheLoaded = true
        context.applicationContext.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
            .edit()
            .remove(KEY_ID)
            .remove(KEY_LABEL)
            .remove(KEY_SHORT_TEXT)
            .remove(KEY_LAT)
            .remove(KEY_LNG)
            .remove(KEY_IS_LIVE)
            .apply()
    }
}
