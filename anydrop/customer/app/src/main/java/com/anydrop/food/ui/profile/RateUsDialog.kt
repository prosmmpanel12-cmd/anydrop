package com.anydrop.food.ui.profile

import android.view.View
import androidx.appcompat.app.AppCompatActivity
import androidx.lifecycle.lifecycleScope
import com.google.android.material.bottomsheet.BottomSheetDialog
import com.anydrop.food.R
import com.anydrop.food.databinding.DialogRateUsBinding
import com.anydrop.food.network.ApiClient
import com.anydrop.food.network.SubmitFeedbackBody
import com.anydrop.food.ui.common.InAppNotifier
import kotlinx.coroutines.launch

/**
 * Phase 3.6 §2.7 (step 12c) — star-rating-only "Rate Us" prompt. The
 * backend's `feedback` endpoint requires a non-blank `message`, so a
 * fixed default message (`rate_us_default_message`) is sent alongside
 * whatever star rating the user picks — there's no free-text field here,
 * that's what the separate Feedback screen is for.
 */
object RateUsDialog {

    fun show(activity: AppCompatActivity) {
        val binding = DialogRateUsBinding.inflate(activity.layoutInflater)
        val dialog = BottomSheetDialog(activity)
        dialog.setContentView(binding.root)
        dialog.setCancelable(true)

        val stars = listOf(
            binding.rateUsStar1,
            binding.rateUsStar2,
            binding.rateUsStar3,
            binding.rateUsStar4,
            binding.rateUsStar5
        )
        var selectedRating = 0

        fun refreshStars() {
            stars.forEachIndexed { index, star ->
                val filled = index < selectedRating
                star.setColorFilter(
                    if (filled) activity.getColor(R.color.rating_gold) else activity.getColor(R.color.outline)
                )
            }
        }

        stars.forEachIndexed { index, star ->
            star.setOnClickListener {
                selectedRating = index + 1
                binding.rateUsError.visibility = View.GONE
                refreshStars()
            }
        }

        binding.btnRateUsMaybeLater.setOnClickListener { dialog.dismiss() }

        binding.btnSubmitRating.setOnClickListener {
            if (selectedRating == 0) {
                binding.rateUsError.visibility = View.VISIBLE
                return@setOnClickListener
            }
            binding.btnSubmitRating.isEnabled = false
            val api = ApiClient.create(activity)
            activity.lifecycleScope.launch {
                try {
                    val response = api.submitFeedback(
                        SubmitFeedbackBody(
                            message = activity.getString(R.string.rate_us_default_message),
                            rating = selectedRating
                        )
                    )
                    if (response.isSuccessful) {
                        InAppNotifier.show(
                            activity,
                            activity.getString(R.string.rate_us_submitted),
                            InAppNotifier.Type.SUCCESS
                        )
                        dialog.dismiss()
                    } else {
                        InAppNotifier.show(
                            activity,
                            activity.getString(R.string.error_generic),
                            InAppNotifier.Type.ERROR
                        )
                        binding.btnSubmitRating.isEnabled = true
                    }
                } catch (e: Exception) {
                    InAppNotifier.show(
                        activity,
                        activity.getString(R.string.error_generic),
                        InAppNotifier.Type.ERROR
                    )
                    binding.btnSubmitRating.isEnabled = true
                }
            }
        }

        dialog.show()
    }
}
