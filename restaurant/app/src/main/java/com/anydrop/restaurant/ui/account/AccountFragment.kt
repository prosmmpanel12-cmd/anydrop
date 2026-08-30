package com.anydrop.restaurant.ui.account

import android.content.Intent
import android.os.Bundle
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import androidx.activity.result.contract.ActivityResultContracts
import androidx.fragment.app.Fragment
import androidx.lifecycle.lifecycleScope
import coil.load
import com.google.gson.Gson
import com.anydrop.restaurant.R
import com.anydrop.restaurant.data.TokenManager
import com.anydrop.restaurant.databinding.FragmentAccountBinding
import com.anydrop.restaurant.databinding.DialogLogoutConfirmBinding
import com.google.android.material.datepicker.MaterialDatePicker
import com.google.android.material.dialog.MaterialAlertDialogBuilder
import com.google.android.material.timepicker.MaterialTimePicker
import com.google.android.material.timepicker.TimeFormat
import com.anydrop.restaurant.network.ApiClient
import com.anydrop.restaurant.network.OperationalStatusUpdateBody
import com.anydrop.restaurant.network.RestaurantProfileDetail
import com.anydrop.restaurant.service.OrderPollingService
import com.anydrop.restaurant.ui.common.InAppNotifier
import com.anydrop.restaurant.ui.login.LoginActivity
import kotlinx.coroutines.launch
import java.text.SimpleDateFormat
import java.util.Calendar
import java.util.Locale
import java.util.TimeZone

/**
 * Account tab (docs/restorent/19 §7, §10 item 5,
 * NEXT_SESSION_PROMPT.md item 1). Rewritten this session to match the
 * redesigned fragment_account.xml: profile summary card + edit row,
 * temp-closed toggle, view-only payout section, logout — replacing the
 * old name+"coming soon"+Logout placeholder.
 *
 * Loads its own profile via api.getProfile() (doesn't rely on
 * MainActivity's dashboard summary, which only carries
 * operationalStatus/currentDue, not the full profile shape needed
 * here) and passes that same profile on to EditProfileActivity so that
 * screen doesn't need its own redundant round trip.
 */
class AccountFragment : Fragment() {

    private var _binding: FragmentAccountBinding? = null
    private val binding get() = _binding!!

    private val api by lazy { ApiClient.create(requireContext()) }
    private lateinit var tokenManager: TokenManager

    private var currentProfile: RestaurantProfileDetail? = null
    private var suppressTempClosedListener = false

    private val editProfileLauncher =
        registerForActivityResult(ActivityResultContracts.StartActivityForResult()) { result ->
            if (result.resultCode != android.app.Activity.RESULT_CANCELED) {
                loadProfile()
            }
        }

    override fun onCreateView(
        inflater: LayoutInflater,
        container: ViewGroup?,
        savedInstanceState: Bundle?
    ): View {
        _binding = FragmentAccountBinding.inflate(inflater, container, false)
        return binding.root
    }

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        super.onViewCreated(view, savedInstanceState)
        tokenManager = TokenManager(requireContext())

        binding.swipeRefresh.setOnRefreshListener { loadProfile() }

        val openEdit = View.OnClickListener {
            val profile = currentProfile ?: return@OnClickListener
            val intent = Intent(requireContext(), EditProfileActivity::class.java)
                .putExtra(EditProfileActivity.EXTRA_PROFILE_JSON, Gson().toJson(profile))
            editProfileLauncher.launch(intent)
        }
        binding.profileSummaryCard.setOnClickListener(openEdit)
        binding.btnEditProfileRow.setOnClickListener(openEdit)

        binding.btnBannersRow.setOnClickListener {
            startActivity(Intent(requireContext(), BannerManagerActivity::class.java))
        }

        binding.btnCouponsRow.setOnClickListener {
            startActivity(Intent(requireContext(), CouponManagerActivity::class.java))
        }

        // Offers (doc 20 §14; docs/29 "Not built" item 1, session 7) —
        // opens OfferManagerActivity. Same launch pattern as Coupons above.
        binding.btnOffersRow.setOnClickListener {
            startActivity(Intent(requireContext(), OfferManagerActivity::class.java))
        }

        // Reviews reply (docs/restorent/00_Status.md, this session) —
        // same row style/placement as Banners/Coupons above.
        binding.btnReviewsRow.setOnClickListener {
            startActivity(Intent(requireContext(), com.anydrop.restaurant.ui.reviews.ReviewListActivity::class.java))
        }

        // Closure Schedule (§3, today.md 2026-08-28; doc 60/61) — same
        // row style/placement as the rows above, launched right after
        // the temp-closed switch card in fragment_account.xml.
        binding.btnClosuresRow.setOnClickListener {
            startActivity(Intent(requireContext(), ClosureScheduleActivity::class.java))
        }

        // Bank Details submission (PENDING.md §15, migration 59, doc 63)
        // — same one-line row-launch pattern as btnClosuresRow above.
        binding.btnBankDetailsRow.setOnClickListener {
            startActivity(Intent(requireContext(), BankDetailsActivity::class.java))
        }

