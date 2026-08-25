package com.anydrop.food.ui.cart

import android.view.LayoutInflater
import android.view.ViewGroup
import androidx.recyclerview.widget.RecyclerView
import coil.load
import com.anydrop.food.data.CartLine
import com.anydrop.food.data.CartManager
import com.anydrop.food.data.CartSyncManager
import com.anydrop.food.databinding.ItemCartLineBinding
import com.anydrop.food.network.ApiClient

/** Item rows for a single restaurant's cart section (see RestaurantCartAdapter). */
class CartItemAdapter(
    private val restaurantId: Int,
    private val onChanged: () -> Unit
) : RecyclerView.Adapter<CartItemAdapter.VH>() {

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

    override fun onBindViewHolder(holder: VH, position: Int) {
        holder.bind(lines[position])
    }

    override fun getItemCount() = lines.size

    inner class VH(private val binding: ItemCartLineBinding) : RecyclerView.ViewHolder(binding.root) {
        fun bind(line: CartLine) {
            binding.cartLineName.text = line.item.name
            // §2.6 — priced off line.unitPrice (item price + selected addons),
            // never line.item.price directly, or a customized line's addons
            // silently stop counting toward what the customer sees here.
            binding.cartLinePrice.text = "₹${(line.unitPrice * line.quantity).toInt()}"
            binding.cartLineQty.text = line.quantity.toString()
            binding.cartLineVegBadge.setBackgroundResource(
                if (line.item.isVeg) com.anydrop.food.R.drawable.bg_badge_veg
                else com.anydrop.food.R.drawable.bg_badge_nonveg
            )

            // App owner ask (2026-08-25) — dish photo in the cart list.
            // Same static-files base-URL prefix every other image load in
            // the app needs (MenuAdapter's own comment explains why the
            // raw relative path alone never renders), null image clears
            // any recycled-view thumbnail rather than leaving a stale one.
            if (!line.item.imageUrl.isNullOrBlank()) {
                binding.cartLineImage.load(ApiClient.baseUrlForStaticFiles(binding.root.context) + line.item.imageUrl)
            } else {
                binding.cartLineImage.setImageDrawable(null)
            }

            val note = listOfNotNull(line.addonSummary, line.specialInstructions?.takeIf { it.isNotBlank() })
                .joinToString(" · ")
            if (note.isNotBlank()) {
                binding.cartLineCustomization.text = note
                binding.cartLineCustomization.visibility = android.view.View.VISIBLE
            } else {
                binding.cartLineCustomization.visibility = android.view.View.GONE
            }

            binding.btnLineIncrease.setOnClickListener {
                CartManager.add(restaurantId, line.item)
                CartSyncManager.scheduleSync(binding.root.context)
                onChanged()
            }
            binding.btnLineDecrease.setOnClickListener {
                CartManager.decrease(restaurantId, line.item)
                CartSyncManager.scheduleSync(binding.root.context)
                onChanged()
            }
        }
    }
}

