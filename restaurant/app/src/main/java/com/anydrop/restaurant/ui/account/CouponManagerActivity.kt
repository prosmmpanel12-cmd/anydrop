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
import com.google.android.material.bottomsheet.BottomSheetDialog
import com.google.android.material.datepicker.MaterialDatePicker
import com.google.android.material.dialog.MaterialAlertDialogBuilder
import com.google.android.material.timepicker.MaterialTimePicker
import com.google.android.material.timepicker.TimeFormat
import kotlinx.coroutines.launch
import java.text.SimpleDateFormat
import java.util.Calendar
import java.util.Locale
import java.util.TimeZone

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
 * Add/edit-coupon both use a `MaterialAlertDialogBuilder` (doc 22 item 2 —
 * switched from the plain `android.app.AlertDialog.Builder` this session
 * as part of the coupon screen's slice of the dialog-modernization ask;
 * the other dialogs in doc 22's list are still plain `AlertDialog.Builder`
 * pending that same pass) wrapping the same custom view
 * (dialog_add_coupon.xml), same "keep it simple, no new screen" pattern
 * OrderAdapter's inline reject dialog already uses in this app, rather
 * than a second Activity. `showAddCouponDialog()`/`showEditCouponDialog()`
 * each inflate their own dialog_add_coupon.xml instance and configure it
 * differently — see `showEditCouponDialog()`'s kdoc for exactly what
 * differs (code locked, discount-type shown as a label instead of chips,
 * every other field pre-filled).
 *
 * doc 22 additions this session:
 * - `is_public` ("show on coupon screen") is now a pill toggle in this
 *   same dialog, editable at both create and edit time (item 3's
 *   follow-up answer — "Both create and edit").
 * - `valid_until` opens a real `MaterialDatePicker` → `MaterialTimePicker`
 *   pair instead of manual yyyy-MM-dd typing (item 5's follow-up answer —
 *   a real date **and** time, not date-only).
 * - Coupon rows now also expose an archive/unarchive action alongside the
 *   existing is_active toggle (migration 27, follow-up answer — "also
 *   add off on delete and other possible option"), handled by
 *   `archiveCoupon()`/`unarchiveCoupon()` below.
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
            onEditClick = { coupon -> showEditCouponDialog(coupon) },
            onArchiveClick = { coupon -> confirmArchive(coupon) },
            onUnarchiveClick = { coupon -> unarchiveCoupon(coupon) }
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

    /**
     * Archive (migration 27, doc 22 follow-up — "also add off on delete
     * and other possible option"). Confirmed first, unlike the plain
     * is_active toggle, since it changes what section of the list the
     * coupon shows up in rather than just flipping a switch back — same
     * "confirm anything that moves/removes a row from view" instinct as
     * other destructive-ish actions in this app.
     */
    private fun confirmArchive(coupon: Coupon) {
        MaterialAlertDialogBuilder(this)
            .setTitle(R.string.coupon_archive_confirm_title)
            .setMessage(R.string.coupon_archive_confirm_message)
            .setPositiveButton(R.string.coupon_archive_confirm_positive) { _, _ -> archiveCoupon(coupon) }
            .setNegativeButton(R.string.btn_cancel, null)
            .show()
    }

    private fun archiveCoupon(coupon: Coupon) {
        lifecycleScope.launch {
            try {
                val response = api.updateCoupon(coupon.id, CouponUpdateBody(isArchived = true))
                val updated = response.body()?.data?.coupon
                if (response.isSuccessful && updated != null) {
                    InAppNotifier.show(this@CouponManagerActivity, getString(R.string.coupon_archived_success), InAppNotifier.Type.SUCCESS)
                    adapter.updateOne(updated)
                } else {
                    InAppNotifier.show(this@CouponManagerActivity, getString(R.string.coupon_archive_failed), InAppNotifier.Type.ERROR)
                }
            } catch (e: Exception) {
                InAppNotifier.show(this@CouponManagerActivity, getString(R.string.coupon_archive_failed), InAppNotifier.Type.ERROR)
            }
        }
    }

    /** No confirmation needed to restore — unlike archiving, this can't
     * lose anything; the coupon just goes back to being manageable. */
    private fun unarchiveCoupon(coupon: Coupon) {
        lifecycleScope.launch {
            try {
                val response = api.updateCoupon(coupon.id, CouponUpdateBody(isArchived = false))
                val updated = response.body()?.data?.coupon
                if (response.isSuccessful && updated != null) {
                    InAppNotifier.show(this@CouponManagerActivity, getString(R.string.coupon_unarchived_success), InAppNotifier.Type.SUCCESS)
                    adapter.updateOne(updated)
                } else {
                    InAppNotifier.show(this@CouponManagerActivity, getString(R.string.coupon_unarchive_failed), InAppNotifier.Type.ERROR)
                }
            } catch (e: Exception) {
                InAppNotifier.show(this@CouponManagerActivity, getString(R.string.coupon_unarchive_failed), InAppNotifier.Type.ERROR)
            }
        }
    }

    private fun showAddCouponDialog() {
        val dialogBinding = DialogAddCouponBinding.inflate(layoutInflater)
        setUpDiscountTypeToggle(dialogBinding)
        setUpValidUntilPicker(dialogBinding)
        dialogBinding.switchPublic.isChecked = false // is_public defaults to false, same as server
        dialogBinding.couponDialogTitle.text = getString(R.string.coupon_add_title)

        // doc 22 item 2 follow-up — bottom sheet, not a centered
        // MaterialAlertDialogBuilder (see dialog_add_coupon.xml's header
        // comment). btnCouponDialogSave/Cancel replace setPositiveButton/
        // setNegativeButton.
        val addDialog = BottomSheetDialog(this)
        addDialog.setContentView(dialogBinding.root)
        dialogBinding.btnCouponDialogCancel.setOnClickListener { addDialog.dismiss() }
        dialogBinding.btnCouponDialogSave.setOnClickListener {
            if (submitNewCoupon(dialogBinding)) addDialog.dismiss()
        }
        addDialog.show()
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

        setUpValidUntilPicker(dialogBinding)
        // valid_until comes back as "yyyy-MM-dd HH:mm:ss" straight from
        // the server — that's exactly the wire format setUpValidUntilPicker()
        // expects in the field's tag, so pre-fill both the tag (source of
        // truth sent back to the server) and a friendly display string
        // together via the same helper the picker itself uses.
        coupon.validUntil?.let { applyValidUntilValue(dialogBinding, it) }

        coupon.usageLimitTotal?.let { dialogBinding.inputUsageLimitTotal.setText(it.toString()) }
        coupon.usageLimitPerUser?.let { dialogBinding.inputUsageLimitPerUser.setText(it.toString()) }

        // is_public — doc 22 item 3 follow-up: editable here too, not
        // just at creation time. Pre-filled from the coupon's current
        // value like every other field in edit mode.
        dialogBinding.switchPublic.isChecked = coupon.isPublic
        dialogBinding.couponDialogTitle.text = getString(R.string.coupon_edit_title)

        val editDialog = BottomSheetDialog(this)
        editDialog.setContentView(dialogBinding.root)
        dialogBinding.btnCouponDialogCancel.setOnClickListener { editDialog.dismiss() }
        dialogBinding.btnCouponDialogSave.setOnClickListener {
            if (submitCouponEdit(coupon.id, dialogBinding)) editDialog.dismiss()
        }
        editDialog.show()
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

    /** yyyy-MM-dd HH:mm:ss — the exact wire format coupons-create.php /
     * coupons-update.php read valid_until in, and what coupons-list.php
     * hands back. Kept as one shared format string so the picker's output,
     * the pre-fill-from-server path, and the submit path can never drift
     * out of sync with each other. */
    private val validUntilWireFormat = SimpleDateFormat("yyyy-MM-dd HH:mm:ss", Locale.US)
    private val validUntilDisplayFormat = SimpleDateFormat("d MMM yyyy, h:mm a", Locale.getDefault())

    /**
     * doc 22 item 5 — real date+time picker. `inputValidUntil` is
     * focusable="false"/clickable="true" (see dialog_add_coupon.xml), so
     * tapping it opens a `MaterialDatePicker` and, on a date being picked,
     * chains straight into a `MaterialTimePicker` — the app owner
     * specifically asked for date **and** time, not date-only (the old
     * behaviour always forced 23:59:59). The field's tag holds the raw
     * `yyyy-MM-dd HH:mm:ss` value actually sent to the server; the field's
     * text is just the friendly display string — submitNewCoupon()/
     * submitCouponEdit() read the tag, never the display text, so display
     * formatting changes can never silently corrupt what's sent.
     *
     * MaterialDatePicker works in UTC millis internally regardless of the
     * device's timezone (its own documented behavior) — converted back to
     * the device's local Calendar here before combining with the picked
     * time, so what the restaurant sees/picks matches their own clock.
     */
    private fun setUpValidUntilPicker(dialogBinding: DialogAddCouponBinding) {
        // Custom end-icon "×" (app:endIconMode="custom", since the field
        // is focusable="false"/click-only — the built-in clear_text mode
        // expects a keyboard-editable field). Only visible once a value
        // is actually set (empty otherwise, so an unused field doesn't
        // show a clear button with nothing to clear).
        dialogBinding.validUntilLayout.setEndIconOnClickListener {
            dialogBinding.inputValidUntil.tag = null
            dialogBinding.inputValidUntil.text = null
            dialogBinding.validUntilLayout.isEndIconVisible = false
        }
        dialogBinding.validUntilLayout.isEndIconVisible = dialogBinding.inputValidUntil.tag != null

        dialogBinding.inputValidUntil.setOnClickListener {
            val datePicker = MaterialDatePicker.Builder.datePicker()
                .setTitleText(getString(R.string.coupon_hint_valid_until))
                .build()
            datePicker.addOnPositiveButtonClickListener { utcMillis ->
                val utcCal = Calendar.getInstance(TimeZone.getTimeZone("UTC"))
                utcCal.timeInMillis = utcMillis

                val timePicker = MaterialTimePicker.Builder()
                    .setTimeFormat(TimeFormat.CLOCK_12H)
                    .setHour(23)
                    .setMinute(59)
                    .build()
                timePicker.addOnPositiveButtonClickListener {
                    val local = Calendar.getInstance()
                    local.set(
                        utcCal.get(Calendar.YEAR),
                        utcCal.get(Calendar.MONTH),
                        utcCal.get(Calendar.DAY_OF_MONTH),
                        timePicker.hour,
                        timePicker.minute,
                        0
                    )
                    applyValidUntilValue(dialogBinding, validUntilWireFormat.format(local.time))
                }
                timePicker.show(supportFragmentManager, "valid_until_time_picker")
            }
            datePicker.show(supportFragmentManager, "valid_until_date_picker")
        }
    }

    /** Sets both the field's displayed text and its tag (the actual value
     * read at submit time) from a single yyyy-MM-dd HH:mm:ss wire-format
     * string — shared by the picker's own callback and by
     * showEditCouponDialog()'s pre-fill from an existing coupon. */
    private fun applyValidUntilValue(dialogBinding: DialogAddCouponBinding, wireValue: String) {
        dialogBinding.inputValidUntil.tag = wireValue
        val display = try {
            validUntilDisplayFormat.format(validUntilWireFormat.parse(wireValue)!!)
        } catch (e: Exception) {
            wireValue // fall back to showing the raw value rather than crashing on an unexpected format
        }
        dialogBinding.inputValidUntil.setText(display)
        dialogBinding.validUntilLayout.isEndIconVisible = true
    }

    private fun formatEditableAmount(value: Double): String {
        return if (value == value.toLong().toDouble()) value.toLong().toString() else value.toString()
    }

    /** Returns true once validation passes and the create request has been
     * kicked off — false on a validation failure, so the bottom sheet
     * calling this (see showAddCouponDialog()) knows whether to dismiss
     * itself or stay open for the user to fix their input. Previously,
     * as a MaterialAlertDialogBuilder positive-button callback, an early
     * return here still let the dialog auto-dismiss underneath it (Android's
     * default AlertDialog behavior) — a validation error toast plus a
     * closed dialog. The bottom-sheet version intentionally fixes that:
     * invalid input now keeps the sheet open. */
    private fun submitNewCoupon(dialogBinding: DialogAddCouponBinding): Boolean {
        val code = dialogBinding.inputCode.text?.toString()?.trim().orEmpty()
        val discountType = if (dialogBinding.chipPercent.isChecked) "percent" else "flat"
        val discountValue = dialogBinding.inputDiscountValue.text?.toString()?.trim()?.toDoubleOrNull()
        val minOrder = dialogBinding.inputMinOrder.text?.toString()?.trim()?.toDoubleOrNull()
        val maxDiscount = dialogBinding.inputMaxDiscount.text?.toString()?.trim()?.toDoubleOrNull()
        val validUntil = dialogBinding.inputValidUntil.tag as? String
        val usageLimitTotal = dialogBinding.inputUsageLimitTotal.text?.toString()?.trim()?.toIntOrNull()
        val usageLimitPerUser = dialogBinding.inputUsageLimitPerUser.text?.toString()?.trim()?.toIntOrNull()
        val isPublic = dialogBinding.switchPublic.isChecked

        if (code.isEmpty() || discountValue == null || discountValue <= 0) {
            InAppNotifier.show(this, getString(R.string.coupon_create_failed), InAppNotifier.Type.ERROR)
            return false
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
                        usageLimitPerUser = usageLimitPerUser,
                        isPublic = isPublic
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
        return true
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
     *
     * Same true/false-on-validation-passed contract as submitNewCoupon()
     * above — see that function's kdoc for why.
     */
    private fun submitCouponEdit(couponId: Int, dialogBinding: DialogAddCouponBinding): Boolean {
        val discountValue = dialogBinding.inputDiscountValue.text?.toString()?.trim()?.toDoubleOrNull()
        if (discountValue == null || discountValue <= 0) {
            InAppNotifier.show(this, getString(R.string.coupon_update_failed), InAppNotifier.Type.ERROR)
            return false
        }
        val minOrder = dialogBinding.inputMinOrder.text?.toString()?.trim()?.toDoubleOrNull()
        val maxDiscount = dialogBinding.inputMaxDiscount.text?.toString()?.trim()?.toDoubleOrNull()
        val validUntil = dialogBinding.inputValidUntil.tag as? String
        val usageLimitTotal = dialogBinding.inputUsageLimitTotal.text?.toString()?.trim()?.toIntOrNull()
        val usageLimitPerUser = dialogBinding.inputUsageLimitPerUser.text?.toString()?.trim()?.toIntOrNull()
        val isPublic = dialogBinding.switchPublic.isChecked

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
                        usageLimitPerUser = usageLimitPerUser,
                        isPublic = isPublic
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
        return true
    }
}
