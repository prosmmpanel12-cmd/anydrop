package com.anydrop.food.ui.checkout

import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import androidx.recyclerview.widget.RecyclerView
import coil.load
import com.anydrop.food.data.CartLine
import com.anydrop.food.databinding.ItemCartLineBinding
import com.anydrop.food.network.ApiClient

/**
 * App owner ask (2026-08-25) — read-only cart item list on the
 * Checkout screen (image + name + qty + price + offer badge), reusing
 * item_cart_line.xml/ItemCartLineBinding exactly as the cart sheet's
 * [com.anydrop.food.ui.cart.CartItemAdapter] does, so the two screens'
 * item rows look identical to the customer.
 *
 * Deliberately its own (near-duplicate) adapter rather than reusing
 * CartItemAdapter directly: CartItemAdapter's qty +/- stepper wires
 * straight into CartManager.add()/decrease() plus an onChanged()
 * callback — editing quantity from *this* screen would need to also
 * re-run /cart/validate (renderBill()) to keep the bill in sync, which
 * is a materially different responsibility than "list what's in the
 * cart for review". Checkout is a review-and-confirm screen; the cart
 * sheet (one tap away via the back arrow) remains the one place
 * quantity actually gets edited — same separation of concerns
 * RestaurantCartAdapter's own kdoc draws between the cart sheet and
 * Checkout elsewhere in this codebase.
 */
class CheckoutItemAdapter : RecyclerView.Adapter<CheckoutItemAdapter.VH>() {

    private val lines = mutableListOf<CartLine>()

    fun submit(newLines: List<CartLine>) {
        lines.clear()
        lines.addAll(newLines)
        notifyDataSetChanged()
    }

    override fun onCreateViewHolder(parent: ViewGroup, viewType: Int): VH {
        val binding = ItemCartLineBinding.inflate(LayoutInflater.from(parent.context), parent, false)
        return VH(binding)
    }

    override fun onBindViewHolder(holder: VH, position: Int) = holder.bind(lines[position])

    override fun getItemCount() = lines.size

    inner class VH(private val binding: ItemCartLineBinding) : RecyclerView.ViewHolder(binding.root) {
        fun bind(line: CartLine) {
            binding.cartLineName.text = line.item.name
            // §2.6 — same unitPrice-not-item.price rule CartItemAdapter's
            // own bind() already documents (addons must count here too).
            binding.cartLinePrice.text = "₹${(line.unitPrice * line.quantity).toInt()}"
            binding.cartLineVegBadge.setBackgroundResource(
                if (line.item.isVeg) com.anydrop.food.R.drawable.bg_badge_veg
                else com.anydrop.food.R.drawable.bg_badge_nonveg
            )

            if (!line.item.imageUrl.isNullOrBlank()) {
                binding.cartLineImage.load(ApiClient.baseUrlForStaticFiles(binding.root.context) + line.item.imageUrl)
            } else {
                binding.cartLineImage.setImageDrawable(null)
            }

            val note = listOfNotNull(line.addonSummary, line.specialInstructions?.takeIf { it.isNotBlank() })
                .joinToString(" · ")
            if (note.isNotBlank()) {
                binding.cartLineCustomization.text = note
                binding.cartLineCustomization.visibility = View.VISIBLE
            } else {
                binding.cartLineCustomization.visibility = View.GONE
            }

            if (!line.item.offerTag.isNullOrBlank()) {
                binding.cartLineOfferTag.text = line.item.offerTag
                binding.cartLineOfferTag.visibility = View.VISIBLE
            } else {
                binding.cartLineOfferTag.visibility = View.GONE
            }

            // Read-only mode — stepper hidden, plain "× qty" shown instead.
            binding.cartLineStepperGroup.visibility = View.GONE
            binding.cartLineQtyReadonly.visibility = View.VISIBLE
            binding.cartLineQtyReadonly.text = "× ${line.quantity}"
        }
    }
}
