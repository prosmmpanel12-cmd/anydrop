package com.anydrop.food.data

import android.content.Context
import android.widget.Toast
import com.anydrop.food.network.ApiClient
import com.anydrop.food.network.ToggleFavoriteBody
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.launch

/**
 * Wires a bookmark icon tap to POST/DELETE customer/favorites.php with
 * optimistic UI — the icon flips instantly, and rolls back with a toast
 * only if the network call actually fails. Used by every screen with a
 * bookmark icon: restaurant cards (Home, Search), dish cards (Home popular
 * row, Search, category-items, Restaurant Detail menu).
 *
 * `currentlySaved` is the icon's state *before* the tap; `onResult` is
 * called with the new (possibly rolled-back) state so the caller can
 * update its adapter item and re-bind the icon.
 *
 * **Shared cache (2026-08-10 bug fix, H2):** every adapter used to keep its
 * own private `savedOverrides` map, so toggling a restaurant's bookmark on
 * one screen (e.g. RestaurantDetailActivity opened from a cart card) never
 * reached any other already-bound screen (e.g. Home's restaurant list) —
 * it stayed stale until that screen's data was fully reloaded from the
 * server. [restaurantOverrides]/[menuItemOverrides] below are session-
 * lifetime (this object is a singleton), so every screen that calls
 * [isSaved] instead of trusting its own locally-cached server flag sees
 * the same, current state — no extra network calls, this is pure local
 * state shared in memory.
 */
object FavoritesManager {

    private val restaurantOverrides = mutableMapOf<Int, Boolean>()
    private val menuItemOverrides = mutableMapOf<Int, Boolean>()

    /** What a screen should actually render for this item's bookmark icon
     * right now — the shared override if this session has touched it,
     * otherwise whatever the server said when this screen's data loaded. */
    fun isSaved(favoriteType: String, id: Int, serverValue: Boolean): Boolean {
        val cache = if (favoriteType == "restaurant") restaurantOverrides else menuItemOverrides
        return cache[id] ?: serverValue
    }

    fun toggle(
        context: Context,
        scope: CoroutineScope,
        favoriteType: String, // "restaurant" | "menu_item"
        favoriteId: Int,
        currentlySaved: Boolean,
        onResult: (Boolean) -> Unit
    ) {
        val cache = if (favoriteType == "restaurant") restaurantOverrides else menuItemOverrides

        // Flip immediately — this is the "optimistic" part.
        val optimisticNewState = !currentlySaved
        cache[favoriteId] = optimisticNewState
        onResult(optimisticNewState)

        scope.launch {
            try {
                val api = ApiClient.create(context)
                val body = ToggleFavoriteBody(favoriteType, favoriteId)
                val response = if (optimisticNewState) {
                    api.addFavorite(body)
                } else {
                    api.removeFavorite(body)
                }

                if (!response.isSuccessful || response.body()?.success != true) {
                    cache[favoriteId] = currentlySaved // roll back
                    onResult(currentlySaved)
                    Toast.makeText(context, "Couldn't update saved items, try again", Toast.LENGTH_SHORT).show()
                }
            } catch (e: Exception) {
                cache[favoriteId] = currentlySaved // roll back — no network etc.
                onResult(currentlySaved)
                Toast.makeText(context, "Couldn't update saved items, try again", Toast.LENGTH_SHORT).show()
            }
        }
    }
}
