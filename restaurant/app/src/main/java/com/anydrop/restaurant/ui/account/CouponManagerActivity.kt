package com.anydrop.restaurant.ui.account

import android.os.Bundle
import androidx.appcompat.app.AppCompatActivity
import androidx.lifecycle.lifecycleScope
import androidx.recyclerview.widget.LinearLayoutManager
import com.anydrop.restaurant.R
import com.anydrop.restaurant.databinding.ActivityCouponManagerBinding
import com.anydrop.restaurant.databinding.DialogAddCouponBinding
import com.anydrop.restaurant.network.ApiClient
import com.anydrop.restaurant.network.Coupon
import com.anydrop.restaurant.network.CouponCreateBody
import com.anydrop.restaurant.network.CouponUpdateBody
import com.anydrop.restaurant.ui.common.InAppNotifier
import kotlinx.coroutines.launch

/**
 * "My Coupons" screen (doc 07_Phase_3.7_Bug_Tracker.md §2.1, this
 * session) — restaurant-created coupons with an on/off visibility
 * toggle. Launched from AccountFragment's "My Coupons" row.
 *
 * The switch on each row IS the "visibility" toggle the ask describes —
 * it flips `is_active`, not `is_public` (see coupons-create.php's kdoc
 * for why those are two different flags: is_public only controls
 * whether the customer app auto-*suggests* the code on its "view all
 * offers" screen; is_active is the real on/off — an inactive coupon is
 * rejected by /cart/validate outright even if someone already knows the
 * exact code).
 *
 * Add/edit-coupon both use a plain AlertDialog + the same custom view
 * (dialog_add_coupon.xml), same "keep it simple, no new screen" pattern
 * OrderAdapter's inline reject dialog already uses in this app, rather
 * than a second Activity. `showAddCouponDialog()`/`showEditCouponDialog()`
 * each inflate their own dialog_add_coupon.xml instance and configure it
 * differently — see `showEditCouponDialog()`'s kdoc for exactly what
 * differs (code locked, discount-type shown as a label instead of chips,
 * every other field pre-filled).
 */
class CouponManagerActivity : AppCompatActivity() {

    private lateinit var binding: ActivityCouponManagerBinding
    private val api by lazy { ApiClient.create(this) }
    private lateinit var adapter: CouponAdapter

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityCouponManagerBinding.inflate(layoutInflater)
        setContentView(binding.root)

        adapter = CouponAdapter(
            onToggleActive = { coupon, checked -> toggleActive(coupon, checked) },
            onEditClick = { coupon -> showEditCouponDialog(coupon) }
        )
        binding.couponList.layoutManager = LinearLayoutManager(this)
        binding.couponList.adapter = adapter

        binding.btnBack.setOnClickListener { finish() }
        binding.btnAddCoupon.setOnClickListener { showAddCouponDialog() }
        binding.swipeRefresh.setOnRefreshListener { loadCoupons() }

