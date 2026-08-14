package com.anydrop.restaurant.ui.signup

import android.content.Intent
import android.os.Bundle
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
}
