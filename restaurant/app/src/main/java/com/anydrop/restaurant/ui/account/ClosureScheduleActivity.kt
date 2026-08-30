package com.anydrop.restaurant.ui.account

import android.os.Bundle
import android.view.View
import androidx.appcompat.app.AppCompatActivity
import androidx.lifecycle.lifecycleScope
import androidx.recyclerview.widget.LinearLayoutManager
import com.anydrop.restaurant.R
import com.anydrop.restaurant.databinding.ActivityNotificationListBinding
import com.anydrop.restaurant.databinding.DialogAddClosureBinding
import com.anydrop.restaurant.databinding.DialogConfirmDeleteBinding
import com.anydrop.restaurant.network.ApiClient
import com.anydrop.restaurant.network.Closure
import com.anydrop.restaurant.network.ClosureCreateBody
import com.anydrop.restaurant.network.ClosureUpdateBody
import com.anydrop.restaurant.ui.common.InAppNotifier
import com.google.android.material.datepicker.MaterialDatePicker
import com.google.android.material.dialog.MaterialAlertDialogBuilder
import kotlinx.coroutines.launch
import java.text.SimpleDateFormat
import java.util.Calendar
import java.util.Locale
import java.util.TimeZone

/**
 * Restaurant-side closure schedule screen (§3, today.md 2026-08-28;
 * doc 60/61 — backend was built those sessions, this is the Android
 * piece that was still open). Launched from AccountFragment's
 * "Closure Schedule" row with no extras — unlike AddonGroupsActivity,
 * a closure schedule isn't scoped to one menu item, it belongs to the
 * restaurant as a whole.
 *
 * Reuses activity_notification_list.xml as its shell, same pattern as
 * AddonGroupsActivity/ReviewListActivity — screenTitle is a fixed
 * string here (not per-item), btnAction shows ic_add / "+ Add
 * Closure". No pagination — same "a restaurant's own list is always
 * small" reasoning as AddonGroupsActivity, every mutation re-fetches
 * the whole list.
 *
 * Date pickers here are date-only (no MaterialTimePicker chaining) —
 * unlike CouponManagerActivity.setUpValidUntilPicker(), start_date/
 * end_date are whole calendar days, not a timestamp.
 */
class ClosureScheduleActivity : AppCompatActivity() {

    private lateinit var binding: ActivityNotificationListBinding
    private val api by lazy { ApiClient.create(this) }
    private lateinit var adapter: ClosureAdapter

    private val wireDateFormat = SimpleDateFormat("yyyy-MM-dd", Locale.US)

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityNotificationListBinding.inflate(layoutInflater)
        setContentView(binding.root)

        binding.screenTitle.text = getString(R.string.closure_schedule_title)
        binding.btnBack.setOnClickListener { finish() }
        binding.btnAction.setImageResource(R.drawable.ic_add)
        binding.btnAction.visibility = View.VISIBLE
        binding.btnAction.setOnClickListener { showClosureDialog(existing = null) }

        adapter = ClosureAdapter(
            onEdit = { showClosureDialog(existing = it) },
            onDelete = { confirmDeleteClosure(it) }
        )
        binding.contentList.layoutManager = LinearLayoutManager(this)
        binding.contentList.adapter = adapter

        binding.emptyStateText.text = getString(R.string.empty_closures)
        binding.swipeRefresh.setOnRefreshListener { loadClosures() }

