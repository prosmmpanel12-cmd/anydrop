package com.anydrop.food.ui.common

import android.view.View

/**
 * Shared fade+scale cross-fade animation for the ADD button <-> qty
 * stepper toggle that appears on every dish card (Restaurant Detail's
 * `MenuAdapter`, Home's `PopularItemsAdapter`, Search's
 * `SearchResultsAdapter`). Both views sit stacked in the same
 * `FrameLayout` position (see `item_menu_item.xml` / `item_popular_dish.xml`
 * / `item_search_dish.xml`), so a cross-fade + scale reads as one control
 * morphing into the other instead of an abrupt visibility jump-cut.
 *
 * [show]/[hide] are for animated, user-triggered toggles (call from inside
 * an add/increase/decrease click callback). Each is a no-op if the target
 * view is already in that state, so rapid taps don't stack animations.
 *
 * [setImmediate] is for `RecyclerView` bind — sets the correct end-state
 * instantly with no animation, and clears any leftover alpha/scale from a
 * recycled view's previous animation. Always use this (not [show]/[hide])
 * for the initial state in `onBindViewHolder`/`bind()`, or a recycled row
 * can flash mid-animation values from whatever it was previously bound to.
 */
object QtyStepperTransition {

    private const val DURATION_MS = 180L
    private const val SHRINK_SCALE = 0.7f

    /** Instantly show the qty stepper and hide ADD, no animation. */
    fun setImmediate(stepper: View, addButton: View, showStepper: Boolean) {
        stepper.animate().cancel()
        addButton.animate().cancel()
        resetTransform(stepper)
        resetTransform(addButton)
        stepper.visibility = if (showStepper) View.VISIBLE else View.GONE
        addButton.visibility = if (showStepper) View.GONE else View.VISIBLE
    }

    /** Animate ADD -> qty stepper (item just went from 0 to 1+ in cart). */
    fun show(stepper: View, addButton: View) {
        if (stepper.visibility == View.VISIBLE && addButton.visibility == View.GONE) return
        stepper.animate().cancel()
        addButton.animate().cancel()

        addButton.animate()
            .alpha(0f).scaleX(SHRINK_SCALE).scaleY(SHRINK_SCALE)
            .setDuration(DURATION_MS)
            .withEndAction {
                addButton.visibility = View.GONE
                resetTransform(addButton)
            }
            .start()

        resetTransform(stepper)
        stepper.alpha = 0f
        stepper.scaleX = SHRINK_SCALE
        stepper.scaleY = SHRINK_SCALE
        stepper.visibility = View.VISIBLE
        stepper.animate()
            .alpha(1f).scaleX(1f).scaleY(1f)
            .setDuration(DURATION_MS)
            .start()
    }

    /** Animate qty stepper -> ADD (item just went back down to 0 via "-"). */
    fun hide(stepper: View, addButton: View) {
        if (addButton.visibility == View.VISIBLE && stepper.visibility == View.GONE) return
        stepper.animate().cancel()
        addButton.animate().cancel()

        stepper.animate()
            .alpha(0f).scaleX(SHRINK_SCALE).scaleY(SHRINK_SCALE)
            .setDuration(DURATION_MS)
            .withEndAction {
                stepper.visibility = View.GONE
                resetTransform(stepper)
            }
            .start()

        resetTransform(addButton)
        addButton.alpha = 0f
        addButton.scaleX = SHRINK_SCALE
        addButton.scaleY = SHRINK_SCALE
        addButton.visibility = View.VISIBLE
        addButton.animate()
            .alpha(1f).scaleX(1f).scaleY(1f)
            .setDuration(DURATION_MS)
            .start()
    }

    private fun resetTransform(view: View) {
        view.alpha = 1f
        view.scaleX = 1f
        view.scaleY = 1f
    }
}
