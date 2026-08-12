package com.anydrop.restaurant.ui.common

import android.app.Activity
import android.widget.Toast

/**
 * Simple Toast-based notifier for the Restaurant app. The customer app has a
 * full custom in-app banner (per its UI/UX requirement); this app is used by
 * restaurant staff at a counter, so a Toast is sufficient here and avoids
 * duplicating the banner view/animation set for equivalent value.
 */
object InAppNotifier {
    enum class Type { SUCCESS, ERROR, INFO }

    fun show(activity: Activity?, message: String, type: Type = Type.INFO) {
        if (activity == null || activity.isFinishing) return
        Toast.makeText(activity, message, Toast.LENGTH_SHORT).show()
    }
}