        loadCoupons()
    }

    private fun loadCoupons() {
        lifecycleScope.launch {
            try {
                val response = api.getCoupons()
                val coupons = response.body()?.data?.coupons
                binding.swipeRefresh.isRefreshing = false
                if (response.isSuccessful && coupons != null) {
                    adapter.submitList(coupons)
                    binding.emptyState.visibility = if (coupons.isEmpty()) android.view.View.VISIBLE else android.view.View.GONE
                } else {
                    InAppNotifier.show(this@CouponManagerActivity, getString(R.string.coupon_create_failed), InAppNotifier.Type.ERROR)
                }
            } catch (e: Exception) {
                binding.swipeRefresh.isRefreshing = false
                InAppNotifier.show(this@CouponManagerActivity, getString(R.string.coupon_create_failed), InAppNotifier.Type.ERROR)
            }
        }
    }

    private fun toggleActive(coupon: Coupon, checked: Boolean) {
        lifecycleScope.launch {
            try {
                val response = api.updateCoupon(coupon.id, CouponUpdateBody(isActive = checked))
                val updated = response.body()?.data?.coupon
                if (response.isSuccessful && updated != null) {
                    adapter.updateOne(updated)
                } else {
                    InAppNotifier.show(this@CouponManagerActivity, getString(R.string.coupon_update_failed), InAppNotifier.Type.ERROR)
                    loadCoupons() // resync the switch back to server truth
                }
            } catch (e: Exception) {
                InAppNotifier.show(this@CouponManagerActivity, getString(R.string.coupon_update_failed), InAppNotifier.Type.ERROR)
                loadCoupons()
            }
        }
    }

    private fun showAddCouponDialog() {
        val dialogBinding = DialogAddCouponBinding.inflate(layoutInflater)
        setUpDiscountTypeToggle(dialogBinding)

        android.app.AlertDialog.Builder(this)
            .setTitle(R.string.coupon_add_title)
            .setView(dialogBinding.root)
            .setPositiveButton(R.string.btn_save) { _, _ -> submitNewCoupon(dialogBinding) }
            .setNegativeButton(R.string.btn_cancel, null)
            .show()
    }

    /**
     * Edit-terms dialog (coupon-system follow-up session — see
     * NEXT_SESSION_PROMPT.md / 00_Status.md's "Not done" list from the
     * coupon session). Reuses dialog_add_coupon.xml with every field
     * pre-filled from [coupon] except code (create-only) — and hides the
     * discount-type chip picker in favor of a plain label, since
     * discount_type is also create-only server-side (coupons-update.php's
     * kdoc). inputCode itself is disabled rather than hidden, so the
     * dialog still visually confirms *which* coupon is being edited.
     */
    private fun showEditCouponDialog(coupon: Coupon) {
        val dialogBinding = DialogAddCouponBinding.inflate(layoutInflater)

        dialogBinding.inputCode.setText(coupon.code)
        dialogBinding.inputCode.isEnabled = false

        dialogBinding.discountTypeHint.visibility = android.view.View.GONE
        dialogBinding.discountTypeGroup.visibility = android.view.View.GONE
        dialogBinding.editDiscountTypeLabel.visibility = android.view.View.VISIBLE
        dialogBinding.editDiscountTypeLabel.text = if (coupon.discountType == "percent") {
            getString(R.string.coupon_edit_type_locked_fmt, getString(R.string.coupon_type_percent))
        } else {
            getString(R.string.coupon_edit_type_locked_fmt, getString(R.string.coupon_type_flat))
        }
        // Not driven by the (hidden) chips here, so maxDiscountLayout's
        // visibility is decided directly from the coupon's own type
        // instead of setUpDiscountTypeToggle()'s chip listener.
        dialogBinding.maxDiscountLayout.visibility =
            if (coupon.discountType == "percent") android.view.View.VISIBLE else android.view.View.GONE

        dialogBinding.inputDiscountValue.setText(formatEditableAmount(coupon.discountValue))
        if (coupon.minOrderAmount > 0) {
            dialogBinding.inputMinOrder.setText(formatEditableAmount(coupon.minOrderAmount))
        }
        coupon.maxDiscountAmount?.let { dialogBinding.inputMaxDiscount.setText(formatEditableAmount(it)) }
        // valid_until comes back as "yyyy-MM-dd HH:mm:ss" — inputValidUntil
        // only collects the date part (submitNewCoupon/submitCouponEdit
        // both append " 23:59:59" themselves), so strip the time here.
        coupon.validUntil?.let { dialogBinding.inputValidUntil.setText(it.substringBefore(' ')) }
        coupon.usageLimitTotal?.let { dialogBinding.inputUsageLimitTotal.setText(it.toString()) }
        coupon.usageLimitPerUser?.let { dialogBinding.inputUsageLimitPerUser.setText(it.toString()) }

        android.app.AlertDialog.Builder(this)
            .setTitle(R.string.coupon_edit_title)
            .setView(dialogBinding.root)
            .setPositiveButton(R.string.btn_save) { _, _ -> submitCouponEdit(coupon.id, dialogBinding) }
            .setNegativeButton(R.string.btn_cancel, null)
            .show()
    }

    /** Add-mode only — wires the chip group to show/hide maxDiscountLayout.
     * Edit-mode sets that visibility directly from the existing coupon's
     * type instead (the chips aren't shown there at all). */
    private fun setUpDiscountTypeToggle(dialogBinding: DialogAddCouponBinding) {
        dialogBinding.discountTypeGroup.setOnCheckedStateChangeListener { _, checkedIds ->
            val isPercent = checkedIds.contains(dialogBinding.chipPercent.id)
            dialogBinding.maxDiscountLayout.visibility = if (isPercent) android.view.View.VISIBLE else android.view.View.GONE
        }
    }

    private fun formatEditableAmount(value: Double): String {
        return if (value == value.toLong().toDouble()) value.toLong().toString() else value.toString()
    }

    private fun submitNewCoupon(dialogBinding: DialogAddCouponBinding) {
        val code = dialogBinding.inputCode.text?.toString()?.trim().orEmpty()
        val discountType = if (dialogBinding.chipPercent.isChecked) "percent" else "flat"
        val discountValue = dialogBinding.inputDiscountValue.text?.toString()?.trim()?.toDoubleOrNull()
        val minOrder = dialogBinding.inputMinOrder.text?.toString()?.trim()?.toDoubleOrNull()
        val maxDiscount = dialogBinding.inputMaxDiscount.text?.toString()?.trim()?.toDoubleOrNull()
        val validUntilRaw = dialogBinding.inputValidUntil.text?.toString()?.trim().orEmpty()
        val validUntil = if (validUntilRaw.isNotEmpty()) "$validUntilRaw 23:59:59" else null
        val usageLimitTotal = dialogBinding.inputUsageLimitTotal.text?.toString()?.trim()?.toIntOrNull()
        val usageLimitPerUser = dialogBinding.inputUsageLimitPerUser.text?.toString()?.trim()?.toIntOrNull()

        if (code.isEmpty() || discountValue == null || discountValue <= 0) {
            InAppNotifier.show(this, getString(R.string.coupon_create_failed), InAppNotifier.Type.ERROR)
            return
        }

        lifecycleScope.launch {
            try {
                val response = api.createCoupon(
                    CouponCreateBody(
                        code = code,
                        discountType = discountType,
                        discountValue = discountValue,
                        minOrderAmount = minOrder,
                        maxDiscountAmount = if (discountType == "percent") maxDiscount else null,
                        validUntil = validUntil,
                        usageLimitTotal = usageLimitTotal,
                        usageLimitPerUser = usageLimitPerUser
                    )
                )
                val created = response.body()?.data?.coupon
                if (response.isSuccessful && created != null) {
                    InAppNotifier.show(this@CouponManagerActivity, getString(R.string.coupon_created), InAppNotifier.Type.SUCCESS)
                    loadCoupons()
                } else if (response.code() == 409) {
                    InAppNotifier.show(this@CouponManagerActivity, getString(R.string.coupon_code_taken), InAppNotifier.Type.ERROR)
                } else {
                    InAppNotifier.show(this@CouponManagerActivity, getString(R.string.coupon_create_failed), InAppNotifier.Type.ERROR)
                }
            } catch (e: Exception) {
                InAppNotifier.show(this@CouponManagerActivity, getString(R.string.coupon_create_failed), InAppNotifier.Type.ERROR)
            }
        }
    }

    /**
     * Edit-terms submit (coupon-system follow-up session). Sends every
     * editable field every time (not just changed ones) — coupons-update.php
     * is a null-skip partial update, but discount_value/min_order_amount
     * only write `array_key_exists` is true, and this dialog doesn't track
     * per-field dirty state, so sending the full current form state is both
     * simpler and correct: every value present is exactly what the dialog
     * showed the user, whether they changed it or not.
     *
     * discount_value must stay positive same as create; min/max/valid-until/
     * usage-limits are all optional and get sent as null when the user
     * clears them, correctly clearing that field server-side.
     */
    private fun submitCouponEdit(couponId: Int, dialogBinding: DialogAddCouponBinding) {
        val discountValue = dialogBinding.inputDiscountValue.text?.toString()?.trim()?.toDoubleOrNull()
        if (discountValue == null || discountValue <= 0) {
            InAppNotifier.show(this, getString(R.string.coupon_update_failed), InAppNotifier.Type.ERROR)
            return
        }
        val minOrder = dialogBinding.inputMinOrder.text?.toString()?.trim()?.toDoubleOrNull()
        val maxDiscount = dialogBinding.inputMaxDiscount.text?.toString()?.trim()?.toDoubleOrNull()
        val validUntilRaw = dialogBinding.inputValidUntil.text?.toString()?.trim().orEmpty()
        val validUntil = if (validUntilRaw.isNotEmpty()) "$validUntilRaw 23:59:59" else null
        val usageLimitTotal = dialogBinding.inputUsageLimitTotal.text?.toString()?.trim()?.toIntOrNull()
        val usageLimitPerUser = dialogBinding.inputUsageLimitPerUser.text?.toString()?.trim()?.toIntOrNull()

        lifecycleScope.launch {
            try {
                val response = api.updateCoupon(
                    couponId,
                    CouponUpdateBody(
                        discountValue = discountValue,
                        minOrderAmount = minOrder,
                        maxDiscountAmount = maxDiscount,
                        validUntil = validUntil,
                        usageLimitTotal = usageLimitTotal,
                        usageLimitPerUser = usageLimitPerUser
                    )
                )
                val updated = response.body()?.data?.coupon
                if (response.isSuccessful && updated != null) {
                    InAppNotifier.show(this@CouponManagerActivity, getString(R.string.coupon_updated), InAppNotifier.Type.SUCCESS)
                    adapter.updateOne(updated)
                } else {
                    InAppNotifier.show(this@CouponManagerActivity, getString(R.string.coupon_update_failed), InAppNotifier.Type.ERROR)
                }
            } catch (e: Exception) {
                InAppNotifier.show(this@CouponManagerActivity, getString(R.string.coupon_update_failed), InAppNotifier.Type.ERROR)
            }
        }
    }
}
