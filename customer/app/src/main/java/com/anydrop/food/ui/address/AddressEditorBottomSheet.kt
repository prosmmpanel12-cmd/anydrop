package com.anydrop.food.ui.address

import android.os.Bundle
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import androidx.lifecycle.lifecycleScope
import com.google.android.material.bottomsheet.BottomSheetDialogFragment
import com.anydrop.food.R
import com.anydrop.food.databinding.FragmentAddressEditorBinding
import com.anydrop.food.network.AddAddressBody
import com.anydrop.food.network.Address
import com.anydrop.food.network.ApiClient
import com.anydrop.food.ui.common.InAppNotifier
import kotlinx.coroutines.launch

/**
 * Structured address form (§2.6) — replaces Checkout's old single free-text
 * field and doubles as the shared editor for Profile → Address Book (§2.7).
 * Add mode when `editingAddress` is null, edit mode (PUT) otherwise. Caller
 * supplies `onSaved` to refresh its own address list; this sheet doesn't
 * know or care which screen opened it.
 *
 * Backend already accepts every field here (addAddress/updateAddress) —
 * this is purely a UI gap being closed, no new endpoints needed.
 */
class AddressEditorBottomSheet private constructor() : BottomSheetDialogFragment() {

    companion object {
        private const val ARG_ADDRESS_ID = "address_id"
        private const val ARG_LABEL = "label"
        private const val ARG_ADDRESS_TYPE = "address_type"
        private const val ARG_FULL_ADDRESS = "full_address"
        private const val ARG_HOUSE_FLAT_NO = "house_flat_no"
        private const val ARG_FLOOR = "floor"
        private const val ARG_LANDMARK = "landmark"
        private const val ARG_RECEIVER_NAME = "receiver_name"
        private const val ARG_RECEIVER_PHONE = "receiver_phone"
        private const val ARG_LATITUDE = "latitude"
        private const val ARG_LONGITUDE = "longitude"

        /** Add-new-address mode. */
        fun newInstance(): AddressEditorBottomSheet = AddressEditorBottomSheet()

        /** Edit-existing-address mode — pre-fills every field from `address`. */
        fun newInstance(address: Address): AddressEditorBottomSheet {
            val sheet = AddressEditorBottomSheet()
            sheet.arguments = Bundle().apply {
                putInt(ARG_ADDRESS_ID, address.id)
                putString(ARG_LABEL, address.label)
                putString(ARG_ADDRESS_TYPE, address.addressType)
                putString(ARG_FULL_ADDRESS, address.fullAddress)
                putString(ARG_HOUSE_FLAT_NO, address.houseFlatNo)
                putString(ARG_FLOOR, address.floor)
                putString(ARG_LANDMARK, address.landmark)
                putString(ARG_RECEIVER_NAME, address.receiverName)
                putString(ARG_RECEIVER_PHONE, address.receiverPhone)
                address.latitude?.let { putDouble(ARG_LATITUDE, it) }
                address.longitude?.let { putDouble(ARG_LONGITUDE, it) }
            }
            return sheet
        }
    }

    /** Set by the caller before showing — called after a successful save/update. */
    var onSaved: (() -> Unit)? = null

    /** Optional — lets the caller (Checkout) pass through a GPS fix obtained
     * via "Use current location" before this sheet was opened. */
    var prefillLat: Double? = null
    var prefillLng: Double? = null

    private var _binding: FragmentAddressEditorBinding? = null
    private val binding get() = _binding!!
    private val api by lazy { ApiClient.create(requireContext()) }

    private var editingAddressId: Int? = null
    private var selectedType: String = "home"

    override fun onCreateView(
        inflater: LayoutInflater,
        container: ViewGroup?,
        savedInstanceState: Bundle?
    ): View {
        _binding = FragmentAddressEditorBinding.inflate(inflater, container, false)
        return binding.root
    }

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        super.onViewCreated(view, savedInstanceState)

        val args = arguments
        editingAddressId = if (args?.containsKey(ARG_ADDRESS_ID) == true) args.getInt(ARG_ADDRESS_ID) else null

        if (editingAddressId != null) {
            binding.editorTitle.text = getString(R.string.edit_address_title)
            binding.btnSaveAddress.text = getString(R.string.btn_update_address)
            selectedType = args?.getString(ARG_ADDRESS_TYPE) ?: "home"
            binding.inputHouseFlatNo.setText(args?.getString(ARG_HOUSE_FLAT_NO).orEmpty())
            binding.inputFloor.setText(args?.getString(ARG_FLOOR).orEmpty())
            binding.inputArea.setText(args?.getString(ARG_FULL_ADDRESS).orEmpty())
            binding.inputLandmark.setText(args?.getString(ARG_LANDMARK).orEmpty())
            binding.inputReceiverName.setText(args?.getString(ARG_RECEIVER_NAME).orEmpty())
            binding.inputReceiverPhone.setText(args?.getString(ARG_RECEIVER_PHONE).orEmpty())
            if (args?.containsKey(ARG_LATITUDE) == true) prefillLat = args.getDouble(ARG_LATITUDE)
            if (args?.containsKey(ARG_LONGITUDE) == true) prefillLng = args.getDouble(ARG_LONGITUDE)
        } else {
            binding.editorTitle.text = getString(R.string.add_address_title)
            binding.btnSaveAddress.text = getString(R.string.btn_save_address)
        }
        applyTypeSelectionUi()

