package com.anydrop.rider.ui.common

import android.app.Activity
import android.view.Gravity
import android.widget.ImageView
import android.widget.TextView
import android.widget.Toast
import com.anydrop.rider.R

/** Toast-based notifier for the Rider app, styled to match the dark/green
 * theme. Same pattern as the Restaurant app's InAppNotifier. */
object InAppNotifier {
    enum class Type { SUCCESS, ERROR, INFO, WARNING }

    fun show(activity: Activity?, message: String, type: Type = Type.INFO) {
        if (activity == null || activity.isFinishing) return

        val view = activity.layoutInflater.inflate(R.layout.toast_custom, null)
        val chip = view.findViewById<android.widget.FrameLayout>(R.id.toastIconChip)
        val icon = view.findViewById<ImageView>(R.id.toastIcon)
        view.findViewById<TextView>(R.id.toastMessage).text = message

        val (iconRes, tintColor) = when (type) {
            Type.SUCCESS -> R.drawable.ic_check_circle to R.color.success_fg
            Type.ERROR -> R.drawable.ic_error to R.color.error_fg
            Type.WARNING -> R.drawable.ic_warning to R.color.warning_fg
            Type.INFO -> R.drawable.ic_info to R.color.info_fg
        }
        val bgColor = when (type) {
            Type.SUCCESS -> R.color.success_bg
            Type.ERROR -> R.color.error_bg
            Type.WARNING -> R.color.warning_bg
            Type.INFO -> R.color.info_bg
        }
        icon.setImageResource(iconRes)
        icon.setColorFilter(activity.getColor(tintColor))
        chip.backgroundTintList =
            android.content.res.ColorStateList.valueOf(activity.getColor(bgColor))

        Toast(activity).apply {
            duration = Toast.LENGTH_SHORT
            setView(view)
            setGravity(Gravity.BOTTOM or Gravity.CENTER_HORIZONTAL, 0, 160)
        }.show()
    }
}