        // Staff Management (doc 71, migration 63, PENDING.md item 3) —
        // row is gone by default in fragment_account.xml and only shown
        // here for an owner session; canManageStaff() is the same
        // client-side-convenience check StaffManagementActivity itself
        // re-checks on launch (the backend's own 403 is what actually
        // enforces this either way).
        if (tokenManager.canManageStaff()) {
            binding.btnStaffManagementRow.visibility = View.VISIBLE
            binding.btnStaffManagementRow.setOnClickListener {
                startActivity(Intent(requireContext(), com.anydrop.restaurant.ui.staff.StaffManagementActivity::class.java))
            }
        }

        // Staff Audit Trail (migration 64, PENDING.md §7's last
        // checkbox) — same owner-only guard/pattern as
        // btnStaffManagementRow immediately above.
        if (tokenManager.canManageStaff()) {
            binding.btnStaffAuditLogRow.visibility = View.VISIBLE
            binding.btnStaffAuditLogRow.setOnClickListener {
                startActivity(Intent(requireContext(), com.anydrop.restaurant.ui.staff.StaffAuditLogActivity::class.java))
            }
        }

        binding.switchTempClosed.setOnCheckedChangeListener { _, isChecked ->
            if (suppressTempClosedListener) return@setOnCheckedChangeListener
            if (isChecked) {
                promptResumeTime()
            } else {
                setTempClosed(turningOn = false, resumeAt = null)
            }
        }

        binding.btnLogout.setOnClickListener {
            // Illustration retrofit (2026-08-19): this used to log out
            // immediately on tap with no confirmation step at all —
            // found while looking for the existing dialog to retrofit
            // with an illustration and there wasn't one. Added a real
            // confirmation now, matching the app owner's reference
            // mockup's Logout Confirmation Dialog.
            val dialogBinding = DialogLogoutConfirmBinding.inflate(layoutInflater)
            val dialog = MaterialAlertDialogBuilder(requireContext())
                .setView(dialogBinding.root)
                .create()
            dialogBinding.btnLogoutDialogCancel.setOnClickListener { dialog.dismiss() }
            dialogBinding.btnLogoutDialogConfirm.setOnClickListener {
                dialog.dismiss()
                // A logged-out device has no business still polling for
                // another restaurant's orders in the background.
                OrderPollingService.stop(requireContext())
                tokenManager.clear()
                startActivity(
                    Intent(requireContext(), LoginActivity::class.java)
                        .addFlags(Intent.FLAG_ACTIVITY_CLEAR_TOP or Intent.FLAG_ACTIVITY_NEW_TASK)
                )
                requireActivity().finish()
            }
            dialog.show()
        }

