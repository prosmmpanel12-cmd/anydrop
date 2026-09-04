package com.anydrop.food.ui.profile

import android.os.Bundle
import android.view.View
import androidx.appcompat.app.AppCompatActivity
import androidx.core.widget.doAfterTextChanged
import androidx.lifecycle.lifecycleScope
import com.anydrop.food.R
import com.anydrop.food.databinding.ActivityFeedbackBinding
import com.anydrop.food.network.ApiClient
import com.anydrop.food.network.SubmitFeedbackBody
import com.anydrop.food.ui.common.InAppNotifier
import kotlinx.coroutines.launch

/**
 * Phase 3.6 §2.7 — Feedback form. Message is required (validated
 * non-empty client-side, mirrors the backend's `require_fields`), star
 * rating is optional (1-5, omitted entirely from the request body when
 * no star is tapped since the backend treats a blank/absent rating as
 * "no rating" rather than 0).
 */
class FeedbackActivity : AppCompatActivity() {

    private lateinit var binding: ActivityFeedbackBinding
    private val api by lazy { ApiClient.create(this) }

    private var selectedRating = 0
    private lateinit var stars: List<android.widget.ImageView>
    private var isSubmitting = false

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityFeedbackBinding.inflate(layoutInflater)
        setContentView(binding.root)

        binding.btnBack.setOnClickListener { finish() }

        stars = listOf(
            binding.feedbackStar1,
            binding.feedbackStar2,
            binding.feedbackStar3,
            binding.feedbackStar4,
            binding.feedbackStar5
        )
        stars.forEachIndexed { index, star ->
            star.setOnClickListener { setRating(index + 1) }
        }
        refreshStars()

        binding.inputFeedbackMessage.doAfterTextChanged {
            if (binding.feedbackMessageError.visibility == View.VISIBLE && !it.isNullOrBlank()) {
                binding.feedbackMessageError.visibility = View.GONE
            }
        }

        binding.btnSubmitFeedback.setOnClickListener { submitFeedback() }
    }

    private fun setRating(rating: Int) {
        // Tapping the already-selected star clears the rating (optional field).
        selectedRating = if (selectedRating == rating) 0 else rating
        refreshStars()
    }

    private fun refreshStars() {
        stars.forEachIndexed { index, star ->
            val filled = index < selectedRating
            star.setColorFilter(
                if (filled) getColor(R.color.rating_gold) else getColor(R.color.outline)
            )
        }
    }

    private fun submitFeedback() {
        if (isSubmitting) return
        val message = binding.inputFeedbackMessage.text?.toString()?.trim().orEmpty()
        if (message.isEmpty()) {
            binding.feedbackMessageError.visibility = View.VISIBLE
            return
        }
        binding.feedbackMessageError.visibility = View.GONE

        isSubmitting = true
        binding.btnSubmitFeedback.isEnabled = false
        lifecycleScope.launch {
            try {
                val response = api.submitFeedback(
                    SubmitFeedbackBody(
                        message = message,
                        rating = if (selectedRating > 0) selectedRating else null
                    )
                )
                if (response.isSuccessful) {
                    InAppNotifier.show(
                        this@FeedbackActivity,
                        getString(R.string.feedback_submitted),
                        InAppNotifier.Type.SUCCESS
                    )
                    finish()
                } else {
                    InAppNotifier.show(
                        this@FeedbackActivity,
                        getString(R.string.error_generic),
                        InAppNotifier.Type.ERROR
                    )
                }
            } catch (e: Exception) {
                InAppNotifier.show(
                    this@FeedbackActivity,
                    getString(R.string.error_generic),
                    InAppNotifier.Type.ERROR
                )
            } finally {
                isSubmitting = false
                binding.btnSubmitFeedback.isEnabled = true
            }
        }
    }
}
