package com.anydrop.food.ui.common

import android.animation.ObjectAnimator
import android.content.Context
import android.widget.ImageView
import androidx.lifecycle.LifecycleCoroutineScope
import coil.load
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

/**
 * Rotates the login-screen hero banner through a set of images with a
 * crossfade + slow "Ken Burns" zoom, switching every [intervalMs].
 *
 * Image source priority:
 *  1. Bundled drawables named login_banner_1, login_banner_2, ... login_banner_8
 *     (checked at runtime via resource name, so you can drop in anywhere from
 *     1 to 8 images — just name them exactly like that in res/drawable and
 *     they'll be picked up automatically, no code changes needed).
 *  2. If none of those exist yet, falls back to the single backend
 *     bannerImageUrl (same one splash-config already provides), so the
 *     screen never looks broken while you're still sourcing images.
 *
 * Uses two stacked ImageViews (front/back) so one is always fading in while
 * the other fades out — no flicker, no visible "blank" frame.
 */
object BannerCarousel {

    private const val MAX_LOCAL_IMAGES = 8

    fun start(
        context: Context,
        scope: LifecycleCoroutineScope,
        viewA: ImageView,
        viewB: ImageView,
        fallbackUrl: String?,
        intervalMs: Long = 2800L,
    ) {
        val localDrawableIds = (1..MAX_LOCAL_IMAGES).mapNotNull { i ->
            val resId = context.resources.getIdentifier(
                "login_banner_$i", "drawable", context.packageName
            )
            resId.takeIf { it != 0 }
        }

        val sources: List<Any> = when {
            localDrawableIds.isNotEmpty() -> localDrawableIds
            !fallbackUrl.isNullOrBlank() -> listOf(fallbackUrl)
            else -> emptyList()
        }

        if (sources.isEmpty()) return

        // Show the first image immediately (no fade-in for the very first frame).
        viewA.load(sources[0]) { crossfade(false) }
        applyKenBurns(viewA)

        if (sources.size == 1) return // nothing to rotate through yet

        scope.launch {
            var index = 0
            var frontIsA = true
            while (true) {
                delay(intervalMs)
                index = (index + 1) % sources.size
                val incoming = if (frontIsA) viewB else viewA
                val outgoing = if (frontIsA) viewA else viewB

                incoming.alpha = 0f
                incoming.load(sources[index]) { crossfade(false) }
                applyKenBurns(incoming)

                incoming.animate().alpha(1f).setDuration(700).start()
                outgoing.animate().alpha(0f).setDuration(700).start()

                frontIsA = !frontIsA
            }
        }
    }

    /** Very subtle slow zoom-in over the display duration, for a premium feel. */
    private fun applyKenBurns(view: ImageView) {
        view.scaleX = 1f
        view.scaleY = 1f
        ObjectAnimator.ofFloat(view, "scaleX", 1f, 1.08f).apply {
            duration = 3500L
            start()
        }
        ObjectAnimator.ofFloat(view, "scaleY", 1f, 1.08f).apply {
            duration = 3500L
            start()
        }
    }
}
