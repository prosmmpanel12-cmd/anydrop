package com.anydrop.food.ui.orderstatus

import android.os.Bundle
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import androidx.lifecycle.lifecycleScope
import com.google.android.material.bottomsheet.BottomSheetDialogFragment
import com.anydrop.food.databinding.BottomSheetCancelOptionsBinding
import com.anydrop.food.databinding.ItemCancelAddressRowBinding
import com.anydrop.food.network.ApiClient
import com.anydrop.food.network.ApiErrorParser
import com.anydrop.food.network.Address
import com.anydrop.food.network.CancelOrderBody
import com.anydrop.food.network.ChangeOrderAddressBody
import com.anydrop.food.ui.common.InAppNotifier
import kotlinx.coroutines.launch

/**
 * Doc 127 §4 / 128 / 129 — cancel-order retention flow. Replaces
 * OrderStatusActivity's old one-tap-and-it's-gone cancelOrder() call
 * with this sheet: "change delivery address" (doesn't cancel — swaps
 * the address on the live order and dismisses) or "still cancel, now
 * with a reason" (picker + optional free-text, concatenated
 * client-side into a single `reason` string — see buildCancelReason()).
 *
 * Scope confirmed with the app owner this session (doc 129): only
 * these two paths are v1. Edit-delivery-instructions,
 * contact-restaurant, and contact/chat-support — all proposed in doc
 * 127 §4 — are explicitly out of scope here.
 *
 * Shape follows ScheduleTimeSlotBottomSheet's pattern: private
 * constructor + newInstance() + plain lambda callbacks set by the
 * caller right after newInstance() and before .show(...) (same
 * doesn't-survive-a-config-change-teardown tradeoff that sheet's own
 * kdoc already accepts elsewhere in this app).
 */
class CancelOrderOptionsBottomSheet private constructor() : BottomSheetDialogFragment() {

    companion object {
        private const val ARG_ORDER_ID = "order_id"
        private const val ARG_CURRENT_ADDRESS_ID = "current_address_id"

        /**
         * @param orderId the order this sheet acts on.
         * @param currentAddressId the order's existing `delivery_address_id`
         *   (from the full Order, not OrderTrackResult — see
         *   OrderStatusActivity's kdoc on why), used to badge/skip that
         *   address in the picker. Null is handled the same as "no match" —
         *   every saved address is offered, none badged "Current".
         */
        fun newInstance(orderId: Int, currentAddressId: Int?): CancelOrderOptionsBottomSheet {
            val sheet = CancelOrderOptionsBottomSheet()
            sheet.arguments = Bundle().apply {
                putInt(ARG_ORDER_ID, orderId)
                if (currentAddressId != null) putInt(ARG_CURRENT_ADDRESS_ID, currentAddressId)
            }
            return sheet
        }
    }

    /** Invoked right after a successful address change, sheet already
     * dismissed. Caller (OrderStatusActivity) should re-fetch the order
     * so the new address / recalculated delivery_charge show up. */
    var onAddressChanged: (() -> Unit)? = null

    /** Invoked right after a successful cancel, sheet already dismissed.
     * Caller does the same success-path work the old inline
     * cancelOrder() did (status text, hide button, refund refresh). */
    var onCancelled: (() -> Unit)? = null

    private var _binding: BottomSheetCancelOptionsBinding? = null
    private val binding get() = _binding!!
    private val api by lazy { ApiClient.create(requireContext()) }

    private var orderId: Int = 0
    private var currentAddressId: Int? = null
    private var addressesLoaded = false

    override fun onCreateView(
        inflater: LayoutInflater,
        container: ViewGroup?,
        savedInstanceState: Bundle?
    ): View {
        _binding = BottomSheetCancelOptionsBinding.inflate(inflater, container, false)
        return binding.root
    }

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        super.onViewCreated(view, savedInstanceState)

        orderId = requireArguments().getInt(ARG_ORDER_ID)
        currentAddressId = if (requireArguments().containsKey(ARG_CURRENT_ADDRESS_ID)) {
            requireArguments().getInt(ARG_CURRENT_ADDRESS_ID)
        } else {
            null
        }

        binding.btnCloseCancelSheet.setOnClickListener { dismiss() }
        binding.btnKeepOrder.setOnClickListener { dismiss() }

        binding.changeAddressRow.setOnClickListener { toggleAddressList() }

