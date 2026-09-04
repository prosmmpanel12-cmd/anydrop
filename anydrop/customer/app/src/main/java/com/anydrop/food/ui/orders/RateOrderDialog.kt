package com.anydrop.food.ui.orders

import android.view.View
import android.widget.ImageView
import android.widget.LinearLayout
import androidx.appcompat.app.AppCompatActivity
import androidx.lifecycle.lifecycleScope
import com.google.android.material.bottomsheet.BottomSheetDialog
import com.anydrop.food.R
import com.anydrop.food.databinding.DialogRateOrderBinding
import com.anydrop.food.network.ApiClient
import com.anydrop.food.network.SubmitReviewBody
import com.anydrop.food.ui.common.InAppNotifier
import kotlinx.coroutines.launch

/**
 * Part 13 — Order rating. Shown either automatically once an order's
 * status reaches "delivered" (OrderStatusActivity) or manually via the
 * "Rate Order" button on a delivered, not-yet-rated card in Order History.
 *
 * Restaurant rating is required (feeds `restaurants.rating_avg` server-side,
 * see backend/lib/reviews.php); food + delivery star rows and the comment
 * are optional. The delivery row is hidden entirely when the order had no
 * rider (e.g. self-pickup / no rider assigned), since there's nothing to
 * rate there.
 */
object RateOrderDialog {

    fun show(
        activity: AppCompatActivity,
        orderId: Int,
        restaurantName: String,
        hasRider: Boolean,
        onSubmitted: (() -> Unit)? = null
    ) {
        val binding = DialogRateOrderBinding.inflate(activity.layoutInflater)
        val dialog = BottomSheetDialog(activity)
        dialog.setContentView(binding.root)
        dialog.setCancelable(true)

        binding.rateOrderRestaurantLabel.text =
            activity.getString(R.string.rate_order_restaurant_label, restaurantName)

        var restaurantRating = 0
        var foodRating = 0
        var deliveryRating = 0

        val restaurantStars = buildStarRow(activity, binding.starsRowRestaurant) { tapped ->
            restaurantRating = tapped
            binding.rateOrderError.visibility = View.GONE
        }
        val foodStars = buildStarRow(activity, binding.starsRowFood) { tapped -> foodRating = tapped }

        if (hasRider) {
            binding.deliveryRatingGroup.visibility = View.VISIBLE
            buildStarRow(activity, binding.starsRowDelivery) { tapped -> deliveryRating = tapped }
        } else {
            binding.deliveryRatingGroup.visibility = View.GONE
        }

        binding.btnRateOrderMaybeLater.setOnClickListener { dialog.dismiss() }

        binding.btnSubmitOrderRating.setOnClickListener {
            if (restaurantRating == 0) {
                binding.rateOrderError.visibility = View.VISIBLE
                return@setOnClickListener
            }
            binding.btnSubmitOrderRating.isEnabled = false
            val api = ApiClient.create(activity)
            activity.lifecycleScope.launch {
                try {
                    val response = api.submitReview(
                        SubmitReviewBody(
                            orderId = orderId,
                            restaurantRating = restaurantRating,
                            foodRating = foodRating.takeIf { it > 0 },
                            deliveryRating = deliveryRating.takeIf { it > 0 },
                            comment = binding.inputRateOrderComment.text?.toString()?.trim()
                                ?.takeIf { it.isNotEmpty() }
                        )
                    )
                    when {
                        response.isSuccessful -> {
                            InAppNotifier.show(
                                activity,
                                activity.getString(R.string.rate_order_submitted),
                                InAppNotifier.Type.SUCCESS
                            )
                            dialog.dismiss()
                            onSubmitted?.invoke()
                        }
                        response.code() == 409 -> {
                            InAppNotifier.show(
                                activity,
                                activity.getString(R.string.rate_order_already_rated),
                                InAppNotifier.Type.INFO
                            )
                            dialog.dismiss()
                            onSubmitted?.invoke()
                        }
                        else -> {
                            InAppNotifier.show(activity, activity.getString(R.string.error_generic), InAppNotifier.Type.ERROR)
                            binding.btnSubmitOrderRating.isEnabled = true
                        }
                    }
                } catch (e: Exception) {
                    InAppNotifier.show(activity, activity.getString(R.string.error_generic), InAppNotifier.Type.ERROR)
                    binding.btnSubmitOrderRating.isEnabled = true
                }
            }
        }

        dialog.show()
    }

    /** Builds 5 tappable star ImageViews into [container], returns them, and
     * fires [onChanged] with the 1-5 rating whenever one is tapped. */
    private fun buildStarRow(
        activity: AppCompatActivity,
        container: LinearLayout,
        onChanged: (Int) -> Unit
    ): List<ImageView> {
        container.removeAllViews()
        val sizePx = (30 * activity.resources.displayMetrics.density).toInt()
        val marginPx = (6 * activity.resources.displayMetrics.density).toInt()
        val stars = mutableListOf<ImageView>()
        var current = 0

        fun refresh() {
            stars.forEachIndexed { index, star ->
                val filled = index < current
                star.setColorFilter(
                    if (filled) activity.getColor(R.color.rating_gold) else activity.getColor(R.color.outline)
                )
            }
        }

        for (i in 0 until 5) {
            val star = ImageView(activity).apply {
                layoutParams = LinearLayout.LayoutParams(sizePx, sizePx).also {
                    if (i < 4) it.marginEnd = marginPx
                }
                setImageResource(R.drawable.ic_star)
                setColorFilter(activity.getColor(R.color.outline))
                isClickable = true
                isFocusable = true
                val outValue = android.util.TypedValue()
                activity.theme.resolveAttribute(
                    android.R.attr.selectableItemBackgroundBorderless, outValue, true
                )
                setBackgroundResource(outValue.resourceId)
            }
            star.setOnClickListener {
                current = i + 1
                onChanged(current)
                refresh()
            }
            stars.add(star)
            container.addView(star)
        }
        return stars
    }
}