        loadProfile()
    }

    private fun loadProfile() {
        binding.swipeRefresh.isRefreshing = true
        viewLifecycleOwner.lifecycleScope.launch {
            try {
                val response = api.getProfile()
                val profile = response.body()?.data?.restaurant
                if (response.isSuccessful && profile != null) {
                    currentProfile = profile
                    populate(profile)
                } else {
                    InAppNotifier.show(activity, getString(R.string.account_profile_load_failed), InAppNotifier.Type.ERROR)
                }
            } catch (e: Exception) {
                InAppNotifier.show(activity, getString(R.string.account_profile_load_failed), InAppNotifier.Type.ERROR)
            } finally {
                _binding?.let { it.swipeRefresh.isRefreshing = false }
            }
        }
    }

    private fun populate(profile: RestaurantProfileDetail) {
        val b = _binding ?: return

        b.profileNameText.text = profile.name
        b.profileAddressText.text = profile.address.orEmpty()
        b.profileHoursText.text = formatHours(profile.openingTime, profile.closingTime)

        if (!profile.logoUrl.isNullOrBlank()) {
            // fragment_account.xml sets app:tint on this ImageView so the
            // ic_store *placeholder* renders muted-grey — but an
            // ImageView's tint applies to whatever drawable is currently
            // set, not just the placeholder, so a real logo bitmap loaded
            // on top would get tinted grey too (looked like the photo
            // "wasn't showing"). Clear the tint on load success; restore
            // it if the load errors back to the ic_store fallback.
            b.profileLogoThumb.load(ApiClient.baseUrlForStaticFiles(requireContext()) + profile.logoUrl) {
                placeholder(R.drawable.ic_store)
                error(R.drawable.ic_store)
                crossfade(true)
                listener(
                    onSuccess = { _, _ -> b.profileLogoThumb.imageTintList = null },
                    onError = { _, _ ->
                        b.profileLogoThumb.imageTintList = androidx.core.content.ContextCompat.getColorStateList(requireContext(), R.color.text_secondary)
                    }
                )
            }
        } else {
            b.profileLogoThumb.imageTintList = androidx.core.content.ContextCompat.getColorStateList(requireContext(), R.color.text_secondary)
            b.profileLogoThumb.setImageResource(R.drawable.ic_store)
        }

        b.upiIdText.text = profile.upiId?.takeIf { it.isNotBlank() } ?: getString(R.string.account_not_set)
        b.currentDueText.text = String.format(Locale.US, "\u20b9%.2f", profile.currentDue)

        // Set isChecked before attaching fires the listener too — guard
        // with suppressTempClosedListener so this programmatic set never
        // triggers a network call of its own.
        suppressTempClosedListener = true
        b.switchTempClosed.isChecked = profile.operationalStatus == "temp_closed"
        suppressTempClosedListener = false
    }

    private fun formatHours(opening: String?, closing: String?): String {
        if (opening.isNullOrBlank() || closing.isNullOrBlank()) return ""
        return "${formatDisplayTime(opening)} - ${formatDisplayTime(closing)}"
    }

    private fun formatDisplayTime(value: String): String {
        val parts = value.split(":")
        val hour = parts.getOrNull(0)?.toIntOrNull() ?: return value
        val minute = parts.getOrNull(1)?.toIntOrNull() ?: 0
        val amPm = if (hour < 12) "AM" else "PM"
        val hour12 = when {
            hour == 0 -> 12
            hour > 12 -> hour - 12
            else -> hour
        }
        return String.format(Locale.US, "%d:%02d %s", hour12, minute, amPm)
    }

    /** Turning the switch on offers an optional reopen-time prompt
     * (§3, today.md 2026-08-28; doc 60/61 — this is what actually lets
     * a restaurant send the resume_at field status-update.php has
     * accepted since doc 60; before this nothing in the app sent it).
     * "Skip" keeps today's exact behavior (resume_at: null). */
    private fun promptResumeTime() {
        MaterialAlertDialogBuilder(requireContext())
            .setTitle(R.string.closure_resume_time_title)
            .setPositiveButton(R.string.btn_save) { _, _ -> showResumeTimePicker() }
            .setNegativeButton(R.string.btn_skip) { _, _ -> setTempClosed(turningOn = true, resumeAt = null) }
            .setOnCancelListener { setTempClosed(turningOn = true, resumeAt = null) }
            .show()
    }

    /** MaterialDatePicker → MaterialTimePicker chain, copied from
     * CouponManagerActivity.setUpValidUntilPicker() — same double-tap
     * guard against two overlapping picker fragments, and the same
     * post() trick to show the time picker only after the date
     * picker's own dismiss transaction has committed (both were real
     * bugs fixed there, not defensive-for-no-reason code). */
    private fun showResumeTimePicker() {
        if (childFragmentManager.findFragmentByTag("resume_at_date_picker") != null) return
        val datePicker = MaterialDatePicker.Builder.datePicker()
            .setTitleText(getString(R.string.closure_resume_time_title))
            .build()
        datePicker.addOnPositiveButtonClickListener { utcMillis ->
            val utcCal = Calendar.getInstance(TimeZone.getTimeZone("UTC"))
            utcCal.timeInMillis = utcMillis

            val timePicker = MaterialTimePicker.Builder()
                .setTimeFormat(TimeFormat.CLOCK_12H)
                .setHour(9)
                .setMinute(0)
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
                val wireFormat = SimpleDateFormat("yyyy-MM-dd HH:mm:ss", Locale.US)
                setTempClosed(turningOn = true, resumeAt = wireFormat.format(local.time))
            }
            binding.root.post {
                val hostActivity = activity
                if (hostActivity != null && !hostActivity.isFinishing && !hostActivity.isDestroyed) {
                    timePicker.show(childFragmentManager, "resume_at_time_picker")
                }
            }
        }
        // Dismissing the date picker (back press) leaves the switch on
        // with no resume time set, same as an explicit Skip, rather
        // than silently reverting the switch.
        datePicker.addOnCancelListener { setTempClosed(turningOn = true, resumeAt = null) }
        datePicker.show(childFragmentManager, "resume_at_date_picker")
    }

    /** Same call MainActivity.setOperationalStatus() uses, distinct
     * status value ("temp_closed" vs "busy") — see fragment_account.xml's
     * comment on why these are kept separate. Reverts the switch on
     * failure rather than leaving it showing a state that didn't
     * actually save. */
    private fun setTempClosed(turningOn: Boolean, resumeAt: String?) {
        val newStatus = if (turningOn) "temp_closed" else "open"
        viewLifecycleOwner.lifecycleScope.launch {
            try {
                val response = api.updateOperationalStatus(OperationalStatusUpdateBody(newStatus, resumeAt))
                if (!response.isSuccessful || response.body()?.data == null) {
                    revertTempClosedSwitch(!turningOn)
                    InAppNotifier.show(activity, getString(R.string.status_update_failed), InAppNotifier.Type.ERROR)
                } else {
                    currentProfile = currentProfile?.copy(operationalStatus = newStatus)
                }
            } catch (e: Exception) {
                revertTempClosedSwitch(!turningOn)
                InAppNotifier.show(activity, getString(R.string.status_update_failed), InAppNotifier.Type.ERROR)
            }
        }
    }

    private fun revertTempClosedSwitch(checked: Boolean) {
        val b = _binding ?: return
        suppressTempClosedListener = true
        b.switchTempClosed.isChecked = checked
        suppressTempClosedListener = false
    }

    override fun onDestroyView() {
        super.onDestroyView()
        _binding = null
    }
}
