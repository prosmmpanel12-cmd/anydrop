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
import com.anydrop.restaurant.network.ApiClient
import com.anydrop.restaurant.network.OperationalStatusUpdateBody
import com.anydrop.restaurant.network.RestaurantProfileDetail
import com.anydrop.restaurant.ui.common.InAppNotifier
import com.anydrop.restaurant.ui.login.LoginActivity
import kotlinx.coroutines.launch
import java.util.Locale

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

        binding.switchTempClosed.setOnCheckedChangeListener { _, isChecked ->
            if (suppressTempClosedListener) return@setOnCheckedChangeListener
            setTempClosed(isChecked)
        }

        binding.btnLogout.setOnClickListener {
            tokenManager.clear()
            startActivity(
                Intent(requireContext(), LoginActivity::class.java)
                    .addFlags(Intent.FLAG_ACTIVITY_CLEAR_TOP or Intent.FLAG_ACTIVITY_NEW_TASK)
            )
            requireActivity().finish()
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

    /** Same call MainActivity.setOperationalStatus() uses, distinct
     * status value ("temp_closed" vs "busy") — see fragment_account.xml's
     * comment on why these are kept separate. Reverts the switch on
     * failure rather than leaving it showing a state that didn't
     * actually save. */
    private fun setTempClosed(turningOn: Boolean) {
        val newStatus = if (turningOn) "temp_closed" else "open"
        viewLifecycleOwner.lifecycleScope.launch {
            try {
                val response = api.updateOperationalStatus(OperationalStatusUpdateBody(newStatus))
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
