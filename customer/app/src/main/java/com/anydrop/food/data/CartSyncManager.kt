package com.anydrop.food.data

import android.content.Context
import com.anydrop.food.network.ApiClient
import com.anydrop.food.network.CartSyncBody
import com.anydrop.food.network.CartSyncItem
import com.anydrop.food.network.CartSyncRestaurant
import com.anydrop.food.network.MenuItem
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext

/**
 * Syncs [CartManager]'s in-memory state to the server
 * (`customer/cart-sync.php`) so a killed/restarted app can restore exactly
 * what was in the cart, and where it was left — closes the "cart empties on
 * restart" gap. Two entry points:
 *
 * - [scheduleSync] — call after *every* CartManager mutation (add/decrease/
 *   removeCart). Debounced [DEBOUNCE_MS] so rapid taps (holding + on a qty
 *   stepper) don't fire one network call per tap — only the settled state
 *   after a short pause gets sent. Silent on failure: this is a convenience
 *   feature, not the order-of-record. The real safety net is still
 *   `POST /cart/validate` at checkout — a dropped sync just means restore
 *   falls back to the last successfully saved snapshot.
 * - [restoreFromServer] — call once per process, right after confirming the
 *   user is logged in and *before* any cart-dependent UI (cart badge,
 *   bottom sheet) first reads CartManager. Wire this into
 *   `HomeActivity.onCreate()`.
 */
object CartSyncManager {

    private const val DEBOUNCE_MS = 1000L
    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.IO)
    private var pendingSync: Job? = null

    fun scheduleSync(context: Context) {
        val appContext = context.applicationContext
        pendingSync?.cancel()
        pendingSync = scope.launch {
            delay(DEBOUNCE_MS)
            pushToServer(appContext)
        }
    }

    /** Forces an immediate sync, skipping the debounce — call from
     * `onStop()` on any screen that can mutate the cart, so "add item then
     * immediately swipe the app away" isn't lost waiting on the debounce
     * timer that never gets to fire. */
    fun syncNow(context: Context) {
        val appContext = context.applicationContext
        pendingSync?.cancel()
        pendingSync = scope.launch { pushToServer(appContext) }
    }

    private suspend fun pushToServer(context: Context) {
        try {
            val api = ApiClient.create(context)
            val body = CartSyncBody(
                carts = CartManager.getCarts().map { cart ->
                    CartSyncRestaurant(
                        restaurantId = cart.restaurantId,
                        restaurantName = cart.restaurantName,
                        couponCode = cart.appliedCouponCode,
                        scheduledFor = cart.scheduledFor,
                        items = cart.getLines().map {
                            CartSyncItem(
                                menuItemId = it.item.id,
                                quantity = it.quantity,
                                addonIds = it.addonIds.ifEmpty { null },
                                specialInstructions = it.specialInstructions
                            )
                        }
                    )
                }
            )
            api.saveCartSync(body)
        } catch (e: Exception) {
            // Network/backend unreachable — the next scheduleSync() call
            // retries with whatever the state is by then. Not surfaced to
            // the user, same "best-effort background write" pattern as
            // FavoritesManager's optimistic toggle calls.
        }
    }

    /**
     * Restores [CartManager] from the server snapshot. Fully replaces
     * whatever local state exists (see [CartManager.restoreFromServer]) —
     * safe to call even on a fresh process where the local cart is already
     * empty. Invokes [onComplete] on the main thread once restore finishes,
     * so the caller can refresh an already-visible cart badge/count.
     */
    fun restoreFromServer(context: Context, onComplete: () -> Unit = {}) {
        scope.launch {
            try {
                val api = ApiClient.create(context)
                val result = api.getCartSync().body()?.data ?: return@launch

                val restored = result.carts.mapNotNull restaurantLoop@{ synced ->
                    val lines = synced.items.mapNotNull itemLoop@{ syncedItem ->
                        val name = syncedItem.name ?: return@itemLoop null
                        val price = syncedItem.price ?: return@itemLoop null
                        val menuItem = MenuItem(
                            id = syncedItem.menuItemId,
                            name = name,
                            description = syncedItem.description,
                            price = price,
                            discountPercent = syncedItem.discountPercent ?: 0.0,
                            isVeg = syncedItem.isVeg ?: true,
                            imageUrl = syncedItem.imageUrl,
                            isRecommended = syncedItem.isRecommended ?: false,
                            isBestseller = syncedItem.isBestseller ?: false,
                            prepTimeMinutes = syncedItem.prepTimeMinutes ?: 15,
                            // §2.6 — restore the item's full addon list too
                            // (not just the selected ids), or CartLine.unitPrice/
                            // addonSummary would have nothing to look up once
                            // restored and a customization would silently
                            // stop pricing correctly after an app restart.
                            addons = syncedItem.addons.orEmpty()
                        )
                        CartLine(
                            item = menuItem,
                            quantity = syncedItem.quantity,
                            addonIds = syncedItem.addonIds.orEmpty(),
                            specialInstructions = syncedItem.specialInstructions
                        )
                    }
                    if (lines.isEmpty()) return@restaurantLoop null

                    val restaurantCart = RestaurantCart(
                        restaurantId = synced.restaurantId,
                        restaurantName = synced.restaurantName.orEmpty(),
                        appliedCouponCode = synced.couponCode,
                        scheduledFor = synced.scheduledFor
                    )
                    lines.forEach { restaurantCart.lines[it.item.id] = it }
                    restaurantCart
                }

                CartManager.restoreFromServer(restored)
                withContext(Dispatchers.Main) { onComplete() }
            } catch (e: Exception) {
                // Backend unreachable at startup — app just starts with an
                // empty cart (today's behavior). The next successful
                // scheduleSync() re-establishes the server snapshot.
            }
        }
    }
}
