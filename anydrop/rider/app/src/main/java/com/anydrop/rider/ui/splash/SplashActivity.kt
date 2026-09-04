package com.anydrop.rider.ui.splash

import android.content.Intent
import android.os.Bundle
import android.os.Handler
import android.os.Looper
import android.view.animation.AnimationUtils
import androidx.appcompat.app.AppCompatActivity
import com.anydrop.rider.R
import com.anydrop.rider.data.TokenManager
import com.anydrop.rider.databinding.ActivitySplashBinding
import com.anydrop.rider.ui.login.LoginActivity
import com.anydrop.rider.ui.pending.ApplicationStatusActivity

/**
 * Launcher activity. Plays the brand entrance animation, then routes:
 * - No saved token -> LoginActivity.
 * - Saved token -> ApplicationStatusActivity, which is itself the single
 *   choke point that redirects an approved rider straight on to
 *   RiderDashboardActivity (Phase 3, doc 83) — see that screen's kdoc for
 *   why the branch lives there instead of duplicated at every entry point
 *   (Splash, post-signup, post-login all funnel through it the same way).
 */
class SplashActivity : AppCompatActivity() {

    private lateinit var binding: ActivitySplashBinding

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivitySplashBinding.inflate(layoutInflater)
        setContentView(binding.root)

        binding.splashLogoCircle.startAnimation(AnimationUtils.loadAnimation(this, R.anim.splash_logo_in))
        binding.splashTitle.startAnimation(AnimationUtils.loadAnimation(this, R.anim.splash_text_in))

        Handler(Looper.getMainLooper()).postDelayed({ routeNext() }, 900)
    }

    private fun routeNext() {
        val tokenManager = TokenManager(this)
        val intent = if (tokenManager.isLoggedIn()) {
            Intent(this, ApplicationStatusActivity::class.java)
        } else {
            Intent(this, LoginActivity::class.java)
        }
        startActivity(intent)
        overridePendingTransition(android.R.anim.fade_in, android.R.anim.fade_out)
        finish()
    }
}
