package com.anydrop.food.ui.common

import android.content.Context
import com.anydrop.food.data.CartManager
import com.anydrop.food.data.CartSyncManager
import com.anydrop.food.network.MenuItem

/**
 * Shared "add to cart" entry point for every ADD / qty-stepper button in the
 * app (Home's Popular row, Search/Category dish cards, Restaurant Detail's
 * menu, and anywhere else a dish can be added).
 *
 * As of the multi-restaurant cart rework (`CartManager` now keeps one
 * independent [com.anydrop.food.data.RestaurantCart] per restaurant, see
 * that file's kdoc), adding a dish from a different restaurant than what's
 * already in the cart no longer needs a confirmation dialog — it simply
 * starts (or adds to) that restaurant's own cart, Zomato/Swiggy-style,
 * without touching any other restaurant's cart.
 */
object CartAddHelper {

    fun add(
        context: Context,
        restaurantId: Int,
        restaurantName: String?,
        item: MenuItem,
        onAdded: () -> Unit
    ) {
        CartManager.add(restaurantId, item, restaurantName)
        CartSyncManager.scheduleSync(context)
        onAdded()
    }
}
