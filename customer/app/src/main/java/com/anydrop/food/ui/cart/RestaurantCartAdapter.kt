package com.anydrop.food.ui.cart

import android.view.LayoutInflater
import android.view.ViewGroup
import androidx.recyclerview.widget.LinearLayoutManager
import androidx.recyclerview.widget.RecyclerView
import com.anydrop.food.R
import com.anydrop.food.data.CartManager
import com.anydrop.food.data.RestaurantCart
import com.anydrop.food.databinding.ItemRestaurantCartSectionBinding

/**
 * One card per restaurant-cart inside the cart sheet — the Zomato/Swiggy-
 * style "multiple independent carts" view (see CartManager.kt kdoc). Each
 * card has its own item list (nested [CartItemAdapter]), its own subtotal,
 * a "clear" icon, and its own "Checkout" button.
 */
class RestaurantCartAdapter(
    private val onChanged: () -> Unit,
    private val onCheckout: (RestaurantCart) -> Unit,
    private val onClear: (RestaurantCart) -> Unit
) : RecyclerView.Adapter<RestaurantCartAdapter.VH>() {

    private val carts = mutableListOf<RestaurantCart>()

    fun submit(newCarts: List<RestaurantCart>) {
        carts.clear()
        carts.addAll(newCarts)
        notifyDataSetChanged()
    }

    override fun onCreateViewHolder(parent: ViewGroup, viewType: Int): VH {
        val binding = ItemRestaurantCartSectionBinding.inflate(LayoutInflater.from(parent.context), parent, false)
        return VH(binding)
    }

    override fun onBindViewHolder(holder: VH, position: Int) = holder.bind(carts[position])

    override fun getItemCount() = carts.size

    inner class VH(private val binding: ItemRestaurantCartSectionBinding) :
        RecyclerView.ViewHolder(binding.root) {

        fun bind(cart: RestaurantCart) {
            binding.sectionRestaurantName.text = cart.restaurantName.ifBlank {
                binding.root.context.getString(R.string.cart_other_restaurant_fallback_generic)
            }
            val count = cart.totalItemCount()
            binding.sectionItemCount.text = binding.root.context.resources.getQuantityString(
                R.plurals.cart_items_count, count, count
            )
            binding.sectionTotalText.text = "₹${cart.totalPrice().toInt()}"

            val lineAdapter = CartItemAdapter(cart.restaurantId) {
                onChanged()
                binding.sectionTotalText.text = "₹${(CartManager.getCart(cart.restaurantId)?.totalPrice() ?: 0.0).toInt()}"
                val newCount = CartManager.getCart(cart.restaurantId)?.totalItemCount() ?: 0
                binding.sectionItemCount.text = binding.root.context.resources.getQuantityString(
                    R.plurals.cart_items_count, newCount, newCount
                )
            }
            binding.sectionCartLineList.layoutManager = LinearLayoutManager(binding.root.context)
            binding.sectionCartLineList.adapter = lineAdapter
            lineAdapter.submit(cart.getLines())

            binding.btnClearSection.setOnClickListener { onClear(cart) }
            binding.btnSectionCheckout.setOnClickListener { onCheckout(cart) }
        }
    }
}
