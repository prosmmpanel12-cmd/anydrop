package com.anydrop.restaurant.ui.common

import android.content.Context
import com.anydrop.restaurant.databinding.DialogConfirmStatusChangeBinding
import com.google.android.material.dialog.MaterialAlertDialogBuilder

/**
 * Status-change confirm dialog (2026-09-11, plan doc 127 §2 — "restorents
 * order ka status change pe confirm mango"). Generic over title/message so
 * this one dialog is reused for both "Mark Preparing" and "Mark Ready" (and
 * any future status-change action) instead of two near-identical copies.
 *
 * Visually modeled on dialog_logout_confirm.xml, per that dialog's existing
 * pattern (illustration + title + message + side-by-side Cancel/Confirm),
 * same as [PrepTimeDialog] mirrors this app's existing quick-dialog
 * conventions rather than inventing a new one.
 *
 * Deliberately NOT wired into the Accept flow — [PrepTimeDialog] already
 * acts as a meaningful confirm step there (the restaurant has to actively
 * choose a prep time), and stacking a second "are you sure" on top would be
 * redundant friction on the highest-frequency action on this screen. See
 * OrderDetailActivity's configureActions() kdoc for the same note.
 */
object StatusChangeConfirmDialog {

    fun show(context: Context, title: String, message: String, onConfirmed: () -> Unit) {
        val dialogBinding = DialogConfirmStatusChangeBinding.inflate(
            android.view.LayoutInflater.from(context)
        )
        dialogBinding.statusChangeDialogTitle.text = title
        dialogBinding.statusChangeDialogMessage.text = message

        val dialog = MaterialAlertDialogBuilder(context)
            .setView(dialogBinding.root)
            .create()

        dialogBinding.btnStatusChangeDialogCancel.setOnClickListener { dialog.dismiss() }
        dialogBinding.btnStatusChangeDialogConfirm.setOnClickListener {
            dialog.dismiss()
            onConfirmed()
        }
        dialog.show()
    }
}
