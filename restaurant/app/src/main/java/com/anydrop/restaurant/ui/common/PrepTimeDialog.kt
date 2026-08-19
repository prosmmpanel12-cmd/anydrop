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

    fun show(context: Context, onConfirm: (prepMinutes: Int) -> Unit) {
        // Bug-hardening (2026-08-18) — was setSingleChoiceItems + a
        // separate positive-button confirm step. setSingleChoiceItems
        // inflates Android's internal single-choice list-item layout,
        // which (unlike plain setItems()/setView()) can be sensitive to
        // custom app themes not fully defining the attributes that
        // layout expects — a real, documented AlertDialog pitfall, and
        // exactly the kind of thing this sandbox has no way to catch
        // (no Android SDK to render it on). setItems() is the most
        // basic/robust list mode AlertDialog has — plain list rows, tap
        // one, dialog closes and fires immediately. Also one tap instead
        // of two, so this is a straight improvement either way.
        val labels = OPTIONS_MINUTES
            .map { context.getString(R.string.prep_time_option_format, it) }
            .toTypedArray()

        AlertDialog.Builder(context)
            .setTitle(R.string.prep_time_dialog_title)
            .setItems(labels) { _, which -> onConfirm(OPTIONS_MINUTES[which]) }
            .setNegativeButton(R.string.btn_cancel, null)
            .show()
    }
}
