package com.anydrop.food.ui.common

import android.app.Activity
import android.os.Handler
import android.os.Looper
import android.view.Gravity
import android.view.ViewGroup
import android.widget.FrameLayout
import androidx.core.content.ContextCompat
import coil.load
import com.anydrop.food.R
import com.anydrop.food.databinding.ViewInAppBannerBinding

/**
 * Reusable in-app popup notification banner (NOT a system push notification —
 * this is the inside-the-app popup for order status alerts, OTP-sent
 * confirmations, error messages, and promo/discount call-outs).
 *
 * Usage:
 *   InAppNotifier.show(activity, "OTP sent to your email", InAppNotifier.Type.SUCCESS)
 *   InAppNotifier.show(activity, "Flat 50% OFF today!", InAppNotifier.Type.OFFER, imageUrl = url)
 */
object InAppNotifier {

    enum class Type { SUCCESS, ERROR, INFO, OFFER }

    private val handler = Handler(Looper.getMainLooper())

    fun show(
        activity: Activity?,
        message: String,
        type: Type = Type.INFO,
        durationMs: Long = 3200,
        imageUrl: String? = null
    ) {
        if (activity == null || activity.isFinishing) return

        val root = activity.findViewById<ViewGroup>(android.R.id.content)
        val binding = ViewInAppBannerBinding.inflate(activity.layoutInflater, root, false)

        val (bgRes, fgColorRes, iconRes) = when (type) {
            Type.SUCCESS -> Triple(R.drawable.bg_banner_success, R.color.success_fg, R.drawable.ic_check_circle)
            Type.ERROR -> Triple(R.drawable.bg_banner_error, R.color.error_fg, R.drawable.ic_error)
            Type.INFO -> Triple(R.drawable.bg_banner_info, R.color.info_fg, R.drawable.ic_notification)
            Type.OFFER -> Triple(R.drawable.bg_banner_offer, R.color.offer_fg, R.drawable.ic_star)
        }

        binding.bannerRoot.setBackgroundResource(bgRes)
        val fgColor = ContextCompat.getColor(activity, fgColorRes)
        binding.bannerText.setTextColor(fgColor)
        binding.bannerText.text = message
        binding.bannerIcon.setImageResource(iconRes)
        binding.bannerIcon.setColorFilter(fgColor)
        binding.bannerClose.setColorFilter(fgColor)

        // Offer/discount notifications can optionally carry an image (banner
        // creative from the backend). Plain alerts (OTP sent, errors, etc.)
        // never show an image — this keeps the "with image / without image"
        // behavior driven purely by whether imageUrl is supplied.
        if (!imageUrl.isNullOrBlank()) {
            binding.bannerImage.visibility = android.view.View.VISIBLE
            binding.bannerImage.load(imageUrl) { crossfade(true) }
        } else {
            binding.bannerImage.visibility = android.view.View.GONE
        }

        val params = FrameLayout.LayoutParams(
            ViewGroup.LayoutParams.MATCH_PARENT,
            ViewGroup.LayoutParams.WRAP_CONTENT
        )
        params.gravity = Gravity.TOP
        params.topMargin = getStatusBarHeight(activity)
        params.leftMargin = 16
        params.rightMargin = 16

        root.addView(binding.root, params)
        binding.root.startAnimation(android.view.animation.AnimationUtils.loadAnimation(activity, R.anim.slide_down_in))

        val dismiss = Runnable {
            if (binding.root.parent != null) {
                binding.root.startAnimation(
                    android.view.animation.AnimationUtils.loadAnimation(activity, R.anim.slide_up_out)
                )
                root.removeView(binding.root)
            }
        }

        binding.bannerClose.setOnClickListener {
            handler.removeCallbacksAndMessages(null)
            dismiss.run()
        }

        // Offers get more time on screen since they usually carry an image.
        val effectiveDuration = if (!imageUrl.isNullOrBlank()) durationMs + 1800 else durationMs
        handler.postDelayed(dismiss, effectiveDuration)
    }

    private fun getStatusBarHeight(activity: Activity): Int {
        val resourceId = activity.resources.getIdentifier("status_bar_height", "dimen", "android")
        return if (resourceId > 0) activity.resources.getDimensionPixelSize(resourceId) else 24
    }
}
