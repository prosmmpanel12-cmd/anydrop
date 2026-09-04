package com.anydrop.restaurant.ui.staff

import android.os.Bundle
import android.view.View
import androidx.appcompat.app.AppCompatActivity
import androidx.lifecycle.lifecycleScope
import androidx.recyclerview.widget.LinearLayoutManager
import com.anydrop.restaurant.R
import com.anydrop.restaurant.data.TokenManager
import com.anydrop.restaurant.databinding.ActivityNotificationListBinding
import com.anydrop.restaurant.databinding.DialogAddStaffBinding
import com.anydrop.restaurant.databinding.DialogConfirmDeleteBinding
import com.anydrop.restaurant.network.ApiClient
import com.anydrop.restaurant.network.StaffCreateBody
import com.anydrop.restaurant.network.StaffProfile
import com.anydrop.restaurant.network.StaffUpdateBody
import com.anydrop.restaurant.ui.common.InAppNotifier
import com.google.android.material.dialog.MaterialAlertDialogBuilder
import kotlinx.coroutines.launch

/**
 * Owner-only Staff Management screen (doc 71, migration 63,
 * PENDING.md item 3 — the piece doc 71 left as "genuinely still open").
 * Reuses activity_notification_list.xml as its shell, same pattern as
 * ClosureScheduleActivity — screenTitle fixed, btnAction is "+ Add
 * Staff". No pagination, same "a restaurant's own list is always
 * small" reasoning as ClosureScheduleActivity: every mutation
 * re-fetches the whole roster rather than patching local state.
 *
 * Launch is guarded by tokenManager.canManageStaff() below (redirects
 * a non-owner straight back out) — client-side UI-hiding convenience
 * only, per doc 71's own note on TokenManager.canManageStaff(); the
 * backend's require_restaurant_permission('manage_staff') 403 is the
 * actual enforcement regardless of whether this guard is ever bypassed.
 */
class StaffManagementActivity : AppCompatActivity() {

    private lateinit var binding: ActivityNotificationListBinding
    private val api by lazy { ApiClient.create(this) }
    private lateinit var tokenManager: TokenManager
    private lateinit var adapter: StaffAdapter

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityNotificationListBinding.inflate(layoutInflater)
        setContentView(binding.root)

        tokenManager = TokenManager(this)
        if (!tokenManager.canManageStaff()) {
            // A staff-role session reaching this screen would be a UI
            // bug (see doc 71's cut line — the Account-tab row that
            // launches this is itself hidden for non-owners), not a
            // realistic path, but the backend's own 403 is the real
            // guard either way; this just avoids showing a screen
            // whose every mutation would fail.
            finish()
            return
        }

        binding.screenTitle.text = getString(R.string.staff_management_title)
        binding.btnBack.setOnClickListener { finish() }
        binding.btnAction.setImageResource(R.drawable.ic_add)
        binding.btnAction.visibility = View.VISIBLE
        binding.btnAction.setOnClickListener { showStaffDialog(existing = null) }

        adapter = StaffAdapter(
            onEdit = { showStaffDialog(existing = it) },
            onDelete = { confirmDeleteStaff(it) },
            onToggleActive = { staff, isActive -> toggleActive(staff, isActive) }
        )
        binding.contentList.layoutManager = LinearLayoutManager(this)
        binding.contentList.adapter = adapter

        binding.emptyStateText.text = getString(R.string.empty_staff)
        binding.swipeRefresh.setOnRefreshListener { loadStaff() }

