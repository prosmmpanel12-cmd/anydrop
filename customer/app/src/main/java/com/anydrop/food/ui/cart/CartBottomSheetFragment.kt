package com.anydrop.food.ui.cart

import android.app.Dialog
import android.content.Intent
import android.os.Bundle
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import androidx.recyclerview.widget.LinearLayoutManager
import com.google.android.material.bottomsheet.BottomSheetBehavior
import com.google.android.material.bottomsheet.BottomSheetDialogFragment
import com.google.android.material.R as MaterialR
import com.anydrop.food.R
import com.anydrop.food.data.CartManager
import com.anydrop.food.data.RestaurantCart
import com.anydrop.food.databinding.FragmentCartBinding
import com.anydrop.food.ui.checkout.CheckoutActivity

/**
 * Cart is local-state-only (server-side validation happens at checkout via
 * POST /cart/validate, and the order is placed via POST /orders — both wired
 * up in Phase 3, see ui/checkout/CheckoutActivity.kt).
 *
 * **Multi-restaurant, Zomato/Swiggy-style** (Phase 3.7 follow-up to bug 1.7):
 * this sheet no longer shows one flat item list — it shows one card per
 * restaurant that currently has items in the cart (via
 * [RestaurantCartAdapter]), each with its own subtotal and its own
 * "Checkout" button, since an order is still placed one restaurant at a
 * time. Coupon entry moved into [CheckoutActivity] since a coupon always
 * applies to one restaurant's order.
 */
class CartBottomSheetFragment : BottomSheetDialogFragment() {

    private var _binding: FragmentCartBinding? = null
    private val binding get() = _binding!!
    private lateinit var adapter: RestaurantCartAdapter

    /**
     * Bug fix (2026-08-10, app owner report): the host Activity's cart
     * badge/button (HomeActivity.updateCartBadge(),
     * RestaurantDetailActivity.updateCartButton()) used to only refresh on
     * onResume()/initial load — but showing/dismissing this
     * BottomSheetDialogFragment does NOT pause/resume the host Activity,
     * so removing or clearing items in here left the badge/button showing
     * a stale count until the user actually left and re-entered the
     * screen. Callers set this to push every local cart change straight
     * back out, the same pattern ItemDetailBottomSheetFragment.onAdded
     * already uses.
     */
    var onCartChanged: (() -> Unit)? = null

    /**
     * Bug fix (customer-reported, 2026-08-07): with 2+ restaurant cards in
     * the cart, the sheet's default collapsed "peek" state doesn't show
     * enough height for every card's item list AND its Checkout button —
     * the button at the bottom of the second (or later) card ends up
     * scrolled off below the visible sheet, looking like it's missing
     * entirely even though the item total above it renders fine. Forcing
     * STATE_EXPANDED on open (and skipping the half-collapsed state) means
     * the sheet always opens as tall as the screen allows, so the
     * RecyclerView's own scroll — not the sheet's peek height — is what
     * decides whether a button is visible.
     */
    override fun onCreateDialog(savedInstanceState: Bundle?): Dialog {
        val dialog = super.onCreateDialog(savedInstanceState)
        dialog.setOnShowListener {
            val bottomSheet = (dialog as? com.google.android.material.bottomsheet.BottomSheetDialog)
                ?.findViewById<View>(MaterialR.id.design_bottom_sheet)
            if (bottomSheet != null) {
                val behavior = BottomSheetBehavior.from(bottomSheet)
                behavior.state = BottomSheetBehavior.STATE_EXPANDED
                behavior.skipCollapsed = true
            }
        }
        return dialog
    }

    override fun onCreateView(
        inflater: LayoutInflater,
        container: ViewGroup?,
        savedInstanceState: Bundle?
    ): View {
        _binding = FragmentCartBinding.inflate(inflater, container, false)
        return binding.root
    }

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        super.onViewCreated(view, savedInstanceState)

        adapter = RestaurantCartAdapter(
            onChanged = { refresh() },
            onCheckout = { cart -> openCheckout(cart) },
            onClear = { cart ->
                CartManager.removeCart(cart.restaurantId)
                com.anydrop.food.data.CartSyncManager.scheduleSync(requireContext())
                refresh()
            }
        )
        binding.restaurantCartList.layoutManager = LinearLayoutManager(requireContext())
        binding.restaurantCartList.adapter = adapter

        binding.btnCloseCart.setOnClickListener { dismiss() }

        refresh()
    }

    private fun openCheckout(cart: RestaurantCart) {
        startActivity(
            Intent(requireContext(), CheckoutActivity::class.java)
                .putExtra(CheckoutActivity.EXTRA_RESTAURANT_ID, cart.restaurantId)
        )
        dismiss()
    }

    private fun refresh() {
        val carts = CartManager.getCarts()
        adapter.submit(carts)
        binding.cartEmptyState.visibility = if (carts.isEmpty()) View.VISIBLE else View.GONE
        binding.restaurantCartList.visibility = if (carts.isEmpty()) View.GONE else View.VISIBLE
        if (carts.size > 1) {
            binding.multiCartHint.visibility = View.VISIBLE
            binding.multiCartHint.text = getString(R.string.cart_multiple_restaurants_hint, carts.size)
        } else {
            binding.multiCartHint.visibility = View.GONE
        }
        // Bug fix (2026-08-10) — every path that reaches refresh() (line
        // qty change, clear-restaurant) may have changed the total item
        // count, so notify the host every time rather than special-casing
        // which change actually altered it. See onCartChanged kdoc above.
        onCartChanged?.invoke()
    }

    override fun onStop() {
        super.onStop()
        com.anydrop.food.data.CartSyncManager.syncNow(requireContext())
    }

    override fun onDestroyView() {
        super.onDestroyView()
        _binding = null
    }
}
