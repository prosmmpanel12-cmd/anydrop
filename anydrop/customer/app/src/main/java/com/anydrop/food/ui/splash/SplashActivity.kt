package com.anydrop.food.ui.splash

import android.content.Intent
import android.os.Bundle
import android.view.animation.AnimationUtils
import androidx.appcompat.app.AppCompatActivity
import androidx.lifecycle.lifecycleScope
import com.anydrop.food.R
import com.anydrop.food.data.AppConfigCache
import com.anydrop.food.data.TokenManager
import com.anydrop.food.databinding.ActivitySplashBinding
import com.anydrop.food.network.ApiClient
import com.anydrop.food.ui.common.UpdateChecker
import com.anydrop.food.ui.home.HomeActivity
import com.anydrop.food.ui.login.LoginActivity
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext

/**
 * Entry point: Splash -> fetch banner image + version check -> Login or Home.
 * The banner image (and legal page URLs) come from the backend's
 * splash-config endpoint and are cached in memory for the Login screen to
 * reuse without a second network call.
 */
class SplashActivity : AppCompatActivity() {

    private lateinit var binding: ActivitySplashBinding

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivitySplashBinding.inflate(layoutInflater)
        setContentView(binding.root)

        playEntranceAnimation()
        loadSplashConfig()

        UpdateChecker.check(this) {
            proceed()
        }
    }

    private fun playEntranceAnimation() {
        // Gentle zoom+fade in for the full-bleed artwork, then the loading
        // dots fade in slightly after so the motion feels sequenced rather
        // than everything popping in at once.
        binding.splashBannerImage.startAnimation(
            AnimationUtils.loadAnimation(this, R.anim.splash_hero_in)
        )
        binding.splashProgress.alpha = 0f
        binding.splashProgress.animate()
            .alpha(1f)
            .setStartDelay(450)
            .setDuration(350)
            .start()
    }

    private fun loadSplashConfig() {
        // The splash screen itself now always shows the bundled
        // splash_hero.jpg (set directly in activity_splash.xml), so we no
        // longer load a network image here. We still fetch splash-config
        // for the legal-page URLs and the login screen's banner rotation.
        lifecycleScope.launch {
            try {
                val api = ApiClient.create(this@SplashActivity)
                val config = withContext(Dispatchers.IO) { api.getSplashConfig() }.body()?.data
                AppConfigCache.splashConfig = config
            } catch (e: Exception) {
                // Backend unreachable — legal links / login banner fallback
                // will simply have nothing extra to show, no crash.
            }
        }
    }

    private fun proceed() {
        val tokenManager = TokenManager(this)
        val next = if (tokenManager.isLoggedIn()) HomeActivity::class.java else LoginActivity::class.java
        startActivity(Intent(this, next))
        overridePendingTransition(android.R.anim.fade_in, android.R.anim.fade_out)
        finish()
    }
}
