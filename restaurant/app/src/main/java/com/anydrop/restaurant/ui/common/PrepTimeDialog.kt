package com.anydrop.restaurant.ui.common

import android.app.AlertDialog
import android.content.Context
import com.anydrop.restaurant.R

/**
 * Order Management small addition — "🟢 Preparation-time select (10/15/20/30
 * min)" from docs/18_Restaurant_App_Full_Scope_And_Rating_System.md. Backend
 * (`orders-accept.php`) already accepts and stores `estimated_prep_minutes`
 * and already falls back to 20 whenever the client doesn't send one — this
 * was purely a missing client-side ask. Shared here so both accept paths
 * (`OrderAdapter`'s inline Accept button on the Orders tab's New section,
 * and `OrderDetailActivity`'s full-screen Accept button) ask the exact same
 * question the same way, instead of one screen quietly defaulting to the
 * backend's 20-min fallback while the other actually prompts.
 *
 * Plain `AlertDialog.setSingleChoiceItems`, no custom layout/ChipGroup —
 * matches this app's existing quick-dialog pattern (see
 * `OrderAdapter.promptRejectReason`) and, per every session note in
 * `docs/restorent/00_Status.md`, there's still no Android SDK in this
 * sandbox to visually verify a custom chip layout before shipping it.
 */
object PrepTimeDialog {

    private val OPTIONS_MINUTES = intArrayOf(10, 15, 20, 30)

    // 20 min — matches orders-accept.php's own fallback when nothing is
    // sent, so the dialog's default selection agrees with what would have
    // happened anyway if this dialog didn't exist.
    private const val DEFAULT_INDEX = 2

    fun show(context: Context, onConfirm: (prepMinutes: Int) -> Unit) {
        var selectedIndex = DEFAULT_INDEX
        val labels = OPTIONS_MINUTES
            .map { context.getString(R.string.prep_time_option_format, it) }
            .toTypedArray()

        AlertDialog.Builder(context)
            .setTitle(R.string.prep_time_dialog_title)
            .setSingleChoiceItems(labels, selectedIndex) { _, which -> selectedIndex = which }
            .setPositiveButton(R.string.btn_accept) { _, _ -> onConfirm(OPTIONS_MINUTES[selectedIndex]) }
            .setNegativeButton(R.string.btn_cancel, null)
            .show()
    }
}
