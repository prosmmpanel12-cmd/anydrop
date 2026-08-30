package com.anydrop.food.ui.common

import android.app.Dialog
import android.os.Bundle
import androidx.fragment.app.DialogFragment
import com.google.android.material.dialog.MaterialAlertDialogBuilder
import com.anydrop.food.databinding.DialogMaintenanceBinding

/**
 * Full-block, non-cancellable popup shown at splash when
 * `maintenance_mode_customer` is on (api/v1/system/app-version.php's
 * `maintenance_mode` field, set from admin/app-settings.php). Same
 * "dialog left on screen over the splash, onDone() never called"
 * pattern UpdateChecker already uses for a forced update — the app
 * never proceeds to Login/Home while this is showing.
 *
 * Only a Retry button (no "Later"/dismiss) since there's nothing
 * useful to fall through to: unlike an outdated version, a maintenance
 * window is a server-side state that can flip off at any moment, so
 * Retry just restarts the splash flow to re-check rather than assuming
 * the user needs to take any action themselves.
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
            // Simplest reliable retry: restart the whole splash flow so
            // UpdateChecker.check() re-hits the backend from scratch,
            // rather than threading a re-check callback through this
            // dialog just to save one activity recreation.
            requireActivity().recreate()
        }

        isCancelable = false

        return MaterialAlertDialogBuilder(requireContext())
            .setView(binding.root)
            .setCancelable(false)
            .create()
    }

    override fun onCancel(dialog: android.content.DialogInterface) {
        // Defense in depth: isCancelable = false already blocks back-press
        // and outside-touch dismissal, but if anything ever slips past
        // that, don't let the app silently proceed past a maintenance
        // block — put it right back up.
        super.onCancel(dialog)
        requireActivity().recreate()
    }
}