        loadClosures()
    }

    private fun loadClosures() {
        binding.swipeRefresh.isRefreshing = true
        lifecycleScope.launch {
            try {
                val response = api.getClosures()
                val closures = response.body()?.data?.closures.orEmpty()
                if (response.isSuccessful) {
                    adapter.submit(closures)
                    binding.emptyState.visibility = if (closures.isEmpty()) View.VISIBLE else View.GONE
                    binding.contentList.visibility = if (closures.isEmpty()) View.GONE else View.VISIBLE
                } else {
                    InAppNotifier.show(this@ClosureScheduleActivity, getString(R.string.closures_load_failed), InAppNotifier.Type.ERROR)
                }
            } catch (e: Exception) {
                InAppNotifier.show(this@ClosureScheduleActivity, getString(R.string.closures_load_failed), InAppNotifier.Type.ERROR)
            } finally {
                binding.swipeRefresh.isRefreshing = false
            }
        }
    }

    /** [existing] null means "add"; non-null pre-fills every field for
     * "edit" — same one-dialog-handles-both shape as
     * AddonGroupsActivity.showAddonGroupDialog(), matching
     * closures-update.php's full-replace design. */
    private fun showClosureDialog(existing: Closure?) {
        val dialogBinding = DialogAddClosureBinding.inflate(layoutInflater)

        val isWeekly = existing?.closureType == "weekly_recurring"
        dialogBinding.radioWeeklyRecurring.isChecked = isWeekly
        dialogBinding.radioDateRange.isChecked = !isWeekly
        applyTypeVisibility(dialogBinding, isWeekly)

        dialogBinding.typeToggleGroup.setOnCheckedChangeListener { _, checkedId ->
            applyTypeVisibility(dialogBinding, checkedId == R.id.radioWeeklyRecurring)
        }

        if (existing != null) {
            dialogBinding.inputStartDate.setText(existing.startDate.orEmpty())
            dialogBinding.inputStartDate.tag = existing.startDate
            dialogBinding.inputEndDate.setText(existing.endDate.orEmpty())
            dialogBinding.inputEndDate.tag = existing.endDate
            // spinnerDayOfWeek is 0-indexed Mon..Sun; day_of_week is 1(Mon)..7(Sun).
            existing.dayOfWeek?.let { dialogBinding.spinnerDayOfWeek.setSelection(it - 1) }
            dialogBinding.inputReason.setText(existing.reason.orEmpty())
        }

        setUpDatePicker(dialogBinding.inputStartDate, R.string.hint_closure_start_date, "closure_start_date_picker")
        setUpDatePicker(dialogBinding.inputEndDate, R.string.hint_closure_end_date, "closure_end_date_picker")

        MaterialAlertDialogBuilder(this)
            .setTitle(if (existing == null) R.string.dialog_add_closure_title else R.string.dialog_edit_closure_title)
            .setView(dialogBinding.root)
            .setPositiveButton(R.string.btn_save) { _, _ ->
                submitClosure(dialogBinding, existing)
            }
            .setNegativeButton(R.string.btn_cancel, null)
            .show()
    }

    private fun applyTypeVisibility(dialogBinding: DialogAddClosureBinding, weekly: Boolean) {
        dialogBinding.dateRangeFields.visibility = if (weekly) View.GONE else View.VISIBLE
        dialogBinding.weeklyFields.visibility = if (weekly) View.VISIBLE else View.GONE
    }

    /** Date-only MaterialDatePicker — no MaterialTimePicker chaining
     * (unlike CouponManagerActivity's valid-until picker), and no
     * double-tap guard around a second fragment tag needed per field
     * since inputStartDate/inputEndDate use two distinct tags. */
    private fun setUpDatePicker(field: com.google.android.material.textfield.TextInputEditText, hintRes: Int, fragmentTag: String) {
        field.setOnClickListener {
            if (supportFragmentManager.findFragmentByTag(fragmentTag) != null) return@setOnClickListener
            val picker = MaterialDatePicker.Builder.datePicker()
                .setTitleText(getString(hintRes))
                .build()
            picker.addOnPositiveButtonClickListener { utcMillis ->
                val cal = Calendar.getInstance(TimeZone.getTimeZone("UTC"))
                cal.timeInMillis = utcMillis
                val wire = wireDateFormat.format(cal.time)
                field.tag = wire
                field.setText(wire)
            }
            picker.show(supportFragmentManager, fragmentTag)
        }
    }

    private fun submitClosure(dialogBinding: DialogAddClosureBinding, existing: Closure?) {
        val weekly = dialogBinding.radioWeeklyRecurring.isChecked
        val closureType = if (weekly) "weekly_recurring" else "date_range"
        val reason = dialogBinding.inputReason.text?.toString()?.trim().takeUnless { it.isNullOrEmpty() }

        if (weekly) {
            // spinnerDayOfWeek is 0-indexed Mon..Sun; day_of_week wants 1..7.
            val dayOfWeek = dialogBinding.spinnerDayOfWeek.selectedItemPosition + 1
            saveClosure(existing, closureType, null, null, dayOfWeek, reason)
        } else {
            val startDate = dialogBinding.inputStartDate.tag as? String
            val endDate = dialogBinding.inputEndDate.tag as? String
            if (startDate.isNullOrBlank() || endDate.isNullOrBlank()) {
                InAppNotifier.show(this, getString(R.string.closure_save_failed), InAppNotifier.Type.ERROR)
                return
            }
            saveClosure(existing, closureType, startDate, endDate, null, reason)
        }
    }

    private fun saveClosure(
        existing: Closure?,
        closureType: String,
        startDate: String?,
        endDate: String?,
        dayOfWeek: Int?,
        reason: String?
    ) {
        lifecycleScope.launch {
            try {
                val ok = if (existing == null) {
                    api.createClosure(
                        ClosureCreateBody(closureType = closureType, startDate = startDate, endDate = endDate, dayOfWeek = dayOfWeek, reason = reason)
                    ).isSuccessful
                } else {
                    api.updateClosure(
                        existing.id,
                        ClosureUpdateBody(closureType = closureType, startDate = startDate, endDate = endDate, dayOfWeek = dayOfWeek, reason = reason)
                    ).isSuccessful
                }
                if (ok) {
                    InAppNotifier.show(this@ClosureScheduleActivity, getString(R.string.closure_saved), InAppNotifier.Type.SUCCESS)
                    loadClosures()
                } else {
                    InAppNotifier.show(this@ClosureScheduleActivity, getString(R.string.closure_save_failed), InAppNotifier.Type.ERROR)
                }
            } catch (e: Exception) {
                InAppNotifier.show(this@ClosureScheduleActivity, getString(R.string.closure_save_failed), InAppNotifier.Type.ERROR)
            }
        }
    }

    /** Reuses dialog_confirm_delete.xml, same pattern as
     * AddonGroupsActivity.confirmDeleteGroup(). */
    private fun confirmDeleteClosure(closure: Closure) {
        val dialogBinding = DialogConfirmDeleteBinding.inflate(layoutInflater)
        dialogBinding.deleteDialogTitle.text = getString(R.string.closure_delete_confirm_title)
        dialogBinding.deleteDialogMessage.text = getString(R.string.closure_delete_confirm_message)
        val dialog = MaterialAlertDialogBuilder(this).setView(dialogBinding.root).create()
        dialogBinding.btnDeleteDialogCancel.setOnClickListener { dialog.dismiss() }
        dialogBinding.btnDeleteDialogDelete.setOnClickListener {
            dialog.dismiss()
            deleteClosure(closure)
        }
        dialog.show()
    }

    private fun deleteClosure(closure: Closure) {
        lifecycleScope.launch {
            try {
                if (api.deleteClosure(closure.id).isSuccessful) {
                    InAppNotifier.show(this@ClosureScheduleActivity, getString(R.string.closure_deleted), InAppNotifier.Type.SUCCESS)
                    loadClosures()
                } else {
                    InAppNotifier.show(this@ClosureScheduleActivity, getString(R.string.closure_save_failed), InAppNotifier.Type.ERROR)
                }
            } catch (e: Exception) {
                InAppNotifier.show(this@ClosureScheduleActivity, getString(R.string.closure_save_failed), InAppNotifier.Type.ERROR)
            }
        }
    }
}
