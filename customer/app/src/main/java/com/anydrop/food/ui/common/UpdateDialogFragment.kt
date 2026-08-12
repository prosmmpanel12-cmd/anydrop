package com.anydrop.food.ui.common

import android.app.Dialog
import android.content.Intent
import android.net.Uri
import android.os.Bundle
import androidx.fragment.app.DialogFragment
import com.google.android.material.dialog.MaterialAlertDialogBuilder
import com.anydrop.food.databinding.DialogUpdateBinding
import com.anydrop.food.network.AppVersionInfo

/**
 * In-app popup shown at splash when the current versionCode is below
 * `min_app_version_customer` (forced, not cancellable) or below
 * `latest_app_version_customer` (optional, "Later" dismisses it).
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
            ?: "A new version of Anydrop is available."

        binding.btnLater.visibility = if (forced) android.view.View.GONE else android.view.View.VISIBLE

        binding.btnUpdateNow.setOnClickListener {
            val url = versionInfo?.updateUrl
            if (!url.isNullOrBlank()) {
                startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(url)))
            }
            if (!forced) dismiss()
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
