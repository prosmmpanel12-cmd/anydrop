package com.anydrop.food.ui.checkout

import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import androidx.recyclerview.widget.RecyclerView
import com.anydrop.food.R
import com.anydrop.food.databinding.ItemCouponRowBinding
import com.anydrop.food.network.CouponListItem

/**
 * H5 — coupons list inside CouponsListBottomSheetFragment. Eligible rows
 * are tappable (fires [onApply]); ineligible ones (usage exhausted, or
 * cart still below that coupon's min_order_amount) render dimmed and
 * inert, same "don't invite a tap that will just fail" stance as
 * CheckoutActivity's btnPlaceOrder disabled state.
 */
class CouponsAdapter(
    private val onApply: (CouponListItem) -> Unit
) : RecyclerView.Adapter<CouponsAdapter.VH>() {

    private val items = mutableListOf<CouponListItem>()
    private var appliedCode: String? = null

    fun submit(list: List<CouponListItem>, currentlyAppliedCode: String?) {
        items.clear()
        items.addAll(list)
        appliedCode = currentlyAppliedCode
        notifyDataSetChanged()
    }

    override fun onCreateViewHolder(parent: ViewGroup, viewType: Int): VH {
        val binding = ItemCouponRowBinding.inflate(LayoutInflater.from(parent.context), parent, false)
        return VH(binding)
    }

    override fun onBindViewHolder(holder: VH, position: Int) = holder.bind(items[position])

    override fun getItemCount() = items.size

    inner class VH(private val binding: ItemCouponRowBinding) : RecyclerView.ViewHolder(binding.root) {
        fun bind(coupon: CouponListItem) {
            val ctx = binding.root.context
            binding.couponCode.text = coupon.code
            binding.couponDiscountLine.text = when {
                // Migration 49 — a coupon_based restaurant offer (B1G1,
                // % off, etc.) has no single discount_value the way a
                // real coupon does; offerLabel is the same human-
                // readable badge (offer_badge_label()) menu/search item
                // tags already show for the auto-applied version of
                // this offer type.
                coupon.discountType == "offer" && coupon.offerLabel != null -> coupon.offerLabel
                coupon.discountType == "percent" && coupon.maxDiscountAmount != null ->
                    ctx.getString(
                        R.string.coupon_percent_off_capped,
                        "%.0f".format(coupon.discountValue),
                        "%.0f".format(coupon.maxDiscountAmount)
                    )
                coupon.discountType == "percent" ->
                    ctx.getString(R.string.coupon_percent_off, "%.0f".format(coupon.discountValue))
                else ->
                    ctx.getString(R.string.coupon_flat_off, "%.0f".format(coupon.discountValue))
            }

            val isApplied = coupon.code.equals(appliedCode, ignoreCase = true)

            binding.couponSubLine.text = when {
                !coupon.isEligible && coupon.ineligibleReason == "usage_limit_reached" ->
                    ctx.getString(R.string.coupon_used_up)
                !coupon.isEligible && coupon.amountNeededToUnlock != null ->
                    ctx.getString(R.string.coupon_add_more_to_unlock, "%.0f".format(coupon.amountNeededToUnlock))
                else ->
                    ctx.getString(R.string.coupon_min_order_line, "%.0f".format(coupon.minOrderAmount))
            }

            binding.couponActionText.visibility = if (coupon.isEligible && !isApplied) View.VISIBLE else View.GONE
            binding.couponActionText.text = ctx.getString(R.string.coupon_tap_to_apply)
            binding.couponAppliedIcon.visibility = if (isApplied) View.VISIBLE else View.GONE

            binding.couponRowRoot.alpha = if (coupon.isEligible) 1.0f else 0.5f
            binding.couponRowRoot.isEnabled = coupon.isEligible && !isApplied
            binding.couponRowRoot.setOnClickListener {
                if (coupon.isEligible && !isApplied) onApply(coupon)
            }
        }
    }
}
