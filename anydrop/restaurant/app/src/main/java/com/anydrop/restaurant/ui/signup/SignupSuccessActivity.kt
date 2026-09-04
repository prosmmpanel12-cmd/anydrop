package com.anydrop.restaurant.ui.signup

import android.content.Intent
import android.os.Bundle
import android.view.View
import android.view.animation.AnimationUtils
import androidx.appcompat.app.AppCompatActivity
import com.anydrop.restaurant.R
import com.anydrop.restaurant.databinding.ActivitySignupSuccessBinding
import com.anydrop.restaurant.ui.login.LoginActivity

class SignupSuccessActivity : AppCompatActivity() {

    private lateinit var binding: ActivitySignupSuccessBinding

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivitySignupSuccessBinding.inflate(layoutInflater)
        setContentView(binding.root)

        binding.successIcon.startAnimation(AnimationUtils.loadAnimation(this, R.anim.success_pop_in))
        binding.successTitle.startAnimation(
            AnimationUtils.loadAnimation(this, R.anim.form_field_in).apply { startOffset = 250 }
        )
        binding.successBody.startAnimation(
            AnimationUtils.loadAnimation(this, R.anim.form_field_in).apply { startOffset = 320 }
        )

        // Service-area notice (§0, 2026-08-28) — informational only, never
        // implies the application failed; see OtpVerifyActivity's kdoc for
        // why this is only ever set true when the owner actually picked a
        // pin that didn't resolve to a known area.
        if (intent.getBooleanExtra(EXTRA_AREA_NOT_COVERED, false)) {
            binding.areaNoticeText.visibility = View.VISIBLE
            binding.areaNoticeText.startAnimation(
                AnimationUtils.loadAnimation(this, R.anim.form_field_in).apply { startOffset = 380 }
            )
        }

        binding.btnBackToLogin.setOnClickListener {
            // Clears SignupActivity/OtpVerifyActivity off the back stack too —
            // pressing back from a fresh Login shouldn't return into the
            // now-submitted signup flow.
            val intent = Intent(this, LoginActivity::class.java).apply {
                flags = Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TASK
            }
            startActivity(intent)
            overridePendingTransition(android.R.anim.fade_in, android.R.anim.fade_out)
        }
    }

    companion object {
        const val EXTRA_AREA_NOT_COVERED = "extra_area_not_covered"
    }
}
