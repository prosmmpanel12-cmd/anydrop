package com.anydrop.restaurant.ui.common

import androidx.fragment.app.FragmentActivity
import com.anydrop.restaurant.BuildConfig
import com.anydrop.restaurant.network.ApiClient
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.MainScope
import kotlinx.coroutines.async
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext

/**
 * Restaurant App's copy of the Customer App's UpdateChecker (§9, 2026-08-28
 * — Restaurant App's SplashActivity had no version-check code at all, so
 * `force_update`/`min_app_version_restaurant` never had any effect here
 * even though the backend endpoint and app_settings already existed and
 * were already wired for the Customer App).
 *
 * Hits GET /system/app-version.php?platform=restaurant at startup and
 * shows an in-app popup if the installed build is outdated. Forced
 * (non-dismissible) when below min_version_code, optional ("Later") when
 * merely below latest_version_code. Same thresholds/behaviour as the
 * Customer App's checker, just pointed at the restaurant platform key.
 *
 * Splash is kept on screen for a minimum duration regardless of how fast
 * the network call returns, so the branded splash always gets a proper
 * moment on screen instead of flashing by instantly. Uses this app's
 * existing splash hold time (900ms, same as SplashActivity's prior
 * logo-only `holdMillis`) rather than the Customer App's longer 7500ms —
 * that value there covers its extra banner-image fetch, which this
 * screen doesn't have.
 */
object UpdateChecker {

    private const val MIN_SPLASH_MILLIS = 900L

    fun check(activity: FragmentActivity, onDone: () -> Unit) {
        val scope = MainScope()
        scope.launch {
            val minDelay = async { delay(MIN_SPLASH_MILLIS) }

            val info = try {
                val api = ApiClient.create(activity)
                withContext(Dispatchers.IO) { api.getAppVersion("restaurant") }.body()?.data
            } catch (e: Exception) {
                // Backend unreachable — don't block the app on a version
                // check failure, same as the Customer App's checker.
                null
            }

            val current = BuildConfig.VERSION_CODE
            when {
                info == null -> {
                    minDelay.await()
                    onDone()
                }
                info.maintenanceMode -> {
                    // Checked ahead of the version-code branches — see
                    // Customer App's UpdateChecker for the same ordering
                    // rationale.
                    minDelay.await()
                    MaintenanceDialogFragment.newInstance(info.maintenanceMessage)
                        .show(activity.supportFragmentManager, "maintenance")
                    // Do not call onDone() — app should not proceed while
                    // in maintenance.
                }
                current < info.minVersionCode -> {
                    minDelay.await()
                    UpdateDialogFragment.newInstance(info, forced = true)
                        .show(activity.supportFragmentManager, "update_forced")
                    // Forced update: do not call onDone() — app should not proceed.
                }
                current < info.latestVersionCode -> {
                    minDelay.await()
                    val dialog = UpdateDialogFragment.newInstance(info, forced = false)
                    dialog.onLater = { onDone() }
                    dialog.show(activity.supportFragmentManager, "update_optional")
                }
                else -> {
                    minDelay.await()
                    onDone()
                }
            }
        }
    }
}
