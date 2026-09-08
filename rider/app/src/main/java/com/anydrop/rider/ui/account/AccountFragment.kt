package com.anydrop.rider.ui.account

import android.content.Intent
import android.content.res.ColorStateList
import android.os.Bundle
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import androidx.fragment.app.Fragment
import androidx.lifecycle.lifecycleScope
import com.anydrop.rider.R
import com.anydrop.rider.data.TokenManager
import com.anydrop.rider.databinding.FragmentAccountBinding
import com.anydrop.rider.network.ApiClient
import com.anydrop.rider.network.RiderMeProfile
import com.anydrop.rider.service.RiderOrderPollingService
import com.anydrop.rider.ui.common.InAppNotifier
import com.anydrop.rider.ui.documents.SubmitDocumentsActivity
import com.anydrop.rider.ui.login.LoginActivity
import kotlinx.coroutines.launch
import java.text.SimpleDateFormat
import java.util.Locale

/**
 * Bottom-nav shell (v24 Part 2) — Account tab. New screen; this app had
 * no Account/Profile hub before this session (see the Rider Documents
 * handover's "No Account/Profile hub screen exists in this app yet"
 * note and RiderDashboardActivity's old header row, which is where the
 * documents-rejected alert and logout button used to live instead).
 *
 * Loads /rider/me directly (same endpoint HomeFragment/
 * ApplicationStatusActivity already use) rather than relying on
 * TokenManager's cached fields for anything beyond first paint —
 * mirrors HomeFragment.refreshFromServer()'s "cached value first,
 * network response is the source of truth" shape, but simpler here
 * since this tab has no online-switch side effects to trigger.
 */
class AccountFragment : Fragment() {

    private var _binding: FragmentAccountBinding? = null
    private val binding get() = _binding!!

    private val api by lazy { ApiClient.create(requireContext()) }
    private lateinit var tokenManager: TokenManager

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

        binding.accountSwipeRefresh.setOnRefreshListener { loadProfile() }

        binding.btnDocumentsRow.setOnClickListener {
            startActivity(Intent(requireContext(), SubmitDocumentsActivity::class.java))
        }

        binding.btnLogout.setOnClickListener {
            // Same immediate-logout shape ApplicationStatusActivity's own
            // onLogoutClicked() already uses in this app — no confirmation
            // dialog exists anywhere in this codebase for this action, so
            // this doesn't introduce a new, inconsistent pattern.
            //
            // A logged-out rider has no business still running a
            // background poll for someone else's delivery offers — same
            // reasoning HomeFragment's old btnLogout handler documented.
            RiderOrderPollingService.stop(requireContext())
            tokenManager.clear()
            startActivity(
                Intent(requireContext(), LoginActivity::class.java)
                    .addFlags(Intent.FLAG_ACTIVITY_CLEAR_TOP or Intent.FLAG_ACTIVITY_NEW_TASK)
            )
            requireActivity().finish()
        }

        // Render the cached snapshot immediately (no blank screen while
        // the network call is in flight), then refresh from the server.
        renderFromCache()
        loadProfile()
    }

    override fun onResume() {
        super.onResume()
        // Coming back from SubmitDocumentsActivity — that screen already
        // updates TokenManager's cached documents_status itself, so this
        // just needs a re-render off the cache, same "cached label, no
        // extra network call" stance HomeFragment/ApplicationStatusActivity
        // already take for the equivalent case.
        renderDocumentsStatusPill(tokenManager.getDocumentsStatus())
    }

    override fun onDestroyView() {
        super.onDestroyView()
        _binding = null
    }

    private fun renderFromCache() {
        binding.accountNameText.text = tokenManager.getRiderName().orEmpty()
        renderDocumentsStatusPill(tokenManager.getDocumentsStatus())
    }

    private fun loadProfile() {
        binding.accountSwipeRefresh.isRefreshing = true
        lifecycleScope.launch {
            try {
                val response = api.getMe()
                val result = response.body()?.data
                if (response.isSuccessful && response.body()?.success == true && result != null) {
                    tokenManager.updateDocumentsStatus(result.rider.documentsStatus)
                    populate(result.rider)
                } else {
                    InAppNotifier.show(activity, getString(R.string.account_profile_load_failed), InAppNotifier.Type.ERROR)
                }
            } catch (e: Exception) {
                InAppNotifier.show(activity, getString(R.string.account_profile_load_failed), InAppNotifier.Type.ERROR)
            } finally {
                _binding?.accountSwipeRefresh?.isRefreshing = false
            }
        }
    }

    private fun populate(rider: RiderMeProfile) {
        val b = _binding ?: return

        b.accountNameText.text = rider.name
        b.accountMobileText.text = rider.mobile.orEmpty()
        b.accountEmailText.text = rider.email
        b.accountEmailText.visibility = if (rider.email.isNotBlank()) View.VISIBLE else View.GONE

        b.accountServiceAreaText.text = rider.serviceAreaName ?: getString(R.string.account_not_set)

        val vehicleParts = listOfNotNull(
            rider.vehicleType?.takeIf { it.isNotBlank() },
            rider.vehicleNumber?.takeIf { it.isNotBlank() }
        )
        b.accountVehicleText.text = if (vehicleParts.isNotEmpty()) {
            vehicleParts.joinToString(" \u2022 ")
        } else {
            getString(R.string.account_not_set)
        }

        b.accountMemberSinceText.text = rider.createdAt?.let {
            getString(R.string.account_member_since_format, formatDisplayDate(it))
        }.orEmpty()

        renderDocumentsStatusPill(rider.documentsStatus)
    }

    /** Same 4-state enum/colors RiderDocumentsResult and this app's other
     *  documents-status pills already use (migration 75). */
    private fun renderDocumentsStatusPill(status: String) {
        val b = _binding ?: return
        val (label, bg, fg) = when (status) {
            "pending" -> Triple(getString(R.string.documents_status_pending), R.color.doc_pending_bg, R.color.doc_pending_fg)
            "verified" -> Triple(getString(R.string.documents_status_verified), R.color.doc_verified_bg, R.color.doc_verified_fg)
            "rejected" -> Triple(getString(R.string.documents_status_rejected), R.color.doc_rejected_bg, R.color.doc_rejected_fg)
            else -> Triple(getString(R.string.documents_status_not_submitted), R.color.doc_not_submitted_bg, R.color.doc_not_submitted_fg)
        }
        val ctx = requireContext()
        b.accountDocumentsStatusPill.text = label
        b.accountDocumentsStatusPill.backgroundTintList = ColorStateList.valueOf(ctx.getColor(bg))
        b.accountDocumentsStatusPill.setTextColor(ctx.getColor(fg))
    }

    private fun formatDisplayDate(wireValue: String): String {
        return try {
            val input = SimpleDateFormat("yyyy-MM-dd HH:mm:ss", Locale.US)
            val output = SimpleDateFormat("d MMM yyyy", Locale.US)
            val date = input.parse(wireValue)
            if (date != null) output.format(date) else wireValue
        } catch (e: Exception) {
            wireValue
        }
    }
}
