package com.anydrop.restaurant.ui.common

import android.app.Dialog
import android.content.Intent
import android.net.Uri
import android.os.Bundle
import androidx.fragment.app.DialogFragment
import com.google.android.material.dialog.MaterialAlertDialogBuilder
import com.anydrop.restaurant.databinding.DialogUpdateBinding
import com.anydrop.restaurant.network.AppVersionInfo

/**
 * Restaurant App's copy of the Customer App's UpdateDialogFragment (§9,
 * 2026-08-28). In-app popup shown at splash when the current versionCode
 * is below `min_app_version_restaurant` (forced, not cancellable) or below
 * `latest_app_version_restaurant` (optional, "Later" dismisses it).
 */
class UpdateDialogFragment : DialogFragment() {

    private var info: AppVersionInfo? = null
    private var forced: Boolean = false
    var onLater: (() -> Unit)? = null

    companion object {
        fun newInstance(info: AppVersionInfo, forced: Boolean): UpdateDialogFragment {
            val f = UpdateDialogFragment()
            f.info = info
            f.forced = forced
            return f
        }
    }

    override fun onCreateDialog(savedInstanceState: Bundle?): Dialog {
        val binding = DialogUpdateBinding.inflate(layoutInflater)
        val versionInfo = info

        binding.updateMessage.text = versionInfo?.updateMessage
            ?: "A new version of Anydrop Partner is available."

        binding.btnLater.visibility = if (forced) android.view.View.GONE else android.view.View.VISIBLE

        binding.btnUpdateNow.setOnClickListener {
            val url = versionInfo?.updateUrl
            if (!url.isNullOrBlank()) {
                startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(url)))
            }
            if (!forced) {
                // Bug fix (2026-08-30) — see Customer App's identical fix
                // for the full rationale: without invoking onLater here,
                // "Update Now" on an optional update left the app stuck on
                // splash after returning from the Play Store/browser.
                onLater?.invoke()
                dismiss()
            }
        }

        binding.btnLater.setOnClickListener {
            onLater?.invoke()
            dismiss()
        }

        isCancelable = !forced

        return MaterialAlertDialogBuilder(requireContext())
            .setView(binding.root)
            .setCancelable(!forced)
            .create()
    }
}
