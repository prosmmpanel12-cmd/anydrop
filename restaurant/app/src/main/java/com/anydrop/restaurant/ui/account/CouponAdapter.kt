package com.anydrop.restaurant.ui.account

import android.view.LayoutInflater
import android.view.ViewGroup
import androidx.recyclerview.widget.RecyclerView
import com.anydrop.restaurant.R
import com.anydrop.restaurant.databinding.ItemCouponManageRowBinding
import com.anydrop.restaurant.network.Coupon
import java.util.Locale

/**
 * Coupon list for CouponManagerActivity (doc 07 §2.1, this session).
 * Same shape as BannerAdapter — plain submitList, no diffing (this
 * screen's list is small and rarely churns mid-session).
 *
 * The switch's `onCheckedChange` listener is detached/reattached around
 * `bind()`'s programmatic `isChecked` set — same guard pattern
 * AccountFragment's `switchTempClosed` already uses — so binding a
 * recycled row never fires a spurious network call for a state the user
 * didn't actually change.
 *
 * `onEditClick` (coupon-system follow-up session) opens the edit-terms
 * dialog — tapping `couponInfoColumn` (code/discount/meta text), kept
 * deliberately separate from the switch's own tap target so toggling
 * visibility and editing terms are never ambiguous gestures.
 */
class CouponAdapter(
    private val onToggleActive: (Coupon, Boolean) -> Unit,
    private val onEditClick: (Coupon) -> Unit
) : RecyclerView.Adapter<CouponAdapter.CouponViewHolder>() {

    private val coupons = mutableListOf<Coupon>()

    fun submitList(newCoupons: List<Coupon>) {
        coupons.clear()
        coupons.addAll(newCoupons)
        notifyDataSetChanged()
    }

    fun updateOne(updated: Coupon) {
        val index = coupons.indexOfFirst { it.id == updated.id }
        if (index != -1) {
            coupons[index] = updated
            notifyItemChanged(index)
        }
    }

    override fun onCreateViewHolder(parent: ViewGroup, viewType: Int): CouponViewHolder {
        val binding = ItemCouponManageRowBinding.inflate(LayoutInflater.from(parent.context), parent, false)
        return CouponViewHolder(binding)
    }

    override fun onBindViewHolder(holder: CouponViewHolder, position: Int) {
        holder.bind(coupons[position])
    }

    override fun getItemCount() = coupons.size

    inner class CouponViewHolder(private val binding: ItemCouponManageRowBinding) :
        RecyclerView.ViewHolder(binding.root) {

        fun bind(coupon: Coupon) {
            val ctx = binding.root.context
            binding.couponCodeText.text = coupon.code

            binding.couponDiscountText.text = if (coupon.discountType == "percent") {
                ctx.getString(R.string.coupon_percent_off_fmt, formatAmount(coupon.discountValue))
            } else {
                ctx.getString(R.string.coupon_flat_off_fmt, formatAmount(coupon.discountValue))
            }

            val metaParts = mutableListOf<String>()
            if (coupon.minOrderAmount > 0) {
                metaParts.add(ctx.getString(R.string.coupon_min_order_fmt, formatAmount(coupon.minOrderAmount)))
            }
            metaParts.add(ctx.getString(R.string.coupon_times_used_fmt, coupon.timesUsed))
            binding.couponMetaText.text = metaParts.joinToString(" · ")

            binding.switchActive.setOnCheckedChangeListener(null)
            binding.switchActive.isChecked = coupon.isActive
            binding.switchActive.setOnCheckedChangeListener { _, checked ->
                onToggleActive(coupon, checked)
            }

            binding.couponInfoColumn.setOnClickListener { onEditClick(coupon) }
        }

        private fun formatAmount(value: Double): String {
            return if (value == value.toLong().toDouble()) {
                value.toLong().toString()
            } else {
                String.format(Locale.getDefault(), "%.2f", value)
            }
        }
    }
}
