package com.anydrop.food.data

import com.anydrop.food.network.MenuItem

/**
 * In-memory cart for Phase 2/3 (local state only — server-side validation
 * happens at checkout via POST /cart/validate, per docs/02_API_Contract.md).
 *
 * **Zomato/Swiggy-style multi-restaurant cart** (bug 1.7 follow-up,
 * `docs/07_Phase_3.7_Bug_Tracker.md`): adding a dish from Restaurant B no
 * longer touches whatever is already sitting in Restaurant A's cart. Each
 * restaurant gets its own independent [RestaurantCart], keyed by
 * `restaurantId`, and the customer can hold items from several restaurants
 * at once — exactly like tapping "Add" on dishes from three different
 * restaurants on Home's Popular row. Checkout still happens **one
 * restaurant at a time** (the backend order model is single-restaurant per
 * order, per `02_API_Contract.md`), so `CartBottomSheetFragment` shows one
 * section per restaurant, each with its own "Checkout" button.
 */
/**
 * [addonIds]/[specialInstructions] come from the dish-customization sheet
 * (§2.6/1.9) — both default empty/null so every existing call site (plain
 * ADD button, qty stepper +/-) that never customizes anything keeps working
 * unchanged. [unitPrice] is the effective per-unit price including selected
 * addons (item.price + sum of matching addon prices) — the cart/checkout UI
 * must read price off this, never off `item.price` directly, or a
 * customized line's addons silently stop counting toward the shown total.
 *
 * Known simplification (flagged in docs/Status.md, not a groups/max-select
 * system per the original §2.6 request): a cart line is still keyed by
 * menu_item_id alone (see RestaurantCart.lines below), so customizing the
 * same dish a second time with different addons overwrites the first
 * customization rather than creating a second, independently-priced line.
 * Good enough for v1 (matches what most food apps also do for a quick
 * re-add), revisit only if customers actually want two differently-
 * customized portions of the same dish in one order.
 */
data class CartLine(
    val item: MenuItem,
    var quantity: Int,
    var addonIds: List<Int> = emptyList(),
    var specialInstructions: String? = null
) {
    val unitPrice: Double
        get() = item.price + item.addons.filter { it.id in addonIds }.sumOf { it.price }

    /** Human-readable "Extra Cheese, Extra Sauce" summary for the cart list. */
    val addonSummary: String?
        get() {
            val names = item.addons.filter { it.id in addonIds }.map { it.name }
            return if (names.isEmpty()) null else names.joinToString(", ")
        }
}

/** One restaurant's independent cart. */
data class RestaurantCart(
    val restaurantId: Int,
    var restaurantName: String,
    val lines: LinkedHashMap<Int, CartLine> = LinkedHashMap(), // key = menu_item_id
    var appliedCouponCode: String? = null,
    // I4 — "yyyy-MM-dd HH:mm:ss" chosen on the restaurant-detail "Schedule
    // for later" sheet, or null for a normal "Deliver Now" order. Same
    // scope/lifetime as appliedCouponCode above: lives on this in-memory
    // cart, synced to the server via CartSyncManager so it survives an app
    // kill, and cleared whenever the cart itself is cleared (checkout
    // success, restaurant switch, logout). The server is still the one
    // that decides whether the slot is actually valid — see
    // validate_scheduled_for() in backend/lib/orders.php — this is just
    // what the picker last set.
    var scheduledFor: String? = null
) {
    fun totalItemCount(): Int = lines.values.sumOf { it.quantity }
    fun totalPrice(): Double = lines.values.sumOf { it.unitPrice * it.quantity }
    fun getLines(): List<CartLine> = lines.values.toList()
}

object CartManager {

    // key = restaurantId. LinkedHashMap keeps insertion order so carts don't
    // reorder themselves in the cart sheet every time an item is added.
    private val carts = LinkedHashMap<Int, RestaurantCart>()

    // I4 — the "Schedule for later" row on RestaurantDetailActivity can be
    // tapped *before* the customer has added anything to their cart (it
    // sits right under the restaurant header, above the menu). A
    // RestaurantCart only exists once there's at least one line in it (see
    // [add]/[setCustomized]), so a picked slot with nothing in the cart yet
    // is held here instead of forcing an empty phantom RestaurantCart into
    // existence — that would make hasAnyItems()/totalItemCount() lie and
    // show a 0-item entry in the cart sheet. Applied onto the real
    // RestaurantCart the moment one gets created for this restaurant.
    private val pendingScheduledFor = HashMap<Int, String?>()

    fun getScheduledFor(restaurantId: Int): String? =
        carts[restaurantId]?.scheduledFor ?: pendingScheduledFor[restaurantId]

    fun setScheduledFor(restaurantId: Int, scheduledFor: String?) {
        val cart = carts[restaurantId]
        if (cart != null) {
            cart.scheduledFor = scheduledFor
        } else {
            pendingScheduledFor[restaurantId] = scheduledFor
        }
    }

