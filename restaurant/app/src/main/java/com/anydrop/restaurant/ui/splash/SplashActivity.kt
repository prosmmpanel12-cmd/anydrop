package com.anydrop.restaurant.ui.splash

import android.content.Intent
import android.os.Bundle
import android.view.View
import android.view.animation.AnimationUtils
import androidx.appcompat.app.AppCompatActivity
import com.anydrop.restaurant.R
import com.anydrop.restaurant.data.TokenManager
import com.anydrop.restaurant.ui.common.UpdateChecker
import com.anydrop.restaurant.ui.login.LoginActivity
import com.anydrop.restaurant.ui.main.MainActivity

/**
 * New launcher Activity (was LoginActivity before this session). Plays the
 * same scale+overshoot logo / fade-up text entrance the Customer app uses
 * on its splash (res/anim/splash_logo_in.xml, splash_text_in.xml — copied
 * as-is so both apps open with a matching brand moment), then routes to
 * MainActivity's bottom-nav shell (already logged in) or Login, exactly
 * like LoginActivity's old isLoggedIn() check used to, just one hop
 * earlier.
 *
 * 2026-08-28 (§9) — also runs UpdateChecker here now. Previously this
 * screen was logo-animation-only with no version-check code at all, so
 * `force_update`/`min_app_version_restaurant` (already wired for the
 * Customer App, already have live backend support) had zero effect on
 * this app — an admin flipping force-update on would do nothing here.
 * UpdateChecker.check() replaces the old fixed-delay Handler.postDelayed
 * proceed() call below: it still honours the same holdMillis via its own
 * MIN_SPLASH_MILLIS, then either proceeds normally, shows a dismissible
 * "Later" popup, or — if below min_version_code — shows a forced,
 * non-dismissible popup and never calls proceed() at all.
 */
class SplashActivity : AppCompatActivity() {

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContentView(R.layout.activity_splash)

        val logo = findViewById<View>(R.id.splashLogo)
        val title = findViewById<View>(R.id.splashTitle)
        val tagline = findViewById<View>(R.id.splashTagline)

        val logoAnim = AnimationUtils.loadAnimation(this, R.anim.splash_logo_in)
        val textAnim = AnimationUtils.loadAnimation(this, R.anim.splash_text_in)
        val taglineAnim = AnimationUtils.loadAnimation(this, R.anim.splash_text_in).apply {
            startOffset = 400
        }

        logo.visibility = View.VISIBLE
        title.visibility = View.VISIBLE
        tagline.visibility = View.VISIBLE
        logo.startAnimation(logoAnim)
        title.startAnimation(textAnim)
        tagline.startAnimation(taglineAnim)

        UpdateChecker.check(this) { proceed() }
    }

    private fun proceed() {
        if (isFinishing) return
        val tokenManager = TokenManager(this)
        val next = if (tokenManager.isLoggedIn()) MainActivity::class.java else LoginActivity::class.java
        startActivity(Intent(this, next))
        overridePendingTransition(android.R.anim.fade_in, android.R.anim.fade_out)
        finish()
    }
}
