package com.anydrop.restaurant.ui.common

import android.app.Dialog
import android.os.Bundle
import androidx.fragment.app.DialogFragment
import com.google.android.material.dialog.MaterialAlertDialogBuilder
import com.anydrop.restaurant.databinding.DialogMaintenanceBinding

/**
 * Restaurant App's copy of the Customer App's MaintenanceDialogFragment
 * — see that file's kdoc for the full rationale. Full-block,
 * non-cancellable popup shown at splash when `maintenance_mode_restaurant`
 * is on. Only a Retry button, since maintenance state can flip off at
 * any moment server-side.
 */
class MaintenanceDialogFragment : DialogFragment() {

    private var message: String? = null

    companion object {
        fun newInstance(message: String?): MaintenanceDialogFragment {
            val f = MaintenanceDialogFragment()
            f.message = message
            return f
        }
    }

    override fun onCreateDialog(savedInstanceState: Bundle?): Dialog {
        val binding = DialogMaintenanceBinding.inflate(layoutInflater)

        if (!message.isNullOrBlank()) {
            binding.maintenanceMessage.text = message
        }
        // else: leaves the layout's bundled maintenance_message_default string.

        binding.btnRetry.setOnClickListener {
            requireActivity().recreate()
        }

        isCancelable = false

        return MaterialAlertDialogBuilder(requireContext())
            .setView(binding.root)
            .setCancelable(false)
            .create()
    }

    override fun onCancel(dialog: android.content.DialogInterface) {
        super.onCancel(dialog)
        requireActivity().recreate()
    }
}