    /** Applies any pending (pre-cart) schedule pick onto a just-created
     * RestaurantCart — called from [add]/[setCustomized] right after
     * `getOrPut` so a slot picked before the first item was added isn't
     * silently dropped. No-op if nothing was pending for this restaurant. */
    private fun applyPendingSchedule(cart: RestaurantCart) {
        if (pendingScheduledFor.containsKey(cart.restaurantId)) {
            cart.scheduledFor = pendingScheduledFor.remove(cart.restaurantId)
        }
    }

    /**
     * Adds [item] to [restaurantId]'s cart, creating that restaurant's cart
     * if this is the first item from it. Never touches any other
     * restaurant's cart — multiple carts can coexist.
     */
    fun add(restaurantId: Int, item: MenuItem, restaurantName: String? = null) {
        val cart = carts.getOrPut(restaurantId) {
            RestaurantCart(restaurantId, restaurantName.orEmpty()).also { applyPendingSchedule(it) }
        }
        if (!restaurantName.isNullOrBlank()) {
            cart.restaurantName = restaurantName
        }
        val existing = cart.lines[item.id]
        if (existing != null) {
            existing.quantity += 1
        } else {
            cart.lines[item.id] = CartLine(item, 1)
        }
    }

    /**
     * Used by [com.anydrop.food.ui.itemdetail.ItemDetailBottomSheetFragment]'s
     * sticky "Add item" button — the sheet already shows the user an
     * absolute quantity + a specific addon/notes combination, so this sets
     * the line directly rather than incrementing by 1 the way plain
     * [add] does. Creating the restaurant's cart if needed, same as [add].
     */
    fun setCustomized(
        restaurantId: Int,
        item: MenuItem,
        quantity: Int,
        addonIds: List<Int>,
        specialInstructions: String?,
        restaurantName: String? = null
    ) {
        // Bug fix: dragging the sheet's own stepper down to 0 is how a
        // customer "un-selects" a dish they'd previously customized — this
        // used to silently no-op (leaving the old line untouched), so
        // route it through the same removal path as [decrease] instead.
        if (quantity <= 0) {
            removeLine(restaurantId, item.id)
            return
        }
        val cart = carts.getOrPut(restaurantId) {
            RestaurantCart(restaurantId, restaurantName.orEmpty()).also { applyPendingSchedule(it) }
        }
        if (!restaurantName.isNullOrBlank()) {
            cart.restaurantName = restaurantName
        }
        cart.lines[item.id] = CartLine(
            item = item,
            quantity = quantity,
            addonIds = addonIds,
            specialInstructions = specialInstructions?.takeIf { it.isNotBlank() }
        )
    }

    fun decrease(restaurantId: Int, item: MenuItem) {
        val cart = carts[restaurantId] ?: return
        val existing = cart.lines[item.id] ?: return
        if (existing.quantity <= 1) {
            cart.lines.remove(item.id)
        } else {
            existing.quantity -= 1
        }
        if (cart.lines.isEmpty()) {
            carts.remove(restaurantId)
        }
    }

    /** Removes a single line from one restaurant's cart (e.g. the
     * customization sheet's qty stepper going down to 0), tidying up the
     * whole restaurant-cart entry too if that was its last line — same
     * cleanup [decrease] already does. */
    fun removeLine(restaurantId: Int, itemId: Int) {
        val cart = carts[restaurantId] ?: return
        cart.lines.remove(itemId)
        if (cart.lines.isEmpty()) {
            carts.remove(restaurantId)
        }
    }

    fun quantityOf(restaurantId: Int, itemId: Int): Int =
        carts[restaurantId]?.lines?.get(itemId)?.quantity ?: 0

    /** All active restaurant-carts, in the order they were first started. */
    fun getCarts(): List<RestaurantCart> = carts.values.toList()

    fun getCart(restaurantId: Int): RestaurantCart? = carts[restaurantId]

    /** Removes one restaurant's cart entirely — used after a successful
     * checkout for that restaurant, or when the user explicitly clears it. */
    fun removeCart(restaurantId: Int) {
        carts.remove(restaurantId)
        // Also drop any pending (pre-cart) schedule pick for this
        // restaurant — otherwise a slot chosen for an order that just got
        // placed would silently carry over onto the *next* cart started
        // with this restaurant.
        pendingScheduledFor.remove(restaurantId)
    }

    fun hasAnyItems(): Boolean = carts.isNotEmpty()

    fun totalItemCount(): Int = carts.values.sumOf { it.totalItemCount() }

    /** Clears every restaurant's cart — used on logout. */
    fun clear() {
        carts.clear()
        pendingScheduledFor.clear()
    }

    /**
     * Replaces local cart state wholesale with what came back from
     * [com.anydrop.food.data.CartSyncManager]'s server restore. Used once
     * on app start (see CartSyncManager.restoreFromServer) — never merges,
     * since the server snapshot is the source of truth for "what was here
     * when the app last synced."
     */
    fun restoreFromServer(restored: List<RestaurantCart>) {
        carts.clear()
        restored.forEach { carts[it.restaurantId] = it }
    }
}
