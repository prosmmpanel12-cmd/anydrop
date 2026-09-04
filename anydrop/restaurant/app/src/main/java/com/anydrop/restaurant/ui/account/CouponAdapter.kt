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
 * The switch's `setOnCheckedChangeListener` is detached/reattached
 * around `bind()`'s programmatic `isChecked` set — same guard pattern
 * AccountFragment's `switchTempClosed` already uses — so binding a
 * recycled row never fires a spurious network call for a state the
 * user didn't actually change. (2026-08-19 toggle-standardization pass:
 * back to a plain SwitchMaterial, matching the app-wide green/red
 * switch style — doc 22 item 4's pill MaterialButtonToggleGroup was an
 * intermediate step, not the final direction.)
 *
 * `onEditClick` (coupon-system follow-up session) opens the edit-terms
 * dialog — tapping `couponInfoColumn` (code/discount/meta text), kept
 * deliberately separate from the toggle's own tap target so toggling
 * visibility and editing terms are never ambiguous gestures.
 *
 * `onArchiveClick`/`onUnarchiveClick` (doc 22 follow-up, migration 27) —
 * archived coupons swap `activeControlsGroup` for `archivedGroup`
 * entirely (see item_coupon_manage_row.xml) since there's nothing left
 * to toggle once a coupon is archived, only "bring it back".
 */
class CouponAdapter(
    private val onToggleActive: (Coupon, Boolean) -> Unit,
    private val onEditClick: (Coupon) -> Unit,
    private val onArchiveClick: (Coupon) -> Unit,
    private val onUnarchiveClick: (Coupon) -> Unit
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

            // Archived coupons have nothing left to toggle — swap the
            // whole controls column for the simple "Archived · Restore"
            // one instead of trying to disable individual controls.
            if (coupon.isArchived) {
                binding.activeControlsGroup.visibility = android.view.View.GONE
                binding.archivedGroup.visibility = android.view.View.VISIBLE
                binding.root.alpha = 0.6f
                binding.btnUnarchive.setOnClickListener { onUnarchiveClick(coupon) }
            } else {
                binding.activeControlsGroup.visibility = android.view.View.VISIBLE
                binding.archivedGroup.visibility = android.view.View.GONE
                binding.root.alpha = 1.0f

                binding.switchActive.setOnCheckedChangeListener(null)
                binding.switchActive.isChecked = coupon.isActive
                binding.switchActive.setOnCheckedChangeListener { _, isChecked ->
                    onToggleActive(coupon, isChecked)
                }

                binding.btnArchive.setOnClickListener { onArchiveClick(coupon) }
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