        loadStaff()
    }

    private fun loadStaff() {
        binding.swipeRefresh.isRefreshing = true
        lifecycleScope.launch {
            try {
                val response = api.listStaff()
                val staff = response.body()?.data?.staff.orEmpty()
                if (response.isSuccessful) {
                    adapter.submit(staff)
                    binding.emptyState.visibility = if (staff.isEmpty()) View.VISIBLE else View.GONE
                    binding.contentList.visibility = if (staff.isEmpty()) View.GONE else View.VISIBLE
                } else {
                    InAppNotifier.show(this@StaffManagementActivity, getString(R.string.staff_load_failed), InAppNotifier.Type.ERROR)
                }
            } catch (e: Exception) {
                InAppNotifier.show(this@StaffManagementActivity, getString(R.string.staff_load_failed), InAppNotifier.Type.ERROR)
            } finally {
                binding.swipeRefresh.isRefreshing = false
            }
        }
    }

    /** [existing] null means "add"; non-null pre-fills name/username/
     * role and leaves the password field blank — same one-dialog-
     * handles-both shape as ClosureScheduleActivity.showClosureDialog(),
     * matching staff-update.php's partial-update convention (blank
     * password on edit = unchanged, per StaffUpdateBody's own kdoc). */
    private fun showStaffDialog(existing: StaffProfile?) {
        val dialogBinding = DialogAddStaffBinding.inflate(layoutInflater)

        if (existing != null) {
            dialogBinding.inputStaffName.setText(existing.name)
            dialogBinding.inputStaffUsername.setText(existing.username)
            // Username is how staff-login.php looks a row up — changing
            // it here would silently break that staff member's existing
            // login, and staff-update.php doesn't even accept a
            // username field. Locked on edit rather than left editable
            // and ignored.
            dialogBinding.inputStaffUsername.isEnabled = false
            dialogBinding.staffPasswordLayout.hint = getString(R.string.hint_staff_password_optional)
            when (existing.role) {
                "kitchen" -> dialogBinding.radioRoleKitchen.isChecked = true
                "cashier" -> dialogBinding.radioRoleCashier.isChecked = true
                else -> dialogBinding.radioRoleManager.isChecked = true
            }
        }

        MaterialAlertDialogBuilder(this)
            .setTitle(if (existing == null) R.string.dialog_add_staff_title else R.string.dialog_edit_staff_title)
            .setView(dialogBinding.root)
            .setPositiveButton(R.string.btn_save) { _, _ ->
                submitStaff(dialogBinding, existing)
            }
            .setNegativeButton(R.string.btn_cancel, null)
            .show()
    }

    private fun submitStaff(dialogBinding: DialogAddStaffBinding, existing: StaffProfile?) {
        val name = dialogBinding.inputStaffName.text?.toString()?.trim().orEmpty()
        val username = dialogBinding.inputStaffUsername.text?.toString()?.trim().orEmpty()
        val password = dialogBinding.inputStaffPassword.text?.toString().orEmpty()
        val role = when (dialogBinding.roleGroup.checkedRadioButtonId) {
            R.id.radioRoleKitchen -> "kitchen"
            R.id.radioRoleCashier -> "cashier"
            else -> "manager"
        }

        // Client-side validation mirrors staff-create.php's own checks
        // (name/username/password required, username >= 3 chars,
        // password >= 6 chars) so an obviously-invalid submission gets
        // a specific message immediately instead of a round-trip to
        // the server just to learn the same thing. Every one of these
        // used to fall through to the same generic staff_save_failed
        // string regardless of which field was actually the problem —
        // that's the "sirf ek generic error, kaunsa field galat hai
        // pata nahi chalta" bug being fixed here.
        if (name.isEmpty()) {
            InAppNotifier.show(this, getString(R.string.staff_error_name_required), InAppNotifier.Type.ERROR)
            return
        }
        if (existing == null && username.length < 3) {
            InAppNotifier.show(this, getString(R.string.staff_error_username_short), InAppNotifier.Type.ERROR)
            return
        }
        if (existing == null && password.isEmpty()) {
            InAppNotifier.show(this, getString(R.string.staff_error_password_required), InAppNotifier.Type.ERROR)
            return
        }
        if (password.isNotEmpty() && password.length < 6) {
            InAppNotifier.show(this, getString(R.string.staff_error_password_short), InAppNotifier.Type.ERROR)
            return
        }
        // Explicit ask: username and password being identical was
        // previously allowed all the way to the server with no
        // specific feedback — the create would sometimes still
        // succeed (a weak-but-technically-valid account) leaving the
        // owner unsure whether it actually worked. Caught here with a
        // clear message before it ever reaches the network.
        val usernameForCompare = if (existing == null) username else existing.username
        if (password.isNotEmpty() && password.equals(usernameForCompare, ignoreCase = true)) {
            InAppNotifier.show(this, getString(R.string.staff_error_username_password_same), InAppNotifier.Type.ERROR)
            return
        }

        lifecycleScope.launch {
            try {
                val response = if (existing == null) {
                    api.createStaff(StaffCreateBody(name = name, username = username, password = password, role = role))
                } else {
                    api.updateStaff(
                        existing.id,
                        StaffUpdateBody(
                            name = name,
                            role = role,
                            isActive = null, // switch handles this independently, see toggleActive()
                            password = password.ifEmpty { null }
                        )
                    )
                }
                if (response.isSuccessful) {
                    InAppNotifier.show(this@StaffManagementActivity, getString(R.string.staff_saved), InAppNotifier.Type.SUCCESS)
                    loadStaff()
                } else {
                    // response.body() is always null on a non-2xx HTTP
                    // response — staff-create.php/staff-update.php's
                    // actual validation_error ({fields:[...],
                    // reason:...}) only ever lives in errorBody(). This
                    // used to only special-case username_taken and
                    // silently collapse every other validation failure
                    // (password too short, username too short, invalid
                    // role, ...) into the same generic "couldn't save"
                    // message — see com.anydrop.restaurant.network.ErrorParsing's
                    // own kdoc for the underlying Retrofit behavior.
                    val parsed = com.anydrop.restaurant.network.parseApiError(response.errorBody())
                    val message = staffErrorMessage(parsed)
                    InAppNotifier.show(this@StaffManagementActivity, message, InAppNotifier.Type.ERROR)
                }
            } catch (e: Exception) {
                InAppNotifier.show(this@StaffManagementActivity, getString(R.string.staff_save_failed), InAppNotifier.Type.ERROR)
            }
        }
    }

    /** Maps staff-create.php/staff-update.php's specific validation_error
     * `reason`/`fields` payload to a message that actually says what
     * was wrong, instead of one generic string for every possible
     * cause. Falls back to the generic message only when the server
     * didn't send anything more specific (a genuine 500, dropped
     * connection during parse, etc.). */
    private fun staffErrorMessage(parsed: com.anydrop.restaurant.network.ParsedApiError): String {
        // Order matters: check the specific `reason` values before
        // falling back to the generic per-field message, otherwise
        // e.g. username_password_same (which also reports
        // fields=["password"]) would incorrectly show "password too
        // short" instead of its own clearer message.
        if (parsed.reason == "username_password_same") {
            return getString(R.string.staff_error_username_password_same)
        }
        if (parsed.reason == "username_taken") {
            return getString(R.string.staff_username_taken)
        }
        if (parsed.code == "validation_error" && parsed.fields != null) {
            return when {
                parsed.fields.contains("username") -> getString(R.string.staff_error_username_short)
                parsed.fields.contains("password") -> getString(R.string.staff_error_password_short)
                parsed.fields.contains("role") -> getString(R.string.staff_error_invalid_role)
                parsed.fields.contains("name") -> getString(R.string.staff_error_name_required)
                else -> getString(R.string.staff_save_failed)
            }
        }
        if (parsed.code == "forbidden") {
            return getString(R.string.staff_error_forbidden)
        }
        return getString(R.string.staff_save_failed)
    }

    /** Quick is_active toggle straight from the roster row's switch —
     * separate from the full submitStaff() edit flow, same "one field,
     * one PATCH" shape as AccountFragment's own temp-closed switch.
     * Reverts the switch on failure rather than leaving it showing a
     * state that didn't actually save (same pattern as
     * AccountFragment.revertTempClosedSwitch()) by simply reloading
     * the authoritative list from the server. */
    private fun toggleActive(staff: StaffProfile, isActive: Boolean) {
        lifecycleScope.launch {
            try {
                val ok = api.updateStaff(staff.id, StaffUpdateBody(isActive = isActive)).isSuccessful
                if (!ok) {
                    InAppNotifier.show(this@StaffManagementActivity, getString(R.string.staff_save_failed), InAppNotifier.Type.ERROR)
                }
            } catch (e: Exception) {
                InAppNotifier.show(this@StaffManagementActivity, getString(R.string.staff_save_failed), InAppNotifier.Type.ERROR)
            } finally {
                // Re-fetch either way so the switch always reflects
                // what the server actually has, whether this call
                // succeeded, failed, or a disabled staff member's own
                // session got booted mid-flight elsewhere.
                loadStaff()
            }
        }
    }

    /** Reuses dialog_confirm_delete.xml, same pattern as
     * ClosureScheduleActivity.confirmDeleteClosure(). staff-delete.php
     * is a real soft-delete (deleted_at), not the is_active toggle
     * above — this permanently removes the account rather than just
     * disabling it. */
    private fun confirmDeleteStaff(staff: StaffProfile) {
        val dialogBinding = DialogConfirmDeleteBinding.inflate(layoutInflater)
        dialogBinding.deleteDialogTitle.text = getString(R.string.staff_delete_confirm_title)
        dialogBinding.deleteDialogMessage.text = getString(R.string.staff_delete_confirm_message)
        val dialog = MaterialAlertDialogBuilder(this).setView(dialogBinding.root).create()
        dialogBinding.btnDeleteDialogCancel.setOnClickListener { dialog.dismiss() }
        dialogBinding.btnDeleteDialogDelete.setOnClickListener {
            dialog.dismiss()
            deleteStaff(staff)
        }
        dialog.show()
    }

    private fun deleteStaff(staff: StaffProfile) {
        lifecycleScope.launch {
            try {
                if (api.deleteStaff(staff.id).isSuccessful) {
                    InAppNotifier.show(this@StaffManagementActivity, getString(R.string.staff_deleted), InAppNotifier.Type.SUCCESS)
                    loadStaff()
                } else {
                    InAppNotifier.show(this@StaffManagementActivity, getString(R.string.staff_save_failed), InAppNotifier.Type.ERROR)
                }
            } catch (e: Exception) {
                InAppNotifier.show(this@StaffManagementActivity, getString(R.string.staff_save_failed), InAppNotifier.Type.ERROR)
            }
        }
    }
}
