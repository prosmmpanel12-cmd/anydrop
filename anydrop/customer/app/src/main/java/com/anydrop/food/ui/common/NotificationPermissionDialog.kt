package com.anydrop.food.ui.common

import android.app.Activity
import android.content.Context
import com.google.android.material.bottomsheet.BottomSheetDialog
import com.anydrop.food.databinding.DialogNotificationPermissionBinding
import com.anydrop.food.notifications.NotificationHelper

/**
 * Custom animated "want to stay updated about offers, order status and
 * more?" popup shown once ever (not once per app open) on Home
 * (screenshot reference — animated bell + Yes/Not now). This wraps the real
 * POST_NOTIFICATIONS system permission request behind a friendlier,
 * on-brand prompt instead of firing the bare OS dialog straight away.
 *
 * Bug 1.5 fix: previously used a static in-memory flag
 * (`alreadyShownThisSession`), which reset on every process restart — i.e.
 * basically every real-world app open, causing the popup loop the user
 * reported. Now: (1) persisted in SharedPreferences so it truly shows once,
 * ever, and (2) skipped entirely if the user already has notifications
 * enabled, regardless of the stored flag.
 */
object NotificationPermissionDialog {

    private const val PREFS = "anydrop_prefs"
    private const val KEY_PROMPT_ANSWERED = "notification_prompt_answered"

    fun showOnce(activity: Activity) {
        // Already enabled (granted earlier, or enabled from system settings
        // directly) — nothing to ask, don't show the popup at all.
        if (NotificationHelper.areNotificationsEnabled(activity)) return

        val prefs = activity.applicationContext.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
        if (prefs.getBoolean(KEY_PROMPT_ANSWERED, false)) return

        val binding = DialogNotificationPermissionBinding.inflate(activity.layoutInflater)
        val dialog = BottomSheetDialog(activity)
        dialog.setContentView(binding.root)
        dialog.setCancelable(true)

        fun markAnswered() {
            prefs.edit().putBoolean(KEY_PROMPT_ANSWERED, true).apply()
        }

        binding.btnEnableNotifications.setOnClickListener {
            markAnswered()
            dialog.dismiss()
            NotificationHelper.requestPermissionIfNeeded(activity)
        }
        binding.btnNotNow.setOnClickListener {
            markAnswered()
            dialog.dismiss()
        }
        dialog.setOnCancelListener {
            markAnswered()
        }

        dialog.show()
    }
}