        binding.btnConfirmCancel.setOnClickListener { confirmCancel() }
    }

    /** First tap expands + lazy-loads (getAddresses(), same "don't fetch
     * until asked for" idea Track Live already established for the
     * map); every tap after that just toggles visibility of what's
     * already loaded — no re-fetch on every open/close. */
    private fun toggleAddressList() {
        val opening = binding.addressListContainer.visibility != View.VISIBLE
        binding.addressListContainer.visibility = if (opening) View.VISIBLE else View.GONE
        binding.changeAddressChevron.rotation = if (opening) 180f else 0f

        if (opening && !addressesLoaded) {
            loadAddresses()
        }
    }

    private fun loadAddresses() {
        binding.addressListLoading.visibility = View.VISIBLE
        binding.addressListEmptyText.visibility = View.GONE
        lifecycleScope.launch {
            try {
                val addresses = api.getAddresses().body()?.data?.addresses.orEmpty()
                addressesLoaded = true
                renderAddressList(addresses)
            } catch (e: Exception) {
                // Same silent-ish convention as loadOrderDetail() elsewhere
                // in this flow — but this one's user-initiated (they tapped
                // to expand), so a quiet empty-state read is better than a
                // banner for what's likely just a network hiccup; they can
                // just tap again to retry (addressesLoaded stays false).
                binding.addressListEmptyText.text = getString(com.anydrop.food.R.string.cancel_no_other_addresses)
                binding.addressListEmptyText.visibility = View.VISIBLE
            } finally {
                binding.addressListLoading.visibility = View.GONE
            }
        }
    }

    private fun renderAddressList(addresses: List<Address>) {
        binding.addressListContainer.removeAllViews()

        if (addresses.isEmpty()) {
            binding.addressListEmptyText.visibility = View.VISIBLE
            return
        }

        val inflater = LayoutInflater.from(requireContext())
        addresses.forEach { address ->
            val row = ItemCancelAddressRowBinding.inflate(inflater, binding.addressListContainer, false)
            val isCurrent = currentAddressId != null && address.id == currentAddressId

            row.cancelAddressRowLabel.text = address.label
                ?: address.addressType.replaceFirstChar { it.uppercase() }
            row.cancelAddressRowFull.text = address.fullAddress
            row.cancelAddressRowCurrentBadge.visibility = if (isCurrent) View.VISIBLE else View.GONE
            row.cancelAddressRowCheck.visibility = if (isCurrent) View.VISIBLE else View.GONE

            if (!isCurrent) {
                row.cancelAddressRowRoot.setOnClickListener { changeAddress(address.id) }
            }

            binding.addressListContainer.addView(row.root)
        }

        // If every saved address turned out to be the current one (i.e.
        // exactly one saved address, already the order's), there's nothing
        // else to switch to — surface the same empty-state copy as a true
        // empty list rather than a picker with nothing tappable in it.
        if (addresses.all { currentAddressId != null && it.id == currentAddressId }) {
            binding.addressListEmptyText.visibility = View.VISIBLE
        }
    }

    private fun changeAddress(addressId: Int) {
        binding.changeAddressRow.isEnabled = false
        lifecycleScope.launch {
            try {
                val response = api.changeOrderAddress(orderId, ChangeOrderAddressBody(addressId))
                if (response.isSuccessful) {
                    InAppNotifier.show(
                        activity,
                        getString(com.anydrop.food.R.string.cancel_address_change_success),
                        InAppNotifier.Type.SUCCESS
                    )
                    onAddressChanged?.invoke()
                    dismiss()
                } else {
                    val errCode = ApiErrorParser.parse(response).code
                    InAppNotifier.show(activity, addressChangeErrorMessage(errCode), InAppNotifier.Type.ERROR)
                    binding.changeAddressRow.isEnabled = true
                }
            } catch (e: Exception) {
                InAppNotifier.show(activity, "Network error while updating address.", InAppNotifier.Type.ERROR)
                binding.changeAddressRow.isEnabled = true
            }
        }
    }

    /** order-change-address.php's error codes (doc 129) — mapped to
     * user-facing copy here since none of these are friendly to show as
     * raw codes yet. Same shape as OrderStatusActivity.statusLabel(). */
    private fun addressChangeErrorMessage(code: String?): String = when (code) {
        "order_not_eligible_for_address_change" ->
            "This order can no longer have its address changed."
        "order_already_paid" ->
            "This order's already been paid for, so the address can't be changed now."
        "payment_method_not_allowed" ->
            "Your payment method isn't available at that address."
        "cod_not_eligible" ->
            "Cash on Delivery isn't available for that address."
        "validation_error" ->
            "That address couldn't be used. Please pick another."
        else -> "Couldn't update the delivery address."
    }

    private fun confirmCancel() {
        binding.btnConfirmCancel.isEnabled = false
        val reason = buildCancelReason()
        lifecycleScope.launch {
            try {
                val response = api.cancelOrder(orderId, CancelOrderBody(reason))
                if (response.isSuccessful) {
                    onCancelled?.invoke()
                    dismiss()
                } else {
                    val errCode = ApiErrorParser.parse(response).code
                    InAppNotifier.show(activity, errCode ?: "Couldn't cancel order", InAppNotifier.Type.ERROR)
                    binding.btnConfirmCancel.isEnabled = true
                }
            } catch (e: Exception) {
                InAppNotifier.show(activity, "Network error while cancelling.", InAppNotifier.Type.ERROR)
                binding.btnConfirmCancel.isEnabled = true
            }
        }
    }

    /** Combines whichever chip is checked (if any) with the free-text
     * field's contents (if non-blank) — app owner's "picker + optional
     * free-text" choice (doc 129), concatenated client-side into the
     * single `reason` string the backend already accepts. Returns null
     * (backend falls back to "Cancelled by customer") when neither is
     * set, matching CancelOrderBody's own default-null shape. */
    private fun buildCancelReason(): String? {
        val chipId = binding.cancelReasonChipGroup.checkedChipId
        val chipLabel = if (chipId != View.NO_ID) {
            binding.cancelReasonChipGroup.findViewById<com.google.android.material.chip.Chip>(chipId)?.text?.toString()
        } else {
            null
        }
        val freeText = binding.cancelReasonDetailsInput.text?.toString()?.trim().orEmpty()

        return when {
            !chipLabel.isNullOrBlank() && freeText.isNotBlank() -> "$chipLabel — $freeText"
            !chipLabel.isNullOrBlank() -> chipLabel
            freeText.isNotBlank() -> freeText
            else -> null
        }
    }

    override fun onDestroyView() {
        super.onDestroyView()
        _binding = null
    }
}