        binding.typeHome.setOnClickListener { selectedType = "home"; applyTypeSelectionUi() }
        binding.typeWork.setOnClickListener { selectedType = "work"; applyTypeSelectionUi() }
        binding.typeOther.setOnClickListener { selectedType = "other"; applyTypeSelectionUi() }

        binding.btnUseCurrentLocation.setOnClickListener {
            (parentFragment as? LocationRequester)?.requestLocationForAddressEditor(this)
                ?: (activity as? LocationRequester)?.requestLocationForAddressEditor(this)
        }

        binding.btnSaveAddress.setOnClickListener { save() }
    }

    /** Called by the host Activity/Fragment once it has resolved a GPS fix
     * on this sheet's behalf (Android's location APIs need an Activity
     * context for permission prompts, which a bottom sheet doesn't have). */
    fun applyResolvedLocation(lat: Double, lng: Double, resolvedAddressLine: String?) {
        prefillLat = lat
        prefillLng = lng
        if (!resolvedAddressLine.isNullOrBlank() && binding.inputArea.text.isNullOrBlank()) {
            binding.inputArea.setText(resolvedAddressLine)
        }
    }

    /** Implemented by any Activity/Fragment that can resolve device location
     * on this sheet's behalf (Checkout already has this logic — reused via
     * this interface rather than duplicated inside the sheet). */
    interface LocationRequester {
        fun requestLocationForAddressEditor(sheet: AddressEditorBottomSheet)
    }

    private fun applyTypeSelectionUi() {
        val chips = listOf(
            binding.typeHome to "home",
            binding.typeWork to "work",
            binding.typeOther to "other"
        )
        chips.forEach { (chip, type) ->
            chip.setBackgroundResource(
                if (type == selectedType) R.drawable.bg_chip_selected else R.drawable.bg_chip_unselected
            )
        }
    }

    private fun save() {
        val houseFlatNo = binding.inputHouseFlatNo.text?.toString()?.trim().orEmpty()
        val floor = binding.inputFloor.text?.toString()?.trim().orEmpty().ifEmpty { null }
        val area = binding.inputArea.text?.toString()?.trim().orEmpty()
        val landmark = binding.inputLandmark.text?.toString()?.trim().orEmpty().ifEmpty { null }
        val receiverName = binding.inputReceiverName.text?.toString()?.trim().orEmpty()
        val receiverPhone = binding.inputReceiverPhone.text?.toString()?.trim().orEmpty()

        if (houseFlatNo.isEmpty()) {
            InAppNotifier.show(activity, getString(R.string.error_house_flat_required), InAppNotifier.Type.INFO)
            return
        }
        if (area.isEmpty()) {
            InAppNotifier.show(activity, getString(R.string.error_area_required), InAppNotifier.Type.INFO)
            return
        }
        if (receiverName.isEmpty()) {
            InAppNotifier.show(activity, getString(R.string.error_receiver_name_required), InAppNotifier.Type.INFO)
            return
        }
        if (receiverPhone.length < 10) {
            InAppNotifier.show(activity, getString(R.string.error_receiver_phone_required), InAppNotifier.Type.INFO)
            return
        }

        val body = AddAddressBody(
            label = selectedType.replaceFirstChar { it.uppercase() },
            addressType = selectedType,
            fullAddress = area,
            houseFlatNo = houseFlatNo,
            floor = floor,
            landmark = landmark,
            receiverName = receiverName,
            receiverPhone = receiverPhone,
            latitude = prefillLat,
            longitude = prefillLng,
            isDefault = editingAddressId == null // first/new address becomes default; edits keep existing default state server-side
        )

        binding.btnSaveAddress.isEnabled = false
        lifecycleScope.launch {
            try {
                val addressId = editingAddressId
                val response = if (addressId != null) {
                    api.updateAddress(addressId, body)
                } else {
                    api.addAddress(body)
                }
                if (response.isSuccessful && response.body()?.success == true) {
                    InAppNotifier.show(
                        activity,
                        if (addressId != null) "Address updated" else "Address saved",
                        InAppNotifier.Type.SUCCESS
                    )
                    onSaved?.invoke()
                    dismiss()
                } else {
                    binding.btnSaveAddress.isEnabled = true
                    InAppNotifier.show(activity, "Couldn't save address", InAppNotifier.Type.ERROR)
                }
            } catch (e: Exception) {
                binding.btnSaveAddress.isEnabled = true
                InAppNotifier.show(activity, "Network error while saving address", InAppNotifier.Type.ERROR)
            }
        }
    }

    override fun onDestroyView() {
        super.onDestroyView()
        _binding = null
    }
}
